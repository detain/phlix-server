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
# PHLIX_APP_ROOT / PHLIX_SUPERVISORD / PHLIX_SUPERVISORD_CONF exist so this
# script can be smoke-tested end to end (it is not exercised by any image
# build in CI). The defaults are the image's real paths, so container behaviour
# is unchanged. See tests/Unit/Docker/DockerEntrypointTest.php, which drives
# every branch below with stub `php` / `supervisord` executables.
# ---------------------------------------------------------------------------

APP_ROOT="${PHLIX_APP_ROOT:-/var/www/html}"
SUPERVISORD="${PHLIX_SUPERVISORD:-/usr/bin/supervisord}"
SUPERVISORD_CONF="${PHLIX_SUPERVISORD_CONF:-/etc/supervisor/conf.d/supervisord.conf}"

echo "Starting Phlix Media Server..."

if [ -n "${PHLIX_DATABASE_HOST}" ]; then
    if [ -f "${APP_ROOT}/scripts/run-migrations.php" ]; then
        echo "Running database migrations..."
        # `set -e` must not abort here: the exit code is inspected below, and
        # `if ! cmd` is the POSIX-portable way to keep `set -e` from firing.
        migration_status=0
        php "${APP_ROOT}/scripts/run-migrations.php" || migration_status=$?

        if [ "$migration_status" -ne 0 ]; then
            echo "==========================================================" >&2
            echo "PHLIX-MIGRATION-FAILURE: scripts/run-migrations.php exited ${migration_status}." >&2
            echo "The database schema may be HALF-MIGRATED. Failing migrations are" >&2
            echo "not recorded in schema_migrations and are retried on next boot." >&2
            echo "Run 'php scripts/run-migrations.php' (or 'bin/phlix migrate')" >&2
            echo "for the full error list." >&2

            case "$(printf '%s' "${PHLIX_MIGRATIONS_STRICT:-}" | tr '[:upper:]' '[:lower:]')" in
                1 | true | yes | on)
                    echo "PHLIX_MIGRATIONS_STRICT is set: refusing to start." >&2
                    echo "==========================================================" >&2
                    exit "$migration_status"
                    ;;
                *)
                    echo "Starting anyway (default). Set PHLIX_MIGRATIONS_STRICT=1 to" >&2
                    echo "refuse to start on a migration failure instead." >&2
                    echo "==========================================================" >&2
                    ;;
            esac
        fi
    fi
fi

exec "$SUPERVISORD" -c "$SUPERVISORD_CONF"
