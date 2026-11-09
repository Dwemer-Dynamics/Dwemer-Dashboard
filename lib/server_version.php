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
