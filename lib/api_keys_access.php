<?php
// Owner boundary for the API Keys rail. The homepage has no login, so key metadata and edits are limited to the
// browser on this PC: loopback, this server's own address, or on WSL the NAT gateway (the Windows host itself). A
// Windows portproxy that forwards LAN traffic into WSL NAT would also arrive from the gateway. Private-LAN clients, proxied
// requests and foreign Host/Origin values are refused. Forwarded headers are never trusted.
declare(strict_types=1);

const API_KEYS_HELPER = '/usr/local/bin/ddistro_dashboard_keys';

function api_keys_ip(string $ip): string|false
{
    $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
    if ($packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $packed = substr($packed, 12);
    }
    return $packed;
}

/** Default IPv4 gateway of a WSL 2 NAT network, which is the Windows host. Never used outside WSL. */
function api_keys_wsl_gateway(): ?string
{
    $release = @file_get_contents('/proc/sys/kernel/osrelease', false, null, 0, 256);
    // Mirrored networking (loopback0) routes through the real LAN router, which is not this PC.
    if (!is_string($release) || stripos($release, 'microsoft') === false || file_exists('/sys/class/net/loopback0')) {
        return null;
    }
    $routes = @file('/proc/net/route', FILE_IGNORE_NEW_LINES);
    foreach (is_array($routes) ? array_slice($routes, 1, 64) : [] as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) > 3 && $fields[0] === 'eth0' && $fields[1] === '00000000' && preg_match('/^[0-9A-F]{8}$/', $fields[2]) && ((int) $fields[3] & 2)) {
            return inet_ntop(strrev((string) hex2bin($fields[2])));
        }
    }
    return null;
}

function api_keys_trusted_client(array $server): bool
{
    $remote = api_keys_ip((string) ($server['REMOTE_ADDR'] ?? ''));
    if ($remote === false) {
        return false;
    }
    // A TCP peer using this server's own address is this machine (WSL mirrored networking connects that way).
    if ($remote === inet_pton('::1') || (strlen($remote) === 4 && ord($remote[0]) === 127)
        || $remote === api_keys_ip((string) ($server['SERVER_ADDR'] ?? ''))) {
        return true;
    }
    $gateway = api_keys_wsl_gateway();
    return $gateway !== null && $remote === inet_pton($gateway);
}

/** Host must name this machine (loopback or the address the request arrived on), which also defeats DNS rebinding. */
function api_keys_trusted_host(string $host, array $server): bool
{
    if (strlen($host) > 80 || !preg_match('/^(?:(localhost|[0-9.]+)|\[([0-9A-Fa-f:.]+)\])(?::([0-9]{1,5}))?$/Di', $host, $match)) {
        return false;
    }
    $name = ($match[2] ?? '') !== '' ? $match[2] : strtolower($match[1]);
    if ($name === 'localhost') {
        return true;
    }
    $packed = api_keys_ip($name);
    if ($packed === false) {
        return false;
    }
    $local = api_keys_ip((string) ($server['SERVER_ADDR'] ?? ''));
    return $packed === inet_pton('::1') || (strlen($packed) === 4 && ord($packed[0]) === 127) || ($local !== false && $packed === $local);
}

/** $api: the JSON endpoint additionally requires a same-origin Origin; page renders only decide whether to issue a token. */
function api_keys_local_request(array $server, bool $api): bool
{
    foreach (['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO',
                 'HTTP_X_REAL_IP', 'HTTP_X_CLIENT_IP', 'HTTP_CLIENT_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_VIA'] as $header) {
        if (isset($server[$header])) {
            return false;
        }
    }
    $host = $server['HTTP_HOST'] ?? '';
    if (!is_string($host) || !api_keys_trusted_client($server) || !api_keys_trusted_host($host, $server)) {
        return false;
    }
    if (!$api) {
        return true;
    }
    $scheme = (($server['HTTPS'] ?? '') !== '' && strtolower((string) $server['HTTPS']) !== 'off') ? 'https' : 'http';
    if (strcasecmp((string) ($server['HTTP_ORIGIN'] ?? ''), $scheme . '://' . $host) !== 0) {
        return false;
    }
    $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
    return $site === null || $site === 'same-origin';
}

/** CSRF token for the rail; only issued to trusted local pages. Callers own the session. */
function api_keys_csrf(): string
{
    if (!is_string($_SESSION['api_keys_csrf'] ?? null) || strlen($_SESSION['api_keys_csrf']) !== 64) {
        $_SESSION['api_keys_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['api_keys_csrf'];
}

/**
 * Run the fixed root helper verb through its exact sudo rule. Fixed argv (no shell), bounded stdin/stdout and a hard
 * deadline. Returns the decoded reply or null; stderr is discarded and never surfaced.
 */
function api_keys_helper(string $verb, string $input): ?array
{
    if (!in_array($verb, ['status', 'edit', 'create', 'test'], true) || strlen($input) > 8192) {
        return null;
    }
    $process = @proc_open(['/usr/bin/sudo', '-n', API_KEYS_HELPER, $verb],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/', ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
    if (!is_resource($process)) {
        return null;
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $input = '';
    stream_set_blocking($pipes[1], false);
    $output = '';
    $deadline = microtime(true) + 50;
    while (!feof($pipes[1]) && strlen($output) <= 262144 && microtime(true) < $deadline) {
        $read = [$pipes[1]];
        $none = null;
        if (@stream_select($read, $none, $none, 1) > 0) {
            $output .= (string) fread($pipes[1], 65536);
        }
    }
    $complete = feof($pipes[1]) && strlen($output) <= 262144;
    fclose($pipes[1]);
    if (!$complete) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    $reply = $complete ? json_decode($output, true, 8) : null;
    return is_array($reply) && is_bool($reply['ok'] ?? null) && is_string($reply['outcome'] ?? null) ? $reply : null;
}
