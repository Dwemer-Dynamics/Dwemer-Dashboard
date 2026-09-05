<?php
require_once dirname(__DIR__) . '/lib/storage_fragment.php';
require_once dirname(__DIR__) . '/lib/storage_manager_guard.php';
require_once dirname(__DIR__) . '/lib/storage_tools.php';

// Legacy controllers can finish an API action without printing or redirecting a page.
class StorageActionResult extends RuntimeException
{
    public function __construct(public array $result) { parent::__construct('Storage action finished.'); }
}
function sm_action_finish(bool $ok, string $message, array $details = []): never
{
    $message = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $message);
    $message = preg_replace('#</(?:p|pre|div)>#i', "\n", $message);
    throw new StorageActionResult(['ok' => $ok, 'message' => trim(html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8')), 'details' => $details]);
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
$bufferLevel = ob_get_level();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        throw new InvalidArgumentException('Use an action button in Playthrough Management.');
    }
    $input = $_POST;
    $mod = $input['mod'] ?? ''; $operation = $input['operation'] ?? '';
    if (!is_string($mod) || !in_array($mod, ['all','chim','stobe','dialectic'], true) || !is_string($operation)) throw new InvalidArgumentException('Invalid action scope.');
    // Never pass arbitrary GET/POST actions through to a server controller.
    $_GET = [];
    $_POST = ['_sm_csrf' => $input['_sm_csrf'] ?? '', '_sm_scope' => $input['_sm_scope'] ?? ''];
    sm_guard($mod === 'all' ? 'shared' : 'database', $mod);
    $scalar = static function (string $key, int $max = 240) use ($input): string {
        $value = $input[$key] ?? '';
        if (!is_string($value) || strlen($value) > $max) throw new InvalidArgumentException('Invalid ' . $key . '.');
        return trim($value);
    };
    $root = $mod === 'all' ? dirname(__DIR__) : dm_server_root(dm_products()[$mod]['dir']);
    if (!$root) throw new RuntimeException('The selected server is not installed.');
    $destination = $scalar('destination') ?: 'chim';
    if (!in_array($destination, ['chim','dialectic'], true)) throw new InvalidArgumentException('Choose a destination for an unlabeled backup.');
    $controller = null;
    $upload = $_FILES['backup'] ?? null;
    $_FILES = [];
    if (in_array($operation, ['preview_restore','restore_backup','download_backup','delete_backup'], true)) {
        if (!in_array($mod, ['all','stobe'], true)) throw new InvalidArgumentException('Use Distro for shared backups.');
        $source = $scalar('source'); $filename = $scalar('filename');
        if ($source === 'upload') {
            if (!in_array($operation, ['preview_restore','restore_backup'], true) || !is_array($upload)
                || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new InvalidArgumentException('Choose a complete backup file. Check the server upload limit if the file is too large.');
            $filename = basename($upload['name']); $path = $upload['tmp_name'];
            if (!preg_match($mod === 'stobe' ? '/\.sql(?:\.gz)?$/i' : '/\.sql$/i', $filename)) throw new InvalidArgumentException('Choose a supported SQL backup.');
        } else $path = sm_backup_file($mod, $source, $filename);
        if (in_array($operation, ['preview_restore','restore_backup'], true)) {
            if ($mod === 'stobe') {
                require_once $root . '/lib/storage_backup_validation.php';
                try { stobeValidateScopedBackup($path); }
                catch (RuntimeException $e) { throw new InvalidArgumentException($e->getMessage()); }
                $scope = 'STOBE';
            } else $scope = sm_backup_scope($path, $filename, true, $destination)['scope_label'];
            $identity = hash_file('sha256', $path);
            if ($identity === false || filesize($path) === 0) throw new InvalidArgumentException('The backup is empty or unreadable.');
            if ($operation === 'preview_restore') {
                $token = bin2hex(random_bytes(24));
                $_SESSION['storage_restore_preview'] = compact('token','mod','source','filename','identity','scope','destination') + ['expires' => time() + 300];
                throw new StorageActionResult(['ok' => true, 'preview' => ['token' => $token, 'scope' => $scope, 'filename' => $filename, 'bytes' => filesize($path), 'expires' => time() + 300, 'combined' => $mod === 'all']]);
            }
            $saved = $_SESSION['storage_restore_preview'] ?? [];
            unset($_SESSION['storage_restore_preview']);
            if (($saved['expires'] ?? 0) < time() || !hash_equals($saved['token'] ?? '', $scalar('preview_token'))
                || array_diff_assoc(compact('mod','source','filename','identity','scope','destination'), $saved)) throw new InvalidArgumentException('The backup or preview changed. Preview it again. Nothing was restored.');
            // Restore exactly the confirmed bytes, even if a server archive changes afterward.
            $stageDirectory = sys_get_temp_dir() . '/storage-restore-' . bin2hex(random_bytes(16));
            if (!mkdir($stageDirectory, 0700)) throw new RuntimeException('Could not prepare the restore.');
            $prepared = $stageDirectory . '/' . basename($filename);
            register_shutdown_function(static function () use ($prepared, $stageDirectory): void {
                if (is_file($prepared)) unlink($prepared);
                rmdir($stageDirectory);
            });
            if (!copy($path, $prepared) || !hash_equals($identity, hash_file('sha256', $prepared) ?: '')) {
                throw new InvalidArgumentException('The backup changed while preparing the restore. Preview it again. Nothing was restored.');
            }
            define('DWEMER_STORAGE_RESTORE_FILE', $prepared);
        }
        if ($operation === 'download_backup') {
            if ($mod === 'all' && $source !== 'automatic') throw new InvalidArgumentException('This backup is restore-only.');
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        if ($operation === 'delete_backup' && $mod === 'all' && $source !== 'automatic') throw new InvalidArgumentException('This backup is restore-only.');
        if ($mod === 'stobe') {
            $controller = $root . '/ui/database_manager.php';
            if ($operation === 'delete_backup') $_GET = ['action' => 'delete_backup','file' => $filename,'source' => $source];
            else $_POST['action'] = 'restore_prepared_backup';
        } else {
            $controller = dirname(__DIR__) . '/database_manager.php';
            if ($operation === 'delete_backup') $_GET = ['action' => 'delete_auto','filename' => $filename];
            else $_POST['action'] = 'restore_prepared_backup';
        }
    } elseif ($operation === 'save_backup_settings' && $mod === 'all') {
        require_once dirname(__DIR__) . '/lib/automatic_backup.php';
        $keep = filter_var($input['keep'] ?? null, FILTER_VALIDATE_INT);
        $enabled = $scalar('enabled');
        if ($keep === false || $keep < 1 || $keep > 10 || !in_array($enabled, ['0','1'], true)) throw new InvalidArgumentException('Choose 1–10 backups to keep.');
        $settings = DashboardBackupSettings::shared();
        $settings->execQuery('BEGIN');
        try {
            dashboardWriteSettingValue($settings, 'AUTOMATIC_DATABASE_BACKUPS', $enabled === '1' ? 'true' : 'false');
            dashboardWriteSettingValue($settings, 'AUTOMATIC_BACKUP_MAX_COUNT', (string)$keep);
            $settings->execQuery('COMMIT');
        } catch (Throwable $e) { $settings->execQuery('ROLLBACK'); throw $e; }
        sm_action_finish(true, 'Automatic backup settings saved.');
    } elseif (in_array($operation, ['setup','create_snapshot','restore_snapshot','delete_snapshot'], true) && $mod !== 'all') {
        $controller = $root . '/ui/playthrough_manager.php';
        $actions = $mod === 'stobe' ? ['create_snapshot'=>'create_snapshot','restore_snapshot'=>'switch_profile','delete_snapshot'=>'delete_profile']
            : ['setup'=>'setup','create_snapshot'=>'create','restore_snapshot'=>'switch','delete_snapshot'=>'delete'];
        if (!isset($actions[$operation])) throw new InvalidArgumentException('This action is not supported by this mod.');
        $_POST['action'] = $actions[$operation];
        $_POST['csrf_token'] = $_SESSION['ptm_csrf'] ?? '';
        if ($operation === 'create_snapshot') {
            $_POST['name'] = $scalar('name', 128); $_POST['notes'] = $scalar('notes', 4000);
            if ($_POST['name'] === '') throw new InvalidArgumentException('Give the playthrough a name.');
        } elseif (in_array($operation, ['restore_snapshot','delete_snapshot'], true)) {
            $id = filter_var($input['profile_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new InvalidArgumentException('Choose a playthrough.');
            $_POST['profile_id'] = (string)$id;
        }
    } else {
        $allowed = match ($mod) {
            'all' => ['export_backup','maintenance','stobe_factory_reset','stobe_replay_versions'],
            'stobe' => ['create_backup','vacuum_analyze','reindex_database','factory_reset_database','reset_db_version','reset_all_db_versions'],
            'chim' => ['repair_oghma_table','repair_core_constraints','factory_reset_database','reset_db_version','reset_all_db_versions'],
            'dialectic' => ['reset_db_version','reset_all_db_versions'],
        };
        if (!in_array($operation, $allowed, true)) throw new InvalidArgumentException('That operation is not available for this mod.');
        $controller = $mod === 'stobe' ? $root . '/ui/database_manager.php' : dirname(__DIR__) . '/database_manager.php';
        $_POST['action'] = $operation;
        if ($operation === 'export_backup') $_GET['action'] = 'backup';
        if ($operation === 'maintenance') $_GET['action'] = 'maintenance';
        if ($operation === 'stobe_factory_reset') $_GET += ['action'=>'factory_reset','target'=>'stobe'];
        if ($operation === 'stobe_replay_versions') $_POST['action'] = 'reset_all_db_versions';
        if ($operation === 'factory_reset_database' && $mod === 'chim') $_GET += ['action'=>'factory_reset','target'=>'herika'];
        if ($operation === 'reset_db_version') {
            $table = $scalar('table');
            if ($table === '') throw new InvalidArgumentException('Choose a version entry.');
            $_POST[$mod === 'stobe' ? 'version_table' : 'tablename'] = $table;
        }
        $_POST['version_target'] = $operation === 'stobe_replay_versions' ? 'stobe' : ($mod === 'chim' ? 'herika' : $mod);
    }
    $_GET['server'] = $mod === 'dialectic' || ($mod === 'all' && $destination === 'dialectic') ? 'dialectic' : 'herika';
    define('DWEMER_STORAGE_ACTIONS_ONLY', true);
    $fragment = ['mod'=>$mod,'view'=>'actions','web_root'=>dm_url_prefix() . '/' . ($mod === 'all' ? 'Dwemer-Dashboard' : dm_products()[$mod]['dir'])];
    dm_fragment_begin($fragment, dm_dashboard_web_root() . '/data_manager.php?mod=' . $mod);
    $result = include $controller;
    if (!is_array($result)) throw new RuntimeException('The server returned no action result.');
    sm_action_finish($result['ok'] === true, $result['message'] ?? 'Action finished.');
} catch (StorageActionResult $finished) {
    $response = $finished->result;
    if (!$response['ok']) http_response_code(409);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code($e instanceof InvalidArgumentException ? 400 : 503);
    error_log('[StorageAction] ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ' - ' . $e->getMessage());
    $response = ['ok'=>false,'error'=>($e instanceof InvalidArgumentException || $e instanceof StorageManagerRequestException || $e instanceof StorageBackupException)
        ? $e->getMessage() : 'The operation could not finish. Check the server log before trying again; it may have made partial changes.'];
}
while (ob_get_level() > $bufferLevel) ob_end_clean();
header_remove('Location');
header('Content-Type: application/json');
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
