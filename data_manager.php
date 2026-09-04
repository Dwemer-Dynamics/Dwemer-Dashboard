<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/data_manager.php';

function dm_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

const DM_PAGE = 'data_manager.php';
const DM_MODS = ['chim', 'stobe', 'dialectic'];
const DM_VIEWS = ['playthroughs', 'storage', 'backups', 'advanced'];

$dmProducts = dm_products();

$dmMod = is_string($_GET['mod'] ?? null) ? strtolower(trim($_GET['mod'])) : 'all';
if ($dmMod !== 'all' && !in_array($dmMod, DM_MODS, true)) {
    $dmMod = 'all';
}
$dmView = is_string($_GET['view'] ?? null) ? strtolower(trim($_GET['view'])) : 'playthroughs';
if (!in_array($dmView, DM_VIEWS, true)) {
    $dmView = 'playthroughs';
}
$dmQuery = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (mb_strlen($dmQuery, 'UTF-8') > 120) {
    $dmQuery = mb_substr($dmQuery, 0, 120, 'UTF-8');
}

$dmModLink = static function (string $mod) use ($dmView): string {
    return DM_PAGE . '?mod=' . rawurlencode($mod) . '&view=' . rawurlencode($dmView);
};
$dmViewLink = static function (string $view) use ($dmMod, $dmQuery): string {
    $url = DM_PAGE . '?mod=' . rawurlencode($dmMod) . '&view=' . rawurlencode($view);
    if ($view === 'playthroughs' && $dmQuery !== '') {
        $url .= '&q=' . rawurlencode($dmQuery);
    }
    return $url;
};

$dmModTabs = ['all' => 'All'];
foreach (DM_MODS as $dmKey) {
    $dmModTabs[$dmKey] = $dmProducts[$dmKey]['label'];
}
$dmViewTabs = [
    'playthroughs' => 'Playthroughs',
    'storage' => 'Storage &amp; cleanup',
    'backups' => 'Backups &amp; restore',
    'advanced' => 'Advanced',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Playthroughs &amp; data</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/data_manager.css">
</head>
<body
    data-page="<?= dm_h(DM_PAGE) ?>"
    data-endpoint="api/data_manager.php"
    data-mod="<?= dm_h($dmMod) ?>"
    data-view="<?= dm_h($dmView) ?>"
    data-query="<?= dm_h($dmQuery) ?>">

<div class="dm-shell">
    <header class="dm-head">
        <div class="dm-head-text">
            <h1>Playthroughs &amp; data</h1>
            <p>Saved snapshots and stored data for each mod. CHIM, STOBE and Dialectic each use their own database.</p>
        </div>
        <nav class="dm-head-links" aria-label="Related pages">
            <button type="button" id="dm-refresh">Refresh</button>
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

    <nav class="dm-tabs dm-tabs-view" id="dm-view-tabs" aria-label="Task"<?= $dmMod === 'all' ? ' hidden' : '' ?>>
        <?php foreach ($dmViewTabs as $dmKey => $dmLabel): ?>
            <a class="dm-tab<?= $dmView === $dmKey ? ' is-active' : '' ?>"
               data-view-link="<?= dm_h($dmKey) ?>"
               <?= $dmView === $dmKey ? 'aria-current="page"' : '' ?>
               href="<?= dm_h($dmViewLink((string)$dmKey)) ?>"><?= $dmLabel ?></a>
        <?php endforeach; ?>
    </nav>

    <p class="dm-readonly" id="dm-readonly"<?= $dmMod === 'all' ? '' : ' hidden' ?>>
        Read-only overview. Open a mod to use its tools.
    </p>

    <p class="dm-live" id="dm-live-region" role="status" aria-live="polite"></p>

    <noscript>
        <p class="dm-warn">This page needs JavaScript to read database and snapshot details.
            <a href="distro_debugger.php">Logs</a> and <a href="database_manager.php">Database Manager</a>
            still work without it.</p>
    </noscript>

    <main>
        <section class="dm-overview" id="dm-overview"<?= $dmMod === 'all' ? '' : ' hidden' ?>>
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
        </section>

        <section class="dm-detail" id="dm-detail"<?= $dmMod === 'all' ? ' hidden' : '' ?>>
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
                <p class="dm-tool" data-tool="snapshots"></p>
            </div>

            <div class="dm-view" data-view="storage"<?= $dmView === 'storage' ? '' : ' hidden' ?>>
                <h2 class="dm-h">Stored data</h2>
                <div id="dm-storage"></div>
                <p class="dm-note">The database total includes live data, saved snapshots and internal storage, not
                    backup files or log files on disk. Row counts are estimates. Deleting rows may free space for
                    reuse without making the database file smaller.</p>
                <details class="dm-help"><summary>What is safe to remove?</summary>
                    <p>These categories describe space usage, not a deletion plan. Events may still be needed to
                        build NPC memories. Use the cleanup tool's preview before removing anything.</p></details>
                <p class="dm-tool" data-tool="cleanup"></p>
            </div>

            <div class="dm-view" data-view="backups"<?= $dmView === 'backups' ? '' : ' hidden' ?>>
                <h2 class="dm-h">Backups &amp; restore</h2>
                <p class="dm-note">Backup files are managed separately. A snapshot in the same database is not an
                    independent backup.</p>
                <p class="dm-note" id="dm-backup-scope"></p>
                <p class="dm-tool" data-tool="backup"></p>
                <details class="dm-help">
                    <summary>What a backup covers</summary>
                    <p>The backup tool works on server databases. It does not cover your game saves, mod files or the
                        rest of your installation.</p>
                </details>
            </div>

            <div class="dm-view" data-view="advanced"<?= $dmView === 'advanced' ? '' : ' hidden' ?>>
                <h2 class="dm-h">Advanced</h2>
                <p class="dm-warn">These are the existing maintenance tools. They can replace or remove stored data, so
                    open them only when you know what you are changing.</p>
                <p class="dm-note" id="dm-advanced-scope"></p>
                <p class="dm-tool" data-tool="advanced"></p>
            </div>
        </section>
    </main>
</div>

<script src="js/data_manager.js" defer></script>
</body>
</html>
