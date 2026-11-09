<?php
// API Keys rail: masked global key status, edits of existing keys, new keys for catalog providers and the read-only test of
// one saved key, for the trusted local browser only.
declare(strict_types=1);

require __DIR__ . '/../lib/api_keys_access.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function api_keys_reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    api_keys_reply(405, ['ok' => false, 'outcome' => 'refused']);
}
if (!api_keys_local_request($_SERVER, true)) {
    api_keys_reply(403, ['ok' => false, 'outcome' => 'refused']);
}
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0 || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
    api_keys_reply(400, ['ok' => false, 'outcome' => 'invalid', 'fields' => []]);
}
$raw = (string) file_get_contents('php://input', false, null, 0, 8193);
$input = strlen($raw) > 8192 ? null : json_decode($raw, true, 4);
$raw = '';

session_start();
$token = $_SESSION['api_keys_csrf'] ?? '';
// Release the session lock before the root helper runs; nothing below writes the session.
session_write_close();
if (!is_array($input) || !is_string($input['csrf'] ?? null) || !is_string($token) || strlen($token) !== 64 || !hash_equals($token, $input['csrf'])) {
    api_keys_reply(403, ['ok' => false, 'outcome' => 'session']);
}

$op = $input['op'] ?? null;
if ($op === 'status' && count($input) === 2) {
    $reply = api_keys_helper('status', '');
    if ($reply === null) {
        api_keys_reply(200, ['ok' => false, 'outcome' => 'unavailable']);
    }
    if ($reply['ok'] && is_array($reply['keys'] ?? null) && is_array($reply['providers'] ?? null)) {
        // The catalog only carries each provider id and whether a new key needs an entered origin.
        $providers = [];
        foreach (array_slice($reply['providers'], 0, 64) as $entry) {
            if (is_array($entry) && is_string($entry['provider'] ?? null) && preg_match('/^[a-z0-9_]{1,32}$/D', $entry['provider'])
                && is_bool($entry['needs_origin'] ?? null)) {
                $providers[] = ['provider' => $entry['provider'], 'needs_origin' => $entry['needs_origin']];
            }
        }
        api_keys_reply(200, ['ok' => true, 'outcome' => 'listed', 'keys' => $reply['keys'], 'providers' => $providers]);
    }
    api_keys_reply(200, ['ok' => false, 'outcome' => $reply['outcome'] === 'not_configured' ? 'not_configured' : 'unavailable']);
}

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
$test = ['csrf', 'op', 'key_id', 'revision'];
if ($op === 'test') {
    // Tests only the saved key named by UUID and revision; no secret is accepted, so an unsaved entry is never sent.
    if (array_diff(array_keys($input), $test) !== [] || count($input) !== count($test) || !is_string($input['key_id'])
        || !preg_match($uuid, $input['key_id']) || !is_int($input['revision']) || $input['revision'] < 1) {
        api_keys_reply(400, ['ok' => false, 'outcome' => 'invalid', 'fields' => []]);
    }
    $reply = api_keys_helper('test', (string) json_encode(['key_id' => $input['key_id'], 'revision' => $input['revision']]));
    if ($reply !== null && $reply['ok'] && $reply['outcome'] === 'tested'
        && in_array($reply['result'] ?? null, ['accepted', 'rejected', 'rate_limited', 'unavailable', 'unsupported', 'reachable'], true)) {
        api_keys_reply(200, ['ok' => true, 'outcome' => 'tested', 'result' => $reply['result']]);
    }
    $outcome = $reply !== null && in_array($reply['outcome'], ['conflict', 'missing', 'busy', 'not_configured'], true) ? $reply['outcome'] : 'unavailable';
    api_keys_reply(200, ['ok' => false, 'outcome' => $outcome]);
}

$create = ['csrf', 'op', 'provider', 'label', 'origin', 'secret'];
if ($op === 'create') {
    if (array_diff(array_keys($input), $create) !== [] || count($input) !== count($create)
        || !is_string($input['provider']) || !preg_match('/^[a-z0-9_]{1,32}$/D', $input['provider'])
        || !is_string($input['label']) || !is_string($input['secret']) || $input['secret'] === ''
        || !($input['origin'] === null || (is_string($input['origin']) && $input['origin'] !== '' && strlen($input['origin']) <= 256))
        || strlen($input['label']) > 256 || strlen($input['secret']) > 4096 || !mb_check_encoding($input['label'], 'UTF-8')) {
        unset($input);
        api_keys_reply(400, ['ok' => false, 'outcome' => 'invalid', 'fields' => []]);
    }
    $request = json_encode(['provider' => $input['provider'], 'label' => $input['label'], 'origin' => $input['origin'], 'secret' => $input['secret']]);
    unset($input);
    $reply = api_keys_helper('create', is_string($request) ? $request : '');
    $request = '';
    if ($reply === null) {
        // The key may have been created; the browser must reload instead of retrying.
        api_keys_reply(200, ['ok' => false, 'outcome' => 'unknown']);
    }
    if ($reply['ok'] && is_array($reply['key'] ?? null)) {
        api_keys_reply(200, ['ok' => true, 'outcome' => 'created', 'key' => $reply['key']]);
    }
    $outcome = in_array($reply['outcome'], ['invalid', 'limit', 'crypto_unavailable', 'busy', 'not_configured', 'unavailable'], true) ? $reply['outcome'] : 'unknown';
    $fields = $outcome === 'invalid' && is_array($reply['fields'] ?? null)
        ? array_values(array_intersect(['provider', 'label', 'origin', 'secret'], $reply['fields'])) : [];
    api_keys_reply(200, ['ok' => false, 'outcome' => $outcome] + ($outcome === 'invalid' ? ['fields' => $fields] : []));
}

$edit = ['csrf', 'op', 'key_id', 'revision', 'label', 'secret'];
if ($op !== 'edit' || array_diff(array_keys($input), $edit) !== [] || count($input) !== count($edit)
    || !is_string($input['key_id']) || !preg_match($uuid, $input['key_id'])
    || !is_int($input['revision']) || $input['revision'] < 1 || !is_string($input['label']) || !is_string($input['secret'])
    || strlen($input['label']) > 256 || strlen($input['secret']) > 4096 || !mb_check_encoding($input['label'], 'UTF-8')) {
    unset($input);
    api_keys_reply(400, ['ok' => false, 'outcome' => 'invalid', 'fields' => []]);
}
$request = json_encode(['key_id' => $input['key_id'], 'revision' => $input['revision'], 'label' => $input['label'], 'secret' => $input['secret']]);
unset($input);
$reply = api_keys_helper('edit', is_string($request) ? $request : '');
$request = '';
if ($reply === null) {
    // The write may have run; the browser must reload instead of retrying.
    api_keys_reply(200, ['ok' => false, 'outcome' => 'unknown']);
}
if ($reply['ok'] && is_array($reply['key'] ?? null)) {
    api_keys_reply(200, ['ok' => true, 'outcome' => $reply['outcome'] === 'unchanged' ? 'unchanged' : 'updated', 'key' => $reply['key']]);
}
$outcome = in_array($reply['outcome'], ['conflict', 'missing', 'invalid', 'busy', 'not_configured', 'unavailable'], true) ? $reply['outcome'] : 'unknown';
$fields = $outcome === 'invalid' && is_array($reply['fields'] ?? null) ? array_values(array_intersect(['label', 'secret'], $reply['fields'])) : [];
api_keys_reply(200, ['ok' => false, 'outcome' => $outcome] + ($outcome === 'invalid' ? ['fields' => $fields] : []));
