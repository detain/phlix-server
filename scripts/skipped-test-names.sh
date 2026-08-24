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
#     5  the run is a `--testdox` run -- its OWN skip glyphs prove it -- and a testdox
#        skip can never be named (see above), the glyph-less suite skip included, so the
#        run's `Skipped: N` counts for the attribution, not the glyphs (AC2). Also taken
#        for a run that ended `No tests executed!` with nothing named: the suite skip is
#        invisible there, and an empty set about it would be a lie. NOT a config fault;
#        re-run without `--testdox`.
#     6  the sorted set could not be written (sort or the caller's redirect failed)
#
# ⚠ 5 is gated on testdox SKIP lines (`↩`) attributed to the SAME run as the shortfall,
# never on the mere presence of testdox-looking output, and it is taken ONLY when that
# run is a testdox run (one or more of its OWN glyphs) and its glyphs do not SURPLUS its
# shortfall (a real testdox run cannot print more glyphs than it has skips). Since AC2
# the attribution is the run's own `Skipped: N`, not the glyph count: a testdox run can
# never name ANY of its skips, the glyph-less suite skip(s) included (ResultPrinter
# emits one glyph per TEST result), so the glyphs merely PROVE the run is testdox and
# the count that decides the verdict is the summary's. Stray glyphs before any PHPUnit
# banner are attributed to NO run (S352 per-run segmentation), so they can never reach
# this branch at all.
# Round 2 of this step's review found the earlier gate (`testdox_lines > 0`) telling the
# reader "Do NOT change phpunit.xml" about a count-only run that happened to share a log
# with a zero-skip testdox run -- i.e. exactly the wrong-diagnosis defect this contract
# exists to prevent, in mirror image. One more attribution was removed since:
#   * MORE skip glyphs than the run's total skips -> nothing is attributed to testdox at
#     all. Real testdox output cannot produce a surplus (each glyph is also counted in
#     that run's own `Skipped: N`), so a surplus proves the glyph count is not
#     attributable to this run's shortfall. The clamp that used to trim it to the
#     shortfall turned every such input -- e.g. two stray ` ↩ ` lines from another tool
#     beside a run that really had lost its list -- into "Do NOT change phpunit.xml"
#     about a config that WAS the cause.
# What remains, and is not claimed away: see KNOWN LIMITS 2-5.
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
#   5  a run whose OWN testdox skip glyphs prove it is a testdox run, either because
#      summarised > summarisable_declared and the glyphs do not surplus the shortfall
#      (since AC2 the attribution is the run's own `Skipped: N`, glyph-less suite skips
#      included), or because the run ended `No tests executed!` with nothing named (the
#      invisible suite skip). Claim: every unnamed skip in THAT RUN belongs to a testdox
#      run, so the config cannot name them. A surplus attributes NOTHING -- see above.
#      Stray glyphs that segment_runs() attributed to no run (preamble/foreign output)
#      can never reach this branch -- AC2a closed KNOWN LIMIT 3, AC2 closed KNOWN LIMIT 2.
#      ⚠ `summarisable_declared` is `declared` MINUS what this run's `No tests executed!`
#      entries declared: those entries have no `Skipped: N` term of their own, and counting
#      them here let them cancel a DIFFERENT run's lost names, at which point this branch
#      exonerated a phpunit.xml that WAS at fault (round 3, finding 1 -- fixtured).
#   4  the shortfall remains after the run's OWN testdox share AND summarisable_declared
#      == 0. Claim: the residual was counted, not named and not this run's testdox, and
#      this run printed no list at all -- so either the attribute is off or phpunit.xml
#      was not loaded. Those are the only two ways the default printer publishes a count
#      with no list THAT THIS PARSER CAN SEE; a third input reads identically, namely a
#      list that WAS printed under a header this parser does not recognise (KNOWN LIMIT 5).
#      Testdox-looking output elsewhere in the input is reported as EVIDENCE (never as
#      exoneration) -- stray glyphs attributed to no run, or more glyphs than this run's
#      shortfall, both leave phpunit.xml in play.
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
#   1. CLOSED (S352): the contract is evaluated PER RUN, not on input-wide sums. A run's
#      `declared`, `summarised`, `testdox_skips` and `nototals_declared` are accumulated
#      inside each segment by segment_runs(), so in a MULTI-RUN log -- which is exactly
#      what the documented `gh run view <id> --log` recipe produces -- one run's shortfall
#      can no longer be paid for by another run's surplus. A multi-run input is refused
#      with a message naming EVERY failing run (ordinal, banner, that run's own
#      arithmetic), and the first failing run's exit code wins. The input-wide tallies
#      still printed to stderr are AUDIT ONLY: they no longer decide any exit.
#   2. CLOSED (S352, AC2): `--testdox` together with a skipped SUITE. A testdox run emits
#      a glyph per TEST result (TextUI/Output/TestDox/ResultPrinter::symbolFor), so a
#      testSuiteSkipped event under `--testdox` lands in `Skipped: N` with no glyph to
#      attribute it to. The attribution now uses the run's own `Skipped: N` once its
#      glyphs prove it is testdox, so the glyph-less suite skip is accounted for: the
#      mixed shape (`--testdox <suite-skip probe> <a 1-skip file>`) exits 5 with "Do NOT
#      change phpunit.xml" instead of accusing the config, and the suite-skip-only shape
#      (`No tests executed!` with nothing named) is REFUSED with a message naming the
#      invisible suite skip instead of exiting 0 with an empty set. What remains: a plain
#      run that genuinely executed nothing (a typo'd `--filter`, an empty directory)
#      prints the identical `No tests executed!` shape, so it is refused too -- loudly,
#      with no set written -- and the message says to verify the invocation. `tests/`
#      still contains no skipped suite, so the testdox half is latent, but the AC2
#      fixtures drive the real binary.
#   3. CLOSED (S352, AC2a): exit 5 used to rest on an EQUALITY of two input-wide totals,
#      so N stray ` ↩ ` lines beside a run that lost N names were arithmetically
#      indistinguishable from one testdox run that skipped N. Measured: one ` ↩ ` line plus
#      a count-only `Skipped: 1.` exited 5 with "Do NOT change phpunit.xml". Per-run
#      segmentation settles it: segment_runs() attributes a glyph only to the run that
#      printed it, and a glyph before any PHPUnit banner is attributed to NO run, so a
#      count-only run's shortfall can never be matched by a foreign glyph -- that input now
#      exits 4 naming phpunit.xml, with the stray glyphs reported as evidence only. A
#      genuine testdox run (its own glyphs inside its own segment) still exits 5, now with
#      the run's own `Skipped: N` carrying the attribution (AC2 -- the glyph-less suite
#      skip included), and a run with glyphs attributed to NO run can never reach the
#      exit-5 branch. Do not read exit 5 as more than "this run's totals are consistent
#      with testdox and with nothing else".
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

# =============================================================================
# S352 — per-run segmentation pass (wired in at the bottom of this file)
# =============================================================================
# The exit contract is now evaluated PER RUN (see the wiring at the bottom), not on
# input-wide sums: in a multi-run log one run's shortfall can be paid for by
# another run's surplus, and the pair nets out to a code that is right for the
# sum and wrong for both runs. This is the segmentation pass that feeds it.
#
# segment_runs() reads the NORMALISED input (the same `normalised=` text the main
# flow builds: CRLF and ANSI escapes stripped, `gh run view --log` prefixes
# removed) on stdin and emits, per PHPUnit run:
#
#   RUN\t<idx>\t<field>\t<value>     one metadata record per accumulated field
#   <name>                           the run's extracted names, one per line,
#                                    printed after its metadata records
#
# A run is delimited by PHPUnit's banner (`PHPUnit N.N ...`): each banner flushes
# the previous run and opens the next. A terminating summary line
# (`OK (`, `OK, but `, `FAILURES!`, `ERRORS!`, `WARNINGS!`, `No tests executed!`,
# `Tests: `) with no open run implicitly opens one, so a banner-less log fragment
# still yields a run. Everything else that is not inside an opened run is ignored
# (e.g. stray ` ↩ ` glyphs before any banner are not attributed to any run).
#
# Each run accumulates the same fields the main flow sums across the whole input
# — `declared` (detail-list headers, both kinds), `summarised` (the `Skipped: N`
# term of `Tests: ...` lines), `nototals_declared` (declared entries matched by a
# `No tests executed!` run), `testdox_skips` (` ↩ ` glyphs), `extracted` (names
# buffered), `has_summary` (a terminating summary line was seen), `no_totals` (a
# `No tests executed!` terminating line was seen) — plus `banner`.
# The names are buffered exactly as the main pass extracts them (`SUITE:` prefix
# for a skipped test suite) and printed with the run that produced them.
segment_runs() {
    awk '
        function flush_run() {
            printf "RUN\t%d\tbanner\t%s\n", idx, banner
            printf "RUN\t%d\tdeclared\t%d\n", idx, declared
            printf "RUN\t%d\tsummarised\t%d\n", idx, summarised
            printf "RUN\t%d\tnototals_declared\t%d\n", idx, nototals_declared
            printf "RUN\t%d\ttestdox_skips\t%d\n", idx, testdox_skips
            printf "RUN\t%d\textracted\t%d\n", idx, extracted
            printf "RUN\t%d\thas_summary\t%d\n", idx, has_summary
            printf "RUN\t%d\tno_totals\t%d\n", idx, no_totals
            if (names != "") {
                printf "%s\n", names
            }
        }

        function open_run(b) {
            idx++
            open = 1
            declared = 0
            summarised = 0
            nototals_declared = 0
            testdox_skips = 0
            extracted = 0
            has_summary = 0
            no_totals = 0
            pending = 0
            mode = ""
            names = ""
            banner = b
        }

        function add_summarised(line,   i, n, f, v) {
            n = split(line, f, " ")
            for (i = 1; i <= n; i++) {
                if (f[i] == "Skipped:") {
                    v = f[i + 1]
                    sub(/\.$/, "", v)
                    if (v ~ /^[0-9]+$/) {
                        summarised += v
                    }
                    break
                }
            }
        }

        BEGIN {
            idx = 0
            open = 0
            names = ""
        }

        # A PHPUnit run banner flushes the previous run and opens the next.
        /^PHPUnit [0-9]+\.[0-9]+/ {
            if (open) flush_run()
            open_run($0)
            next
        }

        # A terminating summary line with no open run implicitly opens one.
        /^(OK \(|OK, but |FAILURES!|ERRORS!|WARNINGS!|No tests executed!|Tests: )/ {
            if (!open) open_run("")
            has_summary = 1
            mode = ""
            if ($0 ~ /^No tests executed!$/) {
                nototals_declared += pending
                no_totals++
                pending = 0
            } else if ($0 ~ /^Tests: /) {
                pending = 0
                add_summarised($0)
            }
            next
        }

        /^There (was|were) [0-9]+ skipped test suites?:$/ {
            declared += $3
            pending += $3
            mode = "suite"
            next
        }

        /^There (was|were) [0-9]+ skipped tests?:$/ {
            declared += $3
            pending += $3
            mode = "test"
            next
        }

        /^ ↩ / {
            testdox_skips++
            next
        }

        /^--$/ {
            mode = ""
            next
        }

        mode == "test" && /^[0-9]+\) [A-Za-z_\\][A-Za-z0-9_\\]*::/ {
            sub(/^[0-9]+\) /, "")
            names = (names == "" ? $0 : names "\n" $0)
            extracted++
            next
        }

        mode == "suite" && /^[0-9]+\) [A-Za-z_\\][A-Za-z0-9_:\\]*$/ {
            sub(/^[0-9]+\) /, "")
            names = (names == "" ? "SUITE:" $0 : names "\nSUITE:" $0)
            extracted++
            next
        }

        END {
            if (open) flush_run()
        }
    '
}

# =============================================================================
# S352 — per-run verdict + aggregation (wired in at the bottom of this file)
# =============================================================================
# evaluate_segment() applies the contract to ONE run's fields as accumulated by
# segment_runs(): idx, banner, declared, summarised, nototals_declared,
# testdox_skips, extracted, has_summary, plus the input-wide testdox tallies
# (input_testdox_lines, input_testdox_skips) used only for the stray-glyph
# EVIDENCE note. It echoes ONE verdict line to stdout --
#
#   RUN\t<idx>\t<exit>\t<message>
#
# -- and returns the exit code, so a caller can do
# `verdict="$(evaluate_segment "$idx" "$banner" ...)"; code=$?`.
#
# `summarisable_declared` is declared minus what this run's `No tests executed!`
# entries declared, and the checks run in this order:
#   * has_summary == 0       -> exit 2  (a banner-opened run that never reached
#     a terminating summary has no trustworthy arithmetic; the per-run mirror
#     of the input-wide "not a PHPUnit run" guard)
#   * summarised > summarisable_declared -> exit 5 when the run's OWN testdox
#     glyphs prove it is a testdox run and do not surplus its shortfall. Since
#     AC2 the attribution is the run's own `Skipped: N`, glyph-less suite skips
#     included -- glyphs merely PROVE the run is testdox, they are no longer the
#     count (a surplus attributes NOTHING; glyphs attributed to no run can never
#     reach this branch -- AC2a), else exit 4 when no detail list was printed at
#     all, else exit 3 (partial set, not written). The exit 4/3 messages carry
#     the input-wide stray-glyph EVIDENCE note when the wider input has
#     testdox-looking output.
#   * declared > summarised with over > nototals_declared -> exit 3
#   * extracted != declared  -> exit 3
#   * no_totals > 0 with NOTHING declared or summarised -> exit 5, refusing an
#     empty set about an invisible suite skip (AC2 -- the suite-skip-only
#     `--testdox` shape; the genuine no-totals runs with declared names are
#     handled by the branches above)
#   * otherwise              -> exit 0
# The last three checks are SEQUENTIAL ifs, not an elif chain, because the
# over-count branch does not always exit (the over-count can be fully explained
# by `No tests executed!` runs) and the extracted check must still run in that
# case.
evaluate_segment() {
    local idx="$1"
    local banner="$2"
    local declared="$3"
    local summarised="$4"
    local nototals_declared="$5"
    local testdox_skips="$6"
    local extracted="$7"
    local has_summary="$8"
    local no_totals="$9"
    local input_testdox_lines="${10}"
    local input_testdox_skips="${11}"

    local summarisable_declared=$((declared - nototals_declared))

    # A run that never completed has no trustworthy arithmetic: its set proves
    # nothing, exactly as the main flow's exit 2 says of an input with no
    # terminating summary line anywhere.
    if [ "$has_summary" -eq 0 ]; then
        printf 'RUN\t%s\t%s\t%s\n' "$idx" 2 "run $idx: no PHPUnit terminating summary line was seen, so this run's set proves nothing (it was opened by a banner and never completed). Feed this script a complete run: ./vendor/bin/phpunit 2>&1 or gh run view <id> --log."
        return 2
    fi

    # The run under-reports names: skips were counted that no list named.
    if [ "$summarised" -gt "$summarisable_declared" ]; then
        missing=$((summarised - summarisable_declared))

        # Testdox gate (AC2): a SURPLUS of ` ↩ ` glyphs attributes NOTHING (a
        # real testdox run cannot produce one, because every glyph it prints is
        # also counted in its own `Skipped: N`); short of a surplus, the run's
        # OWN glyphs PROVE it is a testdox run, and a testdox run can never name
        # ANY of its skips -- the glyph-less suite skip included (ResultPrinter
        # emits a glyph per TEST result). The attribution is therefore the run's
        # own `Skipped: N`, not the glyph count: glyphs > 0 marks the run,
        # `summarised` carries the verdict. (Before AC2 the attribution was the
        # glyph count itself, so the mixed testdox+suite-skip shape fell short
        # by the glyph-less suite and accused phpunit.xml -- KNOWN LIMIT 2.)
        if [ "$testdox_skips" -gt "$missing" ]; then
            testdox_covers=0
            testdox_note=" $testdox_skips testdox skip glyph(s) are present but MORE than the $missing missing name(s), so they attribute NOTHING (a real testdox run cannot produce a surplus);"
        elif [ "$testdox_skips" -gt 0 ]; then
            testdox_covers="$missing"
            testdox_note=" testdox accounts for all $missing missing name(s): the run's own $testdox_skips skip glyph(s) make it a --testdox run, which can never name ANY of its skips, the glyph-less suite skip(s) included;"
        else
            testdox_covers=0
            testdox_note=""
        fi
        residual=$((missing - testdox_covers))

        # residual == 0 now means the whole shortfall belongs to a testdox run:
        # either its glyphs matched it one-for-one (genuine testdox, glyphs ==
        # missing) or the glyphs proved testdox and the run's own `Skipped: N`
        # carried the rest (AC2 -- the glyph-less suite skip). Under either
        # arithmetic the config cannot name those skips.
        if [ "$residual" -eq 0 ]; then
            printf 'RUN\t%s\t%s\t%s\n' "$idx" 5 "run $idx: all $missing skipped test(s) counted but not named belong to a --testdox run, proven by its own $testdox_skips skip glyph(s). PHPUnit hardcodes displayDetailsOnSkippedTests FALSE when --testdox is in effect (vendor/phpunit/phpunit/src/TextUI/Output/Facade.php:204-221), and a testSuiteSkipped event produces no glyph either (TextUI/Output/TestDox/ResultPrinter::symbolFor emits one per TEST result), so neither phpunit.xml nor --display-skipped can name those skips. Do NOT change phpunit.xml; re-run WITHOUT --testdox."
            return 5
        fi

        # The run's OWN glyphs cannot carry the shortfall (we are past exit 5), so any
        # testdox-looking output in the WIDER input is reported as EVIDENCE only. Stray
        # glyphs from another tool sharing the log are attributed to NO run by
        # segment_runs(), so they never reach the exit-5 branch above (AC2a) and they
        # never exonerate phpunit.xml -- but they are still NAMED, because suppressing
        # them would trade a false diagnosis for a missing one. The phrasing mirrors the
        # input-wide `also` notes of S345 (a surplus attributes NOTHING; an under-count
        # leaves both causes in play; a glyph-less run reports the testdox-looking lines).
        evidence_note=""
        if [ "$input_testdox_lines" -gt 0 ]; then
            if [ "$input_testdox_skips" -gt "$missing" ]; then
                evidence_note=" (Evidence, not a cause: this input carries $input_testdox_lines line(s) in --testdox result format, of which $input_testdox_skips are skips, and none of them are attributed to this run -- MORE skip glyphs than the $missing unnamed skip(s) this run reports. A real testdox run cannot produce a surplus, because every glyph it prints is also counted in its own \`Skipped: N\`, so these glyphs belong to output that is not a testdox skip of this run: they are NOT attributable and they explain none of the shortfall. BOTH causes remain in play -- testdox somewhere in this input, AND phpunit.xml -- and neither is ruled out.)"
            elif [ "$input_testdox_skips" -gt 0 ]; then
                evidence_note=" (Evidence, not a cause: this input carries $input_testdox_lines line(s) in --testdox result format, of which $input_testdox_skips are skips, but none of them are attributed to this run, so testdox explains none of this run's shortfall. BOTH causes are in play -- testdox somewhere in this input, AND phpunit.xml -- and neither is ruled out.)"
            else
                evidence_note=" (Evidence, not a cause: this input carries $input_testdox_lines line(s) in --testdox result format, of which $input_testdox_skips are skips, but none of them are attributed to this run, so testdox explains none of this run's shortfall.)"
            fi
        fi

        # The shortfall survives testdox's share, and this run printed NO
        # detail list at all: the only causes that can carry it are the config
        # attribute being off or the invocation not loading phpunit.xml.
        if [ "$summarisable_declared" -eq 0 ]; then
            printf 'RUN\t%s\t%s\t%s\n' "$idx" 4 "run $idx: $residual skipped test(s) counted, NOT named, not explained by testdox, and this run printed NO skipped-details list at all ($testdox_skips testdox skip glyph(s) present).$evidence_note phpunit.xml has lost displayDetailsOnSkippedTests=true (S345), or the invocation did not load phpunit.xml (a -c/--configuration pointing elsewhere, or --no-configuration). The name set cannot be recovered from this run; fix the config and re-run."
            return 4
        fi

        # A NON-empty list exists but still does not cover the count: some run
        # printed no skipped list, so the set would be PARTIAL.
        printf 'RUN\t%s\t%s\t%s\n' "$idx" 3 "run $idx: the run summary reports $summarised skipped test(s) but the detail lists declared only $summarisable_declared, so $missing name(s) are missing.$testdox_note some run printed no skipped list: check that every invocation loads phpunit.xml (displayDetailsOnSkippedTests=true) and that none of them uses --testdox. The set would be PARTIAL, so it is not written.$evidence_note"
        return 3
    fi

    # More declared than summarised is legitimate only up to what the
    # `No tests executed!` runs declared (the one shape that publishes a list
    # with no totals line). This must NOT be an elif of the branch above: it is
    # reached with `over <= nototals_declared` too, and the extracted check
    # below must still run then (mirror of the main flow's sequential ifs).
    if [ "$declared" -gt "$summarised" ]; then
        over=$((declared - summarised))
        if [ "$over" -gt "$nototals_declared" ]; then
            printf 'RUN\t%s\t%s\t%s\n' "$idx" 3 "run $idx: the detail lists declared $declared skipped test(s)/suite(s) but the run summary reports only $summarised. At most $nototals_declared of that difference is explained by the No tests executed! shape, leaving $((over - nototals_declared)) unexplained. The output format has drifted; fix this parser rather than trusting the set."
            return 3
        fi
    fi

    # The extracted entries no longer match the declared headers.
    if [ "$extracted" -ne "$declared" ]; then
        printf 'RUN\t%s\t%s\t%s\n' "$idx" 3 "run $idx: PHPUnit declared $declared skipped test(s)/suite(s) but $extracted name(s) were extracted. The entry format has drifted; fix this parser rather than trusting the set."
        return 3
    fi

    # The INVISIBLE suite skip (AC2 -- KNOWN LIMIT 2's second shape): a run that
    # ended `No tests executed!` with NOTHING named. The genuine no-totals runs
    # (declared names, handled above) published their suite-skip list; this one
    # published no list, no totals and no testdox glyph -- a whole-suite skip
    # under `--testdox` prints exactly this, because a testSuiteSkipped event
    # produces no glyph (ResultPrinter::symbolFor emits one per TEST result) and
    # the testdox path hardcodes the details printer off. An empty set here
    # would be a silent lie about a suite that skipped, so it is refused instead
    # -- and a plain run that genuinely executed nothing (a typo'd `--filter`,
    # an empty directory) prints the identical shape, so the message says to
    # verify the invocation rather than over-claiming a cause.
    if [ "$no_totals" -gt 0 ] && [ "$declared" -eq 0 ] && [ "$summarised" -eq 0 ]; then
        printf 'RUN\t%s\t%s\t%s\n' "$idx" 5 "run $idx: this run ended 'No tests executed!' with NOTHING named -- every suite it collected was skipped, and a testSuiteSkipped event produces NO glyph under --testdox (TextUI/Output/TestDox/ResultPrinter::symbolFor emits one per TEST result), so the suite skip is INVISIBLE here and PHPUnit cannot name it either (displayDetailsOnSkippedTests is hardcoded FALSE on the testdox path, vendor/phpunit/phpunit/src/TextUI/Output/Facade.php:204-221). Do NOT change phpunit.xml; re-run WITHOUT --testdox to get the SUITE:<name> entry, and verify the invocation collected the suites you meant (a genuinely empty run prints the identical shape)."
        return 5
    fi

    printf 'RUN\t%s\t%s\t%s\n' "$idx" 0 "run $idx: ok -- summarised $summarised, declared $declared (of which $nototals_declared by No tests executed! runs), extracted $extracted, $testdox_skips testdox skip glyph(s)."
    return 0
}

# evaluate_all_runs() consumes segment_runs()'s stdout on stdin: it parses each
# run's RUN\t metadata records, accumulates the run's buffered names, and calls
# evaluate_segment() once per run. If ANY run fails the contract, ALL names are
# discarded, stderr enumerates EVERY failing run (ordinal, banner, the run's
# own arithmetic via its verdict line), and the function returns the FIRST
# failing run's exit code. If every run passes, the buffered names are emitted
# on stdout for the existing `LC_ALL=C sort -u` guard to consume.
#
# The two arguments are the INPUT-WIDE testdox tallies the main flow computed
# (result lines, and skip glyphs among them). They are passed to
# evaluate_segment() so that stray glyphs in the wider log are reported as
# EVIDENCE in a failing run's message even though segment_runs() attributed
# them to no run (AC2a).
evaluate_all_runs() {
    local input_testdox_lines="${1:-0}"
    local input_testdox_skips="${2:-0}"
    local current_idx=""
    local banner="" declared="" summarised="" nototals_declared="" testdox_skips="" extracted="" has_summary="" no_totals=""
    local all_names=""
    local ordinal=0
    local fail_count=0
    local first_fail_code=0
    local -a fail_detail=()

    # Evaluate the run whose fields currently sit in the accumulators. Bash's
    # dynamic scoping lets this helper read and write the caller's locals, so
    # it stays inside evaluate_all_runs and is never called from anywhere else.
    evaluate_accumulated_run() {
        local verdict_line
        verdict_line="$(evaluate_segment "$current_idx" "$banner" "$declared" "$summarised" "$nototals_declared" "$testdox_skips" "$extracted" "$has_summary" "$no_totals" "$input_testdox_lines" "$input_testdox_skips")"
        local code=$?
        if [ "$code" -ne 0 ]; then
            fail_count=$((fail_count + 1))
            if [ "$first_fail_code" -eq 0 ]; then
                first_fail_code="$code"
            fi
            local banner_label="$banner"
            if [ -z "$banner_label" ]; then
                banner_label="<no banner>"
            fi
            fail_detail+=("skipped-test-names:   failing run #$current_idx (ordinal $ordinal): banner \"$banner_label\"")
            fail_detail+=("skipped-test-names:   $verdict_line")
        fi
    }

    while IFS= read -r line; do
        if [[ "$line" =~ ^RUN$'\t'([0-9]+)$'\t'([a-z_]+)$'\t'(.*)$ ]]; then
            local ridx="${BASH_REMATCH[1]}"
            local field="${BASH_REMATCH[2]}"
            local value="${BASH_REMATCH[3]}"

            if [ "$ridx" != "$current_idx" ]; then
                if [ -n "$current_idx" ]; then
                    evaluate_accumulated_run
                fi
                current_idx="$ridx"
                ordinal=$((ordinal + 1))
                banner=""; declared=""; summarised=""; nototals_declared=""; testdox_skips=""; extracted=""; has_summary=""; no_totals=""
            fi

            case "$field" in
                banner)            banner="$value" ;;
                declared)          declared="$value" ;;
                summarised)        summarised="$value" ;;
                nototals_declared) nototals_declared="$value" ;;
                testdox_skips)     testdox_skips="$value" ;;
                extracted)         extracted="$value" ;;
                has_summary)       has_summary="$value" ;;
                no_totals)         no_totals="$value" ;;
            esac
        else
            # A bare line is one of the current run's buffered names.
            if [ -n "$current_idx" ]; then
                all_names="${all_names:+$all_names$'\n'}$line"
            fi
        fi
    done

    if [ -n "$current_idx" ]; then
        evaluate_accumulated_run
    fi

    if [ "$fail_count" -gt 0 ]; then
        printf 'skipped-test-names: FATAL -- per-run accounting: %s of %s run(s) failed the skipped-test contract; first failing exit %s. The name set would be PARTIAL, so it is NOT written. Failing run(s):\n' \
            "$fail_count" "$ordinal" "$first_fail_code" >&2
        printf '%s\n' "${fail_detail[@]}" >&2
        return "$first_fail_code"
    fi

    # ALL OK: emit the buffered names for the existing sort -u guard.
    if [ -n "$all_names" ]; then
        printf '%s\n' "$all_names"
    fi
    return 0
}

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

# S352 — AC2a: EVERY input (one run or many, the documented `gh run view --log` recipe
# included) is evaluated PER RUN -- KNOWN LIMIT 1's durable close, because input-wide
# sums can net one run's shortfall against another run's surplus and exit with a code
# that is right for the sum and wrong for both runs. The contract is decided inside each
# segment_runs() run, and the FIRST failing run's exit code wins. Preamble/foreign
# ` ↩ ` glyphs (before any PHPUnit banner) are attributed to NO run by segment_runs(),
# so only same-run testdox glyphs can reach the exit-5 branch (AC2a closes KNOWN
# LIMIT 3; AC2 closes KNOWN LIMIT 2). The input-wide tallies computed above are passed
# along so stray glyphs in the wider log still show up as EVIDENCE in a failing run's
# message.
segmented="$(printf '%s\n' "$normalised" | segment_runs)"
per_run_output="$(printf '%s\n' "$segmented" | evaluate_all_runs "$testdox_lines" "$testdox_skips")"
per_run_status=$?

# The name set (emitted only when EVERY run passed) goes through the same `LC_ALL=C
# sort -u` guard the input-wide path used. `LC_ALL=C` is load-bearing, not decoration:
# GNU `comm` compares BYTE-wise, and a locale-aware sort orders
# `Phlix\Tests\Unit\Admin\ZTest::a` against `Phlix\Tests\UnitB\ATest::a` the other way
# round, at which point `comm` refuses the file as "not in sorted order". `-u` matters
# for a whole-workflow log, where tests/Unit/Server/ skips appear in two jobs.
if [ -n "$per_run_output" ]; then
    printf '%s\n' "$per_run_output" | LC_ALL=C sort -u
    write_status=$?
else
    write_status=0
fi

if [ "$write_status" -ne 0 ]; then
    # `pipefail` is on, so this catches sort itself AND a failing write to the
    # caller's redirect (ENOSPC, SIGPIPE) -- the exact "quietly truncated set
    # handed to comm" outcome the whole exit contract exists to prevent.
    printf 'skipped-test-names: FATAL -- the sorted name set could not be written (exit %s from `sort`/the output stream). stdout is TRUNCATED and must not be compared.\n' \
        "$write_status" >&2
    exit 6
fi

exit "$per_run_status"

s352_per_run_probe() { grep -c 'Skipped:' ; }
