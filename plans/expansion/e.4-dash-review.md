# Review: Step E.4 — DASH output alongside HLS

**Step:** E.4
**Plan file:** `e.4-dash.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show E.4 squashed commit
git branch --list 'e.4-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 11 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'DashStreamer|SegmentTemplate|AdaptationSet'
# Expected: DashStreamer ≥ 85%, SegmentTemplate ≥ 85%, AdaptationSet ≥ 85%

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

For each acceptance criterion in `e.4-dash.md` §7:

- [ ] `DashStreamer::generateMasterMpd()` produces valid XML with `<MPD>` root
      and `<AdaptationSet>` children
- [ ] `DashStreamer::generateMasterMpd()` includes
      `profiles="urn:mpeg:dash:profile:isoff-live:2011"`
- [ ] `DashStreamer::generateAdaptationSetMpd()` produces valid MPD with `<SegmentTemplate>`
- [ ] `DashStreamer::getMasterMpdUrl()` returns `/dash/{jobId}/manifest.mpd`
- [ ] `DashStreamer::saveMpd()` writes the MPD file to the job directory
- [ ] `DashStreamer::getSegmentPath()` returns a path ending in `.m4s`
- [ ] `StreamManager::getManifestUrl('job-123', 'dash')` returns DASH manifest URL
- [ ] `StreamManager::getManifestUrl('job-123', 'hls')` returns HLS manifest URL
- [ ] DASH routes are wired in `Router.php`
- [ ] `config/dash.php` exists with all required keys
- [ ] `docs/developers/streaming-protocols.md` written
- [ ] CHANGELOG has E.4 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-E.4 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.11.0`
- Coverage of `DashStreamer`, `SegmentTemplate`, or `AdaptationSet` drops below 85%
