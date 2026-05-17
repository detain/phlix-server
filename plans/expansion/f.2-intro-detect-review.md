# Review: Step F.2 — Intro/outro detection job

**Step:** F.2
**Plan file:** `f.2-intro-detect.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show F.2 squashed commit
git branch --list 'f.2-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 13 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'IntroDetectionJob|FingerprintClusterer|MarkerCandidateStore'
# Expected: each ≥ 85%

# ─── Static analysis ───
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
# Expected: [OK] No errors

# ─── Code style ───
./vendor/bin/phpcs --standard=PSR12 src/
# Expected: clean (warnings OK, 0 errors)

# ─── Syntax ───
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Expected: empty output
```

## 3. Check deliverables

For each acceptance criterion in `f.2-intro-detect.md` §7:

- [ ] `IntroDetectionJob::detectForShow()` returns `IntroDetectionResult`
- [ ] `FingerprintClusterer::cluster()` correctly groups similar fingerprints
- [ ] `FingerprintClusterer::similarity()` returns 0.0–1.0 float
- [ ] `MarkerCandidateStore::enqueueShow()` / `dequeueShow()` / `completeShow()`
      maintain a FIFO queue in the filesystem
- [ ] `MarkerCandidateRepository::storeCandidates()` persists candidates
      to each episode's `metadata_json`
- [ ] `BackgroundDetectorWorker::runOnce()` processes one show from the queue
- [ ] `scripts/run-marker-detection-worker.php` runs as a standalone CLI
- [ ] `config/marker_detection.php` exists with all required keys
- [ ] `docs/developers/intro-outro-detection.md` written
- [ ] CHANGELOG has F.2 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-F.2 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.12.0`
- Coverage of `IntroDetectionJob`, `FingerprintClusterer`, or `MarkerCandidateStore`
  drops below 85%
- The file-based queue has a race condition on dequeue (must be atomic)
