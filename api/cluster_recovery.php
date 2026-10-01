<?php
// Full-cluster recovery into a private PostgreSQL instance. Status reads only recovery files: it never
// connects to or bootstraps the live database. Mutations use the Playthrough Saves session and CSRF scope.
require_once dirname(__DIR__) . '/lib/storage_manager_guard.php';
require_once dirname(__DIR__) . '/lib/storage_tools.php';
require_once dirname(__DIR__) . '/lib/cluster_recovery.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');

// Show game time with each server's own calendar helper when that server is installed. The CHIM and
// DIALECTIC helpers declare the same global functions, so only the first of them loads per request.
function cr_game_dates(array $view): array
{
    $helpers = ['chim' => ['HerikaServer', 'convert_gamets2skyrim_long_date'], 'dialectic' => ['DialecticServer', 'convert_gamets2fallout_long_date'],
        'stobe' => ['StobeServer', 'stobeGametsDateLabel']];
    foreach ($view['job']['products'] ?? [] as $i => $product) {
        [$directory, $function] = $helpers[$product['key'] ?? ''] ?? [null, null];
        $root = $directory ? dm_server_root($directory) : null;
        $collides = $directory !== 'StobeServer' && function_exists('gamets2timestamp');
        if ($root && !$collides && !empty($product['events']) && is_file($root . '/lib/utils_game_timestamp.php')) require_once $root . '/lib/utils_game_timestamp.php';
        foreach (['first', 'last'] as $edge) {
            $gamets = $product['gamets_' . $edge] ?? null;
            $view['job']['products'][$i]['game_' . $edge] = is_numeric($gamets) && (float)$gamets > 0 && $function && function_exists($function) ? $function($gamets) : null;
        }
    }
    return $view;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $operation = $_POST['operation'] ?? '';
        sm_guard('shared', 'all');
        // Release the session before launching a long-lived worker so other tabs stay responsive.
        session_write_close();
        if ($operation === 'start') {
            $source = $_POST['source'] ?? ''; $filename = $_POST['filename'] ?? '';
            if (!is_string($source) || !in_array($source, ['automatic', 'manual'], true) || !is_string($filename) || strlen($filename) > 240) {
                throw new InvalidArgumentException('Choose a full PostgreSQL backup from the list.');
            }
            $view = cr_start(sm_backup_file('all', $source, $filename), $filename, $source);
            $message = 'Recovery started in the background. You can close this page; progress is kept on the server.';
        } elseif (in_array($operation, ['cancel', 'discard'], true)) {
            $id = $_POST['job_id'] ?? '';
            if (!cr_job_id_valid($id)) throw new InvalidArgumentException('Choose a recovery attempt.');
            $view = $operation === 'cancel' ? cr_cancel($id) : cr_discard($id);
            $message = $operation === 'cancel' ? 'Cancellation requested. The private instance will stop shortly.' : 'Recovery attempt and its files were removed.';
        } else throw new InvalidArgumentException('Unknown recovery action.');
        echo json_encode(['ok' => true, 'message' => $message] + cr_game_dates($view), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } elseif ($method === 'GET') {
        echo json_encode(['ok' => true] + cr_game_dates(cr_view()), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        http_response_code(405);
        throw new InvalidArgumentException('Use GET for status or POST for recovery actions.');
    }
} catch (Throwable $e) {
    $known = $e instanceof InvalidArgumentException || $e instanceof StorageManagerRequestException || $e instanceof ClusterRecoveryException;
    if (http_response_code() < 400) http_response_code($e instanceof ClusterRecoveryException ? 409 : ($known ? 400 : 503));
    error_log('[ClusterRecovery] ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ' - ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $known ? $e->getMessage() : 'Recovery status is unavailable. Check the server log.'], JSON_INVALID_UTF8_SUBSTITUTE);
}
