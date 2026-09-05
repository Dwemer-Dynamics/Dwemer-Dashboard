<?php
declare(strict_types=1);

/**
 * Shared "Playthrough Management" routing and native fragment rendering.
 *
 * A fragment is the ORIGINAL manager page from a mod server (or the legacy
 * Dashboard database manager) included in-process by the shared route. It is
 * included before the shared shell emits anything, so the manager keeps full
 * control of session start, headers, POST handling, redirects and downloads.
 *
 * Only ONE manager may be included per request: these pages declare global
 * helpers (h(), formatFileSize(), ...) that would collide with each other.
 */

require_once __DIR__ . '/data_manager.php';

const DM_SHARED_MOD = 'shared';

/** Single source of truth for mod/task routing; mirrored to the browser as JSON. */
function dm_routes(): array
{
    return [
        'views' => [
            'all' => [],
            'chim' => ['playthroughs', 'storage', 'manage'],
            'stobe' => ['playthroughs', 'storage', 'manage', 'databases'],
            'dialectic' => ['playthroughs', 'storage', 'manage'],
            'shared' => ['databases'],
        ],
        // Deep links from the retired manager pages keep working.
        'aliases' => [
            'all' => ['backups' => ['shared', 'databases'], 'advanced' => ['shared', 'databases']],
            'chim' => ['backups' => ['shared', 'databases'], 'advanced' => ['shared', 'databases']],
            'stobe' => ['backups' => ['stobe', 'databases'], 'advanced' => ['stobe', 'databases']],
            'dialectic' => ['backups' => ['shared', 'databases'], 'advanced' => ['shared', 'databases']],
            'shared' => ['backups' => ['shared', 'databases'], 'advanced' => ['shared', 'databases']],
        ],
        // Views rendered from server-owned markup: these always use full navigation.
        'fragments' => [
            'chim' => ['manage'],
            'stobe' => ['manage', 'databases'],
            'dialectic' => ['manage'],
            'shared' => ['databases'],
        ],
    ];
}

function dm_default_view(string $mod): string
{
    $views = dm_routes()['views'][$mod] ?? [];
    return $views === [] ? 'playthroughs' : $views[0];
}

/** Resolve a requested mod/view pair, following aliases. Returns [mod, view]. */
function dm_resolve_route(string $mod, string $view): array
{
    $routes = dm_routes();
    if (!isset($routes['views'][$mod])) {
        $mod = 'all';
    }
    $alias = $routes['aliases'][$mod][$view] ?? null;
    if (is_array($alias)) {
        return [$alias[0], $alias[1]];
    }
    if ($mod === 'all') {
        return ['all', 'playthroughs'];
    }
    if (!in_array($view, $routes['views'][$mod], true)) {
        return [$mod, dm_default_view($mod)];
    }
    return [$mod, $view];
}

function dm_is_fragment_view(string $mod, string $view): bool
{
    return in_array($view, dm_routes()['fragments'][$mod] ?? [], true);
}

/** URL prefix in front of /Dwemer-Dashboard, so links work under a sub-path install. */
function dm_url_prefix(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#^((?:/[A-Za-z0-9_-]+)*)/Dwemer-Dashboard/#', $script, $match) === 1) {
        return $match[1];
    }
    return '';
}

function dm_dashboard_web_root(): string
{
    return dm_url_prefix() . '/Dwemer-Dashboard';
}

/** Legacy Dashboard database manager: which server's config and version list to show. */
function dm_shared_servers(): array
{
    return [
        'herika' => 'CHIM (HerikaServer)',
        'dialectic' => 'DIALECTIC (DialecticServer)',
    ];
}

function dm_normalize_shared_server(mixed $value): string
{
    $raw = is_string($value) ? strtolower(trim($value)) : '';
    return in_array($raw, ['dialectic', 'dialecticserver'], true) ? 'dialectic' : 'herika';
}

/**
 * Describe the native fragment for a route, or null when the files are not installed.
 * Asset roots are mod-qualified here so nothing depends on the browser's base URL.
 */
function dm_fragment_for(string $mod, string $view, string $server = 'herika'): ?array
{
    if (!dm_is_fragment_view($mod, $view)) {
        return null;
    }

    if ($mod === DM_SHARED_MOD) {
        $file = dirname(__DIR__) . '/database_manager.php';
        // That page exits early when its selected server tree is missing, which
        // would replace the whole shared page with a bare error string.
        $required = $server === 'dialectic' ? 'DialecticServer' : 'HerikaServer';
        if (!is_file($file) || dm_server_root($required) === null) {
            return null;
        }
        return [
            'mod' => $mod,
            'view' => $view,
            'file' => $file,
            'web_root' => dm_dashboard_web_root(),
            'heading' => 'Shared database tools',
            'source' => 'Dwemer-Dashboard/database_manager.php',
        ];
    }

    $products = dm_products();
    if (!isset($products[$mod])) {
        return null;
    }
    $directory = $products[$mod]['dir'];
    $root = dm_server_root($directory);
    if ($root === null) {
        return null;
    }
    if (!is_file($root . '/lib/storage_manager_route.php')) return null;
    $relative = $view === 'databases' ? 'ui/database_manager.php' : 'ui/playthrough_manager.php';
    $file = $root . '/' . $relative;
    if (!is_file($file)) {
        return null;
    }
    $headings = [
        'manage' => $products[$mod]['label'] . ' playthrough tools',
        'databases' => $products[$mod]['label'] . ' database tools',
    ];
    return [
        'mod' => $mod,
        'view' => $view,
        'file' => $file,
        'web_root' => dm_url_prefix() . '/' . $directory,
        'heading' => $headings[$view] ?? $products[$mod]['label'],
        'source' => $directory . '/' . $relative,
    ];
}

/**
 * Stylesheets a fragment needs. Collected during the include and printed in the
 * shared <head>, so the mod's own styling loads before the shell's overrides.
 */
function dwemer_storage_fragment_style(string $href): void
{
    if (!isset($GLOBALS['dm_fragment_styles']) || !is_array($GLOBALS['dm_fragment_styles'])) {
        $GLOBALS['dm_fragment_styles'] = [];
    }
    // Server-authored URLs only: same-origin absolute paths or https.
    if (preg_match('#^(/[^/]|https://)#', $href) !== 1) {
        return;
    }
    if (!in_array($href, $GLOBALS['dm_fragment_styles'], true)) {
        $GLOBALS['dm_fragment_styles'][] = $href;
    }
}

function dm_fragment_styles(): array
{
    $styles = $GLOBALS['dm_fragment_styles'] ?? [];
    return is_array($styles) ? $styles : [];
}

/**
 * Start capturing a fragment. The caller must include the manager at global
 * scope, so the manager's own globals behave exactly as they do standalone.
 */
function dm_fragment_begin(array $fragment, string $route): void
{
    if (!defined('DWEMER_STORAGE_FRAGMENT')) {
        define('DWEMER_STORAGE_FRAGMENT', true);
        define('DWEMER_STORAGE_FRAGMENT_MOD', $fragment['mod']);
        define('DWEMER_STORAGE_FRAGMENT_VIEW', $fragment['view']);
        define('DWEMER_STORAGE_FRAGMENT_WEBROOT', $fragment['web_root']);
        define('DWEMER_STORAGE_FRAGMENT_ROUTE', $route);
    }
    $GLOBALS['dm_fragment_styles'] = [];
    $GLOBALS['dm_fragment_ob_level'] = ob_get_level();
    ob_start();
}

/** Collect the captured markup, unwinding any buffer the fragment left open. */
function dm_fragment_end(): string
{
    $base = (int)($GLOBALS['dm_fragment_ob_level'] ?? 0);
    $html = '';
    while (ob_get_level() > $base) {
        $chunk = ob_get_clean();
        $html = (is_string($chunk) ? $chunk : '') . $html;
    }
    return $html;
}
