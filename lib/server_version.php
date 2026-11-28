<?php
declare(strict_types=1);

// Reads a server's installed release version from its plain-text metadata; never includes server code.
// $files are trusted constant names. Returns null when no candidate holds a release-style version
// (missing, unreadable, oversized, build stamp or hash).
function dashboard_server_version(string $root, array $files): ?string
{
    if ($root === '') {
        return null;
    }
    foreach ($files as $file) {
        $path = $root . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        // One byte past the limit: an oversized file is rejected rather than read as a prefix.
        $raw = @file_get_contents($path, false, null, 0, 65);
        if (!is_string($raw) || strlen($raw) > 64) {
            continue;
        }
        $version = trim($raw);
        if (preg_match('/^v?\d+(?:\.\d+){1,3}(?:[-+.]?[A-Za-z][A-Za-z0-9.-]{0,23})?$/D', $version)) {
            return $version;
        }
    }
    return null;
}

// Reads Mirthwood: Echoes' installed release label from its bundle manifest, mirroring the
// channel checks in Echoes' Update-Server.ps1. version is numeric X.Y.Z; channel "main" carries
// release X.Y.Z and channel "beta" carries X.Y.Z-beta.N (N >= 1). Packages from before release
// channels carry neither field and are Main releases of their version. Returns the release label
// (so Beta stays visible), or null for any other product or malformed/inconsistent metadata.
function dashboard_echoes_version(string $manifest): ?string
{
    if (!is_file($manifest) || !is_readable($manifest)) {
        return null;
    }
    $size = @filesize($manifest);
    if (!is_int($size) || $size > 4 * 1024 * 1024) {
        return null;
    }
    $raw = @file_get_contents($manifest);
    $data = is_string($raw) ? json_decode($raw, true, 16) : null;
    if (!is_array($data) || ($data['id'] ?? null) !== 'mirthwood-echoes' || ($data['name'] ?? null) !== 'Mirthwood: Echoes') {
        return null;
    }
    $version = $data['version'] ?? null;
    if (!is_string($version) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1) {
        return null;
    }
    $channel = array_key_exists('channel', $data) && $data['channel'] !== null ? $data['channel'] : 'main';
    $release = array_key_exists('release', $data) && $data['release'] !== null ? $data['release'] : $version;
    if (!is_string($channel) || !is_string($release)) {
        return null;
    }
    if ($channel === 'main') {
        return $release === $version ? $release : null;
    }
    if ($channel === 'beta') {
        return preg_match('/^' . preg_quote($version, '/') . '-beta\.[1-9][0-9]*$/D', $release) === 1 ? $release : null;
    }
    return null;
}
