# Step I.3 — USB DVB-T driver (Linux): Review Checklist

## Reviewer: run these commands.

```bash
cd /home/sites/phlex

./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Dvbt'
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Config
grep -A5 "'dvbt'" config/livetv.php
# Docs
ls docs/developers/dvbt.md
```

## Acceptance Criteria:

- [ ] `DvbtDeviceScanner::scan()` reads `/dev/dvb/adapter*` directories
- [ ] `DvbtDeviceScanner::scan()` returns `[]` gracefully when `/dev/dvb` absent
- [ ] `DvbtSignalEngine::tune()` invokes `dvbv5-zap` via `proc_open()`
- [ ] `DvbtSignalEngine::getStreamUrl()` returns FFmpeg ingest URL (UDP or pipe)
- [ ] `DvbtTunerDriver` implements `TunerDriverInterface` with `getName() = 'dvbt'`
- [ ] `config/livetv.php` has `dvbt.ffmpeg_path` and `dvbt.dvbv5_zap_path`
- [ ] `LiveTvManager` treats DVB-T devices alongside HDHomeRun
- [ ] ≥ 9 new unit tests pass
- [ ] PHPStan level 9 clean
- [ ] PHPCS clean
- [ ] `docs/developers/dvbt.md` exists

## Non-obvious points:

- `DvbtDevice` holds readonly fields: `adapterPath`, `adapterIndex`,
  `frontendIndex`, `modulation`, `frequencyMin`, `frequencyMax`.
- `DvbtSignalEngine::getStreamUrl()` returns a URL like
  `ffmpeg://localhost:8080/live/{deviceId}/{frequency}` or similar —
  the actual FFmpeg process is started by the HLS streamer.
- The scanner checks `file_exists("/dev/dvb")` first, returns `[]` immediately
  if absent (no exception thrown).
