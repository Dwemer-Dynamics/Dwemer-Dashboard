<?php

// Read-only view of the distro's custom mod registry (written by root's ddistro_custom_mod).
// Links are built here from the validated id and paths; nothing in an entry is used as a URL.
const DASHBOARD_CUSTOM_MOD_REGISTRY = '/var/lib/dwemerdistro-custom-mods/registry';
const DASHBOARD_CUSTOM_MOD_ROOT = '/var/www/html/custom-mods';

function dashboard_custom_mod_path_ok($path, bool $allowTrailingSlash = false): bool
{
    if (!is_string($path) || $path === '' || strlen($path) > 200) {
        return false;
    }
    if ($allowTrailingSlash && str_ends_with($path, '/')) {
        $path = substr($path, 0, -1);
        if ($path === '') {
            return true;
        }
    }
    foreach (explode('/', $path) as $segment) {
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]*\z/', $segment)) {
            return false;
        }
    }
    return true;
}

function dashboard_custom_mod_image_ok($path): bool
{
    return dashboard_custom_mod_path_ok($path) && preg_match('/\.(png|jpe?g)\z/i', $path) === 1;
}

// Same rule as the distro's asset_urls(): the installed asset must be a plain file with no link on its path.
function dashboard_custom_mod_asset_installed(string $folder, string $path): bool
{
    $current = $folder;
    foreach (explode('/', $path) as $segment) {
        $current .= '/' . $segment;
        if (is_link($current) || !file_exists($current)) {
            return false;
        }
    }
    return is_file($current);
}

/**
 * Ready custom mods from $registryRoot, sorted by name.
 * Returns ['readable' => bool, 'mods' => [...], 'unavailable' => int], where unavailable counts registered
 * mods that are installing, updating, or failed. Unregistered, unreadable, oversized, or malformed entries are skipped.
 */
function dashboard_custom_mods(string $registryRoot, string $modRoot = DASHBOARD_CUSTOM_MOD_ROOT): array
{
    $files = is_dir($registryRoot) ? @scandir($registryRoot) : false;
    if ($files === false) {
        return ['readable' => false, 'mods' => [], 'unavailable' => 0];
    }
    $mods = [];
    $unavailable = 0;
    foreach ($files as $file) {
        if (count($mods) + $unavailable >= 50 || !preg_match('/^([a-z][a-z0-9-]{1,30}[a-z0-9])\.json\z/', $file, $match) || str_contains($match[1], '--')) {
            continue;
        }
        $path = $registryRoot . '/' . $file;
        if (is_link($path) || !is_file($path) || (int) @filesize($path) > 65536) {
            continue;
        }
        $entry = json_decode((string) @file_get_contents($path), true);
        $id = $match[1];
        if (!is_array($entry) || ($entry['schema_version'] ?? null) !== 1 || ($entry['id'] ?? null) !== $id) {
            continue;
        }
        $state = $entry['state'] ?? null;
        if (in_array($state, ['installing', 'updating', 'failed'], true)) {
            $unavailable++;
            continue;
        }
        if ($state !== 'ready' || !dashboard_custom_mod_path_ok($entry['dashboard_path'] ?? null, true)) {
            continue;
        }
        $name = is_string($entry['name'] ?? null) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $entry['name']) ?? '') : '';
        $description = is_string($entry['description'] ?? null) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $entry['description']) ?? '') : '';
        $assets = is_array($entry['assets'] ?? null) ? $entry['assets'] : [];
        $base = '/custom-mods/' . $id . '/';
        // Icon paths stay the same across updates; the installed commit keeps browsers from showing a stale file.
        $commit = $entry['commit'] ?? null;
        $version = is_string($commit) && preg_match('/^[0-9a-f]{40}\z/', $commit) === 1 ? '?v=' . substr($commit, 0, 12) : '';
        $icon = $assets['icon'] ?? null;
        $iconOk = dashboard_custom_mod_image_ok($icon) && dashboard_custom_mod_asset_installed($modRoot . '/' . $id, $icon);
        $mods[] = [
            'id' => $id,
            'name' => $name !== '' ? mb_substr($name, 0, 60) : $id,
            'description' => mb_substr($description, 0, 300),
            'url' => $base . $entry['dashboard_path'],
            'icon' => $iconOk ? $base . $icon . $version : '',
        ];
    }
    usort($mods, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return ['readable' => true, 'mods' => $mods, 'unavailable' => $unavailable];
}
