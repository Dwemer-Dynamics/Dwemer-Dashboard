<?php
require_once dirname(__DIR__) . '/lib/data_manager.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
$conn = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        throw new InvalidArgumentException('This overview is read-only.');
    }
    $mod = $_GET['mod'] ?? '';
    $search = $_GET['q'] ?? '';
    $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
    if (!is_string($mod) || !isset(dm_products()[$mod]) || !is_string($search) || strlen($search) > 480 || $offset === false || $offset < 0 || $offset > 1000000) {
        http_response_code(400);
        throw new InvalidArgumentException('Choose a valid mod and snapshot page.');
    }
    $product = dm_products()[$mod];
    $root = dm_server_root($product['dir']);
    if ($root === null) {
        http_response_code(503);
        throw new InvalidArgumentException($product['label'] . ' is not installed here.');
    }
    $conn = dm_connect(dm_connection_settings($mod, $root));
    $data = dm_overview($conn, $mod, $offset, trim($search));
    $calendar = $root . '/lib/utils_game_timestamp.php';
    if (is_file($calendar)) require_once $calendar;
    foreach ($data['snapshots']['items'] as &$snapshot) {
        $gamets = $snapshot['gamets'];
        if (is_numeric($gamets) && (float)$gamets > 0) {
            if ($mod === 'chim' && function_exists('convert_gamets2skyrim_long_date')) $snapshot['game_date'] = convert_gamets2skyrim_long_date($gamets);
            elseif ($mod === 'dialectic' && function_exists('convert_gamets2fallout_long_date')) $snapshot['game_date'] = convert_gamets2fallout_long_date($gamets);
            elseif ($mod === 'stobe' && function_exists('stobeGametsDateLabel')) $snapshot['game_date'] = stobeGametsDateLabel($gamets);
        }
        unset($snapshot['gamets']);
    }
    unset($snapshot);
    echo json_encode(['ok' => true, 'mod' => $mod, 'label' => $product['label'], 'game' => $product['game'], 'available' => true]
        + $data + ['tools' => dm_tools($mod, $root), 'warnings' => []], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if ($e instanceof InvalidArgumentException) $message = $e->getMessage();
    else {
        http_response_code(503);
        $message = 'This server could not be read. It may be offline, busy, or awaiting a server update. Try again shortly.';
        error_log('[DataManager] Overview unavailable for ' . (is_string($mod ?? null) ? $mod : 'invalid mod') . ' (' . get_class($e) . ')');
    }
    echo json_encode(['ok' => false, 'error' => $message]);
} finally {
    if ($conn) pg_close($conn);
}
