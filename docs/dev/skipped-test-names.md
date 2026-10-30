# Comparing skip sets between two runs — never compare the COUNT

Deep-dive companion to `scripts/skipped-test-names.sh`. The script header's
numbered KNOWN LIMITS block is the canonical statement of this contract; this
document is its narrative summary and operator guide. (Moved out of README.md,
where ~145 lines of internal tooling narrative sat in an operator document.)

`phpunit.xml` sets `displayDetailsOnSkippedTests="true"`, so every run of this suite
that loads that file **and uses PHPUnit's default result printer** — CI, local,
`--testsuite Unit`, `syncplay-e2e.yml` — prints each skipped test BY NAME. Use the
names, not the number:

```bash
./vendor/bin/phpunit 2>&1 | scripts/skipped-test-names.sh > /tmp/before.txt
# ...make your change...
./vendor/bin/phpunit 2>&1 | scripts/skipped-test-names.sh > /tmp/after.txt
comm -3 /tmp/before.txt /tmp/after.txt   # Left column: tests that dropped out of the skip set. Right column: tests that joined it.
```

Against a CI run (the script strips `gh`'s `job<TAB>step<TAB>timestamp ` prefix for you):

```bash
gh run view <run-id> --log | scripts/skipped-test-names.sh > /tmp/ci.txt
```

That is also how the mechanism is checked after a merge: master's own pre-S345 log exits
**4** on this command (measured on run `32027697809`), so an exit of **0** with a non-empty
file is the before/after demonstration. The exit code is the whole contract — an empty set
is never handed over silently:

| exit | meaning | what to do |
| --- | --- | --- |
| **0** | every run in the input passed the contract; stdout is the sorted set (zero lines is a valid answer for a run that skipped nothing) | compare it |
| **2** | the input is not PHPUnit output at all — no result summary line anywhere — or a run opened by a banner never completed | check what you piped in |
| **3** | some run's numbers do not add up: a run printed no list, or PHPUnit's list/entry format drifted | do **not** compare; read the denominators on stderr |
| **4** | some run counted skips, printed no detail list, and testdox does not explain them | restore `displayDetailsOnSkippedTests="true"`, or find the invocation that did not load `phpunit.xml` |
| **5** | some run's OWN testdox skip glyphs (` ↩ `) prove it is a `--testdox` run and account for all its unnamed skips (the glyph-less suite skip included), or a run ended `No tests executed!` with nothing named | re-run without `--testdox`; **not** a `phpunit.xml` fault |
| **6** | the sorted set could not be written in full (`sort`, or your redirect, failed) | stdout is truncated; do **not** compare |

Denominators always go to stderr, on every exit, so a set can be checked against the count
PHPUnit itself printed. The script's header enumerates the same six codes with the exact
input class that reaches each one.

The evaluation is **per run**, not per input: a multi-run log — the normal
`gh run view <id> --log` shape — is segmented at PHPUnit's banners and terminating
summary lines, and each run's arithmetic is judged on its own denominators. When any
run fails, every failing run is named on stderr (ordinal, banner, that run's own
numbers), no set is written, and the first failing run's exit code wins; the
input-wide tallies still printed on stderr are audit-only and decide nothing. The old
failure mode — one run's shortfall paid for by another run's surplus, netting to a
code right for the sum and wrong for both runs — is closed, so a refusal now points
at a specific run instead of the input as a whole.

⚠ **`--testdox` is the one invocation this cannot cover, and it is a PHPUnit
limitation, not a config mistake.** PHPUnit constructs the default result printer for
a testdox run with its `$displayDetailsOnSkippedTests` argument hardcoded `false`
(the `outputIsTestDox()` branch of `Facade::createResultPrinter()` — the configuration
value is not consulted there; `vendor/phpunit/phpunit/src/TextUI/Output/Facade.php:204-221`
in the pinned PHPUnit 10.5.64, which is the citation the script's own error message
prints), so a `--testdox` run adds to `Skipped: N` and can never
name those skips, with or without `--display-skipped`. No workflow passes `--testdox`
any more (`syncplay-e2e.yml` dropped it in S345 for exactly this reason). If you add it
back, or use it locally, the script exits **5** and says so — do **not** "fix"
`phpunit.xml` in response; re-run without `--testdox`. Exit 5 is decided inside the
run whose OWN glyphs prove it is a testdox run: any ` ↩ ` glyph that does not surplus
the run's shortfall marks the run, and because the attribution uses the run's own
`Skipped: N` rather than the glyph count, a SINGLE glyph is enough to carry the whole
shortfall to exit 5 — the glyph-less suite skip (a `testSuiteSkipped` event produces
no glyph) is accounted for exactly this way. Only a SURPLUS — more glyphs than the
run's shortfall, which no real testdox run can produce because PHPUnit counts every
glyph it prints in the same run's `Skipped: N` — attributes nothing to testdox: both
causes are then named and `phpunit.xml` is not exonerated. A surplus can only come
from other output sharing the log, which `gh run view --log` makes routine. Stray
glyphs are attributed to NO run only when they fall outside every run's segment —
before any PHPUnit banner, or after a run's terminating summary line, since the
segment is flushed and closed at that line — so those can never reach exit 5 whatever
their count. A stray glyph that lands INSIDE a run's segment is attributed to that
run and can carry it to exit 5, because it is arithmetically identical to the genuine
mixed testdox shape above. Exit 5 is also
taken for a run that ends `No tests executed!` with **nothing named**: a whole-suite
skip under `--testdox` prints exactly that (the suite skip is invisible), so a
quietly-empty set would be a lie. The message says to verify the invocation, because
a genuinely empty run (a typo'd `--filter`, an empty directory) prints a
byte-identical shape and is refused the same way — see the KNOWN LIMITS note below.

⚠ **The second exception is the `assertion-escape-probe` job.** It runs PHPUnit from
`scripts/assertion-escape-audit.php`, which captures the output into a PHP variable and
only tests it for a marker — it never echoes it. So no log line and no name set is
obtainable from that job, whatever `phpunit.xml` says. Nothing is wrong with its config;
its output simply never leaves the process. Those are the only two exceptions: PHPUnit's
`--teamcity` printer still emits the skipped-details list, and was checked.

Skipped test **suites** (a class-level `#[Requires*]`, or `markTestSkipped()` in
`setUpBeforeClass()`) are counted in the same `Skipped: N` but named under a different
header, and a run in which every suite skipped prints `No tests executed!` with no
`Tests:` line at all. The script parses that shape too and emits such an entry as
`SUITE:<name>`.

**Why names and not `Skipped: N`.** The number drifts between runs on its own — this
repo has legitimate environment-dependent skips, and this box reports ~259 where CI
reports 3 — and a moved count cannot distinguish *"a test started skipping"* from
*"a conditionally-skipped test turned into a failure"* from *"a test disappeared"*.
All three move it identically, and two of the three can leave it **unmoved**. `comm`
over the name set separates them; the count cannot. `scripts/skipped-test-names.sh`
writes only the sorted set to stdout (so `comm`/`diff` can eat it directly), puts its
denominators on stderr, and **fails loudly** rather than printing an empty set — with
one of the exit codes in the table above. Each code names the cause its own
arithmetic supports, and never attributes more to a cause than that cause can carry — a
wrong diagnosis would send the next reader to fix the wrong thing, and rounds 2 and 3 of
the S345 review plus its test engineer each caught one doing exactly that before it
shipped (the last was a clamp that trimmed a surplus of testdox glyphs down to the
shortfall; it is removed rather than re-tuned, because a surplus cannot come from a real
testdox run at all).

⚠ That is **not** a guarantee that the code is right for every input — the script's
header carries a numbered KNOWN LIMITS block, re-derived for per-run evaluation, and
this document is its summary. Three of the old input-wide limits are closed by that
re-derivation (KNOWN LIMITS 1–3) and are no longer true: a shortfall in one run can
no longer be paid for by another run's surplus (the multi-run refusal above replaces
the netting — limit 1), a `--testdox` run with a skipped SUITE is attributed by that
run's own `Skipped: N`, glyph-less suite skip included (limit 2, AC2), and exit 5 no
longer rests on an equality of two input-wide totals (limit 3, AC2a). What is still
open — limits 4–6, all measured on this version, plus limit 2's refusal side effect,
listed first:

- **Limit 2's refusal side effect — a run that genuinely executed nothing is refused
  too.** A typo'd `--filter` or an
  empty directory prints a byte-identical `No tests executed!` to the invisible
  suite-skip shape, so it exits **5** with no set written and a message that says to
  verify the invocation — the right refusal for a shape that could otherwise hand
  over a silently-empty set.
- **KNOWN LIMIT 6 — the input is assumed to be CONCATENATED runs**, as `./vendor/bin/phpunit 2>&1` and
  a single job's `gh run view --log` produce. `gh run view --log` strips its
  `job<TAB>step<TAB>timestamp ` prefix but does **not** group the lines by job: when
  two jobs' PHPUnit runs are INTERLEAVED in one log (parallel jobs), the
  banner-delimited segmentation splits at the wrong boundaries and exits **2** naming
  run 1 about input that is complete — the right refusal, wrong cause. The documented
  recipe is safe when a workflow's PHPUnit invocations are sequential steps of ONE
  job; for parallel jobs, pipe each job's log separately
  (`gh run view --log --job <job-id>`).
- **KNOWN LIMIT 4 — a data-set label containing a literal newline** is emitted truncated at that
  newline: the entry spans two lines, the denominators still agree, and this is the
  one shape that escapes the cross-check. No data provider here produces one and the
  truncation is stable across runs, so `comm` is not corrupted by it — but the
  denominator argument does not cover it.
- **KNOWN LIMIT 5 — if a future PHPUnit renames the list header itself** (`There was 1 skipped test:`),
  the refusal is still loud and nothing is written, but it arrives as exit **4**
  blaming `phpunit.xml` rather than as exit 3 blaming the parser — the right refusal
  with the wrong cause.

The script's header carries all six limits (three closed by S352, three still open,
plus the refusal side-effect of limit 2 — the genuinely-empty run shape, listed
first above), numbered, each with the input that reaches it, and enumerates every
exit path with its input class; that header is the canonical statement of this
contract, and this document is its summary.
