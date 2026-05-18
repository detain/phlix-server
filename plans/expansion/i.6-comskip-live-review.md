# Step I.6 — Commercial skip via Comskip: Review Checklist

## Reviewer: run these commands.

```bash
cd /home/sites/phlex

./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ComskipIntegration|ComskipLifecycle|ChapterMarker'
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
grep -A5 "'comskip'" config/livetv.php
ls docs/developers/comskip-live.md
```

## Acceptance Criteria:

- [ ] `ComskipIntegration::processRecording()` calls `ComskipRunner::run()` and then parses the EDL
- [ ] `ComskipIntegration` stores `commercial_processed_at`, `commercial_edl_path`, `commercial_frame_count`, `commercial_duration_seconds` in DB
- [ ] `ComskipLifecycleManager::processNext()` processes one recording at a time
- [ ] `ComskipLifecycleManager` enforces `max_concurrent` (processes one; `processNext()` returns false when already processing)
- [ ] `ChapterMarkerService::toHlsChapters()` outputs HLS chapter markers: `#EXT-X-START:TIME=...` segments
- [ ] `ChapterMarkerService::persistChapters()` stores under `media_items.metadata_json['commercial_chapters']`
- [ ] `Recorder::onComplete()` registers `ComskipLifecycleManager::enqueue()` as callback
- [ ] Completed recordings are automatically enqueued without manual intervention
- [ ] `config/livetv.php` has `comskip.binary_path` = `/usr/bin/comskip`
- [ ] ≥ 12 new unit tests pass
- [ ] PHPStan level 9 clean
- [ ] PHPCS clean
- [ ] `docs/developers/comskip-live.md` exists

## Non-obvious points:

- Comskip runs on a completed `.ts` file. Output is `.edl` alongside the recording.
- EDL format is: `<start_frame> <end_frame> <density>` — times in frames, converted to seconds.
- `ComskipLifecycleManager` uses a simple in-memory queue. In production, this
  could be backed by Redis or the DB, but the plan uses in-memory for I.6.
- `ChapterMarkerService::toHlsChapters()` outputs HLS `EXT-X-DISCONTINUITY` tags between chapters.
- The Comskip binary runs via `proc_open()` with 10-minute timeout.
