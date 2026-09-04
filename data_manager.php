<?php
declare(strict_types=1);

// Shared "Storage & Cleanup" route. Read-only overview plus the mods' own
// management pages rendered natively as fragments (never framed, never proxied).
require_once __DIR__ . '/lib/storage_fragment.php';
require_once __DIR__ . '/lib/storage_manager_guard.php';

function dm_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

const DM_PAGE = 'data_manager.php';
const DM_MODS = ['chim', 'stobe', 'dialectic'];

$dmProducts = dm_products();

$dmModRequested = is_string($_GET['mod'] ?? null) ? strtolower(trim($_GET['mod'])) : 'all';
$dmViewRequested = is_string($_GET['view'] ?? null) ? strtolower(trim($_GET['view'])) : '';
[$dmMod, $dmView] = dm_resolve_route($dmModRequested, $dmViewRequested);

$dmQuery = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (mb_strlen($dmQuery, 'UTF-8') > 120) {
    $dmQuery = mb_substr($dmQuery, 0, 120, 'UTF-8');
}
$dmServer = dm_normalize_shared_server($_GET['server'] ?? $_POST['server'] ?? '');

$dmIsFragmentView = dm_is_fragment_view($dmMod, $dmView);
$dmFragment = $dmIsFragmentView ? dm_fragment_for($dmMod, $dmView, $dmServer) : null;
$dmFragmentHtml = null;
$dmFragmentNotice = '';
$dmGuard = null;

// The fragment runs before the shell emits anything, so its session start,
// headers, POST handling, redirects and file downloads all still work.
if ($dmFragment !== null) {
    $dmFragmentRoute = DM_PAGE . '?mod=' . rawurlencode($dmMod) . '&view=' . rawurlencode($dmView);
    if ($dmMod === DM_SHARED_MOD) {
        $dmFragmentRoute .= '&server=' . rawurlencode($dmServer);
        $_GET['server'] = $dmServer;
    }
    dm_fragment_begin($dmFragment, $dmFragmentRoute);
    try {
        $dmGuard = sm_guard($dmMod === DM_SHARED_MOD ? 'shared' : ($dmView === 'databases' ? 'database' : 'snapshots'), $dmMod);
        include $dmFragment['file'];
        $dmFragmentHtml = dm_fragment_end();
    } catch (Throwable $dmFragmentFailure) {
        dm_fragment_end();
        $dmFragmentHtml = null;
        $dmFragmentNotice = $dmFragmentFailure instanceof StorageManagerRequestException || $dmFragmentFailure instanceof StorageBackupException
            ? $dmFragmentFailure->getMessage()
            : 'These tools could not be opened. The server they belong to may be offline or mid-update.';
        error_log('[StorageManager] Fragment failed for ' . $dmMod . '/' . $dmView
            . ' (' . get_class($dmFragmentFailure) . ')');
    }
} elseif ($dmIsFragmentView) {
    $dmFragmentNotice = $dmMod === DM_SHARED_MOD
        ? 'The shared database tools need an installed CHIM or Dialectic server and could not be opened.'
        : 'These tools are not installed on this machine.';
}

$dmModLink = static function (string $mod) use ($dmView): string {
    $view = dm_is_fragment_view($mod, $dmView) || in_array($dmView, dm_routes()['views'][$mod] ?? [], true)
        ? $dmView
        : dm_default_view($mod);
    $url = DM_PAGE . '?mod=' . rawurlencode($mod);
    if ($mod !== 'all') {
        $url .= '&view=' . rawurlencode($view);
    }
    return $url;
};
$dmViewLink = static function (string $view) use ($dmMod, $dmQuery, $dmServer): string {
    $url = DM_PAGE . '?mod=' . rawurlencode($dmMod) . '&view=' . rawurlencode($view);
    if ($view === 'playthroughs' && $dmQuery !== '') {
        $url .= '&q=' . rawurlencode($dmQuery);
    }
    if ($dmMod === DM_SHARED_MOD) {
        $url .= '&server=' . rawurlencode($dmServer);
    }
    return $url;
};

$dmModTabs = ['all' => 'All'];
foreach (DM_MODS as $dmKey) {
    $dmModTabs[$dmKey] = $dmProducts[$dmKey]['label'];
}
$dmModTabs[DM_SHARED_MOD] = 'Shared databases';

$dmViewLabels = [
    'playthroughs' => 'Snapshots',
    'storage' => 'Storage',
    'manage' => 'Manage',
    'databases' => $dmMod === DM_SHARED_MOD ? 'All databases' : 'Database tools',
];
$dmViewTabs = dm_routes()['views'][$dmMod] ?? [];

$dmIsAll = $dmMod === 'all';
$dmIsShared = $dmMod === DM_SHARED_MOD;
$dmShowDetail = !$dmIsAll && !$dmIsShared && !$dmIsFragmentView;
$dmClientRoutes = json_encode([
    'views' => dm_routes()['views'],
    'aliases' => dm_routes()['aliases'],
    'fragments' => dm_routes()['fragments'],
], JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Storage &amp; Cleanup</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <?php foreach (dm_fragment_styles() as $dmStyle): ?>
        <link rel="stylesheet" href="<?= dm_h($dmStyle) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="css/data_manager.css">
</head>
<body
    data-page="<?= dm_h(DM_PAGE) ?>"
    data-endpoint="api/data_manager.php"
    data-mod="<?= dm_h($dmMod) ?>"
    data-view="<?= dm_h($dmView) ?>"
    data-server="<?= dm_h($dmServer) ?>"
    data-query="<?= dm_h($dmQuery) ?>"
    data-fragment="<?= $dmIsFragmentView ? '1' : '0' ?>"
    data-routes="<?= dm_h((string)$dmClientRoutes) ?>">

<div class="dm-shell">
    <header class="dm-head">
        <div class="dm-head-text">
            <h1>Storage &amp; Cleanup</h1>
            <p>Playthrough snapshots, stored data and database tools for CHIM, STOBE and Dialectic.
                Each mod uses its own database.</p>
        </div>
        <nav class="dm-head-links" aria-label="Related pages">
            <button type="button" id="dm-refresh"<?= $dmIsFragmentView ? ' hidden' : '' ?>>Refresh</button>
            <a href="distro_debugger.php">Logs</a>
            <a href="index.php">Back to Dashboard</a>
        </nav>
    </header>

    <nav class="dm-tabs dm-tabs-mod" aria-label="Mod">
        <?php foreach ($dmModTabs as $dmKey => $dmLabel): ?>
            <a class="dm-tab<?= $dmMod === $dmKey ? ' is-active' : '' ?>"
               data-mod-link="<?= dm_h($dmKey) ?>"
               <?= $dmMod === $dmKey ? 'aria-current="page"' : '' ?>
               href="<?= dm_h($dmModLink((string)$dmKey)) ?>"><?= dm_h($dmLabel) ?></a>
        <?php endforeach; ?>
    </nav>

    <nav class="dm-tabs dm-tabs-view" id="dm-view-tabs" aria-label="Task"<?= $dmViewTabs === [] ? ' hidden' : '' ?>>
        <?php foreach ($dmViewTabs as $dmKey): ?>
            <a class="dm-tab<?= $dmView === $dmKey ? ' is-active' : '' ?>"
               data-view-link="<?= dm_h($dmKey) ?>"
               <?= $dmView === $dmKey ? 'aria-current="page"' : '' ?>
               href="<?= dm_h($dmViewLink((string)$dmKey)) ?>"><?= dm_h($dmViewLabels[$dmKey] ?? $dmKey) ?></a>
        <?php endforeach; ?>
    </nav>

    <p class="dm-readonly" id="dm-readonly"<?= $dmIsAll ? '' : ' hidden' ?>>
        Read-only overview. Open a mod to use its tools.
    </p>

    <p class="dm-live" id="dm-live-region" role="status" aria-live="polite"></p>

    <noscript>
        <p class="dm-warn">The overview needs JavaScript to read database and snapshot details.
            The <strong>Manage</strong> and <strong>Database tools</strong> tabs work without it.</p>
    </noscript>

    <?php if ($dmIsFragmentView && !$dmIsShared): ?><div><?php else: ?><main><?php endif; ?>
        <section class="dm-overview" id="dm-overview"<?= $dmIsAll ? '' : ' hidden' ?>>
            <?php foreach (DM_MODS as $dmKey): ?>
                <article class="dm-card" data-card="<?= dm_h($dmKey) ?>" aria-busy="true">
                    <h2><?= dm_h($dmProducts[$dmKey]['label']) ?><span
                            class="dm-game"><?= dm_h($dmProducts[$dmKey]['game']) ?></span></h2>
                    <p class="dm-state" data-card-state>Loading&hellip;</p>
                    <dl class="dm-facts" data-card-facts></dl>
                    <a class="dm-open" data-mod-link="<?= dm_h($dmKey) ?>"
                       href="<?= dm_h(DM_PAGE . '?mod=' . rawurlencode($dmKey) . '&view=playthroughs') ?>">Open
                        <?= dm_h($dmProducts[$dmKey]['label']) ?></a>
                </article>
            <?php endforeach; ?>
            <article class="dm-card dm-card-shared">
                <h2>Shared databases<span class="dm-game">All mods</span></h2>
                <p class="dm-state">Backup, restore, maintenance and archive tools that are not limited to one
                    mod.</p>
                <a class="dm-open" data-mod-link="<?= dm_h(DM_SHARED_MOD) ?>"
                   href="<?= dm_h(DM_PAGE . '?mod=' . rawurlencode(DM_SHARED_MOD) . '&view=databases') ?>">Open shared
                    databases</a>
            </article>
        </section>

        <section class="dm-detail" id="dm-detail"<?= $dmShowDetail ? '' : ' hidden' ?>>
            <div class="dm-summary" id="dm-summary" aria-busy="true">
                <p class="dm-state" id="dm-summary-state">Loading&hellip;</p>
                <dl class="dm-facts" id="dm-summary-facts"></dl>
            </div>

            <div class="dm-view" data-view="playthroughs"<?= $dmView === 'playthroughs' ? '' : ' hidden' ?>>
                <form class="dm-search" id="dm-search" method="get" action="<?= dm_h(DM_PAGE) ?>" role="search">
                    <input type="hidden" name="mod" id="dm-search-mod" value="<?= dm_h($dmMod) ?>">
                    <input type="hidden" name="view" value="playthroughs">
                    <label for="dm-q">Search saved snapshots</label>
                    <input type="search" id="dm-q" name="q" value="<?= dm_h($dmQuery) ?>" maxlength="120"
                           autocomplete="off" placeholder="Snapshot or player name">
                    <button type="submit">Search</button>
                    <a class="dm-clear" id="dm-clear" href="<?= dm_h(DM_PAGE . '?mod=' . rawurlencode($dmMod) . '&view=playthroughs') ?>"<?= $dmQuery === '' ? ' hidden' : '' ?>>Clear</a>
                </form>

                <h2 class="dm-h">Saved snapshots</h2>
                <div id="dm-snapshots"></div>

                <nav class="dm-paging" id="dm-paging" aria-label="Snapshot pages" hidden>
                    <a class="dm-page-link" id="dm-prev" rel="prev" href="#" hidden>Previous 50</a>
                    <span id="dm-range"></span>
                    <a class="dm-page-link" id="dm-next" rel="next" href="#" hidden>Next 50</a>
                </nav>

                <p class="dm-note">Game dates and event counts describe each saved snapshot, not your current game.
                    Saved dates use the server's clock. Sizes were recorded when the snapshot was created.</p>
                <details class="dm-help">
                    <summary>About snapshots</summary>
                    <p>A snapshot is a saved copy of this mod's server data. It is not a game save and not a full
                        installation backup. Game date comes from the snapshot itself and stays <strong>Unknown</strong>
                        when the snapshot did not record one.</p>
                </details>
                <p class="dm-tool">
                    <a href="<?= dm_h($dmViewLink('manage')) ?>" class="dm-tool-link">Manage snapshots</a>
                </p>
            </div>

            <div class="dm-view" data-view="storage"<?= $dmView === 'storage' ? '' : ' hidden' ?>>
                <h2 class="dm-h">Stored data</h2>
                <div id="dm-storage"></div>
                <p class="dm-note">The database total includes live data, saved snapshots and internal storage, not
                    backup files or log files on disk. Row counts are estimates. Deleting rows may free space fo
                    reuse without making the database file smaller.</p>
                <details class="dm-help"><summary>What is safe to remove?</summary>
                    <p>These categories describe space usage, not a deletion plan. Events may still be needed to
                        build NPC memories. Use the cleanup tool's preview before removing anything.</p></details>
                <p class="dm-tool">
                    <a href="<?= dm_h($dmViewLink('manage') . ($dmMod === 'chim' ? '#retention-section' : '')) ?>"
                       class="dm-tool-link">Cleanup and snapshot tools</a>
                    <?php if (in_array('databases', $dmViewTabs, true)): ?>
                        <a href="<?= dm_h($dmViewLink('databases')) ?>" class="dm-tool-link">Database tools</a>
                    <?php endif; ?>
                    <a href="<?= dm_h(DM_PAGE . '?mod=' . rawurlencode(DM_SHARED_MOD) . '&view=databases') ?>"
                       class="dm-tool-link">Shared databases</a>
                </p>
            </div>
        </section>

        <?php if ($dmIsFragmentView): ?>
            <section class="dm-fragment-host" id="dm-fragment"
                     aria-label="<?= dm_h($dmFragment['heading'] ?? 'Tools') ?>">
                <?php if ($dmIsShared): ?>
                    <div class="dm-scope" role="note">
                        <h2 class="dm-h">Shared / all databases</h2>
                        <p><strong>These controls are not filtered by mod.</strong> Manual backup, restore,
                            maintenance and reset tools have different scopes. Manual exports and maintenance cover CHIM
                            and STOBE. Automatic archives can contain all three mods. Restoring a backup replaces the
                            databases identified in that file, including their saved snapshots.</p>
                        <p class="dm-warn">Stop game and server activity and keep a separate backup before restoring or resetting.
                            Combined restores are not atomic: an error can leave only some databases restored.</p>
                        <div class="dm-scope-switch">
                            <span id="dm-server-label">Show database versions for</span>
                            <span class="dm-tabs dm-tabs-server" role="group" aria-labelledby="dm-server-label">
                                <?php foreach (dm_shared_servers() as $dmServerKey => $dmServerLabel): ?>
                                    <a class="dm-tab<?= $dmServer === $dmServerKey ? ' is-active' : '' ?>"
                                       <?= $dmServer === $dmServerKey ? 'aria-current="true"' : '' ?>
                                       href="<?= dm_h(DM_PAGE . '?mod=' . rawurlencode(DM_SHARED_MOD) . '&view=databases&server=' . rawurlencode((string)$dmServerKey)) ?>"><?= dm_h($dmServerLabel) ?></a>
                                <?php endforeach; ?>
                            </span>
                        </div>
                        <p class="dm-note">Automatic backup settings are shared and stored with CHIM. The selector changes
                            the version list, not the scope of combined backup and maintenance actions.</p>
                    </div>
                <?php endif; ?>

                <?php if ($dmFragmentHtml !== null): ?>
                    <!-- The shared action guard binds to this container. -->
                    <div class="dm-fragment-body" id="sm-controls"
                         data-fragment-mod="<?= dm_h($dmMod) ?>"
                         data-fragment-view="<?= dm_h($dmView) ?>">
                        <?= $dmFragmentHtml ?>
                    </div>
                <?php else: ?>
                    <p class="dm-warn"><?= dm_h($dmFragmentNotice) ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php if ($dmIsFragmentView && !$dmIsShared): ?></div><?php else: ?></main><?php endif; ?>
</div>

<script src="js/data_manager.js" defer></script>
<?php if ($dmGuard !== null && $dmFragmentHtml !== null): ?>
<script type="application/json" id="sm-guard-config"><?= json_encode($dmGuard, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="js/storage_manager_guard.js" defer></script>
<?php endif; ?>
</body>
</html>
