#!/bin/sh
set -e

# Container entry point: apply database migrations, then hand off to supervisord.
#
# ============================================================================
# S159 — the boot path's behaviour on a FAILED migration, decided explicitly
# ============================================================================
#
# This line used to read:
#
#     php /var/www/html/scripts/run-migrations.php || true
#
# and it had read that way since the first commit of this file (c2127f91,
# "Step O.1: Docker images"), written on the same line as the `set -e` above.
# The intent recorded by that pairing is unambiguous and is PRESERVED here: a
# migration problem must not stop the container from starting.
#
# The `|| true` itself is DELETED, for three reasons:
#
#   1. It was indiscriminate. It swallowed not just a recorded migration error
#      but also a fatal PHP error and an unreachable database — every possible
#      outcome collapsed to "success", with nothing printed to say otherwise.
#   2. It made the decision INVISIBLE. Nothing in the file said whether booting
#      anyway was intended or accidental, so no operator or reviewer could tell.
#   3. Until S159 it was also redundant: run-migrations.php always exited 0, so
#      `|| true` could not fire on the case people believed it covered. Now that
#      the script exits 1 on a genuine error, the branch has to be written out.
#
# What now happens on a genuinely failing migration:
#
#   - a loud, greppable `PHLIX-MIGRATION-FAILURE` banner is printed to stderr
#     with the exit code (visible in `docker logs`);
#   - by DEFAULT the container still starts. A home media server that
#     crash-loops is a total outage; one running against a partially-migrated
#     schema is degraded but reachable, and `restart: unless-stopped` would loop
#     a genuinely bad migration file forever with no service and no UI to
#     explain why;
#   - set `PHLIX_MIGRATIONS_STRICT=1` (also true/yes/on) to invert that and
#     refuse to start, exiting with the migration's own exit code. Use it where
#     a half-migrated schema is worse than downtime.
#
# The non-zero exit is still reachable — and asserted — from the paths that DO
# fail fast: `php scripts/run-migrations.php` and `bin/phlix migrate` return 1,
# `scripts/install.sh` aborts under `set -e`, and CI's "Apply database
# migrations" step fails. See src/Common/Database/MigrationRunner.php.
#
# ---------------------------------------------------------------------------
# THIS FILE IS THE IMAGE'S `CMD`. All three Dockerfiles end with
# `CMD ["sh", "/docker-entrypoint.sh"]`, and each now carries a matching
# `COPY docker/docker-entrypoint.sh /docker-entrypoint.sh`. That COPY was
# MISSING from the first commit until the S159 review (finding 4): the CMD
# named a path nothing ever wrote, so no shipped container had ever run this
# script — and a `docker build` never executes CMD, so every image Build job
# passed anyway. tests/Unit/Docker/DockerEntrypointTest.php now asserts the
# COPY/CMD pair and the defaults below against all three Dockerfiles, so the
# path cannot silently die again.
#
# PHLIX_APP_ROOT / PHLIX_SUPERVISORD / PHLIX_SUPERVISORD_CONF exist so this
# script can be smoke-tested end to end (no image build in CI runs it). The
# defaults are the image's real paths, so container behaviour is unchanged.
# ---------------------------------------------------------------------------

APP_ROOT="${PHLIX_APP_ROOT:-/var/www/html}"
SUPERVISORD="${PHLIX_SUPERVISORD:-/usr/bin/supervisord}"
SUPERVISORD_CONF="${PHLIX_SUPERVISORD_CONF:-/etc/supervisor/conf.d/supervisord.conf}"
JWT_SECRET_FILE="${PHLIX_JWT_SECRET_FILE:-/var/phlix/config/jwt_secret}"

# ---------------------------------------------------------------------------
# PHLIX_DATABASE_* -> DB_*   (S159 review finding 5)
#
# Every documented container deployment configures the database with
# `PHLIX_DATABASE_HOST/PORT/NAME/USER/PASSWORD` (docker-compose.yml,
# docker/examples/*/docker-compose.yml, k8s/helm/phlix/templates/deployment.yaml),
# but NO PHP in this repo reads those names — config/database.php reads
# `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USER` / `DB_PASSWORD`, and
# install/systemd.sh says so in as many words ("Only DB_PASSWORD is currently
# read by config/database.php; the rest are placeholders").
#
# That was harmless only while this script was unreachable. Fixing finding 4
# makes it reachable, and without this mapping a CORRECTLY configured container
# would run migrations against the `127.0.0.1:3306 / phlix` defaults with an
# empty password, fail, and print the PHLIX-MIGRATION-FAILURE banner on EVERY
# boot — and never start at all under PHLIX_MIGRATIONS_STRICT=1. A banner that
# always fires is a banner nobody reads.
#
# `DB_*` always wins when already set, so an operator who configures the app
# directly is never overridden. Exported (not merely assigned) so supervisord
# and the programs it starts inherit them too.
#
# ⚠ Known limit, stated rather than papered over: supervisord passes its own
# environment to its children, so the `workerman` program (which is the
# application) sees these. php-fpm pools default to `clear_env = yes`, so
# anything served through php-fpm does NOT — that is a pre-existing property of
# docker/supervisord.conf and is out of scope here.
# ---------------------------------------------------------------------------
[ -n "${DB_HOST:-}" ]     || DB_HOST="${PHLIX_DATABASE_HOST:-}"
[ -n "${DB_PORT:-}" ]     || DB_PORT="${PHLIX_DATABASE_PORT:-}"
[ -n "${DB_DATABASE:-}" ] || DB_DATABASE="${PHLIX_DATABASE_NAME:-}"
[ -n "${DB_USER:-}" ]     || DB_USER="${PHLIX_DATABASE_USER:-}"
[ -n "${DB_PASSWORD:-}" ] || DB_PASSWORD="${PHLIX_DATABASE_PASSWORD:-}"
export DB_HOST DB_PORT DB_DATABASE DB_USER DB_PASSWORD

# ---------------------------------------------------------------------------
# JWT_SECRET — the FIFTH boot blocker, and the one only a booted container can
# find (S163).
#
# `start.php:57-62` calls AuthServicesProvider::assertSecretConfigured() BEFORE
# any Worker is created and `exit(1)`s when JWT_SECRET is empty or is the
# shipped default: the same secret signs JWTs and derives the media signed-URL
# key, so an empty one makes both forgeable. Meanwhile
#
#     grep -rn JWT_SECRET docker-compose.yml docker/examples k8s/
#
# returns NOTHING. Every documented container deployment sets `PHLIX_SECRET_KEY`
# instead — a name no PHP in this repo reads — which is exactly the
# PHLIX_DATABASE_* trap of finding 5 all over again. So even with supervisord
# fixed to start the daemon, `docker compose up` as documented would have the
# app exit 1 on every boot and supervisord BACKOFF then FATAL it. That was
# invisible only because the daemon had never been started at all.
#
# Resolution order, most explicit first:
#   1. JWT_SECRET               — an operator configuring the app directly always wins.
#   2. PHLIX_SECRET_KEY         — what the compose/helm examples actually set.
#   3. $JWT_SECRET_FILE         — a secret generated by a PREVIOUS boot. It lives
#                                 under /var/phlix/config, a persistent volume in
#                                 every shipped example, so it survives restarts:
#                                 a secret that changed each boot would silently
#                                 invalidate every session and signed media URL.
#   4. generated here           — so a bare `docker run` works out of the box,
#                                 mirroring what scripts/install.sh does on a host.
#
# Generation uses od(1)+/dev/urandom (busybox and coreutils both have it) rather
# than `php -r`, so it cannot depend on the application it is about to start.
# If the secret cannot be persisted the container STILL starts — with a loud
# warning — because an unreachable config volume must not mean no media server.
# ---------------------------------------------------------------------------
phlix_generate_secret() {
    if od -An -N32 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n' | grep -qE '^[0-9a-f]{64}$'; then
        od -An -N32 -tx1 /dev/urandom | tr -d ' \n'
        return 0
    fi
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 32
        return 0
    fi
    return 1
}

# ---------------------------------------------------------------------------
# FAIL CLOSED on a documented placeholder. (S163 review F3)
#
# The PHLIX_SECRET_KEY -> JWT_SECRET mapping below is what makes the shipped
# compose examples work — and, before this guard, it also promoted
# `docker/examples/.env.example`'s committed placeholder into a REAL, live
# signing key: `/proc/1/environ` showed
# `JWT_SECRET=change_me_generate_with_openssl` and the daemon happily served
# 200s with it. Every reader of that file would have shared one publicly-known
# key that signs both JWTs and media signed URLs.
#
# AuthServicesProvider::assertSecretConfigured() only rejects '' and
# 'default-secret-change-me', so it could not catch this. The defect was
# INTRODUCED by the mapping — the variable was inert before it — so the guard
# belongs here, next to the mapping.
#
# Refusing to start is the correct failure: a container that boots with a
# guessable signing key is worse than one that tells the operator to generate
# a real one. Leaving the variable UNSET is fully supported and is now what the
# example recommends — the block further down generates and persists a key.
# ---------------------------------------------------------------------------
phlix_is_placeholder_secret() {
    case "$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]')" in
        change_me* | changeme* | change-me* | \
        default-secret-change-me | \
        your-secret-here | your_secret_here | \
        secret | password | placeholder | example | test)
            return 0
            ;;
        *) return 1 ;;
    esac
}

for _candidate_var in JWT_SECRET PHLIX_SECRET_KEY; do
    eval "_candidate_val=\${${_candidate_var}:-}"
    if [ -n "$_candidate_val" ] && phlix_is_placeholder_secret "$_candidate_val"; then
        echo "==========================================================" >&2
        echo "PHLIX-PLACEHOLDER-SECRET: ${_candidate_var} is set to the" >&2
        echo "documented placeholder value \"${_candidate_val}\"." >&2
        echo "" >&2
        echo "That string is committed to this repository, so it is public." >&2
        echo "Using it would make every JWT and every signed media URL on this" >&2
        echo "server forgeable by anyone. Refusing to start." >&2
        echo "" >&2
        echo "Either generate a real key:    openssl rand -hex 32" >&2
        echo "or UNSET ${_candidate_var} entirely and let this entrypoint" >&2
        echo "generate one and persist it to \${PHLIX_JWT_SECRET_FILE}." >&2
        echo "==========================================================" >&2
        exit 1
    fi
done
unset _candidate_var _candidate_val

[ -n "${JWT_SECRET:-}" ] || JWT_SECRET="${PHLIX_SECRET_KEY:-}"

if [ -z "${JWT_SECRET:-}" ] && [ -r "$JWT_SECRET_FILE" ]; then
    JWT_SECRET="$(cat "$JWT_SECRET_FILE" 2>/dev/null || true)"
    [ -n "$JWT_SECRET" ] && echo "Using the JWT signing key persisted at ${JWT_SECRET_FILE}."
fi

if [ -z "${JWT_SECRET:-}" ]; then
    JWT_SECRET="$(phlix_generate_secret 2>/dev/null || true)"
    if [ -z "$JWT_SECRET" ]; then
        echo "==========================================================" >&2
        echo "PHLIX-JWT-SECRET-UNAVAILABLE: could not generate a signing key" >&2
        echo "(no usable od(1)/openssl). Set JWT_SECRET explicitly — the" >&2
        echo "server refuses to start without one." >&2
        echo "==========================================================" >&2
    elif ( umask 077 && printf '%s' "$JWT_SECRET" > "$JWT_SECRET_FILE" ) 2>/dev/null; then
        echo "Generated a JWT signing key and persisted it to ${JWT_SECRET_FILE}."
    else
        echo "==========================================================" >&2
        echo "PHLIX-JWT-SECRET-EPHEMERAL: generated a JWT signing key but" >&2
        echo "could not write ${JWT_SECRET_FILE}." >&2
        echo "It will CHANGE on every restart, invalidating all sessions and" >&2
        echo "signed media URLs. Mount /var/phlix/config, or set JWT_SECRET." >&2
        echo "==========================================================" >&2
    fi
fi

export JWT_SECRET

# `1`/`true`/`yes`/`on`, case-insensitive, whitespace-insensitive. The trim
# matters: a docker `env_file` preserves a trailing space, and
# `PHLIX_MIGRATIONS_STRICT="1 "` silently falling through to boot-anyway is
# exactly the kind of opt-in that looks applied and is not (finding 6).
migrations_strict() {
    case "$(printf '%s' "${PHLIX_MIGRATIONS_STRICT:-}" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')" in
        1 | true | yes | on) return 0 ;;
        *) return 1 ;;
    esac
}

echo "Starting Phlix Media Server..."

migrations_skipped_reason=''
if [ -z "${DB_HOST:-}" ]; then
    migrations_skipped_reason="no database host configured (set PHLIX_DATABASE_HOST or DB_HOST)"
elif [ ! -f "${APP_ROOT}/scripts/run-migrations.php" ]; then
    migrations_skipped_reason="${APP_ROOT}/scripts/run-migrations.php not found"
fi

if [ -n "$migrations_skipped_reason" ]; then
    # Migrations did not run AT ALL. That is not a migration FAILURE, so no
    # PHLIX-MIGRATION-FAILURE banner — but it is also not the guarantee an
    # operator asked for with STRICT, which is "do not start unless the schema
    # is current". Before finding 6 this path booted silently even under
    # STRICT=1, i.e. the one setting whose entire purpose is to refuse.
    echo "Skipping database migrations: ${migrations_skipped_reason}."
    if migrations_strict; then
        echo "==========================================================" >&2
        echo "PHLIX-MIGRATIONS-NOT-RUN: ${migrations_skipped_reason}." >&2
        echo "PHLIX_MIGRATIONS_STRICT is set and the schema could not be" >&2
        echo "verified: refusing to start." >&2
        echo "==========================================================" >&2
        exit 1
    fi
else
    echo "Running database migrations..."
    # `set -e` must not abort here: the exit code is inspected below.
    # `cmd || var=$?` is the POSIX-portable way to suppress `set -e` while
    # KEEPING the status. (`if ! cmd; then …; fi` also suppresses `set -e`, but
    # discards `$?` — the exit code the banner and strict mode both need — so
    # do not "simplify" this into an `if !`.)
    migration_status=0
    php "${APP_ROOT}/scripts/run-migrations.php" || migration_status=$?

    if [ "$migration_status" -ne 0 ]; then
        echo "==========================================================" >&2
        echo "PHLIX-MIGRATION-FAILURE: scripts/run-migrations.php exited ${migration_status}." >&2
        echo "The database schema may be HALF-MIGRATED. Failing migrations are" >&2
        echo "not recorded in schema_migrations and are retried on next boot." >&2
        echo "Run 'php scripts/run-migrations.php' (or 'bin/phlix migrate')" >&2
        echo "for the full error list." >&2

        if migrations_strict; then
            echo "PHLIX_MIGRATIONS_STRICT is set: refusing to start." >&2
            echo "==========================================================" >&2
            exit "$migration_status"
        fi

        echo "Starting anyway (default). Set PHLIX_MIGRATIONS_STRICT=1 to" >&2
        echo "refuse to start on a migration failure instead." >&2
        echo "==========================================================" >&2
    fi
fi

exec "$SUPERVISORD" -c "$SUPERVISORD_CONF"
