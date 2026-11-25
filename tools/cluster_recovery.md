# Full-cluster recovery

Restores a full `pg_dumpall` backup into a private PostgreSQL instance so it can be checked before anyone replaces the live cluster. Code: `lib/cluster_recovery.php` (Dashboard side), `lib/cluster_recovery_cli.php` (worker and operator commands), `api/cluster_recovery.php`.

## Setup and use

Recovery selects the single enabled cluster registered on port 5432. If no enabled cluster
uses 5432, it selects the only enabled cluster registered on another port and uses that
port throughout. Two enabled clusters on 5432, several enabled clusters elsewhere with
none on 5432, a malformed registration or missing server tools of the selected major are
refused. A disabled PostgreSQL 15 migration donor is ignored, and installing a newer server
package does not change the selected recovery version. Registration is inspected without
connecting to the production database.

1. Once, as root: `sudo php lib/cluster_recovery_cli.php setup` (optionally `--owner ACCOUNT`; default `www-data`). This creates `DWEMER_CLUSTER_RECOVERY_ROOT` (default `/var/lib/dwemer-cluster-recovery`), owned by the web account with mode 0700, outside the web root.
2. In the Dashboard, choose **Recover safely** on a listed full-cluster backup. A detached worker running as the web account protects a copy of the backup, scans it, creates a socket-only instance (`listen_addresses = ''`) under the recovery root, restores it with `psql`, counts the recovered events and stops the instance. It never connects to the live cluster.
3. Cancel or Discard from the Dashboard. Only one attempt exists at a time.

## Trusted backups only

The scan rejects psql meta-commands that `pg_dumpall` does not write, custom tablespaces, missing extensions and dumps without a completion marker. It is a sanity check, not a sandbox: SQL in the dump runs as superuser of the private instance, whose processes run as the web account.

`ON_ERROR_STOP` is off so the dump's `CREATE ROLE postgres` can fail against the bootstrap role. Any other error terminates `psql` once its stderr is read, so later statements may already have run in the private instance. That attempt is marked failed, never ready; discard it.

## Disk space

The Dashboard estimates the need as 1.6 × the SQL size + 2 GB (+ the SQL size again if the backup cannot be hard-linked). It is an estimate, not a guarantee. Under WSL, `df` and the Dashboard report the virtual disk, which can be far larger than the free space on the Windows drive that holds it. Check that Windows drive's free space before starting.

## Activation and rollback (operator only)

Run in the Distro terminal as root; each prints what it will do and requires a typed confirmation. There is no browser Activate button and the web account has no sudo.

- `sudo php lib/cluster_recovery_cli.php activate JOB_ID` stops the selected PostgreSQL cluster (for example `17/dwemer` or `15/main`), keeps its data folder with a `.before-recovery-YYYYMMDD-HHMMSS` suffix, moves the recovered copy into place and starts that same cluster. If the copy does not start, the original is put back. The PostgreSQL major version must match and the recovery root must be on the same filesystem as the production data folder.
- `sudo php lib/cluster_recovery_cli.php rollback FOLDER.before-recovery-YYYYMMDD-HHMMSS` restores the kept cluster and keeps the replaced one as `FOLDER.rejected-recovery-…`. `FOLDER` is the last part of the selected cluster's data folder, so it is `dwemer.before-recovery-…` for `/var/lib/postgresql/17/dwemer` and `main.before-recovery-…` for `/var/lib/postgresql/15/main`. Activation prints the exact rollback command.

Both take the recovery root's `control.lock`, so a Dashboard start or discard cannot run meanwhile. Before inspecting or stopping production they also take Core's `/run/lock/dwemerdistro-maintenance.lock` exclusively (the same root-owned, mode 0644, single-link regular file Core's maintenance tools use; created like Core if missing, never replaced) and hold it until they finish. They refuse, changing nothing, when:

- the lock is held (for example an update or distro maintenance is running) or is not a valid lock file;
- `/etc/dwemer/trixie-upgrade.fence` exists (a Debian 13 upgrade is in progress);
- `/var/lib/dwemerdistro/pg17-maintenance` holds any PostgreSQL 17 migration journal (`*.json`), or cannot be checked.
- `/var/lib/dwemerdistro/trixie-maintenance/state.json` (Core's Debian 13 maintenance journal) exists in any form, including a symbolic link, or its absence cannot be proved (for example an unreadable folder).

**Journaled installations.** Core's PostgreSQL 17 migration records the identity of the cluster it manages (the `17/dwemer` candidate, or the `15/main` donor after a migration rollback). Replacing either data folder would invalidate that record, so activation and rollback are not available on any installation that has been through that migration, including completed ones. The journal is kept indefinitely; do not edit, move or delete it to get past this check. On those installations, recovery staging and inspection in the Dashboard still work, and the recovered copy can be checked, but putting it into service needs a clean PostgreSQL instance restored with `psql`. Installations with no migration journal and no Debian 13 maintenance journal (PostgreSQL 15 that was never migrated, or a fresh PostgreSQL 17 install not managed by Debian 13 maintenance) can activate and roll back.

**Debian 13 maintenance journal.** Core's Debian 13 maintenance, including a fresh PostgreSQL 17 install with no migration journal, binds the identity of the cluster it manages in `/var/lib/dwemerdistro/trixie-maintenance/state.json`. That journal can remain after the fence is gone, for example after a cancelled upgrade that still has a pending restore. Activation and rollback refuse whenever it exists, whatever its contents, so a retained journal from a finished or cancelled run also blocks them. They never read, update or delete it; do not edit, move or delete it to get past this check. Let Core's Debian 13 maintenance finish or resolve its own state; otherwise put the recovered copy into service on a clean PostgreSQL instance restored with `psql`.

Close every game and stop the mod servers first; afterwards check Playthrough Saves before loading the matching game save. Activation has not been tested end to end; never try it against data you need.

## Testing

`tools/cluster_recovery_fixture.sh` builds a disposable source cluster, dumps it and drives the real worker against a temporary recovery root. It does not run activation. Run it as an unprivileged account with a new work folder outside the repository, starting with the default smoke size:

```
tools/cluster_recovery_fixture.sh --work ~/cluster-recovery-smoke
tools/cluster_recovery_fixture.sh --work ~/cluster-recovery-1gb --target-gb 1 --keep
```

`--keep` retains the work folder, the final recovered job and copies of each discarded job's status and logs under `evidence/`. A failed run always keeps the work folder.
