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
#   * app ports are published on 127.0.0.1 at a RANDOM high port.
#
# Everything it creates is suffixed with a per-run token and torn down by exact
# name, so it can never collide with, or delete, an unrelated container.
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

# Random loopback-only high ports. 8096/8097 are held by the live server on the
# dev box, so binding them would collide with production traffic.
HTTP_PORT="$(( 30000 + RANDOM % 20000 ))"
WS_PORT="$(( HTTP_PORT + 1 ))"

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

FAILURES=0

# ---------------------------------------------------------------------------
say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
pass() { printf '   \033[32mPASS\033[0m %s\n' "$*"; }
fail() { printf '   \033[31mFAIL\033[0m %s\n' "$*"; FAILURES=$(( FAILURES + 1 )); }

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
info "http port  : 127.0.0.1:${HTTP_PORT} -> container 8096"
info "ws port    : 127.0.0.1:${WS_PORT} -> container 8097"

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
    fail "throwaway MySQL never became TCP-ready: $(printf '%s' "$PINGOUT" | tail -1)"
    $DOCKER logs --tail 20 "$MYSQL_NAME" 2>&1 || true
    exit 1
fi

# ---------------------------------------------------------------------------
# 3. Boot the image. Deliberately NO JWT_SECRET: `docker run` out of the box has
#    to work, and start.php refuses to boot without a signing key.
# ---------------------------------------------------------------------------
say "Booting ${APP_NAME}"
$DOCKER run -d --name "$APP_NAME" --network "$NET" \
    -p "127.0.0.1:${HTTP_PORT}:8096" \
    -p "127.0.0.1:${WS_PORT}:8097" \
    -e PHLIX_DATABASE_HOST="$MYSQL_NAME" \
    -e PHLIX_DATABASE_PORT=3306 \
    -e PHLIX_DATABASE_NAME=phlix \
    -e PHLIX_DATABASE_USER=phlix \
    -e PHLIX_DATABASE_PASSWORD="$MYSQL_PASSWORD" \
    "$IMAGE_TAG" >/dev/null

# ---------------------------------------------------------------------------
# 4. ASSERTION 1 — the application SERVES. This is the assertion that fails all
#    four S163 blockers at once: whether supervisord starts the CGI front
#    controller, or php-fpm FATALs on a missing binary, or fpm can't reopen its
#    log as `nobody`, or nginx fastcgi_passes a port nothing listens on, the
#    observable outcome is identical — nothing answers.
# ---------------------------------------------------------------------------
say "ASSERT 1/10 — GET /health returns 200 from the application"
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
    pass "/health -> $(printf '%s' "$HEALTH_BODY" | tr -d '\n' | head -c 200)"
else
    fail "/health never returned a healthy body within ${BOOT_TIMEOUT}s"
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
say "ASSERT 2/10 — the migration step reported success"
BOOT_LOG="$($DOCKER logs "$APP_NAME" 2>&1 || true)"
if printf '%s' "$BOOT_LOG" | grep -q 'PHLIX-MIGRATION-FAILURE'; then
    fail "the entrypoint printed PHLIX-MIGRATION-FAILURE — the schema is absent or half-applied"
    printf '%s\n' "$BOOT_LOG" | grep -aE 'PHLIX-MIGRATION-FAILURE|exited [0-9]+|SQLSTATE' | head -10 || true
elif printf '%s' "$BOOT_LOG" | grep -q 'PHLIX-MIGRATIONS-NOT-RUN'; then
    fail "migrations were skipped entirely (PHLIX-MIGRATIONS-NOT-RUN)"
elif printf '%s' "$BOOT_LOG" | grep -q 'Skipping database migrations'; then
    fail "migrations were skipped — the gate always configures a database host, so this is a defect"
elif printf '%s' "$BOOT_LOG" | grep -q 'Migrations complete.'; then
    pass "migrations ran to completion"
else
    fail "no migration outcome found in the boot log at all"
    printf '%s\n' "$BOOT_LOG" | head -20 || true
fi

# ---------------------------------------------------------------------------
# 4c. ASSERTION 3 — the SCHEMA really is there, reached with the APPLICATION's
#     own credentials from inside the container. The log says what the
#     entrypoint believed; this says what is actually in the database.
# ---------------------------------------------------------------------------
say "ASSERT 3/10 — the application can reach its database and the schema exists"
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
    fail "the application cannot reach its database, or core tables are missing"
elif [ "${DB_MIGRATIONS:-0}" -lt 1 ] || [ "${DB_TABLES:-0}" -lt 20 ]; then
    fail "schema looks empty (migrations=${DB_MIGRATIONS:-?} tables=${DB_TABLES:-?})"
else
    pass "schema present: ${DB_MIGRATIONS} applied migrations, ${DB_TABLES} tables, media_items queryable"
fi

# ---------------------------------------------------------------------------
# 5. ASSERTION 4 — every supervisord program is RUNNING (none FATAL/BACKOFF).
# ---------------------------------------------------------------------------
say "ASSERT 4/10 — supervisorctl status: all programs RUNNING"
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
    SUP_STATUS="$($DOCKER exec "$APP_NAME" supervisorctl status 2>&1 || true)"
    printf '%s' "$SUP_STATUS" | grep -q 'STARTING' || break
    sleep 3
done
echo "$SUP_STATUS"
SUP_PAIRS="$(sup_states "$SUP_STATUS" || true)"
SUP_BAD="$(printf '%s\n' "$SUP_PAIRS" | grep -E '=(STOPPED|EXITED|FATAL|BACKOFF|UNKNOWN|STOPPING)$' | tr '\n' ' ' || true)"
SUP_STARTING="$(printf '%s\n' "$SUP_PAIRS" | grep -E '=STARTING$' | tr '\n' ' ' || true)"
SUP_RUNNING="$(printf '%s\n' "$SUP_PAIRS" | grep -cE '=RUNNING$' || true)"

if [ -z "$SUP_PAIRS" ]; then
    fail "supervisorctl is unusable — no program states could be read"
elif [ -n "$SUP_BAD" ]; then
    fail "supervisord reports a non-RUNNING program: ${SUP_BAD}"
elif [ -n "$SUP_STARTING" ]; then
    fail "still STARTING after 45s — it is not staying up: ${SUP_STARTING}"
elif ! printf '%s\n' "$SUP_PAIRS" | grep -q "^${APP_PROGRAM}=RUNNING$"; then
    fail "the '${APP_PROGRAM}' program is not RUNNING (states: $(printf '%s' "$SUP_PAIRS" | tr '\n' ' '))"
else
    pass "${SUP_RUNNING} program(s) RUNNING, including '${APP_PROGRAM}'"
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
say "ASSERT 5/10 — the application stays up (${STABILITY_WINDOW}s stability window)"
STABILITY_MARK="$($DOCKER exec "$APP_NAME" sh -c \
    'wc -l < /var/phlix/logs/supervisord.log 2>/dev/null || echo 0' 2>/dev/null | tr -d " \r\n" || true)"
STAB_FIRST="$($DOCKER exec "$APP_NAME" supervisorctl status 2>&1 || true)"
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
    STAB_REASON="the '${APP_PROGRAM}' program is not in supervisord's status at all"
fi

stab_end_at=$(( $(date +%s) + STABILITY_WINDOW ))
while [ "$STAB_OK" = "1" ] && [ "$(date +%s)" -lt "$stab_end_at" ]; do
    sleep "$STABILITY_SAMPLE"
    STAB_NOW="$($DOCKER exec "$APP_NAME" supervisorctl status 2>&1 || true)"
    STAB_FIELDS="$(sup_program "$STAB_NOW" "$APP_PROGRAM" || true)"
    STAB_NOW_STATE="$(printf '%s' "$STAB_FIELDS" | awk '{print $1}')"
    STAB_NOW_PID="$(printf '%s' "$STAB_FIELDS" | awk '{print $2}')"
    STAB_NOW_UPTIME="$(printf '%s' "$STAB_FIELDS" | awk '{print $3}')"
    [ -n "$STAB_NOW_UPTIME" ] || STAB_NOW_UPTIME=-1
    info "t=$(( STABILITY_WINDOW - (stab_end_at - $(date +%s)) ))s   ${APP_PROGRAM}: ${STAB_FIELDS:-<absent>}"

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
# respawn while we were watching. `grep` failing to match is the GOOD case here,
# so it must not be allowed to abort the script under `set -e`.
STAB_NEW_EVENTS="$($DOCKER exec "$APP_NAME" sh -c \
    "tail -n +$(( ${STABILITY_MARK:-0} + 1 )) /var/phlix/logs/supervisord.log 2>/dev/null \
     | grep -cE 'spawned:|exited:|entered (FATAL|BACKOFF)' || true" 2>/dev/null | tr -d " \r\n" || true)"
if [ "${STAB_NEW_EVENTS:-0}" -gt 0 ]; then
    STAB_OK=0
    STAB_REASON="${STAB_REASON:-supervisord recorded ${STAB_NEW_EVENTS} spawn/exit event(s) during the window}"
    echo "--- new supervisord events ---"
    $DOCKER exec "$APP_NAME" sh -c \
        "tail -n +$(( ${STABILITY_MARK:-0} + 1 )) /var/phlix/logs/supervisord.log 2>/dev/null | head -20" 2>&1 || true
fi

if [ "$STAB_OK" = "1" ]; then
    pass "stayed up for ${STABILITY_WINDOW}s: same pid ${STAB_FIRST_PID}, monotonic uptime, no respawn"
else
    fail "NOT STABLE — ${STAB_REASON}"
fi

# ---------------------------------------------------------------------------
# 6. ASSERTION 6 — the Workerman DAEMON is the process that is running, not the
#    CGI front controller. Blocker 1 stated positively.
# ---------------------------------------------------------------------------
say "ASSERT 6/10 — start.php (Workerman master + workers) is the running process"
PS_RC=0
PSOUT="$($DOCKER exec "$APP_NAME" ps -eo args 2>&1)" || PS_RC=$?
# The negative check below is only meaningful if `ps` actually RAN. Without
# this, a failed `docker exec` produced empty output, matched nothing, and
# "no php-fpm process" PASSED vacuously. (S163 review F7)
if [ "$PS_RC" -ne 0 ] || [ -z "$PSOUT" ]; then
    fail "could not read the process table (docker exec rc=${PS_RC}) — later checks would pass vacuously"
    PSOUT=''
fi
# `grep` with no match returns 1; under `set -euo pipefail` that aborted the
# whole script before printing anything. (S163 review F7)
printf '%s\n' "$PSOUT" | grep -E 'php|supervisord' | head -20 || true
if [ -n "$PSOUT" ]; then
    if printf '%s' "$PSOUT" | grep -q 'start.php'; then
        pass "start.php present in the process table"
    else
        fail "no start.php process — the daemon is not what is running"
    fi
    if printf '%s' "$PSOUT" | grep -qE 'public/index\.php|php-fpm|nginx'; then
        fail "a CGI/FPM/nginx process is running — the image still ships two serving models"
    else
        pass "no index.php / php-fpm / nginx process"
    fi
fi

# ---------------------------------------------------------------------------
# 7. ASSERTION 7 — the SyncPlay WS worker bound :8097, and the PUBLISHED port
#    reaches it. The in-container probe alone never exercised the port mapping
#    an operator actually uses. (S163 review F8)
# ---------------------------------------------------------------------------
say "ASSERT 7/10 — SyncPlay WebSocket worker is reachable in-container AND on the published port"
WS_PROBE="$($DOCKER exec "$APP_NAME" php -r \
    '$f=@fsockopen("127.0.0.1",8097,$e,$s,5); echo $f?"OPEN":"CLOSED:".$s;' 2>&1 || true)"
info "in-container: ${WS_PROBE}"
if printf '%s' "$WS_PROBE" | grep -q 'OPEN'; then
    pass ":8097 accepting connections inside the container"
else
    fail ":8097 not listening — the WS worker never started"
fi

# From the HOST, through the published mapping — the thing an operator relies
# on and the in-container probe above never touches. (S163 review F8)
#
# The primary check is a raw TCP connect, because it is unambiguous: it
# succeeds iff the published port reaches a listener. Verified BOTH branches —
# the mapped port connects, a dead port is refused.
#
# ⚠ Do NOT assert on the handshake response. The WS worker enforces JWT auth
# whenever a signing key is configured (and the entrypoint always configures
# one now), so a token-less upgrade is closed WITHOUT an HTTP reply and curl
# reports "(52) Empty reply from server". An earlier version of this assertion
# treated that as failure and reddened a perfectly healthy container — an empty
# reply after a completed connection is proof the worker answered, not that it
# was unreachable. The handshake is logged as INFO only.
if timeout 8 bash -c "exec 3<>/dev/tcp/127.0.0.1/${WS_PORT}" 2>/dev/null; then
    pass "published WS port ${WS_PORT} reaches the worker"
else
    fail "published WS port ${WS_PORT} refused the connection — the mapping does not reach :8097"
fi
WS_HOST_OUT="$(curl -sS -i --max-time 8 --http1.1 \
    -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
    -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: c2lkZXNob3dib2JzMTIzNA==' \
    "http://127.0.0.1:${WS_PORT}/" 2>&1 | head -3 || true)"
info "handshake (unauthenticated, informational): $(printf '%s' "$WS_HOST_OUT" | tr -d '\r' | head -1)"

# ---------------------------------------------------------------------------
# 8. ASSERTION 8 — Workerman serves the SPA shell and its immutable assets
#    itself. This is what makes deleting nginx from the image safe.
# ---------------------------------------------------------------------------
say "ASSERT 8/10 — Workerman serves the SPA shell + hashed static assets"
APP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:${HTTP_PORT}/app" || true)"
info "GET /app -> ${APP_CODE}"
if [ "$APP_CODE" = "200" ]; then
    pass "/app shell served by the daemon"
else
    fail "/app returned ${APP_CODE}"
fi

ASSET_PATH="$($DOCKER exec "$APP_NAME" sh -c \
    'cd /var/www/html/public && ls assets/app/assets/*.js 2>/dev/null | head -1' 2>/dev/null || true)"
ASSET_PATH="$(printf '%s' "$ASSET_PATH" | tr -d '\r')"
if [ -n "$ASSET_PATH" ]; then
    ASSET_HDRS="$(curl -s -D - -o /dev/null --max-time 10 "http://127.0.0.1:${HTTP_PORT}/${ASSET_PATH}" || true)"
    info "GET /${ASSET_PATH}"
    printf '%s\n' "$ASSET_HDRS" | head -8 || true
    if printf '%s' "$ASSET_HDRS" | head -1 | grep -q ' 200'; then
        pass "hashed asset served"
    else
        fail "hashed asset not served"
    fi
    if printf '%s' "$ASSET_HDRS" | grep -qi 'immutable'; then
        pass "immutable cache header present"
    else
        fail "immutable cache header missing"
    fi
else
    fail "no built SPA asset found under public/assets/app/assets/ inside the image"
fi

# ---------------------------------------------------------------------------
# 9. ASSERTION 9 — HEALTHCHECK exists AND the container is healthy AT THE END.
#     Its absence is why a total outage read as `Up 22 minutes`. Checking at the
#     end (after the stability window) matters: a container can pass through
#     `healthy` and then rot.
# ---------------------------------------------------------------------------
say "ASSERT 9/10 — image declares a HEALTHCHECK and the container is healthy"
HC="$($DOCKER inspect -f '{{json .Config.Healthcheck}}' "$IMAGE_TAG" 2>/dev/null || true)"
info "image healthcheck: ${HC}"
if [ -z "$HC" ] || [ "$HC" = "null" ]; then
    fail "no HEALTHCHECK in the image — an outage would report as Up"
else
    pass "HEALTHCHECK declared"
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
        pass "container reports healthy"
    else
        fail "container health is '${HSTATE}'"
    fi
    # The start period must be short enough that `unhealthy` is REACHABLE inside
    # a gate run; otherwise the state is decorative. (S163 review F1)
    HC_START_NS="$($DOCKER inspect -f '{{.Config.Healthcheck.StartPeriod}}' "$IMAGE_TAG" 2>/dev/null || echo 0)"
    HC_START_S=$(( ${HC_START_NS:-0} / 1000000000 ))
    if [ "$HC_START_S" -gt "$MAX_START_PERIOD" ]; then
        fail "HEALTHCHECK start-period is ${HC_START_S}s — longer than a gate run, so 'unhealthy' can never be observed"
    else
        pass "HEALTHCHECK start-period ${HC_START_S}s is observable within a gate run"
    fi
fi

# ---------------------------------------------------------------------------
# 10. ASSERTION 10 — the image satisfies composer.json's platform requirements.
#     The Dockerfiles used to build with --ignore-platform-reqs, which masked a
#     missing extension (ext-ldap is a HARD requirement) until it fatalled.
# ---------------------------------------------------------------------------
say "ASSERT 10/10 — composer check-platform-reqs inside the image"
# NB: capture, do NOT `tee /dev/stderr`. When the caller redirects with
# `> log 2>&1`, /dev/stderr is reopened as a SEPARATE file description with its
# own offset and overwrites the log from byte 0 — which silently destroyed the
# first six assertions' output on the first green run.
PLATFORM_STATUS=0
PLATFORM_OUT="$($DOCKER exec -w /var/www/html "$APP_NAME" \
    composer check-platform-reqs --no-interaction 2>&1)" || PLATFORM_STATUS=$?
printf '%s\n' "$PLATFORM_OUT" | sed 's/^/   | /' || true
if [ "$PLATFORM_STATUS" -eq 0 ]; then
    pass "check-platform-reqs exited 0"
else
    fail "check-platform-reqs exited ${PLATFORM_STATUS} — a required extension is missing"
fi

# ---------------------------------------------------------------------------
say "Entrypoint log (head)"
$DOCKER logs "$APP_NAME" 2>&1 | head -30 || true

say "RESULT"
if [ "$FAILURES" -eq 0 ]; then
    printf '   \033[32mALL ASSERTIONS PASSED\033[0m for %s (%s)\n' "$IMAGE_TAG" "$DOCKERFILE"
    exit 0
fi
printf '   \033[31m%d ASSERTION(S) FAILED\033[0m for %s (%s)\n' "$FAILURES" "$IMAGE_TAG" "$DOCKERFILE"
dump_diagnostics
exit 1
