# Step I.1 — HDHomeRun tuner driver

**Phase:** I (Live TV / DVR / IPTV)
**Step:** I.1
**Depends on:** A.4
**Review:** Yes — see `i.1-hdhomerun-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement a concrete HDHomeRun tuner driver for Phlex's LiveTV module.
HDHomeRun devices are network-attached TV tuners that communicate via:
- **SSDP** (Simple Service Discovery Protocol) on UDP port 1900 for device discovery
- **HTTP API** on port 80 for channel tuning and stream retrieval

This step wires a real network tuner driver into `LiveTvManager`, replacing the
stub `scanForTuners()` / `scanFrequency()` methods with actual SSDP discovery
and HDHomeRun HTTP API calls.

## 2. Context (what already exists)

Read first, do not modify until §4:

- `src/LiveTv/LiveTvManager.php` — framework already has `discoverTuners()`,
  `scanForTuners()`, `scanFrequency()`, `tuneToChannel()`, `stopTuning()`.
  These are the stubs this step replaces.
- `src/LiveTv/ChannelManager.php` — `createChannel()`, already accepts
  `tuner_id` and `service_id`.
- `config/server.php` — server config; HDHomeRun settings will go in a new
  `config/livetv.php`.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Live TV / DVR / IPTV" is **Missing**;
  this step fills that row.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase I table — I.1 is the HDHomeRun driver step.
- `src/Media/Streaming/HlsStreamer.php` — existing HLS output; HDHomeRun
  streams are output as HLS variant playlists.

## 3. Scope — files to create / modify

### Create

#### New classes — HDHomeRun driver

- `src/LiveTv/Tuners/HdHomeRun/HdHomeRunDevice.php` — discovered HDHomeRun
  device descriptor (IP, device ID, tuner count, lineup URL):

  ```php
  class HdHomeRunDevice
  {
      public function __construct(
          public readonly string $deviceId,
          public readonly string $ipAddress,
          public readonly int $tunerCount,
          public readonly string $lineupUrl,
      ) {}

      public function getTunerCount(): int {}
      public function getBaseUrl(): string {}
  }
  ```

- `src/LiveTv/Tuners/HdHomeRun/HdHomeRunDiscovery.php` — SSDP discovery:
  sends `M-SEARCH` on UDP 1900, collects `Location:` HTTP headers,
  resolves device description XML, returns `HdHomeRunDevice[]`.

  ```php
  class HdHomeRunDiscovery
  {
      public function __construct(
          private readonly ?LoggerInterface $logger = null,
          private readonly int $timeoutSecs = 5,
      ) {}

      /** Discover all HDHomeRun devices on the network. Returns HdHomeRunDevice[]. */
      public function discover(): array {}

      /** Send a single SSDP M-SEARCH and collect responses. */
      private function sendSearch(): array {}

      /** Fetch and parse a device's XML description. */
      private function fetchDeviceDescription(string $locationUrl): ?array {}
  }
  ```

- `src/LiveTv/Tuners/HdHomeRun/HdHomeRunApiClient.php` — HTTP API client:

  ```php
  class HdHomeRunApiClient
  {
      public function __construct(
          private readonly string $baseUrl,  // e.g. http://192.168.1.100
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** GET /discover.json — returns device info hash. */
      public function discover(): array {}

      /** GET /lineup.json — returns available channels. */
      public function getChannelLineup(): array {}

      /** GET /lineup.post — trigger a channel scan. */
      public function triggerScan(): bool {}

      /** GET /tuningformatail -- stream URL for a physical channel. */
      public function getTuningResult(string $channel): array {}

      /** Return the HLS stream URL for a channel number. */
      public function getStreamUrl(int $channelNumber): string {}
  }
  ```

- `src/LiveTv/Tuners/HdHomeRun/HdHomeRunTunerDriver.php` — concrete driver
  implementing the `TunerDriverInterface` (defined this step):

  ```php
  interface TunerDriverInterface
  {
      public function getName(): string;
      public function discoverDevices(): array<HdHomeRunDevice>;
      public function getChannelLineup(HdHomeRunDevice $device): array;
      public function scanChannels(HdHomeRunDevice $device): array;
      public function getStreamUrl(HdHomeRunDevice $device, int $channelNumber): string;
  }

  class HdHomeRunTunerDriver implements TunerDriverInterface
  {
      public function __construct(
          private readonly HdHomeRunDiscovery $discovery,
          private readonly HdHomeRunApiClient $apiClient,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function getName(): string { return 'hdhomerun'; }
      public function discoverDevices(): array { /* SSDP → device list */ }
      public function getChannelLineup(HdHomeRunDevice $device): array { /* /lineup.json */ }
      public function scanChannels(HdHomeRunDevice $device): array { /* /lineup.post */ }
      public function getStreamUrl(HdHomeRunDevice $device, int $channelNumber): string
          { /* constructs hdhomerun:// URL or HLS URL */ }
  }
  ```

- `src/LiveTv/Tuners/HdHomeRun/HdHomeRunTunerDriverFactory.php` — factory:

  ```php
  final class HdHomeRunTunerDriverFactory
  {
      public static function build(?LoggerInterface $logger = null): HdHomeRunTunerDriver {}
  }
  ```

#### Tuner driver interface

- `src/LiveTv/Tuners/TunerDriverInterface.php` — shared interface all tuner
  drivers must implement.

#### Config

- `config/livetv.php` — LiveTV configuration:
  ```php
  return [
      'hdhomerun' => [
          'enabled' => true,
          'ssdp_timeout_secs' => 5,
          'preferred_device_id' => null,  // null = auto-discover first
      ],
      'storage_path' => '/var/recordings',
      'max_storage_bytes' => 0,  // 0 = unlimited
  ];
  ```

#### Tests

- `tests/unit/LiveTv/Tuners/HdHomeRun/HdHomeRunDiscoveryTest.php`
- `tests/unit/LiveTv/Tuners/HdHomeRun/HdHomeRunApiClientTest.php`
- `tests/unit/LiveTv/Tuners/HdHomeRun/HdHomeRunTunerDriverTest.php`

#### Documentation

- `docs/developers/hdhomerun.md` — new doc: how SSDP discovery works,
  API endpoints, config keys, and how to extend with another tuner type.

### Modify

- `src/LiveTv/LiveTvManager.php` — refactor `discoverTuners()` to delegate to
  `HdHomeRunTunerDriver`; refactor `scanChannels()` and `scanFrequency()` to
  use `HdHomeRunApiClient`; refactor `tuneToChannel()` to return the
  HDHomeRun HLS/variant stream URL; store `tuner_type = 'hdhomerun'` in DB.
- `composer.json` — no new runtime dependencies.
- `CHANGELOG.md` — add entry: "Added: HDHomeRun tuner driver (SSDP + HTTP API)".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b i.1-hdhomerun`.
2. **Interface first.** Define `TunerDriverInterface` so the driver is
   dependency-injected properly.
3. **Discovery.** `HdHomeRunDiscovery` sends UDP broadcast on port 1900,
   collects SSDP `NOTIFY` responses, fetches device XML, returns devices.
   Socket timeout 5 s; catch all network exceptions and return `[]`.
4. **API client.** `HdHomeRunApiClient` is a plain HTTP client (use
   `file_get_contents` with stream context for simplicity; no Guzzle dep).
   All API calls are GET; parse JSON responses.
5. **Driver.** `HdHomeRunTunerDriver` orchestrates discovery + API client,
   returning `HdHomeRunDevice` objects and stream URLs.
6. **Factory.** `HdHomeRunTunerDriverFactory` wires the pieces.
7. **LiveTvManager wiring.** Remove the stub `/dev/dvb` scanner; inject
   `HdHomeRunTunerDriver` via constructor; `discoverTuners()` calls
   `$this->driver->discoverDevices()` and persists the same DB schema.
8. **Config.** Write `config/livetv.php`.
9. **Tests.** Write three test files covering all new classes per §5.
10. **Verification bar** (§0.4 minimum bar).
11. **Docs.**
12. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `HdHomeRunDiscoveryTest::test_discover_returns_array_of_devices`
2. `HdHomeRunDiscoveryTest::test_discover_returns_empty_on_network_error`
3. `HdHomeRunDiscoveryTest::test_discover_parses_device_xml`
4. `HdHomeRunApiClientTest::test_get_channel_lineup_returns_array`
5. `HdHomeRunApiClientTest::test_get_stream_url_builds_correct_url`
6. `HdHomeRunApiClientTest::test_trigger_scan_returns_bool`
7. `HdHomeRunTunerDriverTest::test_get_name_returns_hdhomerun`
8. `HdHomeRunTunerDriverTest::test_discover_devices_delegates_to_discovery`
9. `HdHomeRunTunerDriverTest::test_get_stream_url_uses_device_ip`

**Coverage target:** `HdHomeRunDiscovery` ≥ 85 %, `HdHomeRunApiClient` ≥ 85 %,
`HdHomeRunTunerDriver` ≥ 80 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → `docs/developers/hdhomerun.md` (new) covers SSDP discovery
  protocol, HDHomeRun HTTP API, config keys, and device description format.
- **"New public class/method"** → all new public classes get PHPDoc with
  `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (HDHomeRun tuners now
  auto-discovered on the network).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `TunerDriverInterface` defines the shared contract.
- [ ] `HdHomeRunDiscovery::discover()` sends SSDP M-SEARCH on UDP 1900 and
      returns `HdHomeRunDevice[]` parsed from device XML.
- [ ] `HdHomeRunDiscovery::discover()` returns `[]` gracefully when no
      devices are found or network is unavailable.
- [ ] `HdHomeRunApiClient::getChannelLineup()` returns parsed `/lineup.json`.
- [ ] `HdHomeRunApiClient::getStreamUrl()` returns a valid stream URL.
- [ ] `HdHomeRunTunerDriver` implements `TunerDriverInterface`.
- [ ] `LiveTvManager` no longer references `/dev/dvb`; uses injected driver.
- [ ] `config/livetv.php` exists with all required keys.
- [ ] `./vendor/bin/phpunit` — green; ≥ 9 new tests.
- [ ] Coverage of `HdHomeRunDiscovery` ≥ 85 %, `HdHomeRunApiClient` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/hdhomerun.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b i.1-hdhomerun

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'HdHomeRun|TunerDriver'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step I.1: HDHomeRun tuner driver — SSDP discovery + HTTP API client"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step I.1 (Live TV): HDHomeRun tuner driver" \
  --body  "Adds HDHomeRun tuner driver: SSDP device discovery on UDP 1900, HTTP API client, TunerDriverInterface, HdHomeRunTunerDriver wired into LiveTvManager. Part of Phase I (Step I.1 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'i.1-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `i.1-hdhomerun-review.md`.
