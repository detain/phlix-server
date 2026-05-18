# Step I.3 — USB DVB-T driver (Linux)

**Phase:** I (Live TV / DVR / IPTV)
**Step:** I.3
**Depends on:** I.1
**Review:** Yes — see `i.3-dvbt-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement a Linux DVB-T (Digital Video Broadcasting — Terrestrial) USB tuner
driver that talks to kernel DVB devices via `/dev/dvb/` and produces a
transport-stream URL for ingestion into Phlex's HLS pipeline. This covers
the USB DVB-T dongles based on common chipsets (RTL2832U, etc.) that expose
Linux DVB API devices.

## 2. Context (what already exists)

- `src/LiveTv/LiveTvManager.php` — after I.1, uses `TunerDriverInterface`.
  I.3 adds a `DvbtTunerDriver` implementing that same interface.
- `src/LiveTv/Tuners/HdHomeRun/HdHomeRunTunerDriver.php` — I.1's driver;
  `DvbtTunerDriver` follows the same `TunerDriverInterface` contract.
- `config/livetv.php` — already has `hdhomerun` key from I.1; will add
  `dvbt` key.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase I table — I.3 is the DVB-T driver step.
- `src/Media/Transcoding/FfmpegRunner.php` — existing FFmpeg wrapper;
  DVB-T transport stream is piped through FFmpeg for HLS packaging.
- `config/ffmpeg.php` — existing FFmpeg config; DVB-T specific transcoding
  profiles may be added here.

## 3. Scope — files to create / modify

### Create

#### New classes — DVB-T driver

- `src/LiveTv/Tuners/Dvbt/DvbtDevice.php` — DVB-T device descriptor:

  ```php
  class DvbtDevice
  {
      public function __construct(
          public readonly string $adapterPath,  // e.g. /dev/dvb/adapter0
          public readonly int $adapterIndex,
          public readonly int $frontendIndex,
          public readonly string $modulation,
          public readonly int $frequencyMin,
          public readonly int $frequencyMax,
      ) {}
  }
  ```

- `src/LiveTv/Tuners/Dvbt/DvbtDeviceScanner.php` — scans `/dev/dvb/` for
  available Linux DVB devices:

  ```php
  class DvbtDeviceScanner
  {
      /** Scan /dev/dvb for available DVB-T adapters. Returns DvbtDevice[]. */
      public function scan(): array {}

      /** Check if a frontend device exists and is a DVB-T type. */
      private function isDvbT(string $frontendPath): bool {}

      /** Read frontend capabilities from sysfs. */
      private function readCapabilities(string $frontendPath): array {}
  }
  ```

- `src/LiveTv/Tuners/Dvbt/DvbtTunerDriver.php` — implements `TunerDriverInterface`.
  Coordinates device scanning, frequency tuning, and stream output:

  ```php
  class DvbtTunerDriver implements TunerDriverInterface
  {
      public function __construct(
          private readonly DvbtDeviceScanner $scanner,
          private readonly DvbtSignalEngine $signalEngine,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function getName(): string { return 'dvbt'; }
      public function discoverDevices(): array { return $this->scanner->scan(); }
      public function getChannelLineup(DvbtDevice $device): array { return []; }
      public function scanChannels(DvbtDevice $device): array { return []; }
      public function getStreamUrl(DvbtDevice $device, int $channelNumber): string
          { return $this->signalEngine->getStreamUrl($device, $channelNumber); }
  }
  ```

- `src/LiveTv/Tuners/Dvbt/DvbtSignalEngine.php` — handles the actual
  DVB-T tuning via `dvbv5-zap` or direct device writes, and produces an
  FFmpeg ingest URL (UDP multicast or named pipe):

  ```php
  class DvbtSignalEngine
  {
      public function __construct(
          private readonly string $ffmpegPath,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Tune to a frequency and return the ingest URL. */
      public function tune(DvbtDevice $device, int $frequencyHz, string $modulation = 'auto'): string {}

      /** Return the HLS-packaged stream URL for the tuned frequency. */
      public function getStreamUrl(DvbtDevice $device, int $channelNumber): string {}

      /** Probe the signal strength of a tuned device. */
      public function getSignalStrength(DvbtDevice $device): array {}
  }
  ```

- `src/LiveTv/Tuners/Dvbt/DvbtTunerDriverFactory.php`:

  ```php
  final class DvbtTunerDriverFactory
  {
      public static function build(?LoggerInterface $logger = null): DvbtTunerDriver {}
  }
  ```

#### Config

- `config/livetv.php` — add `dvbt` key:
  ```php
  'dvbt' => [
      'enabled' => true,
      'ffmpeg_path' => '/usr/bin/ffmpeg',
      'dvbv5_zap_path' => '/usr/bin/dvbv5-zap',
      'default_modulation' => 'auto',
      'default_bandwidth_mhz' => 8,
  ],
  ```

#### Tests

- `tests/unit/LiveTv/Tuners/Dvbt/DvbtDeviceScannerTest.php`
- `tests/unit/LiveTv/Tuners/Dvbt/DvbtSignalEngineTest.php`
- `tests/unit/LiveTv/Tuners/Dvbt/DvbtTunerDriverTest.php`

#### Documentation

- `docs/developers/dvbt.md` — Linux DVB-T API, `/dev/dvb/` structure,
  `dvbv5-zap` usage, modulation types (QAM64/256, DVB-T2).

### Modify

- `config/livetv.php` — add `dvbt` key (done in Create above).
- `src/LiveTv/LiveTvManager.php` — after I.1 + I.2 refactor, also inject
  `DvbtTunerDriver`; union of hardware tuners + IPTV sources.
  Register `dvbt` tuner type in `livetv_tuners` DB table.
- `CHANGELOG.md` — add entry: "Added: Linux DVB-T USB tuner driver".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master, branch `i.3-dvbt`.
2. **Scanner.** `DvbtDeviceScanner` iterates `/dev/dvb/adapter*/frontend*`
   and checks they are DVB-T (reads `caps` from sysfs). Returns `[]`
   gracefully when `/dev/dvb` doesn't exist or is empty.
3. **Signal engine.** `DvbtSignalEngine` uses `dvbv5-zap` (the standard
   Linux DVB tuning tool) via `proc_open()` to tune and stream. The output
   is piped to FFmpeg which repackages to HLS. The HLS output URL is returned.
   Falls back to direct UDP multicast URL if the transport stream is already
   multicast.
4. **Driver.** `DvbtTunerDriver` orchestrates scanner + signal engine.
   Implements `TunerDriverInterface` so `LiveTvManager` treats it uniformly.
5. **Factory.** `DvbtTunerDriverFactory` wires the pieces reading
   `config/livetv.php`.
6. **LiveTvManager.** After I.1+I.2, `discoverTuners()` returns the union of
   HDHomeRun, IPTV, and DVB-T devices.
7. **Tests.** Three test files per §5.
8. **Verification bar.**
9. **Docs.**
10. **Commit + PR + merge.**

## 5. Tests (REQUIRED)

Unit tests (coverage ≥ 85 % on every new class):

1. `DvbtDeviceScannerTest::test_scan_returns_array_of_devices`
2. `DvbtDeviceScannerTest::test_scan_returns_empty_when_no_dev_dvb`
3. `DvbtDeviceScannerTest::test_scan_returns_empty_when_no_frontends`
4. `DvbtSignalEngineTest::test_get_stream_url_returns_string`
5. `DvbtSignalEngineTest::test_tune_returns_ingest_url`
6. `DvbtSignalEngineTest::test_get_signal_strength_returns_array`
7. `DvbtTunerDriverTest::test_get_name_returns_dvbt`
8. `DvbtTunerDriverTest::test_discover_devices_delegates_to_scanner`
9. `DvbtTunerDriverTest::test_get_stream_url_delegates_to_signal_engine`

**Coverage target:** `DvbtDeviceScanner` ≥ 85 %, `DvbtSignalEngine` ≥ 80 %,
`DvbtTunerDriver` ≥ 80 %.

## 6. Documentation

Matrix rows that apply:
- **"Anything"** → `docs/developers/dvbt.md` covers Linux DVB API,
  `/dev/dvb/` structure, `dvbv5-zap` CLI, FFmpeg HLS packaging.
- **"New public class/method"** → all new classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria

- [ ] `DvbtDeviceScanner::scan()` reads `/dev/dvb/adapter*/frontend*`
      and returns `DvbtDevice[]` (or `[]` when absent).
- [ ] `DvbtDeviceScanner::scan()` returns `[]` gracefully when `/dev/dvb`
      does not exist.
- [ ] `DvbtSignalEngine::tune()` calls `dvbv5-zap` to tune to a frequency.
- [ ] `DvbtSignalEngine::getStreamUrl()` returns a valid FFmpeg ingest URL.
- [ ] `DvbtTunerDriver` implements `TunerDriverInterface`.
- [ ] `DvbtTunerDriver::getStreamUrl()` delegates to signal engine.
- [ ] `config/livetv.php` has `dvbt` key with `ffmpeg_path` and
      `dvbv5_zap_path` keys.
- [ ] `./vendor/bin/phpunit` — green; ≥ 9 new tests.
- [ ] Coverage targets met.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/dvbt.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
cd /home/sites/phlex
git status --short && git branch --show-current && git pull --ff-only origin master
git checkout -b i.3-dvbt
# ... implement ...
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Dvbt'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
git add -A
git commit -m "Step I.3: Linux DVB-T USB tuner driver"
unset GITHUB_TOKEN
gh pr create \
  --title "Step I.3 (Live TV): Linux DVB-T USB tuner driver" \
  --body  "Adds DVB-T tuner driver: /dev/dvb scanner, dvbv5-zap signal engine, DvbtTunerDriver wired into LiveTvManager. Part of Phase I (Step I.3 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
git status --short && git branch --show-current && git log --oneline -1 && git branch --list 'i.3-*'
```
