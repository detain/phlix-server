#!/usr/bin/env bash
#
# S345 — turn a PHPUnit run's output into a SORTED SKIPPED-TEST NAME SET, fit for `comm`.
#
# =============================================================================
# WHY THIS EXISTS
# =============================================================================
# Until S345 the only thing any run of this suite published about its skips was a
# COUNT ("Skipped: 8"), and a count cannot distinguish the three things that move it:
#
#   * a test STARTED skipping                (a real regression, e.g. a guard now fires)
#   * a conditionally-skipped test became a FAILURE (it left the skip set AND is red)
#   * a test DISAPPEARED                     (deleted, renamed, or its file stopped
#                                             being collected at all)
#
# All three move the number the same way, and the number also drifts on its own
# between runs because this repo has legitimate environment-dependent skips. That
# cost real audit work twice in one day: S131's mutation runs moved the count
# 11 -> 5 -> 8 -> 8 and the movement had to be recorded as UNPROVEN, and S323
# phase 2's "no rise in skipped tests" had to be settled by building name sets by
# hand.
#
# A NAME SET settles all three, and `comm -3` prints exactly the difference:
#
#     ./vendor/bin/phpunit 2>&1 | scripts/skipped-test-names.sh > /tmp/before.txt
#     # ...change something...
#     ./vendor/bin/phpunit 2>&1 | scripts/skipped-test-names.sh > /tmp/after.txt
#     comm -3 /tmp/before.txt /tmp/after.txt      # left column = left, right = joined
#
# Against a CI run (the log is prefixed `job<TAB>step<TAB>timestamp `, which this
# script strips for you):
#
#     gh run view <run-id> --log | scripts/skipped-test-names.sh > /tmp/ci.txt
#
# =============================================================================
# WHAT FEEDS IT -- AND THE ONE INVOCATION IT CANNOT REACH
# =============================================================================
# `phpunit.xml` carries `displayDetailsOnSkippedTests="true"` (S345). That is the
# CONFIG attribute rather than the `--display-skipped` CLI flag on purpose: the flag
# is per-invocation, and PHPUnit is invoked from five places in this repo
# (.github/workflows/phpunit.yml:173,329, .github/workflows/syncplay-e2e.yml:90,96,
# and scripts/assertion-escape-audit.php:504) plus every developer's shell. A flag
# would have to be re-added to each of them and to every job added later -- the
# "hand-written list that never re-derives" shape.
#
# ⚠ The attribute is read by every invocation that loads phpunit.xml, but it only
# REACHES the output of invocations that use PHPUnit's DEFAULT result printer. It is
# NOT universal, and two exceptions are real:
#
#   * `--testdox`. PHPUnit builds the default result printer for a testdox run with
#     the `$displayDetailsOnSkippedTests` constructor argument hardcoded `false`
#     (vendor/phpunit/phpunit/src/TextUI/Output/Facade.php:204-221 -- the
#     configuration value is not consulted on that path). A `--testdox` run therefore
#     counts its skips in `Skipped: N` and can NEVER name them, whatever phpunit.xml
#     or `--display-skipped` say. Measured: `--testdox` on a file with one
#     unconditional skip prints `Skipped: 1` and zero `There was 1 skipped test:`
#     sections, with and without `--display-skipped`. This script detects testdox
#     output and exits 5 saying so, instead of blaming the config (see below).
#     `syncplay-e2e.yml` used to pass `--testdox`; S345's fixer removed it for
#     exactly this reason.
#   * the assertion-escape prober (scripts/assertion-escape-audit.php:504) captures
#     PHPUnit's output into a PHP variable and only tests it for a marker; it never
#     echoes it, so no log and no name set is obtainable from that job. Nothing is
#     wrong with the config there -- the output simply never leaves the process.
#
# Neither the attribute nor the flag changes the process exit status, and neither
# suppresses any other reporting: PHPUnit's
# `TextUI/Output/Default/ResultPrinter::print()` (lines 125-128) uses
# `displayDetailsOnSkippedTests` to gate two `printList*` calls and nothing else.
# `failOnSkipped` is a SEPARATE setting and is not set in this repo.
#
# =============================================================================
# TWO LISTS, NOT ONE
# =============================================================================
# `displayDetailsOnSkippedTests` gates BOTH `printSkippedTestSuites()` and
# `printSkippedTests()` (ResultPrinter.php:125-128), and `Skipped: N` is the SUM of
# both event kinds (SummaryPrinter.php:106). So this script parses both headers:
#
#   `There were N skipped tests:`       -> entries are `Class::method[ with data set ...]`
#   `There were N skipped test suites:` -> entries are bare suite names, NO `::`
#
# A skipped SUITE (a class-level `#[Requires*]`, or `markTestSkipped()` in
# `setUpBeforeClass()`) is emitted into the set as `SUITE:<name>` so that it is
# visibly a different kind of entry and still sorts and `comm`s like any other line.
# Reproduced with the real binary: 1 skipped suite + 1 skipped test prints both
# headers and `Skipped: 2`.
#
# ⚠ A run in which EVERY suite was skipped prints `No tests executed!` and NO
# `Tests: ...` line at all (SummaryPrinter.php:35-41), so the `Skipped: N`
# cross-check is simply absent for it; the detail list is then the only source. This
# script accounts for that instead of reporting drift.
#
# =============================================================================
# A PARSER THAT MATCHES NOTHING MUST NOT READ AS A PASS
# =============================================================================
# Empty stdout is a legitimate answer ("nothing skipped"), and it is also what a
# parser fed the wrong input produces. So this script refuses to be silent about the
# difference. On stderr it always prints its denominators -- lines read, whether a
# PHPUnit result summary was seen, the count PHPUnit itself declared, and the number
# of names emitted -- and it exits:
#
#     0  parsed a real PHPUnit run (zero skips is a valid 0-line result)
#     2  the input is not PHPUnit output at all (no result summary anywhere)
#     3  parser drift: the numbers in the input do not add up, so the set may be
#        partial and no better cause can be named
#     4  the run DID skip tests, printed no names, and is NOT testdox output --
#        `displayDetailsOnSkippedTests` is off, so the set is unobtainable
#     5  the input contains `--testdox` output, whose skips can never be named (see
#        above). NOT a config fault; re-run without `--testdox`.
#     6  the sorted set could not be written (sort or the caller's redirect failed)
#
# Exit 4 is the one that matters most, and it is why this script cross-checks TWO
# independent numbers instead of one. On master before S345 the log of run
# 32027697809 says `Tests: 10249, Assertions: 79041, Skipped: 3.` and contains ZERO
# `There were N skipped tests:` sections. A parser that trusted only the detail
# section would have printed an empty set and exited 0 -- reporting "nothing skipped"
# about a run that skipped three tests. That is exactly the shape this step exists to
# kill, so it must not be reintroduced by the tool that kills it.
#
# Every non-zero exit must name a cause that is TRUE for the input that produced it:
# a wrong diagnosis is worse than none, because it sends the next reader to fix the
# wrong thing. That is why 4 and 5 are separate codes, and why 3's message no longer
# claims "fix this parser" when a missing list is the likelier cause.
#
# KNOWN LIMIT, recorded rather than hidden: a data-set label containing a literal
# NEWLINE is emitted truncated at that newline (the entry spans two lines and the
# denominators still agree, so this one shape escapes the cross-check). No data
# provider in this repo produces one, and the truncation is stable across runs, so
# `comm` is not corrupted by it -- but the denominator argument above does not cover
# it, so do not claim that it does.
#
# stdout carries ONLY the name set, so `comm` and `diff` can consume it directly.
#
# =============================================================================
# THE ONE-LINER, for when you do not have this script to hand
# =============================================================================
# (identical extraction, without the denominators or the exit contract)
#
#   sed -E 's/\r$//; s/\x1B\[[0-9;]*[A-Za-z]//g; s/^[^\t]*\t[^\t]*\t[^0-9]*[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z //' \
#     | awk '/^There (was|were) [0-9]+ skipped test suites?:$/{m="s";next} \
#            /^There (was|were) [0-9]+ skipped tests?:$/{m="t";next} \
#            /^(--|OK \(|OK, but |FAILURES!|ERRORS!|WARNINGS!|No tests executed!|Tests: )/{m=""} \
#            m=="t" && /^[0-9]+\) [A-Za-z_\\][A-Za-z0-9_\\]*::/{sub(/^[0-9]+\) /,"");print} \
#            m=="s" && /^[0-9]+\) [A-Za-z_\\][A-Za-z0-9_:\\]*$/{sub(/^[0-9]+\) /,"");print "SUITE:" $0}' \
#     | LC_ALL=C sort -u
#
# `set -e` is deliberately NOT used: this script's whole job is to inspect exit codes
# (the `grep -c` at the positive control returns 1 on zero matches, which is not an
# error here), so an implicit abort would skip the denominators it must always print.
# Every command whose failure could truncate stdout is checked EXPLICITLY instead --
# see the `sort` guard at the bottom, which is what exit 6 exists for.
set -uo pipefail

usage() {
    cat >&2 <<'USAGE'
usage: skipped-test-names.sh [FILE]

Reads PHPUnit console output (FILE, or stdin when FILE is omitted or `-`) and
writes the SORTED, DEDUPLICATED set of skipped test names -- `Class::method`, one
per line, plus `SUITE:<name>` for a skipped test SUITE -- to stdout. Denominators
go to stderr. See the header of this file for the exit contract (0/2/3/4/5/6).
USAGE
}

case "${1:-}" in
    -h|--help) usage; exit 0 ;;
esac

src="${1:--}"
if [ "$src" != "-" ] && [ ! -r "$src" ]; then
    printf 'skipped-test-names: cannot read %s\n' "$src" >&2
    exit 2
fi

raw="$(if [ "$src" = "-" ]; then cat; else cat -- "$src"; fi)"

# `gh run view --log` prefixes every line with `job<TAB>step<TAB><ISO-8601>Z `.
# Strip that, any CRLF, and any ANSI escape (a `--colors=always` run wraps the
# summary lines, which would otherwise defeat the positive control below) so that the
# section anchors can use `^`.
normalised="$(printf '%s\n' "$raw" \
    | sed -E 's/\r$//; s/\x1B\[[0-9;]*[A-Za-z]//g; s/^[^\t]*\t[^\t]*\t[^0-9]*[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z //')"

lines_read="$(printf '%s\n' "$normalised" | wc -l | tr -d ' ')"

# Positive control: did we get PHPUnit output at all? Every terminating PHPUnit run
# prints exactly one of these. If none is present the input is something else, and an
# empty name set from it means nothing.
summary="$(printf '%s\n' "$normalised" \
    | grep -c -E '^(OK \(|OK, but |FAILURES!|ERRORS!|WARNINGS!|No tests executed!|Tests: )' || true)"

# Runs that published NO `Tests: ...` line, so no `Skipped: N` cross-check exists for
# them (every suite was skipped -- SummaryPrinter.php:35-41).
no_totals="$(printf '%s\n' "$normalised" | grep -c -E '^No tests executed!$' || true)"

# `--testdox` output: ` <symbol> <prettified name>` per test
# (TextUI/Output/TestDox/ResultPrinter.php:80-96,342-365). `↩` is its skip symbol.
testdox_lines="$(printf '%s\n' "$normalised" | grep -c -E '^ (✔|✘|↩|⚠|∅) ' || true)"
testdox_skips="$(printf '%s\n' "$normalised" | grep -c -E '^ ↩ ' || true)"

# Two INDEPENDENT denominators, summed across every PHPUnit run present in the input
# (a whole-workflow `gh run view --log` contains several):
#
#   `declared`  -- the headers of each run's skipped-details lists, BOTH kinds
#                  (tests and test suites). Zero when the details are not displayed.
#   `summarised`-- the `Skipped: N` term of each run's `Tests: ...` summary line. This
#                  is printed unconditionally (when non-zero), so it is the number
#                  that betrays a missing `displayDetailsOnSkippedTests`.
declared="$(printf '%s\n' "$normalised" \
    | sed -nE 's/^There (was|were) ([0-9]+) skipped (tests?|test suites?):$/\2/p' \
    | awk '{ t += $1 } END { print t + 0 }')"

summarised="$(printf '%s\n' "$normalised" \
    | sed -nE 's/^Tests: [0-9]+,.*[^A-Za-z]Skipped: ([0-9]+).*/\1/p' \
    | awk '{ t += $1 } END { print t + 0 }')"

names="$(printf '%s\n' "$normalised" | awk '
    /^There (was|were) [0-9]+ skipped test suites?:$/ { mode = "suite"; next }
    /^There (was|were) [0-9]+ skipped tests?:$/       { mode = "test";  next }
    /^--$/                                            { mode = "" }
    /^(OK \(|OK, but |FAILURES!|ERRORS!|WARNINGS!|No tests executed!|Tests: )/ { mode = "" }
    mode == "test"  && /^[0-9]+\) [A-Za-z_\\][A-Za-z0-9_\\]*::/     { sub(/^[0-9]+\) /, ""); print }
    mode == "suite" && /^[0-9]+\) [A-Za-z_\\][A-Za-z0-9_:\\]*$/     { sub(/^[0-9]+\) /, ""); print "SUITE:" $0 }
')"

if [ -n "$names" ]; then
    extracted="$(printf '%s\n' "$names" | wc -l | tr -d ' ')"
else
    extracted=0
fi

printf 'skipped-test-names: %s lines read, %s PHPUnit result summary line(s) seen, %s skipped test(s) in the run summaries (%s run(s) printed no totals line), %s declared by the detail lists, %s testdox result line(s) (%s skipped), %s name(s) extracted.\n' \
    "$lines_read" "$summary" "$summarised" "$no_totals" "$declared" "$testdox_lines" "$testdox_skips" "$extracted" >&2

if [ "$summary" -eq 0 ]; then
    printf 'skipped-test-names: FATAL -- no PHPUnit result summary in the input, so this is not a PHPUnit run and an empty name set proves nothing. Feed it `./vendor/bin/phpunit 2>&1` or `gh run view <id> --log`.\n' >&2
    exit 2
fi

# The input under-reports names. Name the cause that is actually true for it.
if [ "$summarised" -gt "$declared" ]; then
    missing=$((summarised - declared))

    if [ "$testdox_lines" -gt 0 ]; then
        printf 'skipped-test-names: FATAL -- %s skipped test(s) in this input can never be named, because it contains `--testdox` output (%s testdox result line(s), %s of them skipped). PHPUnit constructs the default result printer with displayDetailsOnSkippedTests hardcoded FALSE when --testdox is in effect (vendor/phpunit/phpunit/src/TextUI/Output/Facade.php:204-221); the configuration value is not consulted, so neither phpunit.xml nor --display-skipped can fix it. Do NOT change phpunit.xml -- it is not the cause. Re-run the invocation WITHOUT --testdox, or feed this script only the non-testdox step of the log.\n' \
            "$missing" "$testdox_lines" "$testdox_skips" >&2
        exit 5
    fi

    if [ "$declared" -eq 0 ]; then
        printf 'skipped-test-names: FATAL -- the run summaries report %s skipped test(s) but printed NO names, and this is not testdox output. phpunit.xml has lost displayDetailsOnSkippedTests="true" (S345), or this invocation did not load phpunit.xml (a `-c`/`--configuration` pointing elsewhere, or `--no-configuration`). The name set cannot be recovered from this output; fix the config and re-run rather than reading the empty set as "nothing skipped".\n' \
            "$summarised" >&2
        exit 4
    fi

    printf 'skipped-test-names: FATAL -- the run summaries report %s skipped test(s) but the detail lists declared only %s, so %s name(s) are missing. Some run in this input printed no skipped list: check that every invocation loads phpunit.xml (displayDetailsOnSkippedTests="true") and that none of them uses --testdox. The set below would be PARTIAL, so it is not written.\n' \
        "$summarised" "$declared" "$missing" >&2
    exit 3
fi

# More declared than summarised is legitimate ONLY for a run that printed no totals
# line at all (`No tests executed!`); otherwise the parser has over-counted.
if [ "$declared" -gt "$summarised" ] && [ "$no_totals" -eq 0 ]; then
    printf 'skipped-test-names: FATAL -- the detail lists declared %s skipped test(s) but the run summaries report only %s, and no run reported `No tests executed!` (the one shape that legitimately publishes a list without a totals line). The output format has drifted; fix this parser rather than trusting the set.\n' \
        "$declared" "$summarised" >&2
    exit 3
fi

if [ "$extracted" -ne "$declared" ]; then
    printf 'skipped-test-names: FATAL -- PHPUnit declared %s skipped test(s)/suite(s) but %s name(s) were extracted. The entry format has drifted; fix this parser rather than trusting the set.\n' \
        "$declared" "$extracted" >&2
    exit 3
fi

if [ "$extracted" -gt 0 ]; then
    # `LC_ALL=C` is load-bearing, not decoration: GNU `comm` compares BYTE-wise, and a
    # locale-aware sort orders `Phlix\Tests\Unit\Admin\ZTest::a` against
    # `Phlix\Tests\UnitB\ATest::a` the other way round, at which point `comm` refuses
    # the file as "not in sorted order". `-u` matters for a whole-workflow log, where
    # tests/Unit/Server/ skips appear in two jobs.
    printf '%s\n' "$names" | LC_ALL=C sort -u
    write_status=$?

    if [ "$write_status" -ne 0 ]; then
        # `pipefail` is on, so this catches sort itself AND a failing write to the
        # caller's redirect (ENOSPC, SIGPIPE) -- the exact "quietly truncated set
        # handed to comm" outcome the whole exit contract exists to prevent.
        printf 'skipped-test-names: FATAL -- the sorted name set could not be written (exit %s from `sort`/the output stream). stdout is TRUNCATED and must not be compared.\n' \
            "$write_status" >&2
        exit 6
    fi
fi

exit 0
