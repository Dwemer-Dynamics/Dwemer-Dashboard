<?php

// Read-only view of the distro's custom mod registry (written by root's ddistro_custom_mod).
// Links are built here from the validated id, server folder and paths; nothing in an entry is used as a URL.
const DASHBOARD_CUSTOM_MOD_REGISTRY = '/var/lib/dwemerdistro-custom-mods/registry';
const DASHBOARD_WEB_ROOT = '/var/www/html';
const DASHBOARD_SERVICES_CONF = '/etc/dwemerdistro_services.conf';
// Card artwork limits; a larger or unexpected file is left out and the card shows its fallback emblem.
const DASHBOARD_CUSTOM_MOD_IMAGE_BYTES = 4 * 1024 * 1024;
const DASHBOARD_CUSTOM_MOD_IMAGE_MIN_PIXELS = 16;
const DASHBOARD_CUSTOM_MOD_IMAGE_MAX_PIXELS = 4096;
// The installed server's lib/version.php is read as text (never included); a larger file shows no version.
const DASHBOARD_CUSTOM_MOD_VERSION_BYTES = 8192;

// The shared loopback custom mod port, read as data (never sourced) with the distro manager's rules:
// 19000 when unset, null when the file or value is not trustworthy (19000-19999 only).
function dashboard_custom_mods_port(string $conf = DASHBOARD_SERVICES_CONF): ?int
{
    if (!file_exists($conf) && !is_link($conf)) {
        return 19000;
    }
    $stat = @lstat($conf);
    if ($stat === false || is_link($conf) || !is_file($conf) || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0) {
        return null;
    }
    $text = @file_get_contents($conf, false, null, 0, 65536);
    if ($text === false) {
        return null;
    }
    $port = 19000;
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || !str_contains($line, 'CUSTOM_MODS_PORT')) {
            continue;
        }
        if (!preg_match('/^CUSTOM_MODS_PORT=("?)([0-9]{1,5})\1\z/', $line, $match) || (int) $match[2] < 19000 || (int) $match[2] > 19999) {
            return null;
        }
        $port = (int) $match[2];
    }
    return $port;
}

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

// Same rule as the distro's validate_server_folder(): a direct /var/www/html/<Name>Server root, never an
// official, Kagrenac or distro name. Anything else was not written by the manager, so the entry is skipped.
function dashboard_custom_mod_server_folder_ok($name): bool
{
    if (!is_string($name) || preg_match('/^[A-Z][A-Za-z0-9]{0,40}Server\z/', $name) !== 1) {
        return false;
    }
    $reserved = ['herika', 'herikaserver', 'chim', 'stobe', 'stobeserver', 'dialectic', 'dialecticserver',
        'reign', 'reignserver', 'lorkhan', 'lorkhanserver', 'dwemer', 'dwemerdistro', 'postgres', 'template',
        'html', 'admin', 'root', 'example', 'kagrenac', 'custom', 'custommods'];
    return !in_array(strtolower(substr($name, 0, -strlen('Server'))), $reserved, true);
}

// Single-line display text: control, format (including bidirectional overrides) and separator characters are dropped.
function dashboard_custom_mod_text($value, int $max): string
{
    if (!is_string($value)) {
        return '';
    }
    return mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '', $value) ?? ''), 0, $max);
}

/**
 * The asset URL for a plain, size-capped PNG or JPEG whose content matches its extension, or ''.
 * Same path rule as the distro's asset_urls(): no link anywhere from the mod folder down.
 */
function dashboard_custom_mod_image(string $folder, $path, string $base, string $version): string
{
    if (!dashboard_custom_mod_path_ok($path) || preg_match('/\.(png|jpe?g)\z/i', $path, $match) !== 1) {
        return '';
    }
    $current = $folder;
    foreach (explode('/', $path) as $segment) {
        $current .= '/' . $segment;
        if (is_link($current) || !file_exists($current)) {
            return '';
        }
    }
    $size = is_file($current) ? (int) @filesize($current) : 0;
    if ($size <= 0 || $size > DASHBOARD_CUSTOM_MOD_IMAGE_BYTES) {
        return '';
    }
    // Reads only the header: the real type must match the extension and the size must be sane.
    $info = @getimagesize($current);
    if (!is_array($info) || $info[2] !== (strtolower($match[1]) === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG)) {
        return '';
    }
    foreach ([$info[0], $info[1]] as $pixels) {
        if ($pixels < DASHBOARD_CUSTOM_MOD_IMAGE_MIN_PIXELS || $pixels > DASHBOARD_CUSTOM_MOD_IMAGE_MAX_PIXELS) {
            return '';
        }
    }
    // Paths stay the same across updates; the installed commit and file time keep browsers from showing a stale file.
    return $base . $path . '?v=' . $version . dechex((int) @filemtime($current));
}

/**
 * The literal SERVER_VERSION semver from <folder>/lib/version.php, or '' when absent, linked, oversized,
 * ambiguous or not a plain semver. The file is only read as bounded text; nothing in it is executed.
 */
function dashboard_custom_mod_server_version(string $folder): string
{
    $lib = $folder . '/lib';
    $file = $lib . '/version.php';
    if (is_link($lib) || !is_dir($lib) || is_link($file) || !is_file($file)) {
        return '';
    }
    $stat = @lstat($file);
    if ($stat === false || $stat['size'] <= 0 || $stat['size'] > DASHBOARD_CUSTOM_MOD_VERSION_BYTES) {
        return '';
    }
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        return '';
    }
    // The opened file must be the one just checked, so a swap to a link or larger file in between is refused.
    $opened = @fstat($handle);
    $text = $opened !== false && $opened['dev'] === $stat['dev'] && $opened['ino'] === $stat['ino']
        ? @fread($handle, DASHBOARD_CUSTOM_MOD_VERSION_BYTES + 1) : false;
    fclose($handle);
    if (!is_string($text) || strlen($text) > DASHBOARD_CUSTOM_MOD_VERSION_BYTES || !function_exists('token_get_all')) {
        return '';
    }
    // Tokenizing only lexes the text, so comments, strings, heredocs and inline HTML cannot pass as a declaration.
    // Only a top-level `const SERVER_VERSION = '<literal>';` statement counts, and any second one makes it ambiguous.
    $tokens = array_values(array_filter(token_get_all($text), static function ($token): bool {
        return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }));
    $depth = 0;
    $found = [];
    foreach ($tokens as $i => $token) {
        $id = is_array($token) ? $token[0] : $token;
        if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        } elseif ($id === '}') {
            $depth--;
        } elseif ($id === T_CONST && $depth === 0 && isset($tokens[$i + 1]) && is_array($tokens[$i + 1])
            && $tokens[$i + 1][0] === T_STRING && $tokens[$i + 1][1] === 'SERVER_VERSION') {
            $previous = $tokens[$i - 1] ?? null;
            $literal = $tokens[$i + 3] ?? null;
            $found[] = (is_array($previous) && $previous[0] === T_OPEN_TAG || $previous === ';' || $previous === '}')
                && ($tokens[$i + 2] ?? null) === '=' && ($tokens[$i + 4] ?? null) === ';'
                && is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING
                ? substr($literal[1], 1, -1) : '';
        }
    }
    // Semantic Versioning 2.0.0, kept short; quotes, backslashes and `$` never match, so the text is the literal value.
    $number = '(?:0|[1-9][0-9]{0,8})';
    $prerelease = '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)';
    $semver = "/\\A$number\\.$number\\.$number(?:-$prerelease(?:\\.$prerelease)*)?(?:\\+[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?\\z/";
    return count($found) === 1 && strlen($found[0]) <= 40 && preg_match($semver, $found[0]) === 1 ? $found[0] : '';
}

/**
 * Ready custom mods from $registryRoot, sorted by name.
 * Returns ['readable' => bool, 'mods' => [...], 'unavailable' => int], where unavailable counts registered
 * mods that are installing, updating, or failed. Unregistered, unreadable, oversized, or malformed entries are skipped.
 * Ready only means the last install or update finished; it is not a live health check.
 */
function dashboard_custom_mods(string $registryRoot, string $webRoot = DASHBOARD_WEB_ROOT,
                               string $servicesConf = DASHBOARD_SERVICES_CONF): array
{
    $files = is_dir($registryRoot) ? @scandir($registryRoot) : false;
    if ($files === false) {
        return ['readable' => false, 'mods' => [], 'unavailable' => 0];
    }
    // Without a valid port no link can be built, so ready mods are reported as needing attention.
    $port = dashboard_custom_mods_port($servicesConf);
    // Artwork and version are read only from a plain web root and mod folder; a link there leaves both out.
    $webRootOk = !is_link($webRoot) && is_dir($webRoot) && !is_link($webRoot . '/custom-mods');
    $mods = [];
    $routes = [];
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
        // A direct root is /<Name>Server; without one the mod keeps the legacy /custom-mods/<id> layout.
        // A recorded folder must be that canonical one, and no two entries may share a route.
        $serverFolder = $entry['server_folder'] ?? '';
        if ($serverFolder !== '' && !dashboard_custom_mod_server_folder_ok($serverFolder)) {
            continue;
        }
        $route = $serverFolder !== '' ? $serverFolder : 'custom-mods/' . $id;
        if ((array_key_exists('folder', $entry) && $entry['folder'] !== DASHBOARD_WEB_ROOT . '/' . $route)
            || isset($routes[strtolower($route)])) {
            continue;
        }
        $state = $entry['state'] ?? null;
        if (in_array($state, ['installing', 'updating', 'failed'], true) || ($state === 'ready' && $port === null)) {
            $unavailable++;
            continue;
        }
        if ($state !== 'ready' || !dashboard_custom_mod_path_ok($entry['dashboard_path'] ?? null, true)) {
            continue;
        }
        $routes[strtolower($route)] = true;
        $name = dashboard_custom_mod_text($entry['name'] ?? null, 60);
        $name = $name !== '' ? $name : $id;
        $assets = is_array($entry['assets'] ?? null) ? $entry['assets'] : [];
        $base = 'http://127.0.0.1:' . $port . '/' . $route . '/';
        $commit = $entry['commit'] ?? null;
        $version = is_string($commit) && preg_match('/^[0-9a-f]{40}\z/', $commit) === 1 ? substr($commit, 0, 12) . '-' : '';
        $folder = $webRoot . '/' . $route;
        $artworkOk = $webRootOk && !is_link($folder) && is_dir($folder);
        $mods[] = [
            'id' => $id,
            'name' => $name,
            'description' => dashboard_custom_mod_text($entry['description'] ?? null, 300),
            'game' => dashboard_custom_mod_text($entry['game_name'] ?? null, 60),
            'author' => dashboard_custom_mod_text($entry['author'] ?? null, 80),
            // The installed server's own version; empty when it has none (the registry schema is never shown).
            'version' => $artworkOk ? dashboard_custom_mod_server_version($folder) : '',
            'url' => $base . $entry['dashboard_path'],
            'icon' => $artworkOk ? dashboard_custom_mod_image($folder, $assets['icon'] ?? null, $base, $version) : '',
            'banner' => $artworkOk ? dashboard_custom_mod_image($folder, $assets['banner'] ?? null, $base, $version) : '',
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
        ];
    }
    usort($mods, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return ['readable' => true, 'mods' => $mods, 'unavailable' => $unavailable];
}
