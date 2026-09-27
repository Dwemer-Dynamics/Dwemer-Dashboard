<?php
// Keep LM Studio mutations behind a manager session and the restricted WSL helper.
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function lmstudio_helper(string $command, array $input = []): array
{
    $process = proc_open(['sudo', '-n', '-u', 'dwemer', '/usr/local/bin/ddistro_lmstudio', $command],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('LM Studio helper unavailable. Update Distro and install LM Studio from the launcher.');
    }
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1], 65536);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2], 2048);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode($output, true);
    if ($exit !== 0 || !is_array($result)) {
        throw new RuntimeException($result['error'] ?? 'LM Studio helper unavailable. Update Distro and install LM Studio from the launcher.');
    }
    return $result;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Use POST.');
    }
    $input = json_decode(file_get_contents('php://input', false, null, 0, 16384), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['csrf'] ?? null) ||
        !hash_equals($_SESSION['lmstudio_csrf'] ?? '', $input['csrf']) || empty($_SESSION['lmstudio_csrf'])) {
        http_response_code(403);
        throw new RuntimeException('Session expired. Reload the manager.');
    }
    $action = $input['action'] ?? '';
    if ($action === 'authorize') {
        if (time() - ($_SESSION['lmstudio_auth_attempt'] ?? 0) < 2) {
            throw new RuntimeException('Wait a moment before trying again.');
        }
        $_SESSION['lmstudio_auth_attempt'] = time();
        $result = lmstudio_helper('authorize', ['token' => $input['token'] ?? '']);
        if (empty($result['authorized'])) {
            http_response_code(403);
            throw new RuntimeException('Invalid access key. Open Manager from the launcher.');
        }
        session_regenerate_id(true);
        $_SESSION['lmstudio_until'] = time() + 28800;
    } else {
        if (($_SESSION['lmstudio_until'] ?? 0) < time()) {
            http_response_code(401);
            throw new RuntimeException('Open Manager from the launcher to unlock this page.');
        }
        session_write_close();
        if ($action === 'status' || $action === 'catalog') {
            $result = lmstudio_helper($action);
        } elseif (in_array($action, ['start', 'stop', 'restart', 'settings', 'download', 'load', 'unload', 'test'], true)) {
            unset($input['csrf']);
            $result = lmstudio_helper('submit', $input);
        } else {
            throw new RuntimeException('Unknown action.');
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (http_response_code() === 200) {
        http_response_code(400);
    }
    echo json_encode(['error' => $error->getMessage()]);
}
