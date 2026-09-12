# S486 — race-free teardown: OrphanMusicContainerReap + HlsServing integration tests

Lane: s486srv · Branch: s486srv-S486-teardown-races · Base: master @eb6bb619 (clean)
Token: S486 lane sentinel lives as code const in both test files (never in .md).

## Status log (newest last)

- [ok] Forensics done (see below). Root causes diagnosed:
  - OrphanMusic: DETERMINISTIC double-cleanup in tearDown() — testHealingRescan
    registers $clean in BOTH $cleanupDirs tree-walk (root dir loop unlinks all children)
    AND $cleanupFiles; second loop chmod()/unlink() an already-gone file. `@`-suppressed,
    so serial PHPUnit Collector drops it — paraunit prints suppressed PHP events → gate red.
  - HlsServing: detached fMP4 writer race. FfmpegRunner fmp4 wrapper chain ends with
    `rm -f <tmp> <tmp>.i <tmp>.s0 <tmp>.m3u8`; produceSegment() returns as soon as the
    FINAL segment file exists while the wrapper is still alive; tearDown rrmdir() has
    UNGUARDED unlink()/rmdir() → ENOENT warnings on the .part-* siblings.
- [ok] composer install run in sandbox; vendor/bin/paraunit 2.11.0 available.
- [ok] Reproduction: loud serial config surfaced the OrphanMusic pair DETERMINISTICALLY
  (Warnings: 2 — chmod() + unlink(…s153-renamed-ok.mp3)); real paraunit gate shape
  (`--parallel=8 --chunk-size=1 phpunit-parallel.xml "Integration/Media"`) reproduced the same
  CI pair with no config help (mechanism proof: paraunit prints suppressed events). HlsServing's
  wrapper race needs a >100 ms rm stall to interleave; forced deterministically with an
  LD_PRELOAD unlink-delay shim + slow-`rm` PATH shim (3/3 runs = the exact CI `.part-*`+`.m3u8` pair).
- [ok] Fixes implemented (S460 pattern, both files, lane sentinel consts code-resident):
  - OrphanMusic: register-before-create guarded `makeCleanupDirectory()`; removals existence-guarded
    (`discardPath`), files-first-then-dirs, every attempted removal verified via fresh-stat
    `pathStillThere()`, loud sentinel-named fail on survival.
  - HlsServing: `random_bytes` dir mint + guarded mkdir; tearDown = bounded 15 s poll for
    `seg-*.part-*` quiescence (marker loss ⇒ wrapper write phase over) → guarded recursive
    removal (3 attempts) → survivor list fails loudly naming sentinel; blind `rrmdir` deleted.
- [ok] Gates: phpcs --standard=phpcs-tests.xml PASS; phpstan -c phpstan-tests.neon PASS (0 errors;
    three if.alwaysTrue findings fixed honestly — removal-call return value + fresh-stat helper).
- [ok] Proofs: OrphanMusic 10× loud → 10× OK(7 tests) 0 warnings 0 residue; HlsServing 10× loud →
  10× OK(2 tests) 0 warnings; HlsServing 5× on the forced-race venue (pre-fix 2 warnings every run)
  → 0 warnings AND 0 residue; paraunit gate shape Integration/Media 3× → EXIT=0, zero warning lines,
  zero errors/failures; census extension executed in every loud+para run, green; ZeroResidueCensusTest 5/5 OK.
- [ok] Mutation proofs: blind dirs-first loops reintroduced → CI pair verbatim; marker poll removed
  → forced-race venue reproduces unlink ENOENT pair. Both files restored byte-identical.
- [ok] CHANGELOG entry (Unreleased/Fixed, house style, no token).
- [next] commit → push → premerge → merge-under-lock → watch post-merge master.
