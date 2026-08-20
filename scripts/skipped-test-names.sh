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
# WHAT FEEDS IT
# =============================================================================
# `phpunit.xml` carries `displayDetailsOnSkippedTests="true"` (S345). That is the
# CONFIG attribute rather than the `--display-skipped` CLI flag on purpose: the flag
# is per-invocation, and this repo runs PHPUnit from four places
# (.github/workflows/phpunit.yml twice, .github/workflows/syncplay-e2e.yml twice)
# plus every developer's shell. A flag would have to be re-added to each of them and
# to every job added later -- the "hand-written list that never re-derives" shape.
# The attribute is read once by every invocation that loads phpunit.xml, so a new
# job cannot silently ship without name reporting.
#
# Neither the attribute nor the flag changes the process exit status, and neither
# suppresses any other reporting: PHPUnit's
# `TextUI/Output/Default/ResultPrinter::printSkippedTests()` is guarded by
# `displayDetailsOnSkippedTests` alone and only ADDS a list. `failOnSkipped` is a
# SEPARATE setting and is not set in this repo.
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
#     3  parser drift: the names extracted do not match the count PHPUnit declared
#     4  the run DID skip tests but printed no names -- `displayDetailsOnSkippedTests`
#        is off (or was overridden), so the set is unobtainable from this input
#
# Exit 4 is the one that matters most, and it is why this script cross-checks TWO
# independent numbers instead of one. On master before S345 the log of run
# 32027697809 says `Tests: 10249, Assertions: 79041, Skipped: 3.` and contains ZERO
# `There were N skipped tests:` sections. A parser that trusted only the detail
# section would have printed an empty set and exited 0 -- reporting "nothing skipped"
# about a run that skipped three tests. That is exactly the shape this step exists to
# kill, so it must not be reintroduced by the tool that kills it.
#
# stdout carries ONLY the name set, so `comm` and `diff` can consume it directly.
#
# =============================================================================
# THE ONE-LINER, for when you do not have this script to hand
# =============================================================================
# (identical extraction, without the denominators or the exit contract)
#
#   sed -E 's/\r$//; s/^[^\t]*\t[^\t]*\t[^0-9]*[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z //' \
#     | awk '/^There (was|were) [0-9]+ skipped tests?:$/{i=1;next} /^--$/{i=0} \
#            i && /^[0-9]+\) [A-Za-z_\\][A-Za-z0-9_\\]*::/{sub(/^[0-9]+\) /,"");print}' \
#     | sort -u
#
set -uo pipefail

usage() {
    cat >&2 <<'USAGE'
usage: skipped-test-names.sh [FILE]

Reads PHPUnit console output (FILE, or stdin when FILE is omitted or `-`) and
writes the SORTED, DEDUPLICATED set of skipped test names -- `Class::method`, one
per line -- to stdout. Denominators go to stderr. See the header of this file.
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
# Strip that (and any CRLF) so the section anchors below can use `^`.
normalised="$(printf '%s\n' "$raw" \
    | sed -E 's/\r$//; s/^[^\t]*\t[^\t]*\t[^0-9]*[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z //')"

lines_read="$(printf '%s\n' "$normalised" | wc -l | tr -d ' ')"

# Positive control: did we get PHPUnit output at all? Every terminating PHPUnit run
# prints exactly one of these. If none is present the input is something else, and an
# empty name set from it means nothing.
summary="$(printf '%s\n' "$normalised" \
    | grep -c -E '^(OK \(|OK, but |FAILURES!|ERRORS!|WARNINGS!|No tests executed!|Tests: )' || true)"

# Two INDEPENDENT denominators, summed across every PHPUnit run present in the input
# (a whole-workflow `gh run view --log` contains several):
#
#   `declared`  -- the header of each skipped-details list. Zero when the details are
#                  not being displayed at all.
#   `summarised`-- the `Skipped: N` term of each run's `Tests: ...` summary line. This
#                  is printed unconditionally, so it is the number that betrays a
#                  missing `displayDetailsOnSkippedTests`.
declared="$(printf '%s\n' "$normalised" \
    | sed -nE 's/^There (was|were) ([0-9]+) skipped tests?:$/\2/p' \
    | awk '{ t += $1 } END { print t + 0 }')"

summarised="$(printf '%s\n' "$normalised" \
    | sed -nE 's/^Tests: [0-9]+,.*[^A-Za-z]Skipped: ([0-9]+).*/\1/p' \
    | awk '{ t += $1 } END { print t + 0 }')"

names="$(printf '%s\n' "$normalised" | awk '
    /^There (was|were) [0-9]+ skipped tests?:$/ { inlist = 1; next }
    /^--$/                                      { inlist = 0 }
    /^(OK \(|OK, but |FAILURES!|ERRORS!|WARNINGS!|Tests: )/ { inlist = 0 }
    inlist && /^[0-9]+\) [A-Za-z_\\][A-Za-z0-9_\\]*::/        { sub(/^[0-9]+\) /, ""); print }
')"

if [ -n "$names" ]; then
    extracted="$(printf '%s\n' "$names" | wc -l | tr -d ' ')"
else
    extracted=0
fi

printf 'skipped-test-names: %s lines read, %s PHPUnit result summary line(s) seen, %s skipped test(s) in the run summaries, %s declared by the detail lists, %s name(s) extracted.\n' \
    "$lines_read" "$summary" "$summarised" "$declared" "$extracted" >&2

if [ "$summary" -eq 0 ]; then
    printf 'skipped-test-names: FATAL -- no PHPUnit result summary in the input, so this is not a PHPUnit run and an empty name set proves nothing. Feed it `./vendor/bin/phpunit 2>&1` or `gh run view <id> --log`.\n' >&2
    exit 2
fi

if [ "$summarised" -gt 0 ] && [ "$declared" -eq 0 ]; then
    printf 'skipped-test-names: FATAL -- the run summaries report %s skipped test(s) but printed NO names. phpunit.xml has lost displayDetailsOnSkippedTests="true" (S345), or the invocation overrode it with --do-not-display-skipped. The name set cannot be recovered from this output; fix the config and re-run rather than reading the empty set as "nothing skipped".\n' \
        "$summarised" >&2
    exit 4
fi

if [ "$declared" -ne "$summarised" ]; then
    printf 'skipped-test-names: FATAL -- the run summaries report %s skipped test(s) but the detail lists declared %s. The output format has drifted; fix this parser rather than trusting the set.\n' \
        "$summarised" "$declared" >&2
    exit 3
fi

if [ "$extracted" -ne "$declared" ]; then
    printf 'skipped-test-names: FATAL -- PHPUnit declared %s skipped test(s) but %s name(s) were extracted. The output format has drifted; fix this parser rather than trusting the set.\n' \
        "$declared" "$extracted" >&2
    exit 3
fi

if [ "$extracted" -gt 0 ]; then
    printf '%s\n' "$names" | LC_ALL=C sort -u
fi

exit 0
