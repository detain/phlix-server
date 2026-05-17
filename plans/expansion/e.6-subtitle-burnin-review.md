# Review: Step E.6 — Subtitle burn-in pipeline

**Step:** E.6
**Plan file:** `e.6-subtitle-burnin.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show E.6 squashed commit
git branch --list 'e.6-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 15 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Subtitle'
# Expected: SubtitleBurner ≥ 85%, SubtitleStyleOptions ≥ 85%

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

For each acceptance criterion in `e.6-subtitle-burnin.md` §7:

- [ ] `SubtitleFormat::getFfmpegFormat()` returns correct format string for each enum case
- [ ] `SubtitleFormat::supportsFontstyle()` returns `true` for ASS/SSA, `false` for SRT/VTT
- [ ] `SubtitleBurner::detectSubtitleTracks()` returns non-empty array when subtitles exist
- [ ] `SubtitleBurner::detectSubtitleTracks()` returns empty array when no subtitles exist
- [ ] `SubtitleBurner::getBurnInFilter()` returns a filter string containing `subtitles=`
- [ ] `SubtitleBurner::getBurnInFilter()` for VAAPI vendor returns `overlay_vaapi`
- [ ] `SubtitleBurner::getBurnInArgs()` for NVENC returns software fallback args with `hwupload`
- [ ] `SubtitleBurner::extractSubtitle()` writes a valid subtitle file to disk
- [ ] `SubtitleStyleOptions::toAssStyle()` returns a formatted ASS style string
- [ ] `HwaccelCommandBuilder::setSubtitleTrack()` integrates burn-in args into the command
- [ ] `config/subtitles.php` exists with all configuration keys including `style` sub-key
- [ ] `docs/developers/subtitle-processing.md` written
- [ ] CHANGELOG has E.6 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-E.6 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.11.0`
- Coverage of `SubtitleBurner` or `SubtitleStyleOptions` drops below 85%
