<?php
/**
 * Dashboard-owned automatic backup management.
 * This is the source of truth for automatic database backups.
 */

$resolveDashboardHerikaRoot = static function (array $candidates): string {
    foreach ($candidates as $candidate) {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        if (is_dir($normalized) && file_exists($normalized . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php')) {
            return realpath($normalized) ?: $normalized;
        }
    }
    return '';
};

$dashboardAutomaticBackupHerikaRoot = $resolveDashboardHerikaRoot([
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'HerikaServer',
    dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'HerikaServer',
    '/var/www/html/HerikaServer',
]);

if (!class_exists('Logger') && $dashboardAutomaticBackupHerikaRoot !== '') {
    require_once($dashboardAutomaticBackupHerikaRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'logger.php');
}

if (!function_exists('dashboardAutomaticBackupLogInfo')) {
    function dashboardAutomaticBackupLogInfo(string $message): void
    {
        if (class_exists('Logger')) {
            Logger::info($message);
            return;
        }
        error_log('[DashboardAutomaticBackup] ' . $message);
    }
}

if (!function_exists('dashboardAutomaticBackupLogWarn')) {
    function dashboardAutomaticBackupLogWarn(string $message): void
    {
        if (class_exists('Logger')) {
            Logger::warn($message);
            return;
        }
        error_log('[DashboardAutomaticBackup] ' . $message);
    }
}

if (!function_exists('dashboardEnsureSettingsTable')) {
    function dashboardEnsureSettingsTable($db): void
    {
        $db->execQuery("CREATE SCHEMA IF NOT EXISTS chim_meta");
        $db->execQuery("CREATE TABLE IF NOT EXISTS chim_meta.settings (key TEXT NOT NULL, value TEXT)");
        $db->execQuery(
            "DELETE FROM chim_meta.settings a
             USING chim_meta.settings b
             WHERE a.key = b.key
               AND a.ctid < b.ctid"
        );
        $db->execQuery("CREATE UNIQUE INDEX IF NOT EXISTS chim_meta_settings_key_idx ON chim_meta.settings (key)");
    }
}

if (!function_exists('dashboardReadSettingValue')) {
    function dashboardReadSettingValue($db, string $key): ?string
    {
        dashboardEnsureSettingsTable($db);
        $quotedKey = method_exists($db, 'quote') ? $db->quote($key) : ("'" . str_replace("'", "''", $key) . "'");
        $row = $db->fetchOne("SELECT value FROM chim_meta.settings WHERE key = {$quotedKey}");
        if (is_array($row) && array_key_exists('value', $row)) {
            return is_string($row['value']) ? $row['value'] : strval($row['value']);
        }
        return null;
    }
}

if (!function_exists('dashboardWriteSettingValue')) {
    function dashboardWriteSettingValue($db, string $key, string $value): void
    {
        dashboardEnsureSettingsTable($db);
        $db->upsertRowOnConflict('chim_meta.settings', ['key' => $key, 'value' => $value], 'key');
    }
}

class DashboardAutomaticBackup {

    private const MULTI_DB_BACKUP_MARKER = '-- DWEMER_DASHBOARD_MULTI_DB_BACKUP_V1';

    private $backupDir;
    private $maxBackups = 5;

    public function __construct() {
        $this->backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'databasebackups' . DIRECTORY_SEPARATOR;

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
            dashboardAutomaticBackupLogInfo("Created automatic backup directory: " . $this->backupDir);
        }

        try {
            $db = $this->getDatabaseConnection();
            if ($db) {
                $settingValue = dashboardReadSettingValue($db, 'AUTOMATIC_BACKUP_MAX_COUNT');
                if ($settingValue !== null) {
                    $v = intval($settingValue);
                    if ($v > 0 && $v < 100) {
                        $this->maxBackups = $v;
                    }
                }
            }
        } catch (Exception $e) {
            // keep default
        }
    }

    public function isEnabled() {
        try {
            $db = $this->getDatabaseConnection();
            if ($db) {
                $settingValue = dashboardReadSettingValue($db, 'AUTOMATIC_DATABASE_BACKUPS');
                if ($settingValue !== null) {
                    $val = strtolower(trim($settingValue));
                    return ($val === 'true' || $val === '1' || $val === 'yes' || $val === 'on');
                }
            }
        } catch (Exception $e) {
            // fall through
        }

        if (isset($GLOBALS['AUTOMATIC_DATABASE_BACKUPS'])) {
            return $GLOBALS['AUTOMATIC_DATABASE_BACKUPS'] === true || $GLOBALS['AUTOMATIC_DATABASE_BACKUPS'] === "true";
        }

        return false;
    }

    private function getDatabaseConfigs(): array
    {
        return [
            [
                'name' => 'dwemer',
                'exclude_tables' => ['chim_meta.settings'],
            ],
            [
                'name' => 'stobe',
                'exclude_tables' => [],
            ],
            [
                'name' => 'dialectic',
                'exclude_tables' => [],
            ],
        ];
    }

    private function getBackupScopeSlug(): string
    {
        return 'herikaserver_stobeserver_dialecticserver';
    }

    private function appendFileToBackup(string $sourcePath, string $destPath): bool
    {
        $readHandle = @fopen($sourcePath, 'rb');
        if ($readHandle === false) {
            return false;
        }

        $writeHandle = @fopen($destPath, 'ab');
        if ($writeHandle === false) {
            fclose($readHandle);
            return false;
        }

        $copied = stream_copy_to_stream($readHandle, $writeHandle);
        fclose($readHandle);
        fclose($writeHandle);

        return $copied !== false;
    }

    private function createCombinedBackupFile(string $filepath, string $host, string $port, string $username, string &$errorMessage = ''): bool
    {
        $header = self::MULTI_DB_BACKUP_MARKER . PHP_EOL . "\\set ON_ERROR_STOP on" . PHP_EOL;
        if (@file_put_contents($filepath, $header) === false) {
            $errorMessage = 'Failed to initialize automatic backup file.';
            return false;
        }

        foreach ($this->getDatabaseConfigs() as $config) {
            $dbName = trim(strval($config['name'] ?? ''));
            if ($dbName === '') {
                continue;
            }

            $sectionHeader = PHP_EOL . "-- DATABASE: {$dbName}" . PHP_EOL . "\\connect {$dbName}" . PHP_EOL;
            if (@file_put_contents($filepath, $sectionHeader, FILE_APPEND) === false) {
                @unlink($filepath);
                $errorMessage = "Failed to write {$dbName} backup section.";
                return false;
            }

            $tmpFile = $filepath . '.' . $dbName . '.tmp';
            $excludeArgs = '';
            $excludeTables = is_array($config['exclude_tables'] ?? null) ? $config['exclude_tables'] : [];
            foreach ($excludeTables as $tableName) {
                $tableName = trim(strval($tableName));
                if ($tableName !== '') {
                    $excludeArgs .= ' -T ' . escapeshellarg($tableName);
                }
            }

            $command = "HOME=/tmp pg_dump -h " . escapeshellarg($host)
                . " -p " . escapeshellarg($port)
                . " -U " . escapeshellarg($username)
                . " -d " . escapeshellarg($dbName)
                . $excludeArgs
                . " > " . escapeshellarg($tmpFile) . " 2>&1";
            $result = shell_exec($command);

            if (!file_exists($tmpFile) || filesize($tmpFile) <= 0) {
                @unlink($tmpFile);
                @unlink($filepath);
                $errorMessage = "Automatic backup creation failed for {$dbName}.";
                if (is_string($result) && trim($result) !== '') {
                    $errorMessage .= ' ' . trim(substr($result, 0, 500));
                }
                return false;
            }

            $firstChunk = strval(@file_get_contents($tmpFile, false, null, 0, 256));
            if (strpos($firstChunk, 'pg_dump: error:') !== false || strpos($firstChunk, 'FATAL:') !== false) {
                @unlink($tmpFile);
                @unlink($filepath);
                $errorMessage = "Automatic backup creation failed for {$dbName}: " . trim(substr($firstChunk, 0, 500));
                return false;
            }

            if (!$this->appendFileToBackup($tmpFile, $filepath)) {
                @unlink($tmpFile);
                @unlink($filepath);
                $errorMessage = "Failed to append {$dbName} dump to automatic backup.";
                return false;
            }

            @unlink($tmpFile);
        }

        return true;
    }

    public function createBackup() {
        if (!$this->isEnabled()) {
            dashboardAutomaticBackupLogInfo("Automatic backup skipped - feature is disabled");
            return false;
        }

        dashboardAutomaticBackupLogInfo("Starting automatic database backup creation");

        try {
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "auto_backup_" . $this->getBackupScopeSlug() . "_{$timestamp}.sql";
            $filepath = $this->backupDir . $filename;

            dashboardAutomaticBackupLogInfo("Creating backup file: " . $filename);

            $host = 'localhost';
            $port = '5432';
            $username = 'dwemer';

            shell_exec('echo "localhost:5432:*:dwemer:dwemer" > /tmp/.pgpass; echo $?');
            shell_exec('chmod 600 /tmp/.pgpass; echo $?');

            dashboardAutomaticBackupLogInfo("Authentication setup complete");

            $backupError = '';
            $backupCreated = $this->createCombinedBackupFile($filepath, $host, $port, $username, $backupError);

            if ($backupCreated && file_exists($filepath) && filesize($filepath) > 0) {
                $fileSize = filesize($filepath);
                dashboardAutomaticBackupLogInfo("Automatic database backup created successfully: " . $filename . " (Size: " . self::formatFileSize($fileSize) . ")");
                $this->cleanupOldBackups();
                return true;
            }

            dashboardAutomaticBackupLogWarn("Automatic database backup failed: " . ($backupError !== '' ? $backupError : 'file not created or empty'));
            return false;
        } catch (Exception $e) {
            dashboardAutomaticBackupLogWarn("Automatic database backup error: " . $e->getMessage());
            return false;
        }
    }

    public function getBackups() {
        $backups = [];

        if (!is_dir($this->backupDir)) {
            return $backups;
        }

        $files = scandir($this->backupDir);
        foreach ($files as $file) {
            if (strpos($file, 'auto_backup_') === 0 && substr($file, -4) === '.sql') {
                $filepath = $this->backupDir . $file;
                $backups[] = [
                    'filename' => $file,
                    'filepath' => $filepath,
                    'size' => filesize($filepath),
                    'date' => filemtime($filepath),
                    'formatted_date' => date('Y-m-d H:i:s', filemtime($filepath))
                ];
            }
        }

        usort($backups, function($a, $b) {
            return $b['date'] - $a['date'];
        });

        return $backups;
    }

    private function cleanupOldBackups() {
        try {
            $db = $this->getDatabaseConnection();
            if ($db) {
                $settingValue = dashboardReadSettingValue($db, 'AUTOMATIC_BACKUP_MAX_COUNT');
                if ($settingValue !== null) {
                    $v = intval($settingValue);
                    if ($v > 0 && $v < 100) {
                        $this->maxBackups = $v;
                    }
                }
            }
        } catch (Exception $e) {
        }

        $backups = $this->getBackups();
        if (count($backups) > $this->maxBackups) {
            $toDelete = array_slice($backups, $this->maxBackups);
            foreach ($toDelete as $backup) {
                if (unlink($backup['filepath'])) {
                    dashboardAutomaticBackupLogInfo("Deleted old automatic backup: " . $backup['filename']);
                } else {
                    dashboardAutomaticBackupLogWarn("Failed to delete old automatic backup: " . $backup['filename']);
                }
            }
        }
    }

    public static function formatFileSize($bytes) {
        if ($bytes == 0) return '0 Bytes';
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

    public function deleteBackup($filename) {
        $filepath = $this->backupDir . $filename;
        if (strpos($filename, 'auto_backup_') !== 0) {
            dashboardAutomaticBackupLogWarn("Attempted to delete non-automatic backup file: " . $filename);
            return false;
        }

        if (file_exists($filepath) && unlink($filepath)) {
            dashboardAutomaticBackupLogInfo("Manual deletion of automatic backup: " . $filename);
            return true;
        }

        return false;
    }

    public function shouldCreateBackup() {
        $cooldownSeconds = 600;

        try {
            $backups = $this->getBackups();
            if (!empty($backups)) {
                $latest = $backups[0];
                if (isset($latest['date']) && (time() - (int)$latest['date'] < $cooldownSeconds)) {
                    dashboardAutomaticBackupLogInfo("Skipping automatic backup: recent backup exists within cooldown window");
                    return false;
                }
            }

            $db = $this->getDatabaseConnection();
            if ($db) {
                $settingValue = dashboardReadSettingValue($db, 'AUTOMATIC_BACKUP_LAST_TIMESTAMP');
                if ($settingValue !== null) {
                    $last = strtotime($settingValue);
                    if ($last !== false && (time() - $last) < $cooldownSeconds) {
                        dashboardAutomaticBackupLogInfo("Skipping automatic backup: last backup timestamp within cooldown window");
                        return false;
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            dashboardAutomaticBackupLogWarn("Error checking backup requirement: " . $e->getMessage());
            return true;
        }
    }

    public function updateBackupCheckDate() {
        try {
            $db = $this->getDatabaseConnection();
            if (!$db) {
                dashboardAutomaticBackupLogWarn("No database connection available for updating backup timestamp");
                return;
            }

            $timestamp = date('Y-m-d H:i:s');
            dashboardWriteSettingValue($db, 'AUTOMATIC_BACKUP_LAST_TIMESTAMP', $timestamp);

            dashboardAutomaticBackupLogInfo("Updated last backup timestamp to: {$timestamp}");
        } catch (Exception $e) {
            dashboardAutomaticBackupLogWarn("Error updating backup timestamp: " . $e->getMessage());
        }
    }

    private function getDatabaseConnection() {
        try {
            if (isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
                try {
                    $GLOBALS['db']->fetchAll("SELECT 1");
                    return $GLOBALS['db'];
                } catch (Exception $e) {
                    dashboardAutomaticBackupLogWarn("Global database connection exists but not working: " . $e->getMessage());
                }
            }

            if (!class_exists('sql')) {
                $dbDriver = strval($GLOBALS['DBDRIVER'] ?? 'postgresql');
                if ($dashboardAutomaticBackupHerikaRoot !== '') {
                    $dbClassPath = $dashboardAutomaticBackupHerikaRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . $dbDriver . '.class.php';
                    if (file_exists($dbClassPath)) {
                        require_once($dbClassPath);
                    }
                }
            }

            if (!class_exists('sql')) {
                return null;
            }

            $db = new sql();
            $db->fetchAll("SELECT 1");
            return $db;
        } catch (Exception $e) {
            dashboardAutomaticBackupLogWarn("Error getting database connection: " . $e->getMessage());
            return null;
        }
    }
}

function initializeDashboardAutomaticBackup() {
    try {
        $backup = new DashboardAutomaticBackup();
        dashboardAutomaticBackupLogInfo("Automatic backup system initialized");

        if (!$backup->isEnabled()) {
            dashboardAutomaticBackupLogInfo("Automatic backups are disabled - skipping backup creation");
            return;
        }

        if ($backup->shouldCreateBackup()) {
            dashboardAutomaticBackupLogInfo("Server restart backup: creating backup");
            $result = $backup->createBackup();
            if ($result) {
                dashboardAutomaticBackupLogInfo("Server restart backup created successfully");
                $backup->updateBackupCheckDate();
            } else {
                dashboardAutomaticBackupLogWarn("Server restart backup creation failed");
            }
        } else {
            dashboardAutomaticBackupLogInfo("Server restart backup skipped due to cooldown");
        }
    } catch (Exception $e) {
        dashboardAutomaticBackupLogWarn("Failed to initialize automatic backup system: " . $e->getMessage());
    }
}

function deferredDashboardAutomaticBackupInit() {
    static $hasRun = false;
    if ($hasRun) {
        return;
    }
    $hasRun = true;
    initializeDashboardAutomaticBackup();
}
