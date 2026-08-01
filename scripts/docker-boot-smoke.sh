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

FAILURES=0

# ---------------------------------------------------------------------------
say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
pass() { printf '   \033[32mPASS\033[0m %s\n' "$*"; }
fail() { printf '   \033[31mFAIL\033[0m %s\n' "$*"; FAILURES=$(( FAILURES + 1 )); }

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
# the PRODUCTION database. Readiness is decided on the string "mysqld is alive",
# not on the exit code — mysql:8.0 runs a temporary server during first-init and
# the exit code flaps through "socket missing" and "access denied" before the
# real server is up.
MYSQL_READY=0
for attempt in $(seq 1 60); do
    PINGOUT="$($DOCKER exec "$MYSQL_NAME" mysqladmin --no-defaults \
        -uroot -p"root_${MYSQL_PASSWORD}" ping 2>&1 || true)"
    if printf '%s' "$PINGOUT" | grep -q 'mysqld is alive'; then
        info "mysql ready after ${attempt} attempt(s)"
        MYSQL_READY=1
        break
    fi
    sleep 3
done
if [ "$MYSQL_READY" != "1" ]; then
    fail "throwaway MySQL never became ready: $(printf '%s' "$PINGOUT" | tail -1)"
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
say "ASSERT 1/7 — GET /health returns 200 from the application"
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
    pass "/health -> $(printf '%s' "$HEALTH_BODY" | head -c 200)"
else
    fail "/health never returned a healthy body within ${BOOT_TIMEOUT}s"
    echo "--- verbose curl ---"
    curl -sS -i --max-time 5 "http://127.0.0.1:${HTTP_PORT}/health" 2>&1 | head -20 || true
    dump_diagnostics
    exit 1
fi

# ---------------------------------------------------------------------------
# 5. ASSERTION 2 — every supervisord program is RUNNING (none FATAL/BACKOFF).
# ---------------------------------------------------------------------------
say "ASSERT 2/7 — supervisorctl status: all programs RUNNING"
# /health can answer before supervisord's `startsecs` window closes, so the
# program is legitimately STARTING for a few more seconds. Wait it out rather
# than racing it — but never wait out FATAL/BACKOFF, which are terminal.
SUP_STATUS=''
sdeadline=$(( $(date +%s) + 60 ))
while [ "$(date +%s)" -lt "$sdeadline" ]; do
    SUP_STATUS="$($DOCKER exec "$APP_NAME" supervisorctl status 2>&1 || true)"
    printf '%s' "$SUP_STATUS" | grep -q 'STARTING' || break
    sleep 3
done
echo "$SUP_STATUS"
if printf '%s' "$SUP_STATUS" | grep -qiE 'FATAL|BACKOFF|EXITED|STOPPED|UNKNOWN|refused connection|no such file'; then
    fail "supervisorctl reported a non-RUNNING program (or is unusable)"
elif ! printf '%s' "$SUP_STATUS" | grep -qE '[[:space:]]RUNNING[[:space:]]'; then
    fail "supervisorctl reported no RUNNING program at all"
else
    pass "$(printf '%s' "$SUP_STATUS" | grep -cE '[[:space:]]RUNNING[[:space:]]') program(s) RUNNING"
fi

# ---------------------------------------------------------------------------
# 6. ASSERTION 3 — the Workerman DAEMON is the process that is running, not the
#    CGI front controller. Blocker 1 stated positively.
# ---------------------------------------------------------------------------
say "ASSERT 3/7 — start.php (Workerman master + workers) is the running process"
PSOUT="$($DOCKER exec "$APP_NAME" ps -eo args 2>&1 || true)"
echo "$PSOUT" | grep -E 'php|supervisord' | head -20
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

# ---------------------------------------------------------------------------
# 7. ASSERTION 4 — the SyncPlay WS worker really bound :8097.
# ---------------------------------------------------------------------------
say "ASSERT 4/7 — SyncPlay WebSocket worker is listening on 8097"
WS_PROBE="$($DOCKER exec "$APP_NAME" php -r \
    '$f=@fsockopen("127.0.0.1",8097,$e,$s,5); echo $f?"OPEN":"CLOSED:".$s;' 2>&1 || true)"
info "probe: ${WS_PROBE}"
if printf '%s' "$WS_PROBE" | grep -q 'OPEN'; then
    pass ":8097 accepting connections"
else
    fail ":8097 not listening — the WS worker never started"
fi

# ---------------------------------------------------------------------------
# 8. ASSERTION 5 — Workerman serves the SPA shell and its immutable assets
#    itself. This is what makes deleting nginx from the image safe.
# ---------------------------------------------------------------------------
say "ASSERT 5/7 — Workerman serves the SPA shell + hashed static assets"
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
    printf '%s' "$ASSET_HDRS" | head -8
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
# 9. ASSERTION 6 — HEALTHCHECK exists AND reports healthy. Its absence is why
#    a total outage read as `Up 22 minutes`.
# ---------------------------------------------------------------------------
say "ASSERT 6/7 — image declares a HEALTHCHECK and the container becomes healthy"
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
fi

# ---------------------------------------------------------------------------
# 10. ASSERTION 7 — the image satisfies composer.json's platform requirements.
#     The Dockerfiles build with --ignore-platform-reqs, which masks a missing
#     extension (ext-ldap is a HARD requirement) until it fatals at runtime.
# ---------------------------------------------------------------------------
say "ASSERT 7/7 — composer check-platform-reqs inside the image"
# NB: capture, do NOT `tee /dev/stderr`. When the caller redirects with
# `> log 2>&1`, /dev/stderr is reopened as a SEPARATE file description with its
# own offset and overwrites the log from byte 0 — which silently destroyed the
# first six assertions' output on the first green run.
PLATFORM_STATUS=0
PLATFORM_OUT="$($DOCKER exec -w /var/www/html "$APP_NAME" \
    composer check-platform-reqs --no-interaction 2>&1)" || PLATFORM_STATUS=$?
printf '%s\n' "$PLATFORM_OUT" | sed 's/^/   | /'
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
