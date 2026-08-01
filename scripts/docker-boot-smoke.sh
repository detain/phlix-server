#!/usr/bin/env bash
#
# S163 — the Docker BOOT gate.
#
# =============================================================================
# WHY THIS EXISTS
# =============================================================================
# `.github/workflows/docker.yml` used to consist of `docker/build-push-action`
# steps and nothing else. **`docker build` never executes `CMD`, `ENTRYPOINT` or
# `HEALTHCHECK`**, so every runtime-path defect in the images was invisible to
# all 14 green checks — and the images had, in fact, NEVER run the application:
# `docker/supervisord.conf` started `public/index.php` (the one-shot CGI front
# controller, which ignores argv and returns immediately) instead of the
# `start.php` Workerman daemon. Docker reported `Up 22 minutes` throughout a
# session that 502'd every request, because no image carried a `HEALTHCHECK`.
#
# `tests/Unit/Docker/DockerEntrypointTest.php` cannot cover this: it is host-side
# and asserts Dockerfile TEXT, so it cannot see a wrong binary name, a missing
# directory, a missing extension or a FATAL supervisord program.
#
# This script is the missing gate. It BOOTS the image against a throwaway MySQL
# and asserts the application actually serves. It fails on a reintroduction of
# any of the four S163 blockers, because all four end in the same place: nothing
# answering on the port the daemon owns.
#
# =============================================================================
# USAGE
# =============================================================================
#   scripts/docker-boot-smoke.sh <dockerfile> <image-tag> [--keep]
#
#   scripts/docker-boot-smoke.sh docker/Dockerfile        phlix-boot:latest
#   scripts/docker-boot-smoke.sh docker/Dockerfile.intel  phlix-boot:intel
#   scripts/docker-boot-smoke.sh docker/Dockerfile.nvidia phlix-boot:nvidia
#
# Environment:
#   DOCKER            docker command (default `docker`; use `sudo docker` on the
#                     dev box, where the daemon needs root)
#   PHLIX_BASE_IMAGE  base tag for docker/Dockerfile (default: built here from
#                     docker/Dockerfile.base, so a runtime image is NEVER booted
#                     against a stale registry base — the pipeline is two-stage)
#   SKIP_BUILD=1      reuse an already-built <image-tag> (local iteration only)
#   KEEP=1 / --keep   leave the containers up for inspection
#
# Networking rules this script obeys (dev box is a LIVE server):
#   * never `--network host`;
#   * never publish 3306 — MySQL is reachable only on a private bridge network;
#   * app ports are published on 127.0.0.1 at a port DOCKER chooses (`-p
#     127.0.0.1::8096`), never one this script picks — see finding 12 below.
#
# Everything it creates is suffixed with a per-run token and torn down by exact
# name, so it can never collide with, or delete, an unrelated container.
#
# ⚠ ASSERT 11 is DESTRUCTIVE: it deliberately drives a supervisord program into
# FATAL to prove the container dies with a non-zero exit code. It runs last.
# =============================================================================

set -euo pipefail

DOCKERFILE="${1:-docker/Dockerfile}"
IMAGE_TAG="${2:-phlix-boot-smoke:latest}"
KEEP="${KEEP:-0}"
if [ "${3:-}" = "--keep" ]; then KEEP=1; fi

DOCKER="${DOCKER:-docker}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

RUN_ID="s163$(date +%s)$$"
NET="phlix-boot-net-${RUN_ID}"
MYSQL_NAME="phlix-boot-mysql-${RUN_ID}"
APP_NAME="phlix-boot-app-${RUN_ID}"
MYSQL_PASSWORD="phlix_boot_${RUN_ID}"

# Loopback-only published ports, allocated by DOCKER, not by this script.
# (S163 review round 2, finding 12)
#
# This used to be `$(( 30000 + RANDOM % 20000 ))`. That range sits inside the
# kernel's `net.ipv4.ip_local_port_range` on every box I can find — 32768-60999
# is the kernel default (~85% of the draws) and this dev box is 5000-65535
# (100% of them) — so an outbound socket could already hold the port. `docker
# run -p` then fails under `set -e` before a single assertion runs, with no
# diagnostic: a flake the gate inflicts on itself.
#
# `-p 127.0.0.1::8096` hands the choice to Docker's own port allocator, which
# owns a reservation table and RETRIES on conflict. The real host ports are read
# back with `docker port` after the container starts.
HTTP_PORT=''
WS_PORT=''

BOOT_TIMEOUT="${BOOT_TIMEOUT:-180}"

# ASSERT 5's stability window. It must comfortably exceed one restart cycle of a
# crash-looping container: the reviewer's repro died ~2s after a 25s run, i.e. a
# ~27s cycle, and a single sample of that is RUNNING five times out of six.
# 90s at 15s intervals gives six samples across >3 cycles.
STABILITY_WINDOW="${STABILITY_WINDOW:-90}"
STABILITY_SAMPLE="${STABILITY_SAMPLE:-15}"

# A HEALTHCHECK whose start period outlives the gate can never report
# `unhealthy`, which makes the state decorative. Assert the image's start period
# stays under this. (S163 review F1)
MAX_START_PERIOD="${MAX_START_PERIOD:-120}"

# The supervisord program name that IS the application (docker/supervisord.conf).
APP_PROGRAM="${APP_PROGRAM:-phlix}"

# Where supervisord's config lives INSIDE the image (all three Dockerfiles).
# ASSERT 11 appends a deliberately-unstartable program to it.
SUPERVISORD_CONF_IN_IMAGE="${SUPERVISORD_CONF_IN_IMAGE:-/etc/supervisor/conf.d/supervisord.conf}"

FAILURES=0

# ===========================================================================
# THE CHECK REGISTRY — the gate's guard against ITSELF. (S163 review round 2,
# finding 1)
# ===========================================================================
# Round 2 shipped a `MAX_START_PERIOD` assertion that could never run: it fed
# `docker inspect -f '{{.Config.Healthcheck.StartPeriod}}'` (a Go time.Duration
# rendered through String(), i.e. "1m30s") into `$(( ))`. Under
# `set -euo pipefail` bash abandons the remainder of the enclosing block and
# carries on after `fi` with FAILURES UNCHANGED — it does not exit, and an ERR
# trap does not fire either (both verified). The gate printed
# "ALL ASSERTIONS PASSED" against an image whose start period had been reverted
# to the exact 180s value the previous round removed.
#
# The immediate bug is fixed below (use the `{{json …}}` form, which really is
# nanoseconds, and refuse a non-numeric value). But a gate that cannot detect
# its OWN skipped assertions cannot be trusted, and the tell was in plain sight:
# a healthy run printed "15/15 PASS" and the 16th check silently never ran.
#
# So every verdict this gate is REQUIRED to reach is named here. `pass`/`fail`
# take the check id as their first argument and record it. At the end, a check
# that produced NO verdict — for any reason at all: an arithmetic error, an
# early `break`, a branch nobody wrote — is itself a FAILURE. A check that
# produced TWO is a bug in this script and is also reported.
EXPECTED_CHECKS='
health
migrations
schema
supervisor-states
stability-program
stability-workers
daemon-process
no-cgi
ws-in-container
ws-published
spa-shell
spa-asset
spa-immutable
healthcheck-declared
healthcheck-healthy
healthcheck-start-period
platform-reqs
fatal-kills-container
fatal-exit-code-nonzero
'
RECORDED_CHECKS=''

# ---------------------------------------------------------------------------
say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
pass() { RECORDED_CHECKS="${RECORDED_CHECKS} $1"; printf '   \033[32mPASS\033[0m [%s] %s\n' "$1" "$2"; }
fail() {
    RECORDED_CHECKS="${RECORDED_CHECKS} $1"
    printf '   \033[31mFAIL\033[0m [%s] %s\n' "$1" "$2"
    FAILURES=$(( FAILURES + 1 ))
}

# Any number this gate compares MUST be a number. See the registry note above:
# an unparseable value fed to `$(( ))` or `[ -gt ]` is how a check silently
# stopped running. A value read out of `docker inspect`, `wc -l` or `grep -c`
# that is not a plain unsigned integer is therefore a FAILURE, never a skip.
is_uint() {
    case "${1:-}" in
        '' | *[!0-9]*) return 1 ;;
        *) return 0 ;;
    esac
}

# Sets the global UINT_VALUE and returns 0 when $2 is an unsigned integer;
# otherwise records a FAIL against check id $1 and returns 1.
#
# ⚠ Call it as `if uint_or_fail id "$v" 'why'; then … "$UINT_VALUE" … fi`,
# NEVER as `x="$(uint_or_fail …)"`. A command substitution runs in a SUBSHELL,
# so the `fail` inside it would increment FAILURES and append to the check
# registry in a COPY of the shell that is then discarded — the same
# "the verdict never reached the tally" class as finding 1 itself.
UINT_VALUE=0
uint_or_fail() {
    if is_uint "${2:-}"; then
        UINT_VALUE="$2"
        return 0
    fi
    UINT_VALUE=0
    fail "$1" "expected a number, got '${2:-<empty>}' (${3:-unparseable value}) — refusing to skip the check"
    return 1
}

# Every numeric tunable is overridable from the environment, so validate them
# HERE rather than discovering a non-numeric one halfway through a 5-minute run
# — where, per finding 1, it would abandon a block instead of failing.
for _tunable in BOOT_TIMEOUT STABILITY_WINDOW STABILITY_SAMPLE MAX_START_PERIOD \
                SUP_EXEC_RETRIES WORKER_NAME_CHURN_BUDGET WORKER_DROP_TOLERANCE; do
    eval "_tv=\${${_tunable}:-}"
    if [ -n "$_tv" ] && ! is_uint "$_tv"; then
        printf '   \033[31mFAIL\033[0m %s must be an unsigned integer, got %s\n' "$_tunable" "$_tv" >&2
        exit 1
    fi
done
unset _tunable _tv

# supervisorctl status is "NAME  STATE  extra…". Parse it BY COLUMN, never by
# grepping the whole line: the `exit-on-fatal` event listener has "fatal" in its
# NAME, so a line-wise `grep -i FATAL` reports a perfectly healthy container as
# broken. (Found by running this gate against the crash-loop repro.)
#
# Echoes "STATE PID UPTIME_SECONDS" for one program, or nothing if absent.
sup_program() {
    printf '%s\n' "$1" | awk -v prog="$2" '
        $1 == prog {
            state = $2; pid = "-"; up = -1
            for (i = 3; i <= NF; i++) {
                if ($i == "pid")    { pid = $(i + 1); gsub(/,/, "", pid) }
                if ($i == "uptime") { split($(i + 1), t, ":"); up = (t[1] * 3600) + (t[2] * 60) + t[3] }
            }
            print state, pid, up
            exit
        }'
}

# Every "NAME STATE" pair whose STATE is a real supervisor state.
sup_states() {
    printf '%s\n' "$1" | awk '
        NF >= 2 && $2 ~ /^(RUNNING|STARTING|STOPPING|STOPPED|EXITED|FATAL|BACKOFF|UNKNOWN)$/ { print $1 "=" $2 }'
}

# `docker exec` fails transiently on a loaded runner. Round 2's ASSERT 5 read
# an empty result as "the application left the RUNNING state" and FAILED with
# no retry — the same vacuous-negative shape round 1's F7 fixed in ASSERT 6 and
# nowhere else. A gate that randomly reddens gets switched off.
# (S163 review round 2, finding 8)
#
# The subtlety that makes `|| true` wrong here: `supervisorctl status` exits
# NON-ZERO whenever any program is not RUNNING, so a non-zero rc is a perfectly
# good answer. Distinguish by looking at the OUTPUT: if it parses as a status
# block, it is an answer; only unparseable output is an exec failure worth
# retrying. Echoes the status block and returns 0, or returns 1 after
# SUP_EXEC_RETRIES unparseable attempts.
SUP_EXEC_RETRIES="${SUP_EXEC_RETRIES:-3}"
sup_status_retry() {
    _sup_try=0
    while [ "$_sup_try" -lt "$SUP_EXEC_RETRIES" ]; do
        _sup_try=$(( _sup_try + 1 ))
        _sup_out="$($DOCKER exec "$APP_NAME" supervisorctl status 2>&1 || true)"
        if [ -n "$(sup_states "$_sup_out")" ]; then
            printf '%s' "$_sup_out"
            return 0
        fi
        [ "$_sup_try" -lt "$SUP_EXEC_RETRIES" ] && sleep 2
    done
    return 1
}

# "PID NAME" for every Workerman WORKER process in the container.
#
# ASSERT 5 watches the supervisord-level pid, which is the Workerman MASTER. A
# master that stays up while every worker exits and is immediately reforked is
# invisible to it — and that is the exact shape of blocker 6 (io_uring EPERM:
# `worker[phlix-server-http:102467] exit with status 256`, forever, master
# untouched). /health and :8097 happen to catch that particular case; the hub
# heartbeat, background timers, relay tunnel and the managed scan/asset workers
# have no such proxy. (S163 review round 2, finding 10)
worker_snapshot() {
    $DOCKER exec "$APP_NAME" ps -eo pid,args 2>/dev/null \
        | awk '/WorkerMan: worker process/ {
                   for (i = 1; i <= NF; i++) {
                       if ($i == "process") { print $1, $(i + 1); break }
                   }
               }' \
        | sort || true
}

dump_diagnostics() {
    say "DIAGNOSTICS — ${APP_NAME}"
    echo "--- docker ps ---"
    $DOCKER ps -a --filter "name=^${APP_NAME}$" --format '{{.Names}}\t{{.Status}}\t{{.Ports}}' || true
    echo "--- docker inspect .State ---"
    $DOCKER inspect --format '{{json .State}}' "$APP_NAME" 2>/dev/null || true
    echo "--- container logs (last 200) ---"
    $DOCKER logs --tail 200 "$APP_NAME" 2>&1 || true
    echo "--- supervisorctl status ---"
    $DOCKER exec "$APP_NAME" supervisorctl status 2>&1 || true
    echo "--- process table ---"
    $DOCKER exec "$APP_NAME" ps -eo pid,user,args 2>&1 | head -40 || true
    echo "--- /var/phlix/logs ---"
    $DOCKER exec "$APP_NAME" sh -c 'ls -la /var/phlix/logs 2>&1; tail -n 60 /var/phlix/logs/*.log 2>&1' || true
}

cleanup() {
    if [ "$KEEP" = "1" ]; then
        say "KEEP=1 — leaving ${APP_NAME} / ${MYSQL_NAME} / ${NET} up"
        return
    fi
    say "Teardown (exact names only)"
    # List before removing: a bare --filter has matched an unrelated container before.
    $DOCKER ps -a --filter "name=^${APP_NAME}$" --filter "name=^${MYSQL_NAME}$" \
        --format 'removing {{.Names}} ({{.Image}})' || true
    $DOCKER rm -f "$APP_NAME"   >/dev/null 2>&1 || true
    $DOCKER rm -f "$MYSQL_NAME" >/dev/null 2>&1 || true
    $DOCKER network rm "$NET"   >/dev/null 2>&1 || true
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
say "S163 boot gate — ${DOCKERFILE} -> ${IMAGE_TAG}"
info "run id     : ${RUN_ID}"
info "ports      : published on 127.0.0.1, allocated by Docker (read back after boot)"

# ---------------------------------------------------------------------------
# 1. Build. The pipeline is TWO-STAGE: docker/Dockerfile builds FROM the shared
#    base, so the base is rebuilt here first and pinned by tag. Testing a runtime
#    image against a stale registry base would prove nothing about this commit.
# ---------------------------------------------------------------------------
if [ "${SKIP_BUILD:-0}" != "1" ]; then
    BASE_TAG="${PHLIX_BASE_IMAGE:-}"
    if grep -q 'PHLIX_BASE_IMAGE' "${REPO_ROOT}/${DOCKERFILE}"; then
        if [ -z "$BASE_TAG" ]; then
            BASE_TAG="phlix-base-boot:${RUN_ID}"
            say "Building base image ${BASE_TAG} (docker/Dockerfile.base)"
            $DOCKER build --network host -f "${REPO_ROOT}/docker/Dockerfile.base" \
                -t "$BASE_TAG" "${REPO_ROOT}"
        fi
        say "Building ${IMAGE_TAG} from ${DOCKERFILE} (base=${BASE_TAG})"
        $DOCKER build --network host -f "${REPO_ROOT}/${DOCKERFILE}" \
            --build-arg "PHLIX_BASE_IMAGE=${BASE_TAG}" \
            -t "$IMAGE_TAG" "${REPO_ROOT}"
    else
        say "Building ${IMAGE_TAG} from ${DOCKERFILE}"
        $DOCKER build --network host -f "${REPO_ROOT}/${DOCKERFILE}" \
            -t "$IMAGE_TAG" "${REPO_ROOT}"
    fi
fi

# ---------------------------------------------------------------------------
# 2. Throwaway MySQL on a private bridge. 3306 is NEVER published.
# ---------------------------------------------------------------------------
say "Starting throwaway MySQL (${MYSQL_NAME}, unpublished)"
$DOCKER network create "$NET" >/dev/null
$DOCKER run -d --name "$MYSQL_NAME" --network "$NET" \
    -e MYSQL_ROOT_PASSWORD="root_${MYSQL_PASSWORD}" \
    -e MYSQL_DATABASE=phlix \
    -e MYSQL_USER=phlix \
    -e MYSQL_PASSWORD="$MYSQL_PASSWORD" \
    mysql:8.0 >/dev/null

# `--no-defaults` on every mysql* invocation: the dev box's ~/.my.cnf points at
# the PRODUCTION database.
#
# ⚠ Readiness MUST be probed over **TCP**, not the unix socket. (S163 review F2)
# `mysql:8.0` runs a TEMPORARY server during first-init that answers on the
# socket while TCP :3306 is still refused, so a socket `mysqladmin ping`
# returned "mysqld is alive" up to ~40s before the app could connect. The gate
# then booted the app too early, its whole migration chain failed with 114 ×
# `SQLSTATE[HY000] [2002] Connection refused`, and — because /health is DB-free
# — every assertion still passed against a container with NO SCHEMA.
# `--protocol=TCP` is what makes this probe mean what it says.
MYSQL_READY=0
for attempt in $(seq 1 80); do
    PINGOUT="$($DOCKER exec "$MYSQL_NAME" mysqladmin --no-defaults \
        --protocol=TCP -h 127.0.0.1 -P 3306 \
        -uroot -p"root_${MYSQL_PASSWORD}" ping 2>&1 || true)"
    if printf '%s' "$PINGOUT" | grep -q 'mysqld is alive'; then
        # Second gate: the application's OWN user must be able to reach the
        # application's OWN database over TCP. root-alive != app-can-connect.
        if $DOCKER exec "$MYSQL_NAME" mysql --no-defaults --protocol=TCP \
                -h 127.0.0.1 -P 3306 -uphlix -p"$MYSQL_PASSWORD" \
                -e 'SELECT 1' phlix >/dev/null 2>&1; then
            info "mysql ready over TCP after ${attempt} attempt(s)"
            MYSQL_READY=1
            break
        fi
    fi
    sleep 3
done
if [ "$MYSQL_READY" != "1" ]; then
    # Not a registry check: this is the gate's own fixture failing before any
    # assertion could run, and it exits immediately.
    printf '   \033[31mFAIL\033[0m throwaway MySQL never became TCP-ready: %s\n' \
        "$(printf '%s' "$PINGOUT" | tail -1)"
    $DOCKER logs --tail 20 "$MYSQL_NAME" 2>&1 || true
    exit 1
fi

# ---------------------------------------------------------------------------
# 3. Boot the image. Deliberately NO JWT_SECRET: `docker run` out of the box has
#    to work, and start.php refuses to boot without a signing key.
# ---------------------------------------------------------------------------
say "Booting ${APP_NAME}"
# `-p 127.0.0.1::8096` = "publish on loopback, YOU pick the host port".
# Docker's allocator owns a reservation table and retries on conflict, so this
# cannot lose a race with an outbound ephemeral socket the way a hand-rolled
# `$RANDOM` port did. (S163 review round 2, finding 12)
RUN_RC=0
RUN_OUT="$($DOCKER run -d --name "$APP_NAME" --network "$NET" \
    -p 127.0.0.1::8096 \
    -p 127.0.0.1::8097 \
    -e PHLIX_DATABASE_HOST="$MYSQL_NAME" \
    -e PHLIX_DATABASE_PORT=3306 \
    -e PHLIX_DATABASE_NAME=phlix \
    -e PHLIX_DATABASE_USER=phlix \
    -e PHLIX_DATABASE_PASSWORD="$MYSQL_PASSWORD" \
    "$IMAGE_TAG" 2>&1)" || RUN_RC=$?
if [ "$RUN_RC" -ne 0 ]; then
    # The old code had no diagnostic here at all: `set -e` simply ended the run.
    printf '   \033[31mFAIL\033[0m docker run exited %s — the container never started\n' "$RUN_RC"
    printf '%s\n' "$RUN_OUT"
    exit 1
fi

# Read the ports Docker actually chose. `docker port <name> 8096/tcp` prints
# `127.0.0.1:49153`; take the last colon-separated field.
HTTP_PORT="$($DOCKER port "$APP_NAME" 8096/tcp 2>/dev/null | head -1 | sed 's/.*://' | tr -d ' \r' || true)"
WS_PORT="$($DOCKER port "$APP_NAME" 8097/tcp 2>/dev/null | head -1 | sed 's/.*://' | tr -d ' \r' || true)"
if ! is_uint "$HTTP_PORT" || ! is_uint "$WS_PORT"; then
    printf '   \033[31mFAIL\033[0m could not read the published ports (http=%s ws=%s)\n' \
        "${HTTP_PORT:-<empty>}" "${WS_PORT:-<empty>}"
    $DOCKER port "$APP_NAME" 2>&1 || true
    dump_diagnostics
    exit 1
fi
info "http port  : 127.0.0.1:${HTTP_PORT} -> container 8096"
info "ws port    : 127.0.0.1:${WS_PORT} -> container 8097"

# ---------------------------------------------------------------------------
# 4. ASSERTION 1 — the application SERVES. This is the assertion that fails all
#    four S163 blockers at once: whether supervisord starts the CGI front
#    controller, or php-fpm FATALs on a missing binary, or fpm can't reopen its
#    log as `nobody`, or nginx fastcgi_passes a port nothing listens on, the
#    observable outcome is identical — nothing answers.
# ---------------------------------------------------------------------------
say "ASSERT 1/11 — GET /health returns 200 from the application"
HEALTH_BODY=''
deadline=$(( $(date +%s) + BOOT_TIMEOUT ))
while [ "$(date +%s)" -lt "$deadline" ]; do
    if HEALTH_BODY="$(curl -fsS --max-time 5 "http://127.0.0.1:${HTTP_PORT}/health" 2>/dev/null)"; then
        break
    fi
    if [ "$($DOCKER inspect -f '{{.State.Running}}' "$APP_NAME" 2>/dev/null)" != "true" ]; then
        info "container exited early"
        break
    fi
    HEALTH_BODY=''
    sleep 3
done

# The body is pretty-printed JSON (`"status": "ok"`), so the pattern must
# tolerate whitespace. An over-tight pattern here reported FAIL against a
# container that was serving a real 200 — a gate that lies is worse than none.
if printf '%s' "$HEALTH_BODY" | tr -d '\n' | grep -qE '"status"[[:space:]]*:[[:space:]]*"ok"'; then
    pass health "/health -> $(printf '%s' "$HEALTH_BODY" | tr -d '\n' | head -c 200)"
else
    fail health "/health never returned a healthy body within ${BOOT_TIMEOUT}s"
    echo "--- verbose curl ---"
    curl -sS -i --max-time 5 "http://127.0.0.1:${HTTP_PORT}/health" 2>&1 | head -20 || true
    dump_diagnostics
    exit 1
fi

# ---------------------------------------------------------------------------
# 4b. ASSERTION 2 — the MIGRATION STEP SUCCEEDED. (S163 review F2)
#
# /health is DB-free by design, so ASSERT 1 cannot tell "the app serves" from
# "the app serves against no schema whatsoever". The reviewer demonstrated
# exactly that: 114 × `Connection refused`, a `PHLIX-MIGRATION-FAILURE` banner,
# and 7/7 green. The entrypoint's default is to boot ANYWAY on a failed
# migration (a deliberate S159 decision — a crash-looping media server is a
# total outage), so the failure is only ever visible in its output. Read it.
# ---------------------------------------------------------------------------
say "ASSERT 2/11 — the migration step reported success"
BOOT_LOG="$($DOCKER logs "$APP_NAME" 2>&1 || true)"
if printf '%s' "$BOOT_LOG" | grep -q 'PHLIX-MIGRATION-FAILURE'; then
    fail migrations "the entrypoint printed PHLIX-MIGRATION-FAILURE — the schema is absent or half-applied"
    printf '%s\n' "$BOOT_LOG" | grep -aE 'PHLIX-MIGRATION-FAILURE|exited [0-9]+|SQLSTATE' | head -10 || true
elif printf '%s' "$BOOT_LOG" | grep -q 'PHLIX-MIGRATIONS-NOT-RUN'; then
    fail migrations "migrations were skipped entirely (PHLIX-MIGRATIONS-NOT-RUN)"
elif printf '%s' "$BOOT_LOG" | grep -q 'Skipping database migrations'; then
    fail migrations "migrations were skipped — the gate always configures a database host, so this is a defect"
elif printf '%s' "$BOOT_LOG" | grep -q 'Migrations complete.'; then
    pass migrations "migrations ran to completion"
else
    fail migrations "no migration outcome found in the boot log at all"
    printf '%s\n' "$BOOT_LOG" | head -20 || true
fi

# ---------------------------------------------------------------------------
# 4c. ASSERTION 3 — the SCHEMA really is there, reached with the APPLICATION's
#     own credentials from inside the container. The log says what the
#     entrypoint believed; this says what is actually in the database.
# ---------------------------------------------------------------------------
say "ASSERT 3/11 — the application can reach its database and the schema exists"
DB_PROBE_PHP=$(cat <<'PHP_PROBE'
$h = getenv("PHLIX_DATABASE_HOST"); $p = getenv("PHLIX_DATABASE_PORT") ?: "3306";
$d = getenv("PHLIX_DATABASE_NAME"); $u = getenv("PHLIX_DATABASE_USER");
$w = getenv("PHLIX_DATABASE_PASSWORD");
try {
    $pdo = new PDO("mysql:host={$h};port={$p};dbname={$d}", $u, $w,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $m = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
    $t = (int) $pdo->query("SHOW TABLES")->rowCount();
    $pdo->query("SELECT COUNT(*) FROM media_items")->fetchColumn();
    echo "DBPROBE_OK migrations={$m} tables={$t}\n";
} catch (Throwable $e) {
    echo "DBPROBE_FAIL " . $e->getMessage() . "\n";
}
PHP_PROBE
)
DB_PROBE_OUT="$($DOCKER exec "$APP_NAME" php -r "$DB_PROBE_PHP" 2>&1 || true)"
info "probe: $(printf '%s' "$DB_PROBE_OUT" | tr -d '\n' | head -c 300)"
DB_MIGRATIONS="$(printf '%s' "$DB_PROBE_OUT" | sed -n 's/.*migrations=\([0-9]*\).*/\1/p')"
DB_TABLES="$(printf '%s' "$DB_PROBE_OUT" | sed -n 's/.*tables=\([0-9]*\).*/\1/p')"
if ! printf '%s' "$DB_PROBE_OUT" | grep -q 'DBPROBE_OK'; then
    fail schema "the application cannot reach its database, or core tables are missing"
elif ! is_uint "$DB_MIGRATIONS" || ! is_uint "$DB_TABLES"; then
    # DBPROBE_OK without parseable counters means the probe output changed
    # shape. Do NOT fall through to the numeric comparison and do NOT skip.
    fail schema "DBPROBE_OK but the counters are unparseable (migrations='${DB_MIGRATIONS}' tables='${DB_TABLES}')"
elif [ "$DB_MIGRATIONS" -lt 1 ] || [ "$DB_TABLES" -lt 20 ]; then
    fail schema "schema looks empty (migrations=${DB_MIGRATIONS} tables=${DB_TABLES})"
else
    pass schema "schema present: ${DB_MIGRATIONS} applied migrations, ${DB_TABLES} tables, media_items queryable"
fi

# ---------------------------------------------------------------------------
# 5. ASSERTION 4 — every supervisord program is RUNNING (none FATAL/BACKOFF).
# ---------------------------------------------------------------------------
say "ASSERT 4/11 — supervisorctl status: all programs RUNNING"
# /health can answer before supervisord's `startsecs` window closes, so the
# program may legitimately still be STARTING for a few seconds. Wait that out —
# but only ONCE and briefly, and then require RUNNING. This loop used to be the
# bug: a program in a restart loop re-enters STARTING on every cycle, so
# "wait until it is not STARTING" hunted for the RUNNING window instead of
# failing on the loop. ASSERT 5 is what actually catches that; this one must
# not paper over it, so a program still STARTING when the wait expires FAILS.
SUP_STATUS=''
sdeadline=$(( $(date +%s) + 45 ))
while [ "$(date +%s)" -lt "$sdeadline" ]; do
    SUP_STATUS="$(sup_status_retry || true)"
    printf '%s' "$SUP_STATUS" | grep -q 'STARTING' || break
    sleep 3
done
echo "$SUP_STATUS"
SUP_PAIRS="$(sup_states "$SUP_STATUS" || true)"
SUP_BAD="$(printf '%s\n' "$SUP_PAIRS" | grep -E '=(STOPPED|EXITED|FATAL|BACKOFF|UNKNOWN|STOPPING)$' | tr '\n' ' ' || true)"
SUP_STARTING="$(printf '%s\n' "$SUP_PAIRS" | grep -E '=STARTING$' | tr '\n' ' ' || true)"
SUP_RUNNING="$(printf '%s\n' "$SUP_PAIRS" | grep -cE '=RUNNING$' || true)"

if [ -z "$SUP_PAIRS" ]; then
    fail supervisor-states "supervisorctl is unusable — no program states could be read"
elif [ -n "$SUP_BAD" ]; then
    fail supervisor-states "supervisord reports a non-RUNNING program: ${SUP_BAD}"
elif [ -n "$SUP_STARTING" ]; then
    fail supervisor-states "still STARTING after 45s — it is not staying up: ${SUP_STARTING}"
elif ! printf '%s\n' "$SUP_PAIRS" | grep -q "^${APP_PROGRAM}=RUNNING$"; then
    fail supervisor-states "the '${APP_PROGRAM}' program is not RUNNING (states: $(printf '%s' "$SUP_PAIRS" | tr '\n' ' '))"
else
    pass supervisor-states "${SUP_RUNNING} program(s) RUNNING, including '${APP_PROGRAM}'"
fi

# ---------------------------------------------------------------------------
# 5b. ASSERTION 5 — STABILITY OVER TIME. (S163 review F1)
#
# THE most important assertion in this file, and the one whose absence let the
# gate pass a container that crash-restarted forever: supervisord logged
# `exited: phlix (exit status 1; not expected)` / `spawned:` every ~27s
# indefinitely, `docker inspect` said `healthy`, and the gate printed
# ALL ASSERTIONS PASSED.
#
# Three separate things made a single sample worthless:
#   * `startretries` only counts starts that fail INSIDE `startsecs`, so a
#     process that dies AFTER startsecs never reaches FATAL — supervisord
#     restarts it forever and reports RUNNING in between;
#   * ASSERT 4 waited out STARTING, i.e. it waited for the good half of the
#     cycle;
#   * the HEALTHCHECK start period exceeded the gate's whole run.
#
# So: sample repeatedly over a real interval and require the SAME pid with
# MONOTONIC uptime throughout, plus no respawn recorded in supervisord's own
# log. The window must comfortably exceed one restart cycle.
# ---------------------------------------------------------------------------
say "ASSERT 5/11 — the application stays up (${STABILITY_WINDOW}s stability window)"
STABILITY_MARK_RAW="$($DOCKER exec "$APP_NAME" sh -c \
    'wc -l < /var/phlix/logs/supervisord.log 2>/dev/null || echo 0' 2>/dev/null | tr -d " \r\n" || true)"
# A non-numeric line count would be fed straight into `tail -n +$(( … ))`.
# Nothing that reaches arithmetic in this gate is allowed to be unparseable.
if is_uint "$STABILITY_MARK_RAW"; then
    STABILITY_MARK="$STABILITY_MARK_RAW"
else
    info "supervisord.log line count unreadable ('${STABILITY_MARK_RAW}') — treating the whole log as new"
    STABILITY_MARK=0
fi

STAB_EXEC_FAILS=0
if STAB_FIRST="$(sup_status_retry)"; then :; else
    STAB_FIRST=''
    STAB_EXEC_FAILS=$(( STAB_EXEC_FAILS + 1 ))
fi
# Track the APPLICATION program by name. Taking `head -1` of the pid lines
# tracked whichever program sorted first — the event listener — so a restarting
# application went unnoticed.
STAB_FIRST_FIELDS="$(sup_program "$STAB_FIRST" "$APP_PROGRAM" || true)"
STAB_FIRST_PID="$(printf '%s' "$STAB_FIRST_FIELDS" | awk '{print $2}')"
STAB_PREV_UPTIME=-1
STAB_OK=1
STAB_REASON=''
info "t=0s   ${APP_PROGRAM}: ${STAB_FIRST_FIELDS:-<absent>}"
if [ -z "$STAB_FIRST_FIELDS" ]; then
    STAB_OK=0
    if [ "$STAB_EXEC_FAILS" -gt 0 ]; then
        STAB_REASON="supervisorctl could not be read at all (${SUP_EXEC_RETRIES} attempts) — this is an INFRASTRUCTURE failure, not an application restart"
    else
        STAB_REASON="the '${APP_PROGRAM}' program is not in supervisord's status at all"
    fi
fi

# --- worker-level sampling (finding 10) ------------------------------------
WORKERS_T0="$(worker_snapshot)"
WORKER_COUNT_T0="$(printf '%s' "$WORKERS_T0" | grep -c . || true)"
is_uint "$WORKER_COUNT_T0" || WORKER_COUNT_T0=0
WORKER_SEEN="$WORKERS_T0"
WORKER_MIN="$WORKER_COUNT_T0"
info "t=0s   workers: ${WORKER_COUNT_T0} ($(printf '%s' "$WORKERS_T0" | awk '{print $2}' | sort | uniq -c | tr '\n' ' ' | tr -s ' '))"

stab_end_at=$(( $(date +%s) + STABILITY_WINDOW ))
while [ "$STAB_OK" = "1" ] && [ "$(date +%s)" -lt "$stab_end_at" ]; do
    sleep "$STABILITY_SAMPLE"
    STAB_ELAPSED=$(( STABILITY_WINDOW - (stab_end_at - $(date +%s)) ))

    # An exec failure is NOT a restart. Round 2 conflated them and reddened a
    # healthy container on a loaded runner. (S163 review round 2, finding 8)
    if ! STAB_NOW="$(sup_status_retry)"; then
        STAB_EXEC_FAILS=$(( STAB_EXEC_FAILS + 1 ))
        info "t=${STAB_ELAPSED}s   ${APP_PROGRAM}: <supervisorctl unreadable, exec failure #${STAB_EXEC_FAILS}>"
        if [ "$STAB_EXEC_FAILS" -ge 3 ]; then
            STAB_OK=0
            STAB_REASON="supervisorctl was unreadable ${STAB_EXEC_FAILS} times — the container is not inspectable (NOT the same as an application restart)"
            break
        fi
        continue
    fi

    STAB_FIELDS="$(sup_program "$STAB_NOW" "$APP_PROGRAM" || true)"
    STAB_NOW_STATE="$(printf '%s' "$STAB_FIELDS" | awk '{print $1}')"
    STAB_NOW_PID="$(printf '%s' "$STAB_FIELDS" | awk '{print $2}')"
    STAB_NOW_UPTIME="$(printf '%s' "$STAB_FIELDS" | awk '{print $3}')"
    is_uint "${STAB_NOW_UPTIME#-}" || STAB_NOW_UPTIME=-1

    WORKERS_NOW="$(worker_snapshot)"
    WORKER_COUNT_NOW="$(printf '%s' "$WORKERS_NOW" | grep -c . || true)"
    is_uint "$WORKER_COUNT_NOW" || WORKER_COUNT_NOW=0
    WORKER_SEEN="${WORKER_SEEN}
${WORKERS_NOW}"
    [ "$WORKER_COUNT_NOW" -lt "$WORKER_MIN" ] && WORKER_MIN="$WORKER_COUNT_NOW"
    info "t=${STAB_ELAPSED}s   ${APP_PROGRAM}: ${STAB_FIELDS:-<absent>}   workers: ${WORKER_COUNT_NOW}"

    if [ "$STAB_NOW_STATE" != "RUNNING" ]; then
        STAB_OK=0
        STAB_REASON="'${APP_PROGRAM}' left the RUNNING state (now '${STAB_NOW_STATE:-absent}')"
        break
    fi
    if [ -n "$STAB_FIRST_PID" ] && [ "$STAB_FIRST_PID" != "-" ] && [ "$STAB_NOW_PID" != "$STAB_FIRST_PID" ]; then
        STAB_OK=0
        STAB_REASON="pid changed ${STAB_FIRST_PID} -> ${STAB_NOW_PID}: the application RESTARTED"
        break
    fi
    if [ "$STAB_NOW_UPTIME" -lt "$STAB_PREV_UPTIME" ]; then
        STAB_OK=0
        STAB_REASON="uptime went BACKWARDS (${STAB_PREV_UPTIME}s -> ${STAB_NOW_UPTIME}s): the application RESTARTED"
        break
    fi
    STAB_PREV_UPTIME="$STAB_NOW_UPTIME"
done

# Independent second signal: supervisord's own log must not have recorded a
# respawn OF THE APPLICATION while we were watching.
#
# ⚠ Scope this to `$APP_PROGRAM`. Round 2 counted `spawned:`/`exited:` for ANY
# program, so a respawn of the `exit-on-fatal` event listener — which is
# `autorestart=true` and entirely harmless — reddened a perfectly healthy
# application. (S163 review round 2, finding 8)
#
# supervisord's wording, which is what these patterns match:
#   INFO spawned: 'phlix' with pid 141
#   WARN exited: phlix (exit status 1; not expected)
#   INFO gave up: phlix entered FATAL state, too many start retries too quickly
STAB_EVENT_RE="spawned: '${APP_PROGRAM}'|exited: ${APP_PROGRAM}[[:space:](]|${APP_PROGRAM} entered (FATAL|BACKOFF)"
STAB_NEW_EVENTS_RAW="$($DOCKER exec "$APP_NAME" sh -c \
    "tail -n +$(( STABILITY_MARK + 1 )) /var/phlix/logs/supervisord.log 2>/dev/null \
     | grep -cE \"${STAB_EVENT_RE}\" || true" 2>/dev/null | tr -d " \r\n" || true)"
if is_uint "$STAB_NEW_EVENTS_RAW"; then
    STAB_NEW_EVENTS="$STAB_NEW_EVENTS_RAW"
else
    # Unparseable: FAIL rather than treat it as "no events". A silent 0 here is
    # precisely the skip this round is about.
    STAB_NEW_EVENTS=0
    STAB_OK=0
    STAB_REASON="${STAB_REASON:-could not count supervisord events (got '${STAB_NEW_EVENTS_RAW}')}"
fi
if [ "$STAB_NEW_EVENTS" -gt 0 ]; then
    STAB_OK=0
    STAB_REASON="${STAB_REASON:-supervisord recorded ${STAB_NEW_EVENTS} spawn/exit event(s) for '${APP_PROGRAM}' during the window}"
    echo "--- new supervisord events ---"
    $DOCKER exec "$APP_NAME" sh -c \
        "tail -n +$(( STABILITY_MARK + 1 )) /var/phlix/logs/supervisord.log 2>/dev/null | head -20" 2>&1 || true
fi
# Events for OTHER programs are informational only — see the scope note above.
STAB_OTHER_EVENTS="$($DOCKER exec "$APP_NAME" sh -c \
    "tail -n +$(( STABILITY_MARK + 1 )) /var/phlix/logs/supervisord.log 2>/dev/null \
     | grep -E 'spawned:|exited:' | grep -vE \"${STAB_EVENT_RE}\" | head -5 || true" 2>/dev/null || true)"
[ -n "$STAB_OTHER_EVENTS" ] && info "supervisord events for OTHER programs (informational): $(printf '%s' "$STAB_OTHER_EVENTS" | tr '\n' '|')"

if [ "$STAB_OK" = "1" ]; then
    pass stability-program "stayed up for ${STABILITY_WINDOW}s: same pid ${STAB_FIRST_PID}, monotonic uptime, no respawn of '${APP_PROGRAM}'"
else
    fail stability-program "NOT STABLE — ${STAB_REASON}"
fi

# --- 5b. the WORKERS, not just the master (finding 10) ---------------------
# A refork loop under a stable master is what blocker 6 looked like. Two
# independent signals, both cheap:
#   * the live worker count must not collapse; and
#   * the CUMULATIVE set of distinct worker pids must stay close to the count.
#     A healthy container reuses the same pids for the whole window, so churn
#     is ~0; a refork loop multiplies it.
WORKER_DISTINCT="$(printf '%s\n' "$WORKER_SEEN" | grep . | sort -u | grep -c . || true)"
is_uint "$WORKER_DISTINCT" || WORKER_DISTINCT=0
WORKER_CHURN=$(( WORKER_DISTINCT - WORKER_COUNT_T0 ))
[ "$WORKER_CHURN" -lt 0 ] && WORKER_CHURN=0
WORKER_DROP_TOLERANCE="${WORKER_DROP_TOLERANCE:-2}"

# The churn budget is PER WORKER NAME, not global.
#
# A global budget cannot be both sensitive and safe: with 24 worker slots and
# 7 samples, ONE slot reforking on every sample contributes only 6 to a global
# count — indistinguishable from six unrelated one-off restarts, so any global
# budget generous enough not to flake is also generous enough to miss a whole
# worker group in a loop. (Measured: that is exactly what a budget of 6 did.)
# Per name, the same loop is 7 distinct pids for 1 slot, which is unambiguous.
#
# Budget 1 per name = one legitimate restart of a group is tolerated (a managed
# worker that finishes a job and is reforked). Measured on a healthy alpine
# image: every name had exactly `slots` distinct pids over 90s / 7 samples.
WORKER_NAME_CHURN_BUDGET="${WORKER_NAME_CHURN_BUDGET:-1}"
WORKER_CHURN_REPORT="$(
    {
        printf 'T0\n'
        printf '%s\n' "$WORKERS_T0" | grep . || true
        printf 'ALL\n'
        printf '%s\n' "$WORKER_SEEN" | grep . | sort -u || true
    } | awk -v budget="$WORKER_NAME_CHURN_BUDGET" '
        $1 == "T0" { mode = 1; next }
        $1 == "ALL" { mode = 2; next }
        mode == 1 { t0[$2]++; next }
        mode == 2 { all[$2]++ }
        END {
            for (n in all) {
                slots = (n in t0) ? t0[n] : 0
                if (all[n] > slots + budget) {
                    printf "%s: %d distinct pids for %d slot(s)\n", n, all[n], slots
                }
            }
        }' | sort || true
)"

info "workers: t0=${WORKER_COUNT_T0} min=${WORKER_MIN} distinct-pids-seen=${WORKER_DISTINCT} churn=${WORKER_CHURN} (per-name budget ${WORKER_NAME_CHURN_BUDGET})"
if [ "$WORKER_COUNT_T0" -lt 1 ]; then
    fail stability-workers "no Workerman WORKER processes at all — the master forked nothing"
elif [ "$WORKER_MIN" -lt $(( WORKER_COUNT_T0 - WORKER_DROP_TOLERANCE )) ]; then
    fail stability-workers "worker count collapsed from ${WORKER_COUNT_T0} to ${WORKER_MIN} during the window"
    printf '%s\n' "$WORKER_SEEN" | grep . | sort -u | awk '{print $2}' | sort | uniq -c || true
elif [ -n "$WORKER_CHURN_REPORT" ]; then
    fail stability-workers "Workerman WORKERS are REFORKING under a stable master: $(printf '%s' "$WORKER_CHURN_REPORT" | tr '\n' '; ')"
    printf '%s\n' "$WORKER_SEEN" | grep . | sort -u | awk '{print $2}' | sort | uniq -c || true
else
    pass stability-workers "${WORKER_COUNT_T0} workers held their pids across the window (${WORKER_DISTINCT} distinct pids seen, no group above its slot count + ${WORKER_NAME_CHURN_BUDGET})"
fi

# ---------------------------------------------------------------------------
# 6. ASSERTION 6 — the Workerman DAEMON is the process that is running, not the
#    CGI front controller. Blocker 1 stated positively.
# ---------------------------------------------------------------------------
say "ASSERT 6/11 — start.php (Workerman master + workers) is the running process"
PS_RC=0
PSOUT="$($DOCKER exec "$APP_NAME" ps -eo args 2>&1)" || PS_RC=$?
# The negative check below is only meaningful if `ps` actually RAN. Without
# this, a failed `docker exec` produced empty output, matched nothing, and
# "no php-fpm process" PASSED vacuously. (S163 review F7)
if [ "$PS_RC" -ne 0 ] || [ -z "$PSOUT" ]; then
    # BOTH check ids must report, or the completeness check at the end fires —
    # which is the point: a check that cannot be evaluated is a FAILURE, not a
    # skip. (S163 review round 2, finding 1)
    fail daemon-process "could not read the process table (docker exec rc=${PS_RC})"
    fail no-cgi "could not read the process table (docker exec rc=${PS_RC}) — this check would pass vacuously"
    PSOUT=''
else
    # `grep` with no match returns 1; under `set -euo pipefail` that aborted the
    # whole script before printing anything. (S163 review F7)
    printf '%s\n' "$PSOUT" | grep -E 'php|supervisord' | head -20 || true
    if printf '%s' "$PSOUT" | grep -q 'start.php'; then
        pass daemon-process "start.php present in the process table"
    else
        fail daemon-process "no start.php process — the daemon is not what is running"
    fi
    if printf '%s' "$PSOUT" | grep -qE 'public/index\.php|php-fpm|nginx'; then
        fail no-cgi "a CGI/FPM/nginx process is running — the image still ships two serving models"
    else
        pass no-cgi "no index.php / php-fpm / nginx process"
    fi
fi

# ---------------------------------------------------------------------------
# 7. ASSERTION 7 — the SyncPlay WS worker bound :8097, and the PUBLISHED port
#    reaches it. The in-container probe alone never exercised the port mapping
#    an operator actually uses. (S163 review F8)
# ---------------------------------------------------------------------------
say "ASSERT 7/11 — SyncPlay WebSocket worker is reachable in-container AND on the published port"
WS_PROBE="$($DOCKER exec "$APP_NAME" php -r \
    '$f=@fsockopen("127.0.0.1",8097,$e,$s,5); echo $f?"OPEN":"CLOSED:".$s;' 2>&1 || true)"
info "in-container: ${WS_PROBE}"
if printf '%s' "$WS_PROBE" | grep -q 'OPEN'; then
    pass ws-in-container ":8097 accepting connections inside the container"
else
    fail ws-in-container ":8097 not listening — the WS worker never started"
fi

# From the HOST, through the published mapping — the thing an operator relies
# on and the in-container probe above never touches. (S163 review F8)
#
# ⚠ A RAW TCP CONNECT CANNOT FAIL HERE, so it proves nothing. (S163 review
# round 2, finding 2 — a demonstrated FALSE-PASS.) Docker's userland proxy
# (`EnableUserlandProxy: true` by default, including on GitHub-hosted runners)
# binds the HOST port itself and `accept()`s BEFORE it dials the container. So
# `exec 3<>/dev/tcp/…` succeeds whether or not anything is listening inside.
# Proven: `docker run -d -p 127.0.0.1:39901:8097 <base> sleep 300` — no
# listener of any kind in the container — and the connect succeeded. The
# negative control that "verified" the old check used an UNPUBLISHED port,
# which only proves /dev/tcp works.
#
# curl's exit code DOES discriminate, measured on the same mapping:
#   52  Empty reply from server  -> the worker ANSWERED and closed. The WS
#                                   worker enforces JWT auth (the entrypoint
#                                   always configures a key), so a token-less
#                                   upgrade is closed without an HTTP reply.
#                                   This is the healthy case.
#    0  a real HTTP response     -> also fine: something spoke HTTP.
#   56  Recv failure: reset      -> the userland proxy accepted, then found
#                                   NOTHING behind the mapping. The exact
#                                   regression F8 was written to catch.
#    7  Failed to connect        -> no mapping at all (EXPOSE/`-p` dropped).
# So: accept 0 and 52, reject everything else — 7 and 56 explicitly.
WS_CURL_RC=0
curl -sS -o /dev/null --max-time 8 --http1.1 \
    -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
    -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: c2lkZXNob3dib2JzMTIzNA==' \
    "http://127.0.0.1:${WS_PORT}/" >/dev/null 2>&1 || WS_CURL_RC=$?
case "$WS_CURL_RC" in
    0)  pass ws-published "published WS port ${WS_PORT} answered with a real HTTP response (curl rc=0)" ;;
    52) pass ws-published "published WS port ${WS_PORT} reaches the worker (curl rc=52: connected, worker closed the unauthenticated upgrade)" ;;
    56) fail ws-published "published WS port ${WS_PORT}: connection RESET (curl rc=56) — the port mapping exists but NOTHING is listening inside the container" ;;
    7)  fail ws-published "published WS port ${WS_PORT}: could not connect (curl rc=7) — the port mapping is absent" ;;
    28) fail ws-published "published WS port ${WS_PORT}: timed out (curl rc=28) — something accepted but never answered" ;;
    *)  fail ws-published "published WS port ${WS_PORT}: unexpected curl exit ${WS_CURL_RC}" ;;
esac
# Keep the raw connect as a DIAGNOSTIC only, so the two signals can be compared
# in a failing run. It is deliberately not an assertion — see above.
if timeout 8 bash -c "exec 3<>/dev/tcp/127.0.0.1/${WS_PORT}" 2>/dev/null; then
    info "raw TCP connect to ${WS_PORT}: OPEN (diagnostic only — the userland proxy accepts regardless)"
else
    info "raw TCP connect to ${WS_PORT}: REFUSED (diagnostic only)"
fi

# ---------------------------------------------------------------------------
# 8. ASSERTION 8 — Workerman serves the SPA shell and its immutable assets
#    itself. This is what makes deleting nginx from the image safe.
# ---------------------------------------------------------------------------
say "ASSERT 8/11 — Workerman serves the SPA shell + hashed static assets"
APP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:${HTTP_PORT}/app" || true)"
info "GET /app -> ${APP_CODE}"
if [ "$APP_CODE" = "200" ]; then
    pass spa-shell "/app shell served by the daemon"
else
    fail spa-shell "/app returned ${APP_CODE}"
fi

ASSET_PATH="$($DOCKER exec "$APP_NAME" sh -c \
    'cd /var/www/html/public && ls assets/app/assets/*.js 2>/dev/null | head -1' 2>/dev/null || true)"
ASSET_PATH="$(printf '%s' "$ASSET_PATH" | tr -d '\r')"
if [ -n "$ASSET_PATH" ]; then
    ASSET_HDRS="$(curl -s -D - -o /dev/null --max-time 10 "http://127.0.0.1:${HTTP_PORT}/${ASSET_PATH}" || true)"
    info "GET /${ASSET_PATH}"
    printf '%s\n' "$ASSET_HDRS" | head -8 || true
    if printf '%s' "$ASSET_HDRS" | head -1 | grep -q ' 200'; then
        pass spa-asset "hashed asset served"
    else
        fail spa-asset "hashed asset not served"
    fi
    if printf '%s' "$ASSET_HDRS" | grep -qi 'immutable'; then
        pass spa-immutable "immutable cache header present"
    else
        fail spa-immutable "immutable cache header missing"
    fi
else
    # Both downstream ids must still report — see the check registry note.
    fail spa-asset "no built SPA asset found under public/assets/app/assets/ inside the image"
    fail spa-immutable "no built SPA asset to check the immutable header on"
fi

# ---------------------------------------------------------------------------
# 9. ASSERTION 9 — HEALTHCHECK exists AND the container is healthy AT THE END.
#     Its absence is why a total outage read as `Up 22 minutes`. Checking at the
#     end (after the stability window) matters: a container can pass through
#     `healthy` and then rot.
# ---------------------------------------------------------------------------
say "ASSERT 9/11 — image declares a HEALTHCHECK and the container is healthy"
HC="$($DOCKER inspect -f '{{json .Config.Healthcheck}}' "$IMAGE_TAG" 2>/dev/null || true)"
info "image healthcheck: ${HC}"
if [ -z "$HC" ] || [ "$HC" = "null" ]; then
    fail healthcheck-declared "no HEALTHCHECK in the image — an outage would report as Up"
    fail healthcheck-healthy "no HEALTHCHECK, so the container can never report healthy"
    fail healthcheck-start-period "no HEALTHCHECK, so there is no start period to bound"
else
    pass healthcheck-declared "HEALTHCHECK declared"
    hdeadline=$(( $(date +%s) + 120 ))
    HSTATE=''
    while [ "$(date +%s)" -lt "$hdeadline" ]; do
        HSTATE="$($DOCKER inspect -f '{{.State.Health.Status}}' "$APP_NAME" 2>/dev/null || true)"
        [ "$HSTATE" = "healthy" ] && break
        [ "$HSTATE" = "unhealthy" ] && break
        sleep 3
    done
    info "container health: ${HSTATE}"
    if [ "$HSTATE" = "healthy" ]; then
        pass healthcheck-healthy "container reports healthy"
    else
        fail healthcheck-healthy "container health is '${HSTATE}'"
    fi

    # The start period must be short enough that `unhealthy` is REACHABLE inside
    # a gate run; otherwise the state is decorative. (S163 review F1)
    #
    # ⚠ `{{json .Config.Healthcheck.StartPeriod}}` — NOT `{{.Config…}}`.
    # (S163 review round 2, finding 1.) A Go `time.Duration` rendered by the
    # plain `-f` form goes through String() and prints "1m30s"; only the `json`
    # form prints the raw nanoseconds. Round 2 used the plain form, `$(( ))`
    # died with "value too great for base", bash abandoned the rest of the
    # block WITHOUT touching FAILURES, and the gate printed ALL ASSERTIONS
    # PASSED against an image whose start period had been reverted to 180s.
    # Both forms are checked against the same image in the unit suite.
    HC_START_NS="$($DOCKER inspect -f '{{json .Config.Healthcheck.StartPeriod}}' "$IMAGE_TAG" 2>/dev/null || true)"
    HC_START_NS="$(printf '%s' "$HC_START_NS" | tr -d ' "\r\n')"
    if uint_or_fail healthcheck-start-period "$HC_START_NS" \
            'docker inspect returned a non-numeric StartPeriod — a Go duration string, not nanoseconds'; then
        HC_START_S=$(( UINT_VALUE / 1000000000 ))
        if [ "$HC_START_S" -gt "$MAX_START_PERIOD" ]; then
            fail healthcheck-start-period "HEALTHCHECK start-period is ${HC_START_S}s — longer than a gate run, so 'unhealthy' can never be observed"
        else
            pass healthcheck-start-period "HEALTHCHECK start-period ${HC_START_S}s is observable within a gate run (max ${MAX_START_PERIOD}s)"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# 10. ASSERTION 10 — the image satisfies composer.json's platform requirements.
#     The Dockerfiles used to build with --ignore-platform-reqs, which masked a
#     missing extension (ext-ldap is a HARD requirement) until it fatalled.
# ---------------------------------------------------------------------------
say "ASSERT 10/11 — composer check-platform-reqs inside the image"
# NB: capture, do NOT `tee /dev/stderr`. When the caller redirects with
# `> log 2>&1`, /dev/stderr is reopened as a SEPARATE file description with its
# own offset and overwrites the log from byte 0 — which silently destroyed the
# first six assertions' output on the first green run.
PLATFORM_STATUS=0
PLATFORM_OUT="$($DOCKER exec -w /var/www/html "$APP_NAME" \
    composer check-platform-reqs --no-interaction 2>&1)" || PLATFORM_STATUS=$?
printf '%s\n' "$PLATFORM_OUT" | sed 's/^/   | /' || true
if [ "$PLATFORM_STATUS" -eq 0 ]; then
    pass platform-reqs "check-platform-reqs exited 0"
else
    fail platform-reqs "check-platform-reqs exited ${PLATFORM_STATUS} — a required extension is missing"
fi

# ---------------------------------------------------------------------------
# The entrypoint log has to be captured BEFORE assertion 11, which deliberately
# kills the container.
say "Entrypoint log (head)"
$DOCKER logs "$APP_NAME" 2>&1 | head -30 || true

# ---------------------------------------------------------------------------
# 11. ASSERTION 11 — a FATAL program KILLS the container, with a NON-ZERO exit
#     code. (S163 review round 2, findings 3 and 9.)
#
# This is DESTRUCTIVE and must run last.
#
# Round 1's F6 added `docker/supervisord-exit-on-fatal.sh`, and round 2 found
# two things wrong with the way it was covered:
#
#   * finding 9 — NOTHING exercised the path. The gate only required the
#     listener to be RUNNING, so replacing its body with `sleep infinity` kept
#     every assertion green; the only other coverage greps the file for the
#     literal string `kill -TERM 1`. Text, not behaviour.
#   * finding 3 — the container exited 0, because supervisord treats SIGTERM as
#     a clean shutdown. `restart: on-failure`, k8s `restartPolicy: OnFailure`,
#     `docker wait` and every exit-code alert read that as success, i.e. the
#     failure was still invisible — the very thing F6 existed to fix.
#
# So: induce a REAL PROCESS_STATE_FATAL by appending a program that cannot
# possibly spawn, then require (a) the container to die and (b) its exit code
# to be non-zero. No image change is needed and the container is torn down
# immediately afterwards.
# ---------------------------------------------------------------------------
say "ASSERT 11/11 — a FATAL program kills the container with a NON-ZERO exit code"
CANARY_PROGRAM='s163-fatal-canary'
CANARY_RC=0
if [ "$KEEP" = "1" ]; then
    # KEEP=1 is a local-iteration convenience and is never set in CI. A run that
    # skips an assertion is NOT a pass, so say so out loud rather than quietly
    # dropping two checks — that is the exact habit this round is correcting.
    fail fatal-kills-container "SKIPPED: KEEP=1 keeps the container alive, so the destructive FATAL assertion did not run"
    fail fatal-exit-code-nonzero "SKIPPED: KEEP=1 — this run is NOT a complete gate"
else
    # ⚠ The canary command must EXIST and FAIL, not be missing.
    #
    # The first version of this assertion used `command=/nonexistent-binary`
    # and it could never work: supervisor's `startProcess` RPC calls
    # `get_execv_args()` FIRST and raises `NO_FILE` WITHOUT ever calling
    # `spawn()`, so the program stays STOPPED, never reaches BACKOFF and never
    # reaches FATAL. Observed live — `s163-fatal-canary: ERROR (no such file)`,
    # program `STOPPED   Not started`, container untouched. `/bin/sh -c
    # "exit 1"` really is spawned, exits inside `startsecs`, and with
    # `startretries=0` supervisord gives up on the second transition:
    # `WARN exited: … (exit status 1; not expected)` then
    # `INFO gave up: … entered FATAL state`.
    printf '%s\n' \
        '' \
        "[program:${CANARY_PROGRAM}]" \
        'command=/bin/sh -c "exit 1"' \
        'autostart=false' \
        'autorestart=false' \
        'startsecs=1' \
        'startretries=0' \
        | $DOCKER exec -i "$APP_NAME" sh -c "cat >> '${SUPERVISORD_CONF_IN_IMAGE}'" || CANARY_RC=$?

    if [ "$CANARY_RC" -ne 0 ]; then
        fail fatal-kills-container "could not append the canary program to ${SUPERVISORD_CONF_IN_IMAGE} (rc=${CANARY_RC})"
        fail fatal-exit-code-nonzero "could not induce a FATAL, so the exit code cannot be checked"
    else
        $DOCKER exec "$APP_NAME" supervisorctl reread >/dev/null 2>&1 || true
        $DOCKER exec "$APP_NAME" supervisorctl add "$CANARY_PROGRAM" >/dev/null 2>&1 || true
        CANARY_START_OUT="$($DOCKER exec "$APP_NAME" supervisorctl start "$CANARY_PROGRAM" 2>&1 || true)"
        info "supervisorctl start ${CANARY_PROGRAM}: $(printf '%s' "$CANARY_START_OUT" | tr '\n' ' ' | head -c 160)"

        fdeadline=$(( $(date +%s) + 60 ))
        FATAL_RUNNING='unknown'
        while [ "$(date +%s)" -lt "$fdeadline" ]; do
            FATAL_RUNNING="$($DOCKER inspect -f '{{.State.Running}}' "$APP_NAME" 2>/dev/null || echo unknown)"
            [ "$FATAL_RUNNING" = "false" ] && break
            sleep 2
        done
        FATAL_EXIT_RAW="$($DOCKER inspect -f '{{.State.ExitCode}}' "$APP_NAME" 2>/dev/null || true)"
        FATAL_EXIT_RAW="$(printf '%s' "$FATAL_EXIT_RAW" | tr -d ' \r\n')"
        # `docker logs` still works on a dead container, unlike `docker exec`.
        FATAL_LOG="$($DOCKER logs "$APP_NAME" 2>&1 || true)"
        CANARY_WENT_FATAL=0
        printf '%s' "$FATAL_LOG" | grep -qaE "${CANARY_PROGRAM} entered FATAL state" && CANARY_WENT_FATAL=1
        info "after FATAL: Running=${FATAL_RUNNING} ExitCode=${FATAL_EXIT_RAW:-<unreadable>} canary-reached-FATAL=${CANARY_WENT_FATAL}"
        printf '%s\n' "$FATAL_LOG" | tail -12 | sed 's/^/   | /' || true

        if [ "$FATAL_RUNNING" != "false" ] && [ "$CANARY_WENT_FATAL" -eq 0 ]; then
            # Distinguish "the container survived a FATAL" (a real defect) from
            # "no FATAL happened, so nothing was tested" (a gate-setup bug).
            # Conflating those is how the first version of this assertion would
            # have reported a defect that did not exist — the same class as
            # finding 8.
            fail fatal-kills-container "could not induce a FATAL at all (canary never entered FATAL) — this is a GATE SETUP failure, not a verdict on the image"
            fail fatal-exit-code-nonzero "no FATAL was induced, so the container exit code proves nothing"
        else
            if [ "$FATAL_RUNNING" = "false" ]; then
                pass fatal-kills-container "a FATAL program stopped the container instead of leaving it 'Up'"
            else
                fail fatal-kills-container "the container is still running 60s after '${CANARY_PROGRAM}' entered FATAL — a total outage would still read as 'Up'"
            fi

            if uint_or_fail fatal-exit-code-nonzero "$FATAL_EXIT_RAW" \
                    'docker inspect .State.ExitCode was not numeric'; then
                if [ "$UINT_VALUE" -ne 0 ]; then
                    pass fatal-exit-code-nonzero "container exit code is ${UINT_VALUE} — a supervisor can tell this from a clean 'docker stop'"
                else
                    fail fatal-exit-code-nonzero "container exited 0 after a FATAL — indistinguishable from 'docker stop', so 'restart: on-failure', k8s OnFailure and 'docker wait' all read it as success"
                fi
            fi
        fi
    fi
fi

# ---------------------------------------------------------------------------
# THE COMPLETENESS CHECK — did every assertion actually RUN?
# (S163 review round 2, finding 1.)
#
# "15/15 PASS" was read as complete when the 16th check silently never
# executed. Counting the verdicts that DID happen can never notice that; only
# comparing them against the list of verdicts that were REQUIRED can.
# ---------------------------------------------------------------------------
say "CHECK COMPLETENESS"
CHECKS_EXPECTED=0
CHECKS_MISSING=''
CHECKS_DUPLICATED=''
for _chk in $EXPECTED_CHECKS; do
    CHECKS_EXPECTED=$(( CHECKS_EXPECTED + 1 ))
    _seen=0
    for _rec in $RECORDED_CHECKS; do
        [ "$_rec" = "$_chk" ] && _seen=$(( _seen + 1 ))
    done
    case "$_seen" in
        0) CHECKS_MISSING="${CHECKS_MISSING} ${_chk}" ;;
        1) ;;
        *) CHECKS_DUPLICATED="${CHECKS_DUPLICATED} ${_chk}(x${_seen})" ;;
    esac
done
CHECKS_RECORDED=0
for _rec in $RECORDED_CHECKS; do
    CHECKS_RECORDED=$(( CHECKS_RECORDED + 1 ))
    case " $(printf '%s' "$EXPECTED_CHECKS" | tr '\n' ' ') " in
        *" ${_rec} "*) ;;
        *) CHECKS_MISSING="${CHECKS_MISSING} !unregistered:${_rec}" ;;
    esac
done
info "assertions expected: ${CHECKS_EXPECTED}   verdicts recorded: ${CHECKS_RECORDED}"
if [ -n "$CHECKS_MISSING" ]; then
    fail gate-completeness "these checks produced NO verdict (they did not run):${CHECKS_MISSING}"
fi
if [ -n "$CHECKS_DUPLICATED" ]; then
    fail gate-completeness "these checks produced MORE THAN ONE verdict:${CHECKS_DUPLICATED}"
fi
if [ -z "$CHECKS_MISSING" ] && [ -z "$CHECKS_DUPLICATED" ]; then
    info "all ${CHECKS_EXPECTED} registered checks reported exactly once"
fi

say "RESULT"
if [ "$FAILURES" -eq 0 ]; then
    printf '   \033[32mALL %d ASSERTIONS PASSED\033[0m for %s (%s)\n' \
        "$CHECKS_EXPECTED" "$IMAGE_TAG" "$DOCKERFILE"
    exit 0
fi
printf '   \033[31m%d of %d ASSERTION(S) FAILED\033[0m for %s (%s)\n' \
    "$FAILURES" "$CHECKS_EXPECTED" "$IMAGE_TAG" "$DOCKERFILE"
dump_diagnostics
exit 1
