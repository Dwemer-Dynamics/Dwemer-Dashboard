<?php
session_start();

$resolveServerRoot = static function (array $candidates): string {
    foreach ($candidates as $candidate) {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        if (is_dir($normalized) && file_exists($normalized . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php')) {
            return realpath($normalized) ?: $normalized;
        }
    }
    return '';
};

$herikaRoot = $resolveServerRoot([
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'HerikaServer',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'HerikaServer',
    '/var/www/html/HerikaServer',
]);

if ($herikaRoot === '') {
    http_response_code(500);
    echo 'HerikaServer path not found.';
    exit;
}

$herikaUiPath = $herikaRoot . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR;

// Build URL prefixes for dashboard/herika links.
$scriptPath = str_replace('\\', '/', strval($_SERVER['SCRIPT_NAME'] ?? '/Dwemer-Dashboard/database_manager.php'));
if (preg_match('#^([A-Za-z]:[\\\\/]|/mnt/)#', $scriptPath) === 1) {
    $scriptPath = '/Dwemer-Dashboard/database_manager.php';
}
$urlPrefix = preg_replace('#/Dwemer-Dashboard(?:/.*)?$#', '', $scriptPath);
if (!is_string($urlPrefix) || $urlPrefix === '/' || $urlPrefix === null) {
    $urlPrefix = '';
}
$urlPrefix = rtrim($urlPrefix, '/');
$dashboardWebRoot = ($urlPrefix !== '' ? $urlPrefix : '') . '/Dwemer-Dashboard';
$webRoot = ($urlPrefix !== '' ? $urlPrefix : '') . '/HerikaServer';

require_once($herikaUiPath . 'profile_loader.php');

$enginePath = $herikaRoot . DIRECTORY_SEPARATOR;

require_once($enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");

$embedParam = strval($_GET['embed'] ?? $_POST['embed'] ?? '');
$isEmbed = ($embedParam === '1');
$debugPaneLink = false;

if (isset($_SESSION["PROFILE"])) {
    require_once($_SESSION["PROFILE"]);
}

$pattern = '/conf_([a-f0-9]+)\.php/';
preg_match($pattern, basename($_SESSION["PROFILE"]), $matches);
$hash = isset($matches[1]) ? $matches[1] : 'default';    

$db=new sql();
$GLOBALS["db"] = $db;
$res=$db->fetchAll("select max(gamets) as last_gamets from eventlog");
$last_gamets=$res[0]["last_gamets"]+1;

// Enable error reporting (for development purposes)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Paths
$rootPath = $enginePath;
$configFilepath = $rootPath . "conf" . DIRECTORY_SEPARATOR;

// Database connection details
$host = 'localhost';
$port = '5432';
$dbname = 'dwemer';
$schema = 'public';
$username = 'dwemer';
$password = 'dwemer';

// Initialize message variable
$message = '';

// PHP function to format file sizes
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function getStobePgConnection(string $host, string $port, string $username, string $password)
{
    if (!function_exists('pg_connect')) {
        return false;
    }
    $connectionString = "host={$host} port={$port} dbname=stobe user={$username} password={$password} connect_timeout=2";
    return @pg_connect($connectionString);
}

function getPgVersioningColumns($connection): array
{
    $columns = ['table' => '', 'version' => ''];
    $result = @pg_query($connection, "SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='database_versioning'");
    if (!$result) {
        return $columns;
    }

    $available = [];
    while ($row = @pg_fetch_assoc($result)) {
        $columnName = strtolower(trim(strval($row['column_name'] ?? '')));
        if ($columnName !== '') {
            $available[] = $columnName;
        }
    }
    @pg_free_result($result);

    if (in_array('tablename', $available, true)) {
        $columns['table'] = 'tablename';
    } elseif (in_array('table_name', $available, true)) {
        $columns['table'] = 'table_name';
    }

    if (in_array('version', $available, true)) {
        $columns['version'] = 'version';
    } elseif (in_array('patch_version', $available, true)) {
        $columns['version'] = 'patch_version';
    }

    return $columns;
}

function formatVersionDate($version) {
    // Version format: YYYYMMDDNNN (e.g., 20251207001)
    $str = (string)$version;
    if (strlen($str) >= 8) {
        $year = substr($str, 0, 4);
        $month = substr($str, 4, 2);
        $day = substr($str, 6, 2);
        $revision = strlen($str) > 8 ? substr($str, 8) : '001';
        return "{$year}-{$month}-{$day} (rev {$revision})";
    }
    return $version;
}



// Include automatic backup management
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "automatic_backup.php");

// Handle Automatic Backup settings (enable/disable and retention) BEFORE rendering (PRG pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_auto_backup_settings') {
    try {
        $enabled = isset($_POST['auto_enabled']) ? 'true' : 'false';
        $maxKeep = max(1, min(10, intval($_POST['auto_max'] ?? 5)));
        // Persist into chim_meta.settings
        $db->execQuery("CREATE SCHEMA IF NOT EXISTS chim_meta");
        $db->execQuery("CREATE TABLE IF NOT EXISTS chim_meta.settings (key TEXT PRIMARY KEY, value TEXT)");
        $db->upsertRowOnConflict('chim_meta.settings', ['key'=>'AUTOMATIC_DATABASE_BACKUPS','value'=>$enabled], 'key');
        $db->upsertRowOnConflict('chim_meta.settings', ['key'=>'AUTOMATIC_BACKUP_MAX_COUNT','value'=>(string)$maxKeep], 'key');
    } catch (Throwable $e) {
        // swallow and continue to redirect, errors will be visible in logs
    }
    // Redirect back to the same page (preserve query string like ?embed=1)
    // Preserve ?embed=1 so navbar stays hidden in config hub
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'database_manager.php') . ($qs ? ('?' . $qs) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Load live auto-backup settings once for consistent rendering
$autoEnabled = false;
$currentMax = 5;
try {
    $dbMeta = new sql();
    $dbMeta->execQuery("CREATE SCHEMA IF NOT EXISTS chim_meta");
    $dbMeta->execQuery("CREATE TABLE IF NOT EXISTS chim_meta.settings (key TEXT PRIMARY KEY, value TEXT)");
    $rowEn = $dbMeta->fetchOne("SELECT value FROM chim_meta.settings WHERE key='AUTOMATIC_DATABASE_BACKUPS'");
    if (is_array($rowEn) && isset($rowEn['value'])) {
        $val = strtolower(trim((string)$rowEn['value']));
        $autoEnabled = in_array($val, ['true','1','yes','on'], true);
    }
    $rowMax = $dbMeta->fetchOne("SELECT value FROM chim_meta.settings WHERE key='AUTOMATIC_BACKUP_MAX_COUNT'");
    if (is_array($rowMax) && isset($rowMax['value'])) {
        $v = intval(trim((string)$rowMax['value']));
        if ($v >= 1 && $v <= 10) { $currentMax = $v; }
    }
} catch (Throwable $e) {}

// Handle tile actions with PRG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_auto_enabled') {
    try {
        $dbMeta2 = new sql();
        $dbMeta2->execQuery("CREATE SCHEMA IF NOT EXISTS chim_meta");
        $dbMeta2->execQuery("CREATE TABLE IF NOT EXISTS chim_meta.settings (key TEXT PRIMARY KEY, value TEXT)");
        // Read current value and invert
        $rowEn2 = $dbMeta2->fetchOne("SELECT value FROM chim_meta.settings WHERE key='AUTOMATIC_DATABASE_BACKUPS'");
        $cur = 'false';
        if (is_array($rowEn2) && isset($rowEn2['value'])) {
            $val2 = strtolower(trim((string)$rowEn2['value']));
            $cur = (in_array($val2, ['true','1','yes','on'], true)) ? 'true' : 'false';
        }
        $new = ($cur === 'true') ? 'false' : 'true';
        $dbMeta2->upsertRowOnConflict('chim_meta.settings', ['key'=>'AUTOMATIC_DATABASE_BACKUPS','value'=>$new], 'key');
    } catch (Throwable $e) {}
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'database_manager.php') . ($qs ? ('?' . $qs) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_auto_max') {
    try {
        $maxKeep = max(1, min(10, intval($_POST['auto_max'] ?? 5)));
        $dbMeta3 = new sql();
        $dbMeta3->execQuery("CREATE SCHEMA IF NOT EXISTS chim_meta");
        $dbMeta3->execQuery("CREATE TABLE IF NOT EXISTS chim_meta.settings (key TEXT PRIMARY KEY, value TEXT)");
        $dbMeta3->upsertRowOnConflict('chim_meta.settings', ['key'=>'AUTOMATIC_BACKUP_MAX_COUNT','value'=>(string)$maxKeep], 'key');
    } catch (Throwable $e) {}
    $redirectUrl = $_SERVER['REQUEST_URI'] ?? ($_SERVER['PHP_SELF'] ?? 'database_manager.php');
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle reset database versioning entry (single entry)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'reset_db_version'
) {
    $versionTarget = strtolower(trim(strval($_POST['version_target'] ?? 'herika')));
    try {
        $tablename = trim(strval($_POST['tablename'] ?? ''));
        if ($tablename !== '') {
            if ($versionTarget === 'stobe') {
                $stobeConn = getStobePgConnection($host, $port, $username, $password);
                if (!$stobeConn) {
                    throw new RuntimeException('Failed to connect to Stobe database.');
                }
                $stobeColumns = getPgVersioningColumns($stobeConn);
                if ($stobeColumns['table'] === '') {
                    @pg_close($stobeConn);
                    throw new RuntimeException('Stobe database_versioning table was not found or is incompatible.');
                }
                $query = "DELETE FROM public.database_versioning WHERE {$stobeColumns['table']} = $1";
                $deleteResult = @pg_query_params($stobeConn, $query, [$tablename]);
                if (!$deleteResult) {
                    $errorText = trim(strval(@pg_last_error($stobeConn)));
                    @pg_close($stobeConn);
                    throw new RuntimeException($errorText !== '' ? $errorText : 'Unknown Stobe delete error.');
                }
                @pg_free_result($deleteResult);
                @pg_close($stobeConn);
            } else {
                $db->execQuery("DELETE FROM public.database_versioning WHERE tablename = " . $db->quote($tablename));
            }
            $message = "<p><strong>Database version reset successfully!</strong></p>";
            $message .= "<p>Table: <strong>" . htmlspecialchars($tablename) . "</strong></p>";
            $message .= "<p>Target: <strong>" . ($versionTarget === 'stobe' ? 'STOBE' : 'CHIM') . "</strong></p>";
            $message .= "<p>This update will be re-applied on the next server restart.</p>";
        } else {
            $message = "<p><strong>Error:</strong> Invalid table name.</p>";
        }
    } catch (Throwable $e) {
        $message = "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'database_manager.php') . ($qs ? ('?' . $qs) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle reset all database versioning entries
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'reset_all_db_versions'
) {
    $versionTarget = strtolower(trim(strval($_POST['version_target'] ?? 'herika')));
    try {
        if ($versionTarget === 'stobe') {
            $stobeConn = getStobePgConnection($host, $port, $username, $password);
            if (!$stobeConn) {
                throw new RuntimeException('Failed to connect to Stobe database.');
            }
            $stobeColumns = getPgVersioningColumns($stobeConn);
            if ($stobeColumns['table'] === '') {
                @pg_close($stobeConn);
                throw new RuntimeException('Stobe database_versioning table was not found or is incompatible.');
            }
            $countResult = @pg_query($stobeConn, "SELECT COUNT(*) AS count FROM public.database_versioning");
            $count = 0;
            if ($countResult) {
                $countRow = @pg_fetch_assoc($countResult);
                $count = intval($countRow['count'] ?? 0);
                @pg_free_result($countResult);
            }
            $deleteResult = @pg_query($stobeConn, "DELETE FROM public.database_versioning");
            if (!$deleteResult) {
                $errorText = trim(strval(@pg_last_error($stobeConn)));
                @pg_close($stobeConn);
                throw new RuntimeException($errorText !== '' ? $errorText : 'Unknown Stobe delete-all error.');
            }
            @pg_free_result($deleteResult);
            @pg_close($stobeConn);
        } else {
            $result = $db->fetchOne("SELECT COUNT(*) as count FROM public.database_versioning");
            $count = intval($result['count'] ?? 0);
            $db->execQuery("DELETE FROM public.database_versioning");
        }

        $message = "<p><strong>All database versions reset successfully!</strong></p>";
        $message .= "<p>Reset <strong>{$count}</strong> version entries.</p>";
        $message .= "<p>Target: <strong>" . ($versionTarget === 'stobe' ? 'STOBE' : 'CHIM') . "</strong></p>";
        $message .= "<p><strong>Important:</strong> All database updates will be re-applied on the next server restart. This may take several minutes.</p>";
    } catch (Throwable $e) {
        $message = "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'database_manager.php') . ($qs ? ('?' . $qs) : '');
    header('Location: ' . $redirectUrl);
    exit;
}
// Handle download automatic backup
if (isset($_GET['action']) && $_GET['action'] === 'download_auto' && isset($_GET['filename'])) {
    $autoBackup = new AutomaticBackup();
    $filename = $_GET['filename'];
    
    // Security check
    if (strpos($filename, 'auto_backup_') === 0 && substr($filename, -4) === '.sql') {
        $backups = $autoBackup->getBackups();
        $validFile = false;
        
        foreach ($backups as $backup) {
            if ($backup['filename'] === $filename) {
                $validFile = true;
                $backupPath = $backup['filepath'];
                break;
            }
        }
        
        if ($validFile && file_exists($backupPath)) {
            // Force download of the backup file (streamed to avoid memory usage)
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($backupPath));
            header('Cache-Control: must-revalidate');
            header('Pragma: public');

            // Fully clear output buffers before streaming large files
            while (ob_get_level() > 0) { ob_end_clean(); }

            // Stream the file in chunks
            $fh = fopen($backupPath, 'rb');
            if ($fh !== false) {
                set_time_limit(0);
                while (!feof($fh)) {
                    echo fread($fh, 8192);
                    flush();
                }
                fclose($fh);
            }
            exit();
        } else {
            $message = "<p><strong>Error:</strong> Backup file not found.</p>";
        }
    } else {
        $message = "<p><strong>Error:</strong> Invalid filename format.</p>";
    }
}

// Handle delete automatic backup
if (isset($_GET['action']) && $_GET['action'] === 'delete_auto' && isset($_GET['filename'])) {
    $autoBackup = new AutomaticBackup();
    $filename = $_GET['filename'];
    
    // Security check
    if (strpos($filename, 'auto_backup_') === 0 && substr($filename, -4) === '.sql') {
        if ($autoBackup->deleteBackup($filename)) {
            $message = "<p><strong>✅ Automatic backup deleted successfully!</strong></p>";
            $message .= "<p>Deleted: <strong>$filename</strong></p>";
        } else {
            $message = "<p><strong>Error:</strong> Failed to delete backup file.</p>";
        }
    } else {
        $message = "<p><strong>Error:</strong> Invalid filename format.</p>";
    }
}

// Handle restore from automatic backup
if (isset($_GET['action']) && $_GET['action'] === 'restore_auto' && isset($_GET['filename'])) {
    $autoBackup = new AutomaticBackup();
    $filename = $_GET['filename'];
    
    // Security check
    if (strpos($filename, 'auto_backup_') === 0 && substr($filename, -4) === '.sql') {
        $backups = $autoBackup->getBackups();
        $validFile = false;
        
        foreach ($backups as $backup) {
            if ($backup['filename'] === $filename) {
                $validFile = true;
                $backupPath = $backup['filepath'];
                break;
            }
        }
        
        if ($validFile && file_exists($backupPath)) {
            // Proceed with database restore using the automatic backup
            $conn = pg_connect("host=$host port=$port dbname=$dbname user=$username password=$password");
            
            if (!$conn) {
                $message .= "<p><strong>Error:</strong> Failed to connect to database: " . pg_last_error() . "</p>";
            } else {
                // Drop and recreate database schemas and extensions
                $Q = array();
                $Q[] = "DROP SCHEMA IF EXISTS $schema CASCADE";
                $Q[] = "DROP EXTENSION IF EXISTS vector CASCADE";
                $Q[] = "DROP EXTENSION IF EXISTS pg_trgm CASCADE";
                $Q[] = "CREATE SCHEMA $schema";
                $Q[] = "CREATE EXTENSION vector";
                $Q[] = "CREATE EXTENSION IF NOT EXISTS pg_trgm";

                $errorOccurred = false;

                foreach ($Q as $QS) {
                    $r = pg_query($conn, $QS);
                    if (!$r) {
                        $message .= "<p>Error executing query: " . pg_last_error($conn) . "</p>";
                        $errorOccurred = true;
                        break;
                    } else {
                        $message .= "<p>$QS executed successfully.</p>";
                    }
                }

                if (!$errorOccurred) {
                    // Command to import SQL file using psql
                    $psqlCommand = "PGPASSWORD=" . escapeshellarg($password) . " psql -h " . escapeshellarg($host) . " -p " . escapeshellarg($port) . " -U " . escapeshellarg($username) . " -d " . escapeshellarg($dbname) . " -f " . escapeshellarg($backupPath);

                    // Execute psql command
                    $output = [];
                    $returnVar = 0;
                    exec($psqlCommand, $output, $returnVar);

                    if ($returnVar !== 0) {
                        $message .= "<p>Failed to restore from automatic backup.</p>";
                        $message .= '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
                    } else {
                        // In embedded mode: show success message and redirect parent (config hub) after short delay
                        echo "<script type='text/javascript'>\n".
                             "  try {\n".
                             "    const msg = 'Database restored successfully from automatic backup.';\n".
                             "    if (window.top && window.top !== window) {\n".
                             "      window.top.postMessage({type:'toast', message: msg}, '*');\n".
                             "      setTimeout(function(){ window.top.location.href = '".$webRoot."/ui/home.php'; }, 1200);\n".
                             "    } else {\n".
                             "      alert(msg);\n".
                             "      setTimeout(function(){ window.location.href = '".$webRoot."/ui/home.php'; }, 1200);\n".
                             "    }\n".
                             "  } catch(e) { window.location.href = '".$webRoot."/ui/home.php'; }\n".
                             "</script>";
                        exit;
                    }
                }
                pg_close($conn);
            }
        } else {
            $message = "<p><strong>Error:</strong> Invalid backup file specified.</p>";
        }
    } else {
        $message = "<p><strong>Error:</strong> Invalid filename format.</p>";
    }
}

// Handle import from server-side file
if (isset($_POST['action']) && $_POST['action'] === 'import_from_server' && isset($_POST['server_file'])) {
    $serverFile = $_POST['server_file'];
    $uploadsDir = $herikaUiPath . 'data' . DIRECTORY_SEPARATOR . 'manualbackup' . DIRECTORY_SEPARATOR;
    $fullPath = realpath($uploadsDir . basename($serverFile));
    
    // Security: ensure file is within uploads directory and has .sql extension
    if ($fullPath && strpos($fullPath, realpath($uploadsDir)) === 0 && pathinfo($fullPath, PATHINFO_EXTENSION) === 'sql' && file_exists($fullPath)) {
        // Connect to the database
        $conn = pg_connect("host=$host port=$port dbname=$dbname user=$username password=$password");
        
        if (!$conn) {
            $message .= "<p><strong>Error:</strong> Failed to connect to database: " . pg_last_error() . "</p>";
        } else {
            // Drop and recreate database schemas and extensions
            $Q = array();
            $Q[] = "DROP SCHEMA IF EXISTS $schema CASCADE";
            $Q[] = "DROP SCHEMA IF EXISTS chim_meta CASCADE";
            $Q[] = "DROP EXTENSION IF EXISTS vector CASCADE";
            $Q[] = "DROP EXTENSION IF EXISTS pg_trgm CASCADE";
            $Q[] = "CREATE SCHEMA $schema";
            $Q[] = "CREATE SCHEMA chim_meta";
            $Q[] = "CREATE EXTENSION vector";
            $Q[] = "CREATE EXTENSION IF NOT EXISTS pg_trgm";

            $errorOccurred = false;

            foreach ($Q as $QS) {
                $r = pg_query($conn, $QS);
                if (!$r) {
                    $message .= "<p>Error executing query: " . pg_last_error($conn) . "</p>";
                    $errorOccurred = true;
                    break;
                } else {
                    $message .= "<p>$QS executed successfully.</p>";
                }
            }

            if (!$errorOccurred) {
                // Command to import SQL file using psql
                $psqlCommand = "PGPASSWORD=" . escapeshellarg($password) . " psql -h " . escapeshellarg($host) . " -p " . escapeshellarg($port) . " -U " . escapeshellarg($username) . " -d " . escapeshellarg($dbname) . " -f " . escapeshellarg($fullPath);

                // Execute psql command
                $output = [];
                $returnVar = 0;
                exec($psqlCommand, $output, $returnVar);

                if ($returnVar !== 0) {
                    $message .= "<p><strong>Error:</strong> Failed to import SQL file.</p>";
                    $message .= '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
                } else {
                    // Success - redirect with message
                    echo "<script type='text/javascript'>\n".
                         "  try {\n".
                         "    const msg = 'Database restored successfully from server file.';\n".
                         "    if (window.top && window.top !== window) {\n".
                         "      window.top.postMessage({type:'toast', message: msg}, '*');\n".
                         "      setTimeout(function(){ window.top.location.href = '".$webRoot."/ui/home.php'; }, 1200);\n".
                         "    } else {\n".
                         "      alert(msg);\n".
                         "      setTimeout(function(){ window.location.href = '".$webRoot."/ui/home.php'; }, 1200);\n".
                         "    }\n".
                         "  } catch(e) { window.location.href = '".$webRoot."/ui/home.php'; }\n".
                         "</script>";
                    exit;
                }
            }
            pg_close($conn);
        }
    } else {
        $message = "<p><strong>Error:</strong> Invalid file selected or file does not exist.</p>";
    }
}

// Handle backup database request
if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    try {
        // Create authentication setup (same as AutomaticBackup class)
        $pgpassResult = shell_exec('echo "localhost:5432:dwemer:dwemer:dwemer" > /tmp/.pgpass; echo $?');
        $chmodResult = shell_exec('chmod 600 /tmp/.pgpass; echo $?');
        
        $filename = "manual_backup_" . date("Y-m-d_H-i-s") . ".sql";
        $backupFile = $rootPath . 'data/export_' . $filename;
        
        // Execute pg_dump with direct file output to avoid memory issues
        $command = "HOME=/tmp pg_dump -d dwemer -U dwemer -h localhost > " . escapeshellarg($backupFile) . " 2>&1";
        $result = shell_exec($command);
        
        // pg_dump writes directly to file, so we don't need to handle output in memory
        
        // Check if backup was created successfully
        if (file_exists($backupFile) && filesize($backupFile) > 0) {
            $fileSize = filesize($backupFile);
            
            // Check if the file contains error messages instead of actual backup data
            $firstLine = file_get_contents($backupFile, false, null, 0, 100);
            if (strpos($firstLine, 'pg_dump: error:') !== false || strpos($firstLine, 'FATAL:') !== false) {
                $message = "<p><strong>Error:</strong> Database backup failed.</p>";
                $message .= "<pre>" . htmlspecialchars(substr($firstLine, 0, 500)) . "</pre>";
                if (file_exists($backupFile)) {
                    unlink($backupFile);
                }
            } else {
                // Successful backup - force download (streamed)
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="dwemer_backup_' . $filename . '"');
                header('Content-Length: ' . $fileSize);
                header('Cache-Control: must-revalidate');
                header('Pragma: public');

                // Fully clear output buffers before streaming large files
                while (ob_get_level() > 0) { ob_end_clean(); }

                // Stream the file in chunks to avoid memory exhaustion
                $fh = fopen($backupFile, 'rb');
                if ($fh !== false) {
                    set_time_limit(0);
                    while (!feof($fh)) {
                        echo fread($fh, 8192);
                        flush();
                    }
                    fclose($fh);
                }

                // Clean up - delete the temporary file
                unlink($backupFile);

                exit();
            }
        } else {
            $message = "<p><strong>Error:</strong> Backup creation failed or file is empty.</p>";
            if ($result) {
                $message .= "<p><strong>pg_dump output:</strong></p>";
                $message .= "<pre>" . htmlspecialchars(substr($result, 0, 1000)) . "</pre>";
            }
        }
        
    } catch (Exception $e) {
        $message = "<p><strong>Error:</strong> Exception during backup creation: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if a file was uploaded without errors
    if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        // Validate the uploaded file
        $fileTmpPath = $_FILES['sql_file']['tmp_name'];
        $fileName = $_FILES['sql_file']['name'];
        $fileSize = $_FILES['sql_file']['size'];
        $fileType = $_FILES['sql_file']['type'];

        // Allowed file extensions
        $allowedfileExtensions = array('sql');

        // Get file extension
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (in_array($fileExtension, $allowedfileExtensions)) {
            // Directory where the uploaded file will be moved
            $uploadFileDir = $rootPath . 'data' . DIRECTORY_SEPARATOR;
            $destPath = $uploadFileDir . 'dwemer.sql';

            // Ensure the upload directory exists
            if (!file_exists($uploadFileDir)) {
                Logger::info("Creating $uploadFileDir");
                mkdir($uploadFileDir, 0755, true);
            }

            // Move the file to the destination directory with the new name
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                // Proceed to restore the database
                // Connect to the database
                $conn = pg_connect("host=$host port=$port dbname=$dbname user=$username password=$password");

                if (!$conn) {
                    $message .= "<p>Failed to connect to database: " . pg_last_error() . "</p>";
                } else {
                    // Drop and recreate database schemas and extensions
                    $Q = array();
                    $Q[] = "DROP SCHEMA IF EXISTS $schema CASCADE";
                    $Q[] = "DROP SCHEMA IF EXISTS chim_meta CASCADE";
                    $Q[] = "DROP EXTENSION IF EXISTS vector CASCADE";
                    $Q[] = "DROP EXTENSION IF EXISTS pg_trgm CASCADE";
                    $Q[] = "CREATE SCHEMA $schema";
                    $Q[] = "CREATE SCHEMA chim_meta";
                    $Q[] = "CREATE EXTENSION vector";
                    $Q[] = "CREATE EXTENSION IF NOT EXISTS pg_trgm";

                    $errorOccurred = false;

                    foreach ($Q as $QS) {
                        $r = pg_query($conn, $QS);
                        if (!$r) {
                            $message .= "<p>Error executing query: " . pg_last_error($conn) . "</p>";
                            $errorOccurred = true;
                            break;
                        } else {
                            $message .= "<p>$QS executed successfully.</p>";
                        }
                    }

                    if (!$errorOccurred) {
                        // Path to SQL file to import
                        $sqlFile = $destPath;

                        // Command to import SQL file using psql
                        $psqlCommand = "PGPASSWORD=" . escapeshellarg($password) . " psql -h " . escapeshellarg($host) . " -p " . escapeshellarg($port) . " -U " . escapeshellarg($username) . " -d " . escapeshellarg($dbname) . " -f " . escapeshellarg($sqlFile);

                        // Execute psql command
                        $output = [];
                        $returnVar = 0;
                        exec($psqlCommand, $output, $returnVar);

                        if ($returnVar !== 0) {
                            $message .= "<p>Failed to import SQL file.</p>";
                            $message .= '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
                        } else {
                            $message .= "<p>SQL file imported successfully.</p>";
                            $message .= '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
                            $message .= "<p>Import completed.</p>";

                            // Provide a clickable link and popup message
                            $redirectUrl = $webRoot . '/ui/home.php';
                            $message .= "<script type='text/javascript'>
                                            alert('Database restored successfully.');
                                         </script>";
                        }
                    }

                    // Close the database connection
                    pg_close($conn);
                }
            } else {
                $message .= '<p>There was an error moving the uploaded file.</p>';
            }
        } else {
            $message .= '<p>Upload failed. Allowed file types: ' . implode(',', $allowedfileExtensions) . '</p>';
        }
    } else {
        $message .= '<p>No file uploaded or there was an upload error.</p>';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/css/main.css">
    <title>Database Manager</title>
    <style>
        /* Database Manager - Modern styling */
        body {
            font-family: 'Futura CondensedLight', Arial, sans-serif;
            background-color: #2c2c2c;
            color: #f8f9fa;
            font-size: 18px;
            min-height: 100vh;
        }

        h1, h2, h3, h4, h5, h6 {
            color: #ffffff !important;
            font-family: 'Futura CondensedLight', Arial, sans-serif;
            margin-bottom: 15px;
        }

        h1 {
            font-size: 32px;
            font-family: 'Futura CondensedLight', Arial, sans-serif;
            font-weight: normal;
            letter-spacing: 0.5px;
            word-spacing: 8px;
        }

        label {
            font-weight: bold;
            color: #f8f9fa;
        }

        .message {
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .message:hover {
            border-color: rgba(242, 124, 17, 0.3);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px rgba(255, 255, 255, 0.05);
        }

        .message p {
            margin: 0 0 10px 0;
            line-height: 150%;
            font-size: 16px;
        }
        
        /* Page header styling */
        .page-header {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }

        .page-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        
        .page-header h1 {
            margin: 0 0 8px 0;
            font-size: 32px;
            color: #ffffff;
            font-family: 'Futura CondensedLight', Arial, sans-serif;
            font-weight: normal;
            letter-spacing: 0.5px;
            word-spacing: 8px;
        }
        
        .page-subtitle {
            color: #9fb1c9;
            font-size: 16px;
            margin: 0;
            font-family: 'Futura CondensedLight', Arial, sans-serif;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border-radius: 8px;
            border: 1px solid rgba(138, 155, 182, 0.36);
            background: rgba(30, 35, 45, 0.88);
            color: #fff;
            text-decoration: none;
            padding: 8px 14px;
            white-space: nowrap;
            transition: all 0.2s ease-in-out;
            font-size: 15px;
        }

        .back-link:hover {
            border-color: rgba(230, 183, 108, 0.52);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.28);
            color: #fff;
            text-decoration: none;
        }
        
        /* Grid container */
        .grid-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 20px;
            align-items: stretch;
        }

        .manager-sections {
            display: flex;
            flex-direction: column;
            gap: 32px;
            margin-bottom: 24px;
        }

        .manager-section {
            width: 100%;
        }

        .tools-grid {
            margin-bottom: 16px;
        }

        .backup-restore-grid {
            grid-template-columns: 1fr 1fr;
            margin-top: 10px;
            margin-bottom: 0;
            align-items: start;
        }

        .backup-restore-grid > .message {
            margin-bottom: 0;
            min-width: 0;
            height: 100%;
        }

        .section-divider {
            height: 1px;
            background: #343a46;
            opacity: 0.95;
            margin: 0 0 20px 0;
        }
        
        @media (max-width: 1400px) {
            .grid-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .grid-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1100px) {
            .backup-restore-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Card styling */
        .card-tile {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            padding: 20px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }
        
        .card-tile:hover {
            border-color: rgba(242, 124, 17, 0.3);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px rgba(255, 255, 255, 0.05);
        }
        
        .card-content {
            flex-grow: 1;
        }
        
        .card-actions {
            margin-top: auto;
        }
        
        /* Stats grid styling */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        
        .stat-tile {
            background: rgba(26, 26, 26, 0.8);
            border: 1px solid #3a3a3a;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-tile:hover {
            border-color: rgba(242, 124, 17, 0.3);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .stat-tile h5 {
            margin: 0 0 5px 0;
            color: #f8f9fa;
            font-size: 14px;
        }
        
        .stat-value {
            margin: 0;
            font-size: 16px;
            font-weight: bold;
            color: #f8f9fa;
        }

        .response-container {
            margin-top: 20px;
        }

        .indent {
            padding-left: 10ch;
        }

        .indent5 {
            padding-left: 5ch;
            padding-right: 20px;
        }

        .button {
            padding: 10px 20px;
            color: #ffffff;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            margin: 5px;
            font-weight: 500;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.05);
            box-sizing: border-box;
            max-width: 100%;
        }

        .card-actions .button {
            width: 100% !important;
            margin: 0;
        }

        .button:hover {
            transform: translateY(-1px);
            border-color: rgba(242, 124, 17, 0.5);
            color: rgb(242, 124, 17);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3), inset 0 1px rgba(255, 255, 255, 0.1);
            text-decoration: none;
        }

        .button:active {
            transform: translateY(1px);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.05);
        }

        /* Form elements using modern styling */
        input[type="text"],
        input[type="file"],
        select {
            background: rgba(26, 26, 26, 0.8);
            color: #f8f9fa;
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid #3a3a3a;
            cursor: pointer;
            width: auto;
            transition: border-color 0.3s ease, box-shadow 0.3s ease, background 0.3s ease;
        }
        
        input[type="text"]:focus,
        input[type="file"]:focus,
        select:focus {
            outline: none;
            border-color: rgb(242, 124, 17);
            box-shadow: 0 0 0 3px rgba(242, 124, 17, 0.1);
            background: rgba(26, 26, 26, 0.95);
        }

        input[type="file"]::-webkit-file-upload-button {
            background-color: #6c757d;
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
            transition: background-color 0.3s ease;
            font-size: 14px;
        }

        input[type="file"]::-webkit-file-upload-button:hover {
            background-color: #5a6268;
        }

        pre {
            background-color: #2c2c2c;
            padding: 10px;
            border: 1px solid #4a4a4a;
            border-radius: 8px;
            color: #f8f9fa;
            overflow: auto;
        }

        /* Progress bar styling */
        #progressBar {
            background: linear-gradient(90deg, #007bff 0%, #0056b3 100%);
            border: 1px solid #4a4a4a;
        }

        /* Backup list container */
        .backup-list {
            background: rgba(26, 26, 26, 0.8);
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            padding: 0;
            margin: 0;
        }

        .backup-item {
            padding: 12px;
            border-bottom: 1px solid #3a3a3a;
            transition: all 0.3s ease;
        }

        .backup-item:hover {
            background: rgba(42, 42, 42, 0.5);
        }
        
        .backup-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .backup-details {
            flex-grow: 1;
            min-width: 0;
        }
        
        .backup-filename {
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 4px;
            word-break: break-all;
            color: #f8f9fa;
        }
        
        .backup-meta {
            font-size: 11px;
            color: #ccc;
            display: flex;
            justify-content: space-between;
        }
        
        .backup-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        
        .backup-btn {
            color: white;
            padding: 4px 8px;
            border: none;
            border-radius: 3px;
            font-size: 11px;
            cursor: pointer;
            flex: 1;
            min-width: 70px;
            margin: 0;
        }
        
        /* Instruction box styling */
        .instruction-box {
            background: rgba(23, 101, 41, 0.1);
            border: 1px solid #176529;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        
        code {
            background-color: #000000;
            padding: 2px 6px;
            border-radius: 3px;
            color: #f8f9fa;
        }
        
        /* Empty state styling */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #888;
            font-style: italic;
            background: rgba(26, 26, 26, 0.8);
            border-radius: 8px;
            border: 1px dashed #3a3a3a;
            margin: 15px 0;
        }
        
        .empty-state-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        /* Version table styling */
        .version-table-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            background: rgba(26, 26, 26, 0.8);
        }
        
        .version-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .version-table thead {
            position: sticky;
            top: 0;
            background: rgba(26, 26, 26, 0.95);
            border-bottom: 2px solid rgba(242, 124, 17, 0.3);
            z-index: 1;
        }
        
        .version-table th {
            padding: 12px;
            font-weight: bold;
            color: rgb(242, 124, 17);
            border-bottom: 1px solid #3a3a3a;
        }
        
        .version-table tbody tr {
            border-bottom: 1px solid #3a3a3a;
            transition: background-color 0.3s ease;
        }
        
        .version-table tbody tr:hover {
            background: rgba(242, 124, 17, 0.05);
        }
        
        .version-table td {
            padding: 10px;
            color: #f8f9fa;
        }

        .versioning-manager {
            margin-top: 0;
        }

        .versioning-panels {
            margin-top: 12px;
        }

        .versioning-panel {
            display: none;
        }

        .versioning-panel.active {
            display: block;
        }

        .version-panel-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .versioning-tabs {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #343a46;
        }

        .version-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 220px;
            min-height: 56px;
            border: 1px solid #4b5668;
            border-radius: 8px;
            background: rgba(40, 44, 52, 0.92);
            color: #fff;
            padding: 11px 20px;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.2px;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }

        .version-tab-icon {
            width: 24px;
            height: 24px;
            object-fit: contain;
            border-radius: 3px;
            flex: 0 0 auto;
        }

        .version-tab-logo {
            height: 30px;
            width: auto;
            object-fit: contain;
            display: block;
        }

        .version-tab[data-version-tab="chim"] {
            background-color: rgb(242, 124, 17);
            border-color: rgba(242, 124, 17, 0.95);
            color: #fff;
        }

        .version-tab[data-version-tab="chim"]:hover {
            background-color: rgb(221, 106, 6);
            border-color: rgba(221, 106, 6, 0.95);
            color: #fff;
        }

        .version-tab[data-version-tab="stobe"] {
            background-color: #e6b76c;
            border-color: #e6b76c;
            color: #fff;
        }

        .version-tab[data-version-tab="stobe"]:hover {
            background-color: #d2a45a;
            border-color: #d2a45a;
            color: #fff;
        }

        .version-tab:hover {
            border-color: rgba(230, 183, 108, 0.5);
            color: #f1c88c;
        }

        .version-tab.active {
            border-color: #e6b76c;
            color: #fff;
            background-color: rgba(59, 66, 78, 0.95);
            box-shadow: inset 0 1px rgba(255, 255, 255, 0.12);
        }

        .version-tab[data-version-tab="chim"].active,
        .version-tab[data-version-tab="stobe"].active {
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.14), inset 0 1px rgba(255, 255, 255, 0.18);
            filter: saturate(1.03);
        }

        .version-tab[data-version-tab="chim"].active {
            background-color: rgb(242, 124, 17);
            border-color: rgba(242, 124, 17, 0.95);
        }

        .version-tab[data-version-tab="stobe"].active {
            background-color: #e6b76c;
            border-color: #e6b76c;
        }

        /* Loading overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.85);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-content {
            text-align: center;
            color: #f8f9fa;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 6px solid rgba(138, 155, 182, 0.3);
            border-top: 6px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            font-size: 24px;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .loading-subtext {
            font-size: 14px;
            color: #ccc;
            max-width: 400px;
            line-height: 1.5;
        }

        .loading-bar-container {
            width: 400px;
            height: 6px;
            background-color: rgba(138, 155, 182, 0.3);
            border-radius: 3px;
            margin: 20px auto 10px;
            overflow: hidden;
        }

        .loading-bar {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #60a5fa);
            animation: progress 2s ease-in-out infinite;
        }

        @keyframes progress {
            0% { width: 0%; }
            50% { width: 70%; }
            100% { width: 100%; }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="importLoadingOverlay" class="loading-overlay">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <div class="loading-text">Importing Database...</div>
            <div class="loading-bar-container">
                <div class="loading-bar"></div>
            </div>
            <div class="loading-subtext">
                This may take several minutes for large databases.<br>
                Please do not close or refresh this page.
            </div>
        </div>
    </div>
<?php if ($isEmbed): ?>
<style> main { padding-top: 20px; } </style>
<?php endif; ?>
<div class="indent5">
    <div class="page-header">
        <div class="page-header-top">
            <h1>Database Manager</h1>
            <?php if (!$isEmbed): ?>
            <a class="back-link" href="<?php echo htmlspecialchars($dashboardWebRoot . '/index.php', ENT_QUOTES, 'UTF-8'); ?>">Back to Dashboard</a>
            <?php endif; ?>
        </div>
        <div class="page-subtitle">Manage database backups, imports, exports, and maintenance operations</div>
    </div>

    <div class="manager-sections">
    <!-- Main Grid Container -->
    <div class="manager-section">
    <div class="grid-container tools-grid">
        
        <!-- Database Manager Section -->
        <div class="card-tile">
            <div class="card-content">
                <h3>🗄️ Database Access</h3>
                <p>Access the pgAdmin database manager for advanced database management.</p>
                <p><strong>Login:</strong> username = dwemer & password = dwemer</p>
            </div>
            <div class="card-actions">
                <a href="/pgAdmin/" target="_blank" class="button" style="background-color: rgb(1 53 166 / 90%); color: white; width: 100%; text-align: center;">
                    Open Database Manager
                </a>
            </div>
        </div>
        
        <!-- Backup Section -->
        <div class="card-tile">
            <div class="card-content">
                <h3>📦 Manual Backup</h3>
                <p>Create a backup of your current database. This will generate an SQL file you can download.</p>
                <p style="color: #ccc; font-size: 14px;">Creates a one-time downloadable backup file.</p>
            </div>
            <div class="card-actions">
                <a href="?action=backup" class="button" style="background-color: #176529; color: white; width: 100%; text-align: center;">
                    Create Backup
                </a>
            </div>
        </div>
        
        <!-- Maintenance Section -->
        <div class="card-tile">
            <div class="card-content">
                <h3>🔧 Database Maintenance</h3>
                <p>Optimize and clean your database. This will compact the database and reclaim unused space.</p>
                <p><strong>⚠️ Important:</strong> Make sure Skyrim is stopped before running maintenance.</p>
            </div>
            <div class="card-actions">
                <button onclick="if (confirm('Database maintenance will optimize and compact the database.\n\n- Make sure Skyrim game is stopped\n- To reclaim unused space, free temporary space is required\n- During this operation tables will be locked, do not interrupt\n- This could take some time, please wait until you see the confirmation\n\nContinue?')) { window.open('<?php echo $webRoot; ?>/ui/vacuum_db.php', 'Database_maintenance', 'resizable=yes,scrollbars=yes,titlebar=no,width=800,height=600'); return false; }" 
                        class="button" style="background-color: #fd7e14; color: white; width: 100%;">
                    Run Database Maintenance
                </button>
            </div>
        </div>
        
        <!-- Factory Reset Section -->
        <div class="card-tile" style="border-color: #dc3545;">
            <div class="card-content">
                <h3>💥 Factory Reset Database</h3>
                <p>Completely wipe and reinstall the entire database to the default configuration.</p>
                <p><strong>⚠️ DANGER:</strong> This will permanently delete data including events, diaries, and memories.</p>
            </div>
            <div class="card-actions">
                <button onclick="if (confirm('⚠️ FACTORY RESET DATABASE\n\nThis will wipe and reinstall the entire database to the default configuration.\n\n❌ ALL DATA WILL BE PERMANENTLY LOST:\n- All event logs\n- All diaries and memories\n- All custom Oghma and NPC Biography management profiles\n\n✅ Database will be reset to fresh installation state\n\nThis action CANNOT be undone!\n\nAre you absolutely sure you want to continue?')) { window.location.href = '<?php echo $webRoot; ?>/ui/index.php?reinstall=true&delete=true'; }" 
                        class="button" style="background-color: #dc3545; color: white; width: 100%;">
                    Factory Reset Database
                </button>
            </div>
        </div>
        
    </div>
    </div>
    
    <!-- Second Row - Automatic Backups and Manual Restore Side by Side -->
    <?php
    $autoBackup = new AutomaticBackup();
    $automaticBackups = $autoBackup->getBackups();
    $totalBackupsSize = 0;
    foreach ($automaticBackups as $backup) {
        $totalBackupsSize += $backup['size'];
    }
    // Load current retention from chim_meta.settings (fallback 5)
    $currentMax = 5;
    $autoEnabled = false;
    try {
        $dbTmp = new sql();
        $dbTmp->execQuery("CREATE SCHEMA IF NOT EXISTS chim_meta");
        $dbTmp->execQuery("CREATE TABLE IF NOT EXISTS chim_meta.settings (key TEXT PRIMARY KEY, value TEXT)");
        $rowEn = $dbTmp->fetchOne("SELECT value FROM chim_meta.settings WHERE key='AUTOMATIC_DATABASE_BACKUPS'");
        if (is_array($rowEn) && isset($rowEn['value'])) {
            $val = strtolower(trim((string)$rowEn['value']));
            $autoEnabled = in_array($val, ['true','1','yes','on'], true);
        }
        $rowMax = $dbTmp->fetchOne("SELECT value FROM chim_meta.settings WHERE key='AUTOMATIC_BACKUP_MAX_COUNT'");
        if (is_array($rowMax) && isset($rowMax['value'])) { $v=intval($rowMax['value']); if ($v>0) $currentMax=$v; }
    } catch (Throwable $e) {}
    ?>
    <div class="manager-section">
    <div class="grid-container backup-restore-grid">
        
        <!-- Left Column: Automatic Backups -->
        <div class="message">
            <h3>🤖 Automatic Backup System</h3>
            <p>System-generated backups created automatically every time the server starts up. Keeps a maximum of <?php echo (int)$currentMax; ?> backups, automatically deleting the oldest when the limit is reached.</p>
            
            <div class="stats-grid">
                <div class="stat-tile">
                    <h5>Status</h5>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="action" value="toggle_auto_enabled">
                        <input type="hidden" name="embed" value="1">
                        <button type="submit" class="button" style="background-color: <?php echo ($autoEnabled ? '#176529' : '#6c757d'); ?>; color: #fff; padding: 6px 12px; font-size: 14px;">
                            <?php echo ($autoEnabled ? '✅ On' : '❌ Off'); ?>
                        </button>
                    </form>
                </div>
                
                <div class="stat-tile">
                    <h5>Available</h5>
                    <form method="post" style="margin:0; display:flex; gap:6px; justify-content:center; align-items:center;">
                        <input type="hidden" name="action" value="update_auto_max">
                        <input type="hidden" name="embed" value="1">
                        <span style="font-size: 16px; font-weight: bold; color: #f8f9fa;"><?php echo count($automaticBackups); ?> / </span>
                        <select name="auto_max" onchange="this.form.submit()">
                            <?php for ($i=1; $i<=10; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ((int)$currentMax === $i ? 'selected' : ''); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                </div>
                
                <div class="stat-tile">
                    <h5>Total Size</h5>
                    <p class="stat-value" style="color: #eaee05;">
                        <?php echo AutomaticBackup::formatFileSize($totalBackupsSize); ?>
                    </p>
                </div>
            </div>
            

            <h4 style="margin: 15px 0 10px 0;">📂 Backup Management</h4>
            
            <?php if (!empty($automaticBackups)): ?>
                <div class="backup-list">
                    <?php foreach ($automaticBackups as $index => $backup): ?>
                        <div class="backup-item" style="<?php echo $index === count($automaticBackups) - 1 ? 'border-bottom: none;' : ''; ?>">
                            <div class="backup-info">
                                <div class="backup-details">
                                    <div class="backup-filename">
                                        <?php echo htmlspecialchars($backup['filename']); ?>
                                    </div>
                                    <div class="backup-meta">
                                        <span>📁 <?php echo AutomaticBackup::formatFileSize($backup['size']); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="backup-actions">
                                <button onclick="window.location.href='?action=download_auto&filename=<?php echo urlencode($backup['filename']); ?>'" 
                                        class="button backup-btn" style="background-color: #176529;" 
                                        title="Download backup file">
                                    📥
                                </button>
                                <button onclick="if (confirm('⚠️ RESTORE DATABASE\n\nRestore from: <?php echo htmlspecialchars($backup['filename']); ?>\n\nThis will COMPLETELY REPLACE your current database with this backup.\n\n❌ All current data will be lost!\n✅ Database will be restored to backup state\n\nAre you absolutely sure you want to continue?')) { window.location.href='?action=restore_auto&filename=<?php echo urlencode($backup['filename']); ?>'; }" 
                                        class="button backup-btn" style="background-color: rgb(1 53 166 / 90%);" 
                                        title="Restore database from this backup">
                                    🔄
                                </button>
                                <button onclick="if (confirm('⚠️ DELETE BACKUP\n\nDelete: <?php echo htmlspecialchars($backup['filename']); ?>\n\nThis action cannot be undone!\n\nAre you sure you want to permanently delete this backup?')) { window.location.href='?action=delete_auto&filename=<?php echo urlencode($backup['filename']); ?>'; }" 
                                        class="button backup-btn" style="background-color: rgba(166, 53, 63, 0.9);" 
                                        title="Delete this backup file">
                                    🗑️
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📂</div>
                    <p style="margin: 0;">No automatic backups available yet.</p>
                    <?php if ($autoEnabled): ?>
                        <small style="color: #ffffff; display: block; margin-top: 8px;">Backups will be created on server restart.</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Server-Side File Import -->
        <div class="message">
            <h3>💾 Restore Manual Backup</h3>
            <p>Restore manual backup files from the server filesystem.</p>
            
            <div class="instruction-box">
                <h4 style="color: #4ade80; margin: 0 0 10px 0;">Instructions</h4>
                <ol style="color: #f8f9fa; margin: 0; padding-left: 20px; font-size: 14px;">
                    <li>Click [Open Server Folder] in CHIM.exe</li>
                    <li>Navigate to: ui/data/manualbackup</li>
                    <li>Copy your <code>backup.sql</code> backup file there</li>
                    <li>Refresh the page and select it from the list below and click Import. It may take a while to import so please don't refresh the page.</li>
                </ol>
                <p style="margin: 10px 0 0 0; font-size: 13px; color: #ccc;">This bypasses PHP upload limits and handles files of any size.</p>
            </div>
            
            <?php
            // Scan for SQL files in the manual backup directory
            $uploadsDir = $herikaUiPath . 'data' . DIRECTORY_SEPARATOR . 'manualbackup' . DIRECTORY_SEPARATOR;
            if (!file_exists($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }
            $sqlFiles = glob($uploadsDir . '*.sql');
            ?>
            
            <?php if (!empty($sqlFiles)): ?>
                <form id="importForm" method="post" onsubmit="return handleImportSubmit(event);">
                    <input type="hidden" name="action" value="import_from_server">
                    <label for="server_file" style="color: #f8f9fa; font-weight: bold; display: block; margin-bottom: 8px;">Available SQL files on server:</label>
                    <select name="server_file" id="server_file" required style="width: 100%; padding: 10px; margin: 10px 0;">
                        <?php foreach ($sqlFiles as $sqlFile): 
                            $filename = basename($sqlFile);
                            $filesize = filesize($sqlFile);
                            $formattedSize = formatFileSize($filesize);
                        ?>
                            <option value="<?php echo htmlspecialchars($filename); ?>">
                                <?php echo htmlspecialchars($filename); ?> (<?php echo $formattedSize; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="submit" class="button" value="🚀 Import from Server" 
                           style="background-color: #176529; color: white; padding: 12px 24px; margin-top: 10px; width: 100%; font-size: 16px;">
                </form>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📁</div>
                    <p style="margin: 0; color: #ccc;">No SQL files found in manual backup directory.</p>
                    <small style="color: #888; display: block; margin-top: 5px;">Place .sql files in ui/data/manualbackup folder to import them.</small>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    </div>
    </div>

    <div class="section-divider"></div>

    <!-- Database Versioning Manager Section -->
    <?php
    $chimDbVersions = [];
    try {
        $chimDbVersions = $db->fetchAll("SELECT tablename, version FROM public.database_versioning ORDER BY tablename ASC");
    } catch (Throwable $e) {
        $chimDbVersions = [];
    }

    $stobeDbVersions = [];
    $stobeVersioningAvailable = false;
    $stobeVersioningError = '';
    try {
        $stobeConn = getStobePgConnection($host, $port, $username, $password);
        if ($stobeConn) {
            $stobeColumns = getPgVersioningColumns($stobeConn);
            if ($stobeColumns['table'] !== '') {
                $versionSelect = $stobeColumns['version'] !== ''
                    ? ", {$stobeColumns['version']} AS version_value"
                    : ", ''::text AS version_value";
                $stobeQuery = "SELECT {$stobeColumns['table']} AS table_name{$versionSelect} FROM public.database_versioning ORDER BY {$stobeColumns['table']} ASC";
                $stobeResult = @pg_query($stobeConn, $stobeQuery);
                if ($stobeResult) {
                    while ($row = @pg_fetch_assoc($stobeResult)) {
                        $stobeDbVersions[] = [
                            'tablename' => strval($row['table_name'] ?? ''),
                            'version' => strval($row['version_value'] ?? ''),
                        ];
                    }
                    @pg_free_result($stobeResult);
                    $stobeVersioningAvailable = true;
                } else {
                    $stobeVersioningError = trim(strval(@pg_last_error($stobeConn)));
                }
            } else {
                $stobeVersioningError = 'database_versioning table was not found in Stobe.';
            }
            @pg_close($stobeConn);
        } else {
            $stobeVersioningError = 'Failed to connect to Stobe database.';
        }
    } catch (Throwable $e) {
        $stobeVersioningError = $e->getMessage();
    }

    $activeVersionTab = strtolower(trim(strval($_GET['version_tab'] ?? 'chim')));
    if (!in_array($activeVersionTab, ['chim', 'stobe'], true)) {
        $activeVersionTab = 'chim';
    }
    ?>

    <div class="message versioning-manager">
        <h3>Database Versioning Manager</h3>
        <p>This table tracks which database updates have been applied. Resetting an entry will cause that specific update to be re-applied on the next server restart.</p>

        <div class="instruction-box">
            <h4 style="margin: 0 0 10px 0;">How It Works</h4>
            <ul style="color: #f8f9fa; margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.6;">
                <li>Each entry represents a database update that has been applied.</li>
                <li><strong>Reset Individual Entry:</strong> Deletes one entry in the selected tab.</li>
                <li><strong>Reset All:</strong> Deletes all entries in the selected tab.</li>
                <li><strong>Important:</strong> Changes take effect only after restarting the server.</li>
            </ul>
        </div>

        <div class="versioning-tabs">
            <button type="button" class="version-tab <?php echo $activeVersionTab === 'chim' ? 'active' : ''; ?>" data-version-tab="chim">
                <img class="version-tab-icon" src="images/chim-icon.png" alt="" aria-hidden="true">
                <img class="version-tab-logo" src="images/chim-logo.png" alt="CHIM">
            </button>
            <button type="button" class="version-tab <?php echo $activeVersionTab === 'stobe' ? 'active' : ''; ?>" data-version-tab="stobe">
                <img class="version-tab-icon" src="images/stobe-icon.png" alt="" aria-hidden="true">
                <img class="version-tab-logo" src="images/stobe-logo.png" alt="STOBE">
            </button>
        </div>

        <div class="versioning-panels">
            <div class="versioning-panel <?php echo $activeVersionTab === 'chim' ? 'active' : ''; ?>" data-version-panel="chim">
                <?php if (!empty($chimDbVersions)): ?>
                    <div class="version-panel-title-row">
                        <h4 style="margin: 0;">CHIM Version Entries (<?php echo count($chimDbVersions); ?> total)</h4>
                        <form method="post" style="margin: 0;" onsubmit="return confirm('Reset ALL CHIM database version entries? This will cause all CHIM DB updates to re-apply on restart.');">
                            <input type="hidden" name="action" value="reset_all_db_versions">
                            <input type="hidden" name="version_target" value="herika">
                            <button type="submit" class="button" style="background-color: #dc3545; color: white; padding: 8px 16px; font-size: 14px;">
                                Reset All Versions
                            </button>
                        </form>
                    </div>

                    <div class="version-table-container">
                        <table class="version-table">
                            <thead>
                                <tr>
                                    <th style="text-align: left;">Table/Feature Name</th>
                                    <th style="text-align: left;">Version</th>
                                    <th style="text-align: center; width: 120px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($chimDbVersions as $entry): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?></td>
                                        <td style="font-size: 12px; color: #ccc;"><?php echo htmlspecialchars(formatVersionDate(strval($entry['version'] ?? ''))); ?></td>
                                        <td style="text-align: center;">
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Reset CHIM version entry for <?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?>?');">
                                                <input type="hidden" name="action" value="reset_db_version">
                                                <input type="hidden" name="version_target" value="herika">
                                                <input type="hidden" name="tablename" value="<?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?>">
                                                <button type="submit" class="button" style="background-color: #fd7e14; color: white; padding: 4px 12px; font-size: 12px;">
                                                    Reset
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">DB</div>
                        <p style="margin: 0;">No CHIM database versioning entries found.</p>
                        <small style="color: #666; display: block; margin-top: 8px;">The CHIM database_versioning table is empty or does not exist.</small>
                    </div>
                <?php endif; ?>
            </div>

            <div class="versioning-panel <?php echo $activeVersionTab === 'stobe' ? 'active' : ''; ?>" data-version-panel="stobe">
                <?php if ($stobeVersioningAvailable && !empty($stobeDbVersions)): ?>
                    <div class="version-panel-title-row">
                        <h4 style="margin: 0;">STOBE Version Entries (<?php echo count($stobeDbVersions); ?> total)</h4>
                        <form method="post" style="margin: 0;" onsubmit="return confirm('Reset ALL STOBE database version entries? This will cause all STOBE DB updates to re-apply on restart.');">
                            <input type="hidden" name="action" value="reset_all_db_versions">
                            <input type="hidden" name="version_target" value="stobe">
                            <button type="submit" class="button" style="background-color: #dc3545; color: white; padding: 8px 16px; font-size: 14px;">
                                Reset All Versions
                            </button>
                        </form>
                    </div>

                    <div class="version-table-container">
                        <table class="version-table">
                            <thead>
                                <tr>
                                    <th style="text-align: left;">Table/Feature Name</th>
                                    <th style="text-align: left;">Version</th>
                                    <th style="text-align: center; width: 120px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stobeDbVersions as $entry): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?></td>
                                        <td style="font-size: 12px; color: #ccc;"><?php echo htmlspecialchars(formatVersionDate(strval($entry['version'] ?? ''))); ?></td>
                                        <td style="text-align: center;">
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Reset STOBE version entry for <?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?>?');">
                                                <input type="hidden" name="action" value="reset_db_version">
                                                <input type="hidden" name="version_target" value="stobe">
                                                <input type="hidden" name="tablename" value="<?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?>">
                                                <button type="submit" class="button" style="background-color: #fd7e14; color: white; padding: 4px 12px; font-size: 12px;">
                                                    Reset
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">DB</div>
                        <p style="margin: 0;">No STOBE database versioning entries available.</p>
                        <small style="color: #666; display: block; margin-top: 8px;">
                            <?php echo htmlspecialchars($stobeVersioningError !== '' ? $stobeVersioningError : 'The STOBE database_versioning table is empty.'); ?>
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <?php
    if (!empty($message)) {
        echo '<div class="message">';
        echo $message;
        echo '</div>';
    }
    ?>
</div>

<script>
function handleImportSubmit(event) {
    // Show confirmation dialog
    const confirmed = confirm('⚠️ RESTORE DATABASE\n\nThis will COMPLETELY REPLACE your current database with the selected backup.\n\n❌ All current data will be lost!\n✅ Database will be restored to backup state\n\nAre you absolutely sure?');
    
    if (confirmed) {
        // Show loading overlay
        document.getElementById('importLoadingOverlay').classList.add('active');
        return true; // Allow form submission
    }
    
    return false; // Cancel form submission
}

function initVersioningTabs() {
    const tabButtons = Array.from(document.querySelectorAll('[data-version-tab]'));
    const tabPanels = Array.from(document.querySelectorAll('[data-version-panel]'));
    if (tabButtons.length === 0 || tabPanels.length === 0) {
        return;
    }

    const applyTab = (targetTab, updateUrl) => {
        const normalized = (targetTab === 'stobe') ? 'stobe' : 'chim';

        tabButtons.forEach((button) => {
            const isActive = button.getAttribute('data-version-tab') === normalized;
            button.classList.toggle('active', isActive);
        });

        tabPanels.forEach((panel) => {
            const isActive = panel.getAttribute('data-version-panel') === normalized;
            panel.classList.toggle('active', isActive);
        });

        if (updateUrl && window.history && typeof window.history.replaceState === 'function') {
            const url = new URL(window.location.href);
            url.searchParams.set('version_tab', normalized);
            window.history.replaceState({}, '', url.toString());
        }
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            applyTab(button.getAttribute('data-version-tab') || 'chim', true);
        });
    });

    const initialTab = new URL(window.location.href).searchParams.get('version_tab') || 'chim';
    applyTab(initialTab, false);
}

document.addEventListener('DOMContentLoaded', initVersioningTabs);
</script>

</body>
</html>



