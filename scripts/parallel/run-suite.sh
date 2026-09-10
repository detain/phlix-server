#!/usr/bin/env bash
# S457 prototype: run one PHPUnit testsuite under paraunit with per-worker isolation.
#
# Usage: scripts/parallel/run-suite.sh <Unit|Integration|E2E> <parallel-count> [shard-count]
#
# Isolation seams (all opt-in; serial runs are byte-identical without them):
#   - TMPDIR root under PHLIX_TEST_TMP_BASE/worker-<id> via scripts/parallel/php PATH shim
#     (sys_get_temp_dir() binds at MINIT, so this MUST happen at exec time, not via putenv).
#   - DB_DATABASE=phlix_test_wK via tests/bootstrap.php slot claim (PHLIX_TEST_DB_SHARDS).
#     Integration workers refuse to start unless shards >= parallel; run
#     scripts/parallel-test-db.sh <shards> first.
#   - --do-not-cache-result (.phpunit.cache) and --no-coverage (per-child coverage
#     init costs ~2.7s) are passed through to every child.
set -euo pipefail

suite="${1:-}"; parallel="${2:-}"
case "$suite" in Unit|Integration|E2E) ;; *) echo "usage: $0 <Unit|Integration|E2E> <parallel> [shards]" >&2; exit 2 ;; esac
[[ "$parallel" =~ ^[0-9]+$ ]] && (( parallel >= 1 )) || { echo "parallel must be a positive integer" >&2; exit 2; }

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export PHLIX_TEST_TMP_BASE="${PHLIX_TEST_TMP_BASE:-/tmp/phlix-parallel-tests}"
export PATH="$repo_root/scripts/parallel:$PATH"

case "$suite" in
  Integration)
    shards="${3:-$parallel}"
    (( shards >= parallel )) || { echo "shards ($shards) must be >= parallel ($parallel)" >&2; exit 2; }
    export PHLIX_TEST_DB_SHARDS="$shards"
    mysqladmin --protocol=tcp -h 127.0.0.1 -P 3306 -u root -proot status >/dev/null \
      || { echo "MySQL not reachable at 127.0.0.1:3306 - run scripts/parallel-test-db.sh $shards first" >&2; exit 1; }
    ;;
  *)
    unset PHLIX_TEST_DB_SHARDS || true
    ;;
esac

rm -rf "$PHLIX_TEST_TMP_BASE"
mkdir -p "$PHLIX_TEST_TMP_BASE"
cd "$repo_root"
TMPDIR="$PHLIX_TEST_TMP_BASE" exec php -d max_execution_time=0 vendor/bin/paraunit run \
  --parallel="$parallel" \
  --configuration=phpunit-parallel.xml \
  --testsuite "$suite" \
  --pass-through=--do-not-cache-result \
  --pass-through=--no-coverage
