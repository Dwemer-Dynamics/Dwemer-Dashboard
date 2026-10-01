# Full-cluster recovery

Restores a full `pg_dumpall` backup into a private PostgreSQL instance so it can be checked before anyone replaces the live cluster. Code: `lib/cluster_recovery.php` (Dashboard side), `lib/cluster_recovery_cli.php` (worker and operator commands), `api/cluster_recovery.php`.

## Setup and use

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

- `sudo php lib/cluster_recovery_cli.php activate JOB_ID` stops PostgreSQL `main`, keeps the current data folder as `main.before-recovery-YYYYMMDD-HHMMSS`, moves the recovered copy into place and starts PostgreSQL. If the copy does not start, the original is put back. The PostgreSQL major version must match and the recovery root must be on the same filesystem as the production data folder.
- `sudo php lib/cluster_recovery_cli.php rollback main.before-recovery-YYYYMMDD-HHMMSS` restores the kept cluster and keeps the replaced one as `main.rejected-recovery-…`.

Both take the recovery root's `control.lock`, so a Dashboard start or discard cannot run meanwhile. Close every game and stop the mod servers first; afterwards check Playthrough Saves before loading the matching game save. Activation has not been tested end to end; never try it against data you need.

## Testing

`tools/cluster_recovery_fixture.sh` builds a disposable source cluster, dumps it and drives the real worker against a temporary recovery root. It does not run activation. Run it as an unprivileged account with a new work folder outside the repository, starting with the default smoke size:

```
tools/cluster_recovery_fixture.sh --work ~/cluster-recovery-smoke
tools/cluster_recovery_fixture.sh --work ~/cluster-recovery-1gb --target-gb 1 --keep
```

`--keep` retains the work folder, the final recovered job and copies of each discarded job's status and logs under `evidence/`. A failed run always keeps the work folder.
