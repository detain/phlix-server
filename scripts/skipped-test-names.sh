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
# is per-invocation, and PHPUnit is invoked from several places in this repo
# (.github/workflows/phpunit.yml twice, .github/workflows/syncplay-e2e.yml twice, and
# scripts/assertion-escape-audit.php once) plus every developer's shell. A flag would
# have to be re-added to each of them and to every job added later -- the
# "hand-written list that never re-derives" shape.
#
# ⚠ Those sites are named by FILE on purpose: an earlier revision of this comment cited
# line numbers, and its own commit moved them. The count and the per-file multiplicity
# are RE-DERIVED from the repo by
# tests/Unit/Support/SkippedTestNameReportingTest::test_the_documented_invocation_sites_are_still_the_real_ones,
# which fails if an invocation is added, moved between files or removed.
#
# ⚠ The attribute is read by every invocation that loads phpunit.xml, but it only
# REACHES the output of invocations that use PHPUnit's DEFAULT result printer. It is
# NOT universal, and two exceptions are real:
#
#   * `--testdox`. PHPUnit builds the default result printer for a testdox run with
#     the `$displayDetailsOnSkippedTests` constructor argument hardcoded `false` -- the
#     `outputIsTestDox()` branch of `Facade::createResultPrinter()`, which is
#     vendor/phpunit/phpunit/src/TextUI/Output/Facade.php:204-221 in the pinned PHPUnit
#     10.5.64 (the configuration value is not consulted on that path). Every vendor line
#     number in this file was re-verified against that version; the SYMBOL is the durable
#     citation, the line number is the convenience. A `--testdox` run therefore
#     counts its skips in `Skipped: N` and can NEVER name them, whatever phpunit.xml
#     or `--display-skipped` say. Measured: `--testdox` on a file with one
#     unconditional skip prints `Skipped: 1` and zero `There was 1 skipped test:`
#     sections, with and without `--display-skipped`. This script detects testdox
#     output and exits 5 saying so, instead of blaming the config (see below).
#     `syncplay-e2e.yml` used to pass `--testdox`; S345's fixer removed it for
#     exactly this reason, and
#     tests/Unit/Support/SkippedTestNameReportingTest::test_no_workflow_invokes_phpunit_with_testdox
#     re-derives the ban from every .yml AND .yaml workflow, folding backslash
#     continuations first so a wrapped command cannot hide the flag.
#   * the assertion-escape prober (scripts/assertion-escape-audit.php) captures
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
# script accounts for that instead of reporting drift -- but only up to what THOSE runs
# declared (`nototals_declared`, computed per run). One `No tests executed!` anywhere in
# a whole-workflow log does not buy an unbounded excuse for every other run in it, and it
# does not offset another run's MISSING names either: those entries are subtracted out
# (`summarisable_declared`) before the shortfall is computed, because a list printed by a
# run with no count is no evidence about a run that published one.
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
#     4  skips were counted, NO detail list was printed at all, and testdox does not
#        account for all of them -- `displayDetailsOnSkippedTests` is off (or this
#        invocation did not load phpunit.xml), so the set is unobtainable
#     5  the unnamed skips are matched ONE-FOR-ONE by testdox skip glyphs, and a testdox
#        skip can never be named (see above). NOT a config fault; re-run without
#        `--testdox`.
#     6  the sorted set could not be written (sort or the caller's redirect failed)
#
# ⚠ 5 is gated on the number of testdox SKIP lines (`↩`), never on the mere presence of
# testdox-looking output, and it is taken ONLY when that number EQUALS the shortfall.
# Round 2 of this step's review found the earlier gate (`testdox_lines > 0`) telling the
# reader "Do NOT change phpunit.xml" about a count-only run that happened to share a log
# with a zero-skip testdox run -- i.e. exactly the wrong-diagnosis defect this contract
# exists to prevent, in mirror image. Two more attributions were removed since:
#   * testdox covering only PART of the shortfall -> both causes are named and phpunit.xml
#     is not exonerated;
#   * MORE skip glyphs than unnamed skips -> nothing is attributed to testdox at all. Real
#     testdox output cannot produce a surplus (each glyph is also counted in that run's own
#     `Skipped: N`), so a surplus proves the glyph count is not attributable to this input's
#     shortfall. The clamp that used to trim it to the shortfall turned every such input --
#     e.g. two stray ` ↩ ` lines from another tool beside a run that really had lost its
#     list -- into "Do NOT change phpunit.xml" about a config that WAS the cause.
# What remains, and is not claimed away: see KNOWN LIMITS 3.
#
# Exit 4 is the one that matters most, and it is why this script cross-checks TWO
# independent numbers instead of one. On master before S345 the log of run
# 32027697809 says `Tests: 10249, Assertions: 79041, Skipped: 3.` and contains ZERO
# `There were N skipped tests:` sections. A parser that trusted only the detail
# section would have printed an empty set and exited 0 -- reporting "nothing skipped"
# about a run that skipped three tests. That is exactly the shape this step exists to
# kill, so it must not be reintroduced by the tool that kills it.
#
# Each non-zero exit names the cause its own arithmetic supports, and never attributes
# more to a cause than that cause can carry: a wrong diagnosis is worse than none, because
# it sends the next reader to fix the wrong thing. That is why 4 and 5 are separate codes,
# and why 3's message no longer claims "fix this parser" when a missing list is the likelier
# cause. ⚠ It is NOT a guarantee that the code is right for every possible input -- KNOWN
# LIMITS below lists the inputs measured to reach the wrong one, and why. Enumerated, with
# the input class that reaches each exit and the claim its message makes:
#
#   2  summary == 0. NOTHING in the input matches any of PHPUnit's seven terminating
#      summary lines. Claim: "not a PHPUnit run, an empty set proves nothing" -- true by
#      construction; every terminating run prints one of those lines.
#   5  summarised > summarisable_declared AND testdox_skips == the shortfall > 0. Claim:
#      every unnamed skip is matched by a testdox skip glyph, so the config cannot name
#      them. Supported as far as an input-wide sum can support it: skip glyphs are counted
#      directly, never over-attributed (a surplus attributes NOTHING -- see above) and
#      never under-attributed except for the glyph-less suite skip of KNOWN LIMIT 2. It is
#      an equality of two totals, not a proof that they came from the same run -- KNOWN
#      LIMIT 3.
#      ⚠ `summarisable_declared` is `declared` MINUS what the `No tests executed!` runs
#      declared: those entries have no `Skipped: N` term of their own, and counting them
#      here let them cancel a different run's lost names, at which point this branch
#      exonerated a phpunit.xml that WAS at fault (round 3, finding 1 -- fixtured).
#   4  the shortfall remains after testdox's share AND summarisable_declared == 0. Claim:
#      the residual was counted, not named and not testdox's, and no run that published a
#      totals line printed a list at all -- so either the attribute is off or phpunit.xml
#      was not loaded. Those are the only two ways the default printer publishes a count
#      with no list THAT THIS PARSER CAN SEE; a third input reads identically, namely a
#      list that WAS printed under a header this parser does not recognise (KNOWN LIMIT 5).
#      Testdox's share is 0 in two cases -- no skip glyphs at all, and MORE
#      skip glyphs than unnamed skips (unattributable, see above) -- and in both the
#      message reports the glyphs as evidence while leaving phpunit.xml in play.
#   3  three disjoint shapes, each stating its own arithmetic: (a) shortfall remains
#      with a NON-empty summarisable list, so some run in the input printed no list --
#      partial set, not written; (b) declared > summarised beyond what the
#      `No tests executed!` runs themselves declared -- unexplained over-count; (c)
#      extracted != declared -- the ENTRY format drifted. Each message prints the numbers
#      it derived its claim from.
#   6  the `sort` pipeline (or the caller's redirect) failed. Claim: stdout is truncated.
#      True: the pipeline is the only writer of stdout and pipefail is on.
#   0  none of the above: the two denominators agree and every declared name parsed.
#
# KNOWN LIMITS, recorded rather than hidden. These are the measured inputs for which the
# exit code above can be the WRONG one, and they are why this header does not claim that
# every exit names a true cause:
#
#   1. EVERY denominator here is an INPUT-WIDE SUM. `declared`, `summarised`,
#      `testdox_skips` and `nototals_declared` are totals over the whole input, so in a
#      MULTI-RUN log -- which is exactly what the documented `gh run view <id> --log`
#      recipe produces -- a shortfall in one run can be paid for by a surplus in another,
#      and the pair can net out to a code that is right for the sum and wrong for both
#      runs. The `declared - nototals_declared` split closes the two inputs round 3 of
#      S345's review measured (both are fixtures now), but it is a bounded correction, not
#      a closure of the class. The DURABLE fix is per-RUN segmentation: split the input at
#      the terminating summary lines and evaluate the contract inside each segment. That
#      is deliberately NOT done here: it is FILED, with the measured inputs, as
#      `S352-followup-perrun-segmentation.md` in the build program's step directory
#      (outside this repo; its S-number is pending allocation), because a deferral left in
#      a worklog is not a contract -- that is the S126 lesson S345 exists to enforce.
#      Until it is: a non-zero exit tells you the input as a whole must not be compared;
#      it does not always tell you which run in it is at fault. Measured on THIS version,
#      so it is not a stale note: a run declaring 2 names but summarising 1, followed by a
#      count-only run summarising 1, exits **0** with a 2-name set and no sign that the
#      second run lost a name.
#      One member of the family was removed rather than clamped -- see the surplus-glyph
#      paragraph above and limit 3 -- because that branch had no legitimate input at all.
#      The rest of the family is untouched.
#   2. `--testdox` together with a skipped SUITE. `testdox_skips` is counted from ` ↩ `
#      glyph lines, and TextUI/Output/TestDox/ResultPrinter emits a glyph per TEST result,
#      so a testSuiteSkipped event under `--testdox` lands in `Skipped: N` with no glyph to
#      attribute it to. Measured: `--testdox <suite-skip probe> <a 1-skip file>` exits 4
#      accusing phpunit.xml, when the config is correct and testdox could not have named
#      that skip either; `--testdox <suite-skip probe>` alone exits 0 with an EMPTY set,
#      where the plain run on the same input correctly emits `SUITE:...`. `tests/`
#      currently contains no skipped suite, so this is latent here. Same follow-up.
#   3. exit 5 rests on an EQUALITY of two input-wide totals, so N stray ` ↩ ` lines beside
#      a run that lost N names are arithmetically indistinguishable from one testdox run
#      that skipped N. Measured: one ` ↩ ` line plus a count-only `Skipped: 1.` still exits
#      5 with "Do NOT change phpunit.xml". A SURPLUS of glyphs is no longer read that way
#      (it attributes nothing, and the input falls through to 4/3 naming both causes),
#      which is what removes the shape that had NO legitimate input; an exact match still
#      does, because a genuine testdox run produces exactly that arithmetic and nothing in
#      the input-wide totals tells the two apart. Per-run segmentation (limit 1) is what
#      settles it -- attribute glyphs only to the run that printed them. Do not read exit 5
#      as more than "the totals are consistent with testdox and with nothing else".
#   4. a data-set label containing a literal NEWLINE is emitted truncated at that newline
#      (the entry spans two lines and the denominators still agree, so this one shape
#      escapes the cross-check). No data provider in this repo produces one, and the
#      truncation is stable across runs, so `comm` is not corrupted by it -- but the
#      denominator argument above does not cover it, so do not claim that it does.
#   5. a drift in the list HEADER text refuses correctly but names the wrong cause. The
#      three parsers are anchored on three independent shapes -- the list header, the
#      `Tests: ...` totals line, and the `N) ` entry -- and S345's test engineer measured
#      all three: a drifted totals line and a drifted ENTRY both exit 3 and say "fix this
#      parser", but a drifted HEADER (e.g. a future PHPUnit printing `Skipped tests (1):`)
#      makes `declared` 0 while `summarised` stays right, which is arithmetically identical
#      to the attribute being off -- so it exits 4 and blames phpunit.xml. Re-measured on
#      THIS version, so it is not a stale note. The refusal is the RIGHT one (loud, nothing
#      written, no set to mis-compare) and only the CAUSE is wrong; if exit 4 ever fires on
#      output whose config you have checked, compare the list header text against this
#      parser before touching phpunit.xml. Per-run segmentation does not settle this one
#      either: a header the parser cannot see is invisible however the input is split.
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

# How much of `declared` belongs to runs that published NO totals line. PHPUnit prints
# each run's lists BEFORE its summary, so anything declared since the last summary and
# then followed by `No tests executed!` has no `Skipped: N` term to match, legitimately.
# This BOUNDS the allowance: an input-wide switch-off would excuse real drift in every
# other run sharing the same log.
nototals_declared="$(printf '%s\n' "$normalised" | awk '
    /^There (was|were) [0-9]+ skipped (tests?|test suites?):$/ { pending += $3; next }
    /^No tests executed!$/                                     { allowed += pending; pending = 0; next }
    /^Tests: /                                                 { pending = 0 }
    END { print allowed + 0 }
')"

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

printf 'skipped-test-names: %s lines read, %s PHPUnit result summary line(s) seen, %s skipped test(s) in the run summaries (%s run(s) printed no totals line, declaring %s between them), %s declared by the detail lists, %s testdox result line(s) (%s skipped), %s name(s) extracted.\n' \
    "$lines_read" "$summary" "$summarised" "$no_totals" "$nototals_declared" "$declared" "$testdox_lines" "$testdox_skips" "$extracted" >&2

if [ "$summary" -eq 0 ]; then
    printf 'skipped-test-names: FATAL -- no PHPUnit result summary in the input, so this is not a PHPUnit run and an empty name set proves nothing. Feed it `./vendor/bin/phpunit 2>&1` or `gh run view <id> --log`.\n' >&2
    exit 2
fi

# Only the part of `declared` that belongs to runs which PUBLISHED a totals line can
# offset `summarised`. Entries declared by a `No tests executed!` run have no `Skipped: N`
# term of their own, so counting them here let them cancel a DIFFERENT run's lost names:
# round 3 of this step's review measured a suite-skip run + a testdox run + a count-only
# run netting out to exit 5 ("Do NOT change phpunit.xml") while the count-only run had
# genuinely lost its list, and a suite-skip run + a count-only run netting out to exit 0
# with a set short by one name. Both are pinned by fixtures in
# tests/Unit/Support/SkippedTestNameReportingTest.php.
#
# ⚠ This is a BOUNDED correction, not a closure of the class: every denominator in this
# script is an input-wide SUM, so a multi-run log can still net one run's shortfall against
# another run's surplus. See KNOWN LIMITS in the header. The durable fix is per-run
# segmentation, not another clamp.
summarisable_declared=$((declared - nototals_declared))

# The input under-reports names: skips were counted that no list named. Name the cause
# that is TRUE for this input, and never attribute more to a cause than it can carry --
# a wrong diagnosis is worse than none, because it sends the reader somewhere else.
if [ "$summarised" -gt "$summarisable_declared" ]; then
    missing=$((summarised - summarisable_declared))

    # `--testdox` can only explain the skips IT counted, and none at all when it skipped
    # nothing. Gating on `testdox_lines` instead would blame testdox for a plain run that
    # merely shares a log with one -- or for any other tool that prints those glyphs --
    # and then tell the reader to leave phpunit.xml alone when phpunit.xml IS the cause.
    #
    # A SURPLUS of skip glyphs is NOT clamped down to the shortfall, deliberately. A real
    # testdox run cannot produce one: PHPUnit prints a ` ↩ ` glyph per skipped test AND
    # counts that same test in the run's `Skipped: N`, so genuine testdox output always
    # satisfies `testdox_skips <= missing`. When the input has MORE skip glyphs than
    # unnamed skips, at least some of those glyphs are not testdox skips belonging to this
    # input's shortfall -- another tool's output sharing the log, which the documented
    # `gh run view <id> --log` recipe makes routine. The glyph count is then not
    # ATTRIBUTABLE at all, so it buys nothing: the whole shortfall stays unexplained and
    # both candidate causes are reported below. The earlier code clamped `testdox_covers`
    # to `missing` here, which manufactured `residual == 0` and printed "Do NOT change
    # phpunit.xml" -- measured on two stray ` ↩ ` lines plus one count-only run whose list
    # really was lost (S345 test engineer), i.e. a branch with no legitimate input that
    # could only ever lie.
    if [ "$testdox_skips" -gt "$missing" ]; then
        testdox_covers=0
        testdox_unattributable=1
    else
        testdox_covers="$testdox_skips"
        testdox_unattributable=0
    fi
    residual=$((missing - testdox_covers))

    # residual == 0 now implies `testdox_skips == missing > 0`: every unnamed skip is
    # matched one-for-one by a testdox skip glyph, which is the arithmetic a real testdox
    # run produces and the only one under which testdox alone can account for the whole
    # shortfall. It is NOT proof that the glyphs and the unnamed skips come from the SAME
    # run -- N foreign glyphs beside a count-only run that lost N names look identical to
    # an input-wide sum. See KNOWN LIMITS 3 in the header; per-run segmentation is what
    # settles it.
    if [ "$residual" -eq 0 ]; then
        printf 'skipped-test-names: FATAL -- all %s skipped test(s) that this input counts but does not name are matched one-for-one by testdox skip glyphs: it carries %s line(s) in --testdox result format, %s of them skips. PHPUnit constructs the default result printer with displayDetailsOnSkippedTests hardcoded FALSE when --testdox is in effect (vendor/phpunit/phpunit/src/TextUI/Output/Facade.php:204-221), so the configuration value is not consulted and neither phpunit.xml nor --display-skipped can name those skips. Do NOT change phpunit.xml -- for a testdox run it is not the cause. Re-run WITHOUT --testdox, or feed this script only the non-testdox steps of the log.\n' \
            "$missing" "$testdox_lines" "$testdox_skips" >&2
        exit 5
    fi

    # Testdox cannot carry the whole shortfall, so from here it is reported as EVIDENCE
    # only, and the reader is NOT told that phpunit.xml is innocent.
    if [ "$testdox_unattributable" -eq 1 ]; then
        also=" (Evidence, not a cause: this input carries $testdox_lines line(s) in --testdox result format, of which $testdox_skips are skips -- MORE skip glyphs than the $missing unnamed skip(s). A real testdox run cannot do that, because every glyph it prints is also counted in its own \`Skipped: N\`, so some of those glyphs belong to output that is not a testdox skip of this input: they are NOT attributable and they explain none of the shortfall. BOTH causes remain in play -- testdox somewhere in this input, AND phpunit.xml -- and neither is ruled out.)"
    elif [ "$testdox_covers" -gt 0 ]; then
        also=" Testdox accounts for $testdox_covers of the unnamed skip(s) ($testdox_skips testdox skip line(s), unnamable by design -- Facade.php:204-221); the remaining $residual it does not, so BOTH causes are in play and phpunit.xml may be one of them."
    elif [ "$testdox_lines" -gt 0 ]; then
        also=" (Evidence, not a cause: this input carries $testdox_lines line(s) in --testdox result format, of which $testdox_skips are skips, so testdox explains none of the shortfall.)"
    else
        also=""
    fi

    # `summarisable_declared`, not `declared`: a list printed by a `No tests executed!` run
    # is not evidence that the runs which DID publish a count printed one. Attributing it
    # to them is what let exit 5 exonerate phpunit.xml in round 3's finding 1.
    if [ "$nototals_declared" -gt 0 ]; then
        aside=" ($nototals_declared entry/entries WERE declared, by $no_totals run(s) reporting \`No tests executed!\`, but those have no count of their own to match and so cannot stand in for a run that published one.)"
    else
        aside=""
    fi

    if [ "$summarisable_declared" -eq 0 ]; then
        printf 'skipped-test-names: FATAL -- %s skipped test(s) in this input are counted, NOT named, and not explained by testdox, and NO run that published a `Tests: ...` totals line printed a skipped-details list at all.%s phpunit.xml has lost displayDetailsOnSkippedTests="true" (S345), or the invocation did not load phpunit.xml (a `-c`/`--configuration` pointing elsewhere, or `--no-configuration`). The name set cannot be recovered from this output; fix the config and re-run rather than reading the empty set as "nothing skipped".%s\n' \
            "$residual" "$aside" "$also" >&2
        exit 4
    fi

    printf 'skipped-test-names: FATAL -- the run summaries report %s skipped test(s) but the runs that published a totals line declared only %s in their detail lists, so %s name(s) are missing.%s Some run in this input printed no skipped list: check that every invocation loads phpunit.xml (displayDetailsOnSkippedTests="true") and that none of them uses --testdox. The set below would be PARTIAL, so it is not written.%s\n' \
        "$summarised" "$summarisable_declared" "$missing" "$aside" "$also" >&2
    exit 3
fi

# More declared than summarised is legitimate only for the runs that printed no totals
# line at all (`No tests executed!`), and only up to what THOSE runs declared -- an
# input-wide switch-off would excuse real drift anywhere else in the same log.
# (`over > nototals_declared` below is the same comparison as the branch above, mirrored:
# it is true exactly when `summarisable_declared > summarised`. The two branches therefore
# cover the same arithmetic from both sides and cannot both fire.)
if [ "$declared" -gt "$summarised" ]; then
    over=$((declared - summarised))

    if [ "$over" -gt "$nototals_declared" ]; then
        printf 'skipped-test-names: FATAL -- the detail lists declared %s skipped test(s)/suite(s) but the run summaries report only %s. At most %s of that difference is explained by the %s run(s) that reported `No tests executed!` (the one shape that legitimately publishes a list with no totals line), leaving %s unexplained. The output format has drifted; fix this parser rather than trusting the set.\n' \
            "$declared" "$summarised" "$nototals_declared" "$no_totals" $((over - nototals_declared)) >&2
        exit 3
    fi
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

s352_per_run_probe() { grep -c 'Skipped:' ; }
