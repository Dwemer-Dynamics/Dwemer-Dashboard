# Dwemer-Dashboard Agent Notes

## Playthrough Saves and database backups

- The Dashboard hosts navigation and storage tools for CHIM, STOBE and DIALECTIC. Each server owns its Playthrough Saves policy and switching implementation.
- [lib/storage_fragment.php](lib/storage_fragment.php) resolves product routes and embeds the relevant server manager. Preserve product selection, route allowlists and missing-server handling.
- Do not duplicate table lists here. Read each server's `AGENTS.md` and `lib/playthrough_policy.php` before changing a shared contract.
- Each server captures selected gameplay tables and gameplay rows from mixed settings tables. Global setup stays live; unmanaged plugin tables stay untouched.
- Selected public tables carry exactly `Playthrough Manager Backed Up`; excluded public table comments are NULL/blank. Server database updates synchronise these comments. The Dashboard must not infer backup membership from comments.
- New playthrough saves current progress and starts with empty managed gameplay data. Switching stages and validates data before transactional activation, using the server's runtime barrier and worker refresh. Route operations through the owning server; do not bypass its guards with Dashboard SQL.
- Older-save schema upgrades and missing-table compatibility belong to each server. Never substitute the currently active playthrough's data for missing historical data.
- Server playthrough switching does not change game saves or automatically match character names. Users close the game, switch the server playthrough, wait for confirmation, then load the matching game save.

## Distro scope

- Distro database backups are separate from selected-table Playthrough Saves.
- [lib/cluster_backup.php](lib/cluster_backup.php) implements unfiltered `pg_dumpall` for manual and automatic full PostgreSQL exports, including databases and cluster globals. Do not narrow this to the three mod databases or a table allowlist.
- [api/storage_action.php](api/storage_action.php) handles actions; [lib/storage_manager_actions.php](lib/storage_manager_actions.php) identifies backup scope. Preserve full-cluster versus legacy-backup detection.
- Full-cluster backups require `psql` restoration to a clean PostgreSQL instance. The mod restore tool must not pretend it can restore the whole cluster.
- [lib/storage_tools.php](lib/storage_tools.php) and [api/storage_tools.php](api/storage_tools.php) resolve backup locations and listings. Preserve path validation, backup identity checks and the established restore confirmation flow.
- Keep Distro controls scoped to the whole database/server operation and product-specific controls in the relevant mod section.

## Storage rules and validation

- Each server owns category accounting and cleanup in `lib/playthrough_categories.php` and `lib/playthrough_retention.php`. Keep Playthrough Storage categories and cleanup scopes aligned.
- Automatic older-game-save protection stays enabled; users adjust its in-game-day threshold. Cleanup evaluates enabled categories without a user-facing master toggle. Older-event cleanup is off by default; saved-copy retention has no maximum by default.
- For routing changes, check all three products and absent-server behaviour. For cleanup or restore changes, verify target scope and rejection paths in disposable databases and temporary backup directories.
- Never run destructive backup-restore, cleanup or fresh-playthrough tests against the user's active data. Do not treat an HTTP success as proof of restored content or in-game behaviour.
