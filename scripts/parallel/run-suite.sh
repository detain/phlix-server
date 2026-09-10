#!/usr/bin/env bash
# S457 — run one PHPUnit testsuite under paraunit with per-worker isolation.
#
# Usage: scripts/parallel/run-suite.sh <Unit|Integration> <parallel-count> [shard-count] [--coverage <out.covobj>]
#
#   --coverage <out.covobj>  collect coverage and emit the merged php-code-coverage
#                         OBJECT (not Clover) to <out.covobj>; scripts/parallel/
#                         merge-coverage.php unions the per-suite objects into
#                         coverage.xml with PHPUnit's own CloverGenerator. The
#                         .covobj suffix is load-bearing: a .php extension at the
#                         repo root joins the S431 executable-census file count and
#                         poisons every later local run.
#
# The E2E suite is deliberately NOT runnable here: parallel E2E contends for the
# hardware encoder and HwaccelE2ETest flakes under load (measured in the S457
# prototype). CI runs E2E as a serial tail step with the same config.
#
# Isolation seams (all opt-in; serial runs are byte-identical without them):
#   - TMPDIR root under PHLIX_TEST_TMP_BASE/worker-<id> via scripts/parallel/php PATH shim
#     (sys_get_temp_dir() binds at MINIT, so this MUST happen at exec time, not via putenv).
#   - DB_DATABASE=phlix_test_wK via tests/bootstrap.php slot claim (PHLIX_TEST_DB_SHARDS).
#     Integration workers refuse to start unless shards >= parallel; run
#     scripts/parallel-test-db.sh create first.
#   - --do-not-cache-result (.phpunit.cache) is passed through to every child;
#     --no-coverage additionally in run mode (per-child coverage init costs ~2.7s).
#   - Every child writes its own JUnit XML: the __PHLIX_WORKER__ token in
#     --log-junit is expanded to the child's PARAUNIT_PROCESS_UNIQUE_ID by the shim.
#     merge-junit.php unions them (plus the E2E tail's junit-e2e.xml) into junit.xml,
#     which is what the S305 browser-E2E gate reads.
#   - The AssertionEscapeGuard writes per-child reports; assertion-escape-check.php
#     globs the family. No action needed here.
#
# paraunit reports "N files with ERRORS/FAILURES" without guaranteeing a non-zero
# exit code of its own; any non-zero ERRORS/FAILURES file count therefore fails this
# script (S457). WARNINGS are surfaced but NOT fatal, deliberately: the recorded set
# includes warnings that are the behavior under test (src failure-path @fsockopen /
# @file_get_contents against unroutable URLs, corrupt-fixture @getimagesize/@exif,
# Monolog's lazy-stream @fileinode before first write). They always fired — serial
# printers hide suppressed PHP events, paraunit prints them. The file-system hygiene
# warnings that were genuine test debt (unlink/rmdir/stat on paths a test knows may
# be absent) are fixed; a growing WARNINGS block in CI is still loud and reviewable.
set -euo pipefail

usage() {
    echo "usage: $0 <Unit|Integration> <parallel> [shards] [--coverage <out.covobj>]" >&2
}

suite="${1:-}"; parallel="${2:-}"
case "$suite" in
  Unit|Integration) ;;
  E2E) echo "E2E must run as a serial tail step — parallel E2E flakes on hardware-encoder contention (S457)." >&2; exit 2 ;;
  *) usage; exit 2 ;;
esac
[[ "$parallel" =~ ^[0-9]+$ ]] && (( parallel >= 1 )) || { usage; exit 2; }
shift 2

shards=""
if [[ "${1:-}" =~ ^[0-9]+$ ]]; then
    shards="$1"
    shift
fi
if [[ "${1:-}" == "--coverage" ]]; then
    coverage_out="${2:-}"
    [[ -n "$coverage_out" && "$coverage_out" != -* ]] || { echo "--coverage needs an output path" >&2; exit 2; }
    mode="coverage"
else
    coverage_out=""
    mode="run"
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export PHLIX_TEST_TMP_BASE="${PHLIX_TEST_TMP_BASE:-/tmp/phlix-parallel-tests}"
export PATH="$repo_root/scripts/parallel:$PATH"

case "$suite" in
  Integration)
    shards="${shards:-$parallel}"
    (( shards >= parallel )) || { echo "shards ($shards) must be >= parallel ($parallel)" >&2; exit 2; }
    export PHLIX_TEST_DB_SHARDS="$shards"
    mysqladmin --protocol=tcp -h 127.0.0.1 -P 3306 -u root -proot status >/dev/null \
      || { echo "MySQL not reachable at 127.0.0.1:3306 - run scripts/parallel-test-db.sh create first" >&2; exit 1; }
    ;;
  *)
    unset PHLIX_TEST_DB_SHARDS || true
    ;;
esac

rm -rf "$PHLIX_TEST_TMP_BASE"
mkdir -p "$PHLIX_TEST_TMP_BASE"
cd "$repo_root"

# One consistent wipe point for the merged-JUnit corpus: the CI flow is
# Unit -> Integration -> E2E tail -> merge, and Unit is always first.
if [ "$suite" = "Unit" ]; then
    rm -rf .phpunit-junit
fi
mkdir -p .phpunit-junit

log="$PHLIX_TEST_TMP_BASE/paraunit-$suite.log"

paraunit_args=(
    --parallel="$parallel"
    --configuration=phpunit-parallel.xml
    --testsuite "$suite"
    --pass-through=--do-not-cache-result
    --pass-through=--log-junit=.phpunit-junit/junit-__PHLIX_WORKER__.xml
)
if [ "$mode" = "coverage" ]; then
    paraunit_args+=(--php="$coverage_out" --text-summary)
else
    paraunit_args+=(--pass-through=--no-coverage)
fi

status=0
TMPDIR="$PHLIX_TEST_TMP_BASE" php -d max_execution_time=0 vendor/bin/paraunit "$mode" \
    "${paraunit_args[@]}" >"$log" 2>&1 || status=$?
cat "$log"

if [ "$status" -ne 0 ]; then
    exit "$status"
fi

if grep -Eq '[1-9][0-9]* files with (ERRORS|FAILURES)' "$log"; then
    echo "run-suite: paraunit reported files with ERRORS/FAILURES — see the block above." >&2
    exit 1
fi

# `|| true` is load-bearing, not cosmetics: this script runs under `set -euo pipefail`,
# and grep exits 1 when it matches nothing. A CLEAN lane has no "files with WARNINGS"
# line at all, so without the guard the substitution returns 1, pipefail propagates it,
# and set -e kills the script with exit 1 on a run that passed (PR 758 CI run 3: Unit
# survived only because it had 33 warning files to match; Integration had 0 and died here
# with paraunit itself having exited 0 — note paraunit only ever returns 0 or 10).
warn_line="$(grep -E '^[0-9]+ files with WARNINGS' "$log" | tail -1 || true)"
if [ -n "$warn_line" ]; then
    echo "run-suite: ${warn_line} — recorded PHP warnings (see WARNINGS block above); fatal classes are ERRORS/FAILURES only."
fi
