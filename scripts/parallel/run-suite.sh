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
# Verdict contract (S485). Measured on facile-it/paraunit 2.11.0 in this vendor tree:
# the runner maps TEST VERDICTS to exactly two codes — 0 when every worker exited 0,
# 10 when any worker exited non-zero (Runner::onProcessParsingCompleted). Because
# children run with failOnWarning=true, a warnings-only lane ALSO exits 10: CI run
# 34651283031 attempt-1 killed the Integration lane over the same chmod/unlink
# warning text that had passed twice before. The old wrapper re-exited any non-zero
# status verbatim (old :122-124) while its comment claimed warnings were non-fatal —
# the comment was the intent, the code was the flake. Now paraunit_verdict() grades
# the artifacts paraunit itself produced:
#   - status outside {0,10}  never ran children to a verdict (console/config
#                            failure, measured exit 1) — propagate verbatim;
#   - ERRORS / FAILURES      recap count > 0 — fatal (the S457 gate, unchanged);
#   - ABNORMAL TERMINATIONS  a worker died mid-test — fatal;
#   - RISKY OUTCOME          failOnRisky=true says risky is a failure here — fatal;
#   - WARNINGS block entry   ' [UNKNOWN]' — a runner-level warning not attributable
#                            to any test file (paraunit Test::unknown()); this is
#                            the S439 zero-residue-census / tooling warning class
#                            and it STAYS RED;
#   - JUnit tail holds       <error>/<failure> — fatal backstop against recap
#     element                            wording drift (warnings can never appear
#                                        in JUnit: JunitXmlLogger has no such node);
#   - zero JUnit tails       under status 10 the workers produced no evidence —
#                            a gate that cannot read results must not report
#                            success (same doctrine as coverage-threshold-check);
#   - anything else          deterministic NON-fatal: the warn note is echoed and
#                            the lane passes. WARNINGS remain loud and reviewable;
#                            the recorded set includes warnings that are the
#                            behavior under test (src failure-path @fsockopen /
#                            @file_get_contents against unroutable URLs,
#                            corrupt-fixture @getimagesize/@exif, Monolog's
#                            lazy-stream @fileinode before first write).
# Known limit: a test-attributed PHPUnit warning is indistinguishable from a PHP
# warning in the recap (paraunit collapses both onto the test file), so it is
# demoted together with them; tooling warnings keep their fatal route via the
# [UNKNOWN] marker. Console errors exit 1, never 10, so they can't hide in here.
set -euo pipefail

usage() {
    echo "usage: $0 <Unit|Integration> <parallel> [shards] [--coverage <out.covobj>]" >&2
    echo "       $0 verdict <status> <paraunit-log> <junit-dir>  (S485 seam: grade artifacts without paraunit)" >&2
}

# paraunit_verdict <status> <log> <junit-dir> — the exit-status half of the
# contract above. Echoes its reasoning; EXITS the script (0 pass, 1 fatal, or the
# verbatim status when paraunit produced no test verdict). Test-attributed via
# scripts/parallel/run-suite.sh's `verdict` subcommand by
# tests/Unit/Support/ParaunitVerdictExactnessTest.php against REAL paraunit output.
paraunit_verdict() {
    local status="$1" log="$2" junit_dir="$3"

    if [ "$status" != "0" ] && [ "$status" != "10" ]; then
        echo "run-suite: paraunit exited $status without producing a test verdict — the runner maps verdicts to 0/10 only; console/config failures exit 1 (measured 2.11.0). Propagating verbatim." >&2
        exit "$status"
    fi

    if grep -Eq '[1-9][0-9]* files with (ERRORS|FAILURES)' "$log"; then
        echo "run-suite: paraunit reported files with ERRORS/FAILURES — see the block above." >&2
        exit 1
    fi

    if [ "$status" = "10" ]; then
        if grep -Eq '[1-9][0-9]* files with ABNORMAL TERMINATIONS' "$log"; then
            echo "run-suite: paraunit exited 10 with ABNORMAL TERMINATIONS — a worker died mid-test. Failing." >&2
            exit 1
        fi

        # Block-scoped: the literal line ' [UNKNOWN]' inside a WARNINGS recap block
        # (header, then single-space-indented names, ended by the next blank line).
        # An awk + variable, never a pipeline: under `set -o pipefail` a `grep -q`
        # closing the pipe would SIGPIPE awk (exit 141) and flip the whole condition
        # false — the same trap the `|| true` below documents.
        local unknown_warning
        unknown_warning="$(awk '/^[0-9]+ files with WARNINGS:/{b=1;next} b && /^ /{if ($0 == " [UNKNOWN]") {print; exit} next} b{b=0}' "$log")"
        if [ -n "$unknown_warning" ]; then
            echo "run-suite: paraunit exited 10 with a runner-level (non-test-attributable) [UNKNOWN] warning — the S439 census/tooling class; fatal. See the WARNINGS block above." >&2
            exit 1
        fi

        if grep -Eq '[1-9][0-9]* files with RISKY OUTCOME' "$log"; then
            echo "run-suite: paraunit exited 10 with RISKY OUTCOME — phpunit-parallel.xml sets failOnRisky=true; failing." >&2
            exit 1
        fi

        local tails=( "$junit_dir"/*.xml )
        if [ ! -e "${tails[0]}" ]; then
            echo "run-suite: paraunit exited 10 but wrote no JUnit tails under $junit_dir — no evidence to grade, no pass." >&2
            exit 1
        fi
        if grep -qE '<(error|failure)[ />]' "${tails[@]}"; then
            echo "run-suite: paraunit exited 10 and a JUnit tail records an <error>/<failure> element (recap wording drift backstop) — failing." >&2
            exit 1
        fi
    fi

    # `|| true` is load-bearing, not cosmetics: this script runs under `set -euo pipefail`,
    # and grep exits 1 when it matches nothing. A CLEAN lane has no "files with WARNINGS"
    # line at all, so without the guard the substitution returns 1, pipefail propagates it,
    # and set -e kills the script with exit 1 on a run that passed (PR 758 CI run 3: Unit
    # survived only because it had 33 warning files to match; Integration had 0 and died here
    # with paraunit itself having exited 0 — paraunit maps test verdicts to 0/10 only).
    local warn_line
    warn_line="$(grep -E '^[0-9]+ files with WARNINGS' "$log" | tail -1 || true)"
    if [ -n "$warn_line" ]; then
        echo "run-suite: ${warn_line} — recorded PHP warnings (see WARNINGS block above); fatal classes are ERRORS, FAILURES, ABNORMAL TERMINATIONS, RISKY OUTCOME and runner-level [UNKNOWN] warnings only."
    fi
    if [ "$status" = "10" ]; then
        echo "run-suite: paraunit exited 10 on test-attributable warnings only — deterministic non-fatal verdict (S485)."
    fi
}

if [ "${1:-}" = "verdict" ]; then
    shift
    [ "$#" -eq 3 ] || { usage; exit 2; }
    [[ "$1" =~ ^[0-9]+$ ]] && [ -f "$2" ] && [ -d "$3" ] || { usage; exit 2; }
    paraunit_verdict "$1" "$2" "$3"
    exit 0
fi

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
    # Credentials come from the same DB_* env phpunit.xml exports for the serial lane;
    # hardcoding root/root here would probe a server the run does not actually use.
    mysqladmin --protocol=tcp -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" \
      -u "${DB_USER:-root}" "-p${DB_PASSWORD:-root}" status >/dev/null \
      || { echo "MySQL not reachable at ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306} - run scripts/parallel-test-db.sh create first" >&2; exit 1; }
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

paraunit_verdict "$status" "$log" "$repo_root/.phpunit-junit"
