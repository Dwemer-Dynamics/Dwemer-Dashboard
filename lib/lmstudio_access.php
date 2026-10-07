<?php
// Trust direct requests from this machine or a private LAN; proxied or hostname requests keep the access key.
declare(strict_types=1);

function lmstudio_private_ip(string $ip): bool
{
    $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
    if ($packed === false) {
        return false;
    }
    if (strlen($packed) === 16) {
        if ($packed === inet_pton('::1')) {
            return true;
        }
        if ((ord($packed[0]) & 0xfe) === 0xfc) {
            return true;
        }
        if (substr($packed, 0, 12) !== str_repeat("\0", 10) . "\xff\xff") {
            return false;
        }
        $packed = substr($packed, 12);
    }
    [$a, $b] = [ord($packed[0]), ord($packed[1])];
    return $a === 127 || $a === 10 || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168);
}

function lmstudio_local_host(string $host): bool
{
    if (!preg_match('/^(?:(localhost|[0-9.]+)|\[([0-9A-Fa-f:.]+)\])(?::([0-9]{1,5}))?$/Di', $host, $match)) {
        return false;
    }
    $port = $match[3] ?? '';
    if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
        return false;
    }
    if (($match[2] ?? '') !== '') {
        return filter_var($match[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && lmstudio_private_ip($match[2]);
    }
    if (strtolower($match[1]) === 'localhost') {
        return true;
    }
    return filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && lmstudio_private_ip($match[1]);
}

function lmstudio_local_request(array $server): bool
{
    foreach (['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO',
                 'HTTP_X_REAL_IP', 'HTTP_X_CLIENT_IP', 'HTTP_CLIENT_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_VIA'] as $header) {
        if (isset($server[$header])) {
            return false;
        }
    }
    $host = $server['HTTP_HOST'] ?? '';
    if (!is_string($host) || !lmstudio_local_host($host) ||
        !lmstudio_private_ip((string) ($server['REMOTE_ADDR'] ?? ''))) {
        return false;
    }
    $origin = $server['HTTP_ORIGIN'] ?? null;
    $scheme = (($server['HTTPS'] ?? '') !== '' && strtolower((string) $server['HTTPS']) !== 'off') ? 'https' : 'http';
    if ($origin !== null && strcasecmp((string) $origin, $scheme . '://' . $host) !== 0) {
        return false;
    }
    $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
    return $site === null || $site === 'same-origin';
}
