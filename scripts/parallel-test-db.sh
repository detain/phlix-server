#!/usr/bin/env bash
# S457 (prototype) — create/drop the N clone schemas used by parallel Integration runs.
#   PHLIX_TEST_DB_SHARDS=N ./scripts/parallel-test-db.sh create|drop
# The template schema is $DB_DATABASE (default phlix_test), migrated exactly as CI
# migrates it; each clone is a fresh mysqldump pipe (schema + seed rows + routines).
set -euo pipefail
N="${PHLIX_TEST_DB_SHARDS:?set PHLIX_TEST_DB_SHARDS=<count>}"
TEMPLATE="${DB_DATABASE:-phlix_test}"
HOST="${DB_HOST:-127.0.0.1}"; PORT="${DB_PORT:-3306}"
USER="${DB_USER:-root}"; PASS="${DB_PASSWORD:-root}"
MYSQL=(mysql -h"$HOST" -P"$PORT" -u"$USER" ${PASS:+-p"$PASS"})
[ "${1:-}" = "create" ] || [ "${1:-}" = "drop" ] || { echo "usage: $0 create|drop" >&2; exit 2; }
for i in $(seq 1 "$N"); do
  db="${TEMPLATE}_w${i}"
  if [ "$1" = "drop" ]; then
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$db\`"
    continue
  fi
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4"
  mysqldump -h"$HOST" -P"$PORT" -u"$USER" ${PASS:+-p"$PASS"} \
    --no-tablespaces --routines --triggers --single-transaction "$TEMPLATE" \
    | "${MYSQL[@]}" "$db"
  tables=$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db'")
  echo "$db: $tables tables"
done
