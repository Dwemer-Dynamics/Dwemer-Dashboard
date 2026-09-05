<?php
class StorageManagerRequestException extends RuntimeException {}
// Shared UI boundary only. Each server still owns its playthrough and database operations.
function sm_guard(string $kind, string $mod): array
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (empty($_SESSION['storage_csrf'])) $_SESSION['storage_csrf'] = bin2hex(random_bytes(32));
    $scope = $kind === 'shared' ? 'CHIM, STOBE and DIALECTIC databases' : strtoupper($mod) . ' database';
    $legacyActions = $kind === 'shared'
        ? ['maintenance', 'factory_reset', 'delete_auto', 'restore_auto', 'backup']
        : ($kind === 'database' ? ['delete_backup'] : []);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        throw new StorageManagerRequestException('Use GET to view data or POST to change it.');
    }
    $queryAction = $_GET['action'] ?? '';
    if (!is_string($queryAction)) {
        http_response_code(400);
        throw new StorageManagerRequestException('Invalid storage action.');
    }
    if ($method === 'GET' && in_array($queryAction, $legacyActions, true)) {
        http_response_code(405);
        throw new StorageManagerRequestException('This action now requires confirmation. Open Playthrough Management and use its action button. Nothing was changed.');
    }
    if ($method === 'POST') {
        $token = $_POST['_sm_csrf'] ?? null;
        $confirmedScope = $_POST['_sm_scope'] ?? null;
        if (!is_string($token) || !hash_equals($_SESSION['storage_csrf'], $token)
            || !is_string($confirmedScope) || !hash_equals($scope, $confirmedScope)) {
            http_response_code(403);
            throw new StorageManagerRequestException('Security check failed. Reload Playthrough Management and try again. Nothing was changed.');
        }
        if ($kind === 'shared' && ($_GET['server'] ?? '') === 'dialectic'
            && in_array($_POST['action'] ?? null, ['repair_oghma_table', 'repair_core_constraints'], true)) {
            http_response_code(400);
            throw new StorageManagerRequestException('These repair tools are for CHIM. Select CHIM before using them. Nothing was changed.');
        }
        // Serialize admin operations across browser sessions without holding a database transaction.
        $lock = fopen(sys_get_temp_dir() . '/dwemer-storage-manager.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            http_response_code(409);
            throw new StorageManagerRequestException('Another storage action is running. Wait for it to finish, then try again.');
        }
        register_shutdown_function(static function () use ($lock): void { flock($lock, LOCK_UN); fclose($lock); });
        $legacy = $_POST['_sm_legacy_action'] ?? null;
        if ($legacy !== null) {
            if (!is_string($legacy) || !in_array($legacy, $legacyActions, true)) {
                http_response_code(400);
                throw new StorageManagerRequestException('Invalid storage action. Nothing was changed.');
            }
            // Adapt only known old GET handlers after the POST security and scope checks.
            $_GET['action'] = $legacy;
            foreach (['filename', 'file', 'source', 'target'] as $key) {
                if (isset($_POST[$key]) && is_string($_POST[$key])) $_GET[$key] = $_POST[$key];
            }
        } elseif (in_array($queryAction, $legacyActions, true)) {
            http_response_code(400);
            throw new StorageManagerRequestException('Use the action button, not a bookmarked action URL. Nothing was changed.');
        }
    }
    return ['csrf' => $_SESSION['storage_csrf'], 'scope' => $scope, 'legacyActions' => $legacyActions];
}
