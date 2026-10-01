#!/usr/bin/env bash
# Disposable end-to-end check of full-cluster recovery. It builds a throwaway "active" PostgreSQL
# cluster, seeds several databases, dumps them with pg_dumpall and drives the production recovery code
# (cr_start -> background worker) against a temporary recovery root. Every instance uses a private socket
# folder under --work and no TCP listener; the Distro's live cluster is only observed, never contacted.
# Start with the default smoke size. ~31 GB needs an estimated 145 GB free (under WSL, on the Windows drive too) and several hours.
set -euo pipefail
usage() { echo "Usage: $0 --work NEW_DIR [--target-gb 0.05] [--skip-lifecycle] [--keep]" >&2; exit 64; }
WORK='' TARGET_GB=0.05 LIFECYCLE=1 KEEP=0 PASSED=0
while [ $# -gt 0 ]; do
    case "$1" in
        --work) WORK=${2:-}; shift 2 ;; --target-gb) TARGET_GB=${2:-}; shift 2 ;;
        --skip-lifecycle) LIFECYCLE=0; shift ;; --keep) KEEP=1; shift ;; *) usage ;;
    esac
done
[ -n "$WORK" ] && [[ "$TARGET_GB" =~ ^[0-9]+(\.[0-9]+)?$ ]] || usage
[ "$(id -u)" != 0 ] || { echo 'Run as an unprivileged account; PostgreSQL refuses to run as root.' >&2; exit 1; }
REPO=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(realpath -m "$WORK")
case "$WORK/" in "$REPO"/*|/var/lib/postgresql/*|/var/www/*|/etc/*) echo 'Choose a work folder outside the repository, web root and PostgreSQL data.' >&2; exit 1 ;; esac
[ ! -e "$WORK" ] || [ -z "$(ls -A "$WORK")" ] || { echo "Work folder $WORK must be new or empty." >&2; exit 1; }
PHP=${PHP:-php}
PGBIN=$(ls -d /usr/lib/postgresql/*/bin | sort -V | tail -1)
"$PHP" -r 'exit(function_exists("posix_geteuid") && function_exists("pg_connect") && function_exists("pcntl_signal") ? 0 : 1);' \
    || { echo 'PHP CLI needs the posix, pcntl and pgsql extensions.' >&2; exit 1; }
SRC=$WORK/source SRC_RUN=$WORK/source-run SRC_PORT=55499 REC=$WORK/recovery BK=$WORK/backups LOG=$WORK/fixture.log
mkdir -p "$WORK" && chmod 700 "$WORK" && mkdir -m 700 "$SRC_RUN" "$REC" "$BK"
export DWEMER_CLUSTER_RECOVERY_ROOT=$REC
say() { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*" | tee -a "$LOG"; }
fail() { say "FAIL: $*"; exit 1; }
need=$(awk -v g="$TARGET_GB" 'BEGIN { printf "%d", (g * 4.5 + 5) * 1024 ^ 3 }')
free=$(df -B1 --output=avail "$WORK" | tail -1)
[ "$free" -ge "$need" ] || fail "Need about $((need / 1024 ** 3)) GB free in $WORK; $((free / 1024 ** 3)) GB available."
if grep -qi microsoft /proc/version 2>/dev/null; then
    say "WSL: df reports the virtual disk, not Windows. Check that the Windows drive holding the WSL disk also has about $((need / 1024 ** 3)) GB free."
fi

cleanup() {
    "$PGBIN/pg_ctl" stop -D "$SRC" -m fast >/dev/null 2>&1 || true
    for data in "$REC"/jobs/*/data; do [ -f "$data/postmaster.pid" ] && "$PGBIN/pg_ctl" stop -D "$data" -m immediate >/dev/null 2>&1; done || true
    if [ "$PASSED" = 1 ] && [ "$KEEP" = 0 ]; then rm -rf --one-file-system -- "$WORK"; echo "Removed $WORK."; else echo "Kept $WORK for inspection (log: $LOG)."; fi
}
trap cleanup EXIT

# Read-only fingerprint of the Distro's own clusters: status and postmaster PIDs must not change.
live_snapshot() { { pg_lsclusters 2>/dev/null || true; cat /var/run/postgresql/*.pid 2>/dev/null || true; } | sha256sum | cut -c1-16; }
LIVE_BEFORE=$(live_snapshot)

# Production code path: the same functions api/cluster_recovery.php calls.
cr() { "$PHP" -d display_errors=stderr -r 'require $argv[1]; echo json_encode(($argv[2])(...array_slice($argv, 3))), "\n";' -- "$REPO/lib/cluster_recovery.php" "$@"; }
field() { "$PHP" -r '$v = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) $v = is_array($v) ? ($v[$k] ?? null) : null; echo is_scalar($v) ? $v : json_encode($v);' -- "$1"; }
job_id() { cr cr_view | field job.id; }
psrc() { "$PGBIN/psql" -X -q -v ON_ERROR_STOP=1 -h "$SRC_RUN" -p "$SRC_PORT" -U postgres "$@"; }

# Waits for a state. Returns 1 if the job ends in another terminal state, 2 on timeout.
wait_state() {
    local want=$1 limit=$2 began=$SECONDS last='' view state line
    while :; do
        view=$(cr cr_view); state=$(field job.state <<<"$view")
        line="$state $(field job.progress.done <<<"$view")/$(field job.progress.total <<<"$view")"
        [ "$line" = "$last" ] || say "  state: $line"; last=$line
        [[ "$state" =~ ^($want)$ ]] && return 0
        [[ "$state" =~ ^(ready|failed|cancelled|interrupted|activated)$ ]] && { say "  ended: $(field job.error <<<"$view")"; return 1; }
        [ $((SECONDS - began)) -lt "$limit" ] || return 2
        sleep 1
    done
}

fingerprint() { # socket-dir port
    "$PGBIN/psql" -X -At -h "$1" -p "$2" -U postgres -d postgres -c "SELECT 'role:'||rolname||':'||rolsuper||':'||rolcanlogin FROM pg_roles WHERE rolname !~ '^pg_' ORDER BY 1"
    for db in dwemer stobe dialectic fixture_extra; do
        "$PGBIN/psql" -X -At -v ON_ERROR_STOP=1 -h "$1" -p "$2" -U postgres -d "$db" <<'SQL'
SELECT 'ext:'||current_database()||':'||extname FROM pg_extension ORDER BY 1;
SELECT 'idx:'||current_database()||':'||indexname FROM pg_indexes WHERE schemaname NOT IN ('pg_catalog','information_schema') ORDER BY 1;
SELECT 'seq:'||current_database()||':'||sequencename||':'||coalesce(last_value,0) FROM pg_sequences ORDER BY 1;
SELECT format('SELECT %L||'':''||count(*)||'':''||coalesce(sum(hashtextextended(t::text,0)),0) FROM %s t', 'tbl:'||current_database()||':'||c.oid::regclass::text, c.oid::regclass)
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.relkind='r' AND n.nspname NOT IN ('pg_catalog','information_schema') ORDER BY 1 \gexec
SQL
    done
}

assert_stopped() { [ ! -f "$REC/jobs/$1/data/postmaster.pid" ] || fail "private instance for $1 is still running"; }
# With --keep, each job's status and logs are copied to evidence/ before discard deletes them.
discard() {
    if [ "$KEEP" = 1 ]; then mkdir -p "$WORK/evidence/$1"; cp -p "$REC/jobs/$1"/*.json "$REC/jobs/$1"/*.log "$WORK/evidence/$1/" 2>/dev/null || true; fi
    cr cr_discard "$1" >/dev/null; [ ! -e "$REC/jobs/$1" ] || fail "discard left $REC/jobs/$1"
}

# ---- 1. Disposable "active" cluster with deterministic data ----
say "Creating disposable source cluster with PostgreSQL $("$PGBIN/postgres" --version | awk '{print $3}') (target ${TARGET_GB} GB of SQL)."
"$PGBIN/initdb" -D "$SRC" -U postgres --auth-local=trust --auth-host=reject -E UTF8 --locale=C.UTF-8 >>"$LOG"
printf "listen_addresses = ''\nunix_socket_directories = '%s'\nport = %s\nsynchronous_commit = off\nmax_wal_size = '4GB'\n" "$SRC_RUN" "$SRC_PORT" >>"$SRC/postgresql.conf"
"$PGBIN/pg_ctl" start -D "$SRC" -l "$WORK/source.log" -w >>"$LOG"
psrc -d postgres -c "CREATE ROLE dwemer LOGIN SUPERUSER PASSWORD 'fixture-only-secret'; CREATE ROLE fixture_reader LOGIN PASSWORD 'fixture-only-secret';"
for db in dwemer stobe dialectic fixture_extra; do psrc -d postgres -c "CREATE DATABASE $db OWNER dwemer"; done
for db in dwemer stobe dialectic; do
    psrc -d "$db" -c "CREATE TABLE public.eventlog (rowid bigserial PRIMARY KEY, type varchar(128), data text, sess text, gamets bigint NOT NULL,
        localts bigint NOT NULL, ts bigint, people text, location text, party text);
        CREATE INDEX eventlog_gamets_idx ON public.eventlog (gamets); CREATE INDEX eventlog_type_idx ON public.eventlog (type);
        CREATE TABLE public.memory_summary (id serial PRIMARY KEY, summary text NOT NULL, tags text[], created bigint);
        CREATE SCHEMA ${db}_meta; CREATE TABLE ${db}_meta.settings (key text PRIMARY KEY, value text);
        INSERT INTO ${db}_meta.settings VALUES ('fixture', '$db');"
done
if [ -n "$(psrc -d dwemer -At -c "SELECT 1 FROM pg_available_extensions WHERE name='vector'")" ]; then
    psrc -d dwemer -c "CREATE EXTENSION vector; CREATE TABLE public.embeddings (id int PRIMARY KEY, v vector(4));
        INSERT INTO public.embeddings SELECT g, ARRAY[g, g*2, g%7, 1]::real[]::vector FROM generate_series(1,5000) g;"
fi
psrc -d fixture_extra -c "CREATE TABLE public.blobs (id bigint PRIMARY KEY, payload bytea NOT NULL)"
rows_for() { awk -v g="$TARGET_GB" -v s="$1" -v b="$2" 'BEGIN { r = int(g * s * 1024 ^ 3 / b); print (r < 1000 ? 1000 : r) }'; }
fill() { # db rows sql-with-$A-$B
    local db=$1 rows=$2 sql=$3 at=0 n
    while [ "$at" -lt "$rows" ]; do
        n=$((rows - at < 500000 ? rows - at : 500000))
        psrc -d "$db" -c "${sql//@RANGE@/generate_series($((at + 1)),$((at + n))) g}"
        at=$((at + n))
    done
}
EVENT_SQL="INSERT INTO public.eventlog (type,data,sess,gamets,localts,ts,people,location,party)
    SELECT (ARRAY['inputtext','chat','infoloc','death'])[1+g%4], repeat(md5(g::text),26), 'fixture', 1000000+g*37, 1700000000+g, g, 'Lydia,Hadvar', 'Whiterun '||(g%97), '' FROM @RANGE@"
began=$SECONDS
for spec in dwemer:0.68 stobe:0.15 dialectic:0.10; do
    db=${spec%%:*}; rows=$(rows_for "${spec#*:}" 1000)
    say "Seeding $db with $rows events."
    fill "$db" "$rows" "$EVENT_SQL"
    fill "$db" $((rows / 50 + 1)) "INSERT INTO public.memory_summary (summary,tags,created) SELECT repeat('memory '||g, 20), ARRAY['a'||g%5,'b'||g%11], g FROM @RANGE@"
done
fill fixture_extra "$(rows_for 0.07 900)" "INSERT INTO public.blobs SELECT g, decode(repeat(md5(g::text),13),'hex') FROM @RANGE@"
say "Seeded in $((SECONDS - began)) s."
SOURCE_BEFORE=$(fingerprint "$SRC_RUN" "$SRC_PORT" | sha256sum | cut -c1-16)
CHIM_EVENTS=$(psrc -d dwemer -At -c 'SELECT count(*) FROM public.eventlog')

# ---- 2. Full cluster dump, named like an automatic backup ----
DUMP=$BK/auto_backup_cluster_$(date +%Y-%m-%d_%H-%M-%S).sql
began=$SECONDS
"$PGBIN/pg_dumpall" -h "$SRC_RUN" -p "$SRC_PORT" -U dwemer >"$DUMP"
DUMP_BYTES=$(stat -c %s "$DUMP")
say "pg_dumpall wrote $DUMP_BYTES bytes ($(numfmt --to=iec "$DUMP_BYTES")) in $((SECONDS - began)) s."

# ---- 3. Rejection and failure paths (small handcrafted dumps) ----
mkdump() { printf -- '--\n-- PostgreSQL database cluster dump\n--\n\n%s\n\n--\n-- PostgreSQL database cluster dump complete\n--\n\n' "$2" >"$BK/$1"; }
expect_failure() { # file expected-error-substring
    local id
    cr cr_start "$BK/$1" "$1" manual >/dev/null; id=$(job_id)
    if wait_state failed 600; then :; else fail "$1 did not fail as expected"; fi
    cr cr_view | field job.error | grep -qF "$2" || fail "$1 failed with an unexpected message: $(cr cr_view | field job.error)"
    assert_stopped "$id"; discard "$id"; say "PASS rejected $1 ($2)."
}
mkdump missing_extension.sql 'CREATE EXTENSION IF NOT EXISTS dwemer_fixture_missing;'
mkdump psql_command.sql "\\! touch $WORK/meta-command-ran"
mkdump bad_sql.sql 'CREATE DATABASE fixture_bad; SELECT 1/0;'
head -c 65536 "$DUMP" >"$BK/truncated.sql"
expect_failure missing_extension.sql 'dwemer_fixture_missing'
expect_failure psql_command.sql 'psql command'
[ ! -e "$WORK/meta-command-ran" ] || fail 'a rejected psql command ran'
expect_failure bad_sql.sql 'division by zero'
expect_failure truncated.sql 'completion marker'

# ---- 4. Cancellation, single-job lock and worker loss on the real dump ----
if [ "$LIFECYCLE" = 1 ]; then
    cr cr_start "$DUMP" "$(basename "$DUMP")" automatic >/dev/null; id=$(job_id)
    if wait_state restoring 36000; then
        if cr cr_start "$BK/bad_sql.sql" bad_sql.sql manual >/dev/null 2>&1; then fail 'a second recovery started while one was active'; fi
        say 'PASS second recovery rejected while one is active.'
        if cr cr_cancel "$id" >/dev/null 2>&1; then
            wait_state cancelled 600 || fail 'cancellation did not finish'
            assert_stopped "$id"; say 'PASS cancel stopped psql and the private instance.'
        else say 'SKIP cancel: the restore finished before it could be cancelled (use a larger --target-gb).'; fi
    else say 'SKIP cancel: the restore finished before it could be cancelled (use a larger --target-gb).'; fi
    discard "$id"
    cr cr_start "$DUMP" "$(basename "$DUMP")" automatic >/dev/null; id=$(job_id)
    # Kill only a process whose command line is this job's worker, never a reused PID.
    kill_worker() { local pid; pid=$(field worker_pid <"$REC/jobs/$1/status.json")
        tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null | grep -qF "cluster_recovery_cli.php worker $1" && kill -9 "$pid"; }
    if wait_state restoring 36000 && kill_worker "$id"; then
        sleep 2; state=$(cr cr_view | field job.state)
        if [ "$state" = interrupted ]; then
            discard "$id"; say 'PASS killed worker reported as interrupted; discard stopped the orphaned instance.'
        elif [ "$state" = ready ]; then discard "$id"; say 'SKIP worker loss: the restore finished before the worker was killed.'
        else fail "a killed worker was reported as $state"; fi
    else discard "$id"; say 'SKIP worker loss: the restore finished too quickly.'; fi
fi

# ---- 5. Full recovery through the production worker ----
cr cr_start "$DUMP" "$(basename "$DUMP")" automatic >/dev/null; ID=$(job_id); JOB=$REC/jobs/$ID
say "Recovery job $ID started ($(cr cr_view | field job.staged || true))."
began=$SECONDS
wait_state 'restoring|inspecting|stopping|ready' 36000 || fail 'recovery ended before restoring'
[ "$(stat -c %a "$JOB/run")" = 700 ] || fail 'private socket folder is not mode 0700'
grep -q "^listen_addresses = ''" "$JOB/data/postgresql.conf" || fail 'private instance listens on TCP'
if command -v ss >/dev/null && ss -ltnH | grep -q ':55432 '; then fail 'something listens on TCP port 55432'; fi
say 'PASS private instance has no TCP listener and a 0700 socket folder.'
wait_state ready 43200 || fail 'recovery did not finish'
say "Recovery finished in $((SECONDS - began)) s."
VIEW=$(cr cr_view)
! grep -q 'fixture-only-secret' <<<"$VIEW" || fail 'status exposes a role password'
[ "$(field job.products.0.events <<<"$VIEW")" = "$CHIM_EVENTS" ] || fail "CHIM event count differs: $(field job.products.0.events <<<"$VIEW") vs $CHIM_EVENTS"
say "PASS inspection: CHIM $CHIM_EVENTS events, game time $(field job.products.0.gamets_first <<<"$VIEW")..$(field job.products.0.gamets_last <<<"$VIEW")."
"$PGBIN/pg_controldata" "$JOB/data" | grep -Eq '^Database cluster state:\s+shut down$' || fail 'recovered copy was not shut down cleanly'
"$PGBIN/pg_ctl" start -D "$JOB/data" -l "$WORK/recovered-check.log" -w >>"$LOG"
RECOVERED=$(fingerprint "$JOB/run" 55432 | sha256sum | cut -c1-16)
"$PGBIN/pg_ctl" stop -D "$JOB/data" -m fast -w >>"$LOG"
[ "$RECOVERED" = "$SOURCE_BEFORE" ] || fail "recovered fingerprint $RECOVERED differs from source $SOURCE_BEFORE"
say 'PASS recovered roles, extensions, indexes, sequences and per-table row hashes match the source.'
[ "$(fingerprint "$SRC_RUN" "$SRC_PORT" | sha256sum | cut -c1-16)" = "$SOURCE_BEFORE" ] || fail 'the source cluster changed'
[ "$(live_snapshot)" = "$LIVE_BEFORE" ] || fail 'the Distro live cluster status or PID changed during the test'
say "PASS source cluster and Distro live cluster unchanged. SQL bytes: $DUMP_BYTES. Activation was not run."
[ "$KEEP" = 1 ] || discard "$ID"
PASSED=1
