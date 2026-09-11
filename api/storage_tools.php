<?php
require_once dirname(__DIR__) . '/lib/storage_tools.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); throw new InvalidArgumentException('This endpoint is read-only.'); }
    $mod = $_GET['mod'] ?? 'all'; $view = $_GET['view'] ?? 'backups'; $search = $_GET['q'] ?? '';
    $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
    if (!is_string($mod) || !in_array($mod, ['all','chim','stobe','dialectic'], true) || !is_string($search)
        || strlen($search) > 480 || $offset === false || $offset < 0 || $offset > 1000000) throw new InvalidArgumentException('Invalid list request.');
    if ($view === 'backups' && in_array($mod, ['all','stobe'], true)) {
        $data = ['backups' => sm_backup_list($mod, $offset, trim($search))];
        if ($mod === 'all') {
            require_once dirname(__DIR__) . '/lib/automatic_backup.php';
            $settings = DashboardBackupSettings::shared();
            $enabled = dashboardReadSettingValue($settings, 'AUTOMATIC_DATABASE_BACKUPS');
            $count = dashboardReadSettingValue($settings, 'AUTOMATIC_BACKUP_MAX_COUNT');
            $keep = (int)$count;
            $data['automatic'] = ['enabled' => in_array(strtolower($enabled ?? 'false'), ['true','1','yes','on'], true), 'keep' => $keep >= 1 && $keep <= 10 ? $keep : 5];
        }
    } elseif ($view === 'advanced' && $mod !== 'all') {
        $data = ['versions' => sm_version_list($mod, $offset, trim($search))];
    } else throw new InvalidArgumentException('These tools are not available in this scope.');
    echo json_encode(['ok' => true] + $data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if (http_response_code() === 200) http_response_code($e instanceof InvalidArgumentException ? 400 : 503);
    error_log('[StorageTools] ' . get_class($e));
    echo json_encode(['ok' => false, 'error' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'These tools are unavailable. Check the server connection and try again.']);
}
