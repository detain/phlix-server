# Step J.1 — SSDP + mDNS broadcast + listener

**Phase:** J (DLNA / Cast / Discovery)
**Step:** J.1
**Depends on:** A.7
**Review:** Yes — see `j.1-discovery-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement SSDP (Simple Service Discovery Protocol) and mDNS (multicast DNS / Bonjour/Avahi) broadcast and listener infrastructure for Phlex's discovery layer.

- **SSDP** uses UDP multicast address `239.255.255.250` port `1900` to discover DLNA devices and announce the Phlex server.
- **mDNS** uses UDP multicast address `224.0.0.251` port `5353` to discover Chromecast, AirPlay, and Roku devices, and to announce Phlex services.

This step establishes the shared discovery foundation that J.2–J.6 build on.

## 2. Context (what already exists)

Read first, do not modify until §4:

- `src/Dlna/DlnaServer.php` — already has `DlnaServer` with UDN prefix, SOAP handlers, device description XML. This step makes it discoverable via SSDP NOTIFY.
- `src/Dlna/DeviceRegistry.php` — registry for discovered DLNA renderers (used by J.3 AVTransport).
- `src/Dlna/ContentDirectory.php` — existing CDS implementation (not yet wired to SSDP).
- `src/Dlna/AvTransport.php` — existing AVTransport implementation (not yet wired to SSDP).
- `src/Server/Core/Application.php` — server entry point; discovery services should start here.
- `config/server.php` — server config; discovery settings go in `config/discovery.php`.
- Phase A.7 doc (`plans/expansion/a.7-plugin-docs.md`) — this step satisfies the "Discovery / Protocol docs" row in PHLEX_EXPANSION_PLAN.md.

## 3. Scope — files to create / modify

### Create

#### New classes — SSDP discovery

- `src/Discovery/Ssdp/SsdpSocket.php` — raw UDP socket wrapper for SSDP:
  ```php
  class SsdpSocket
  {
      public const MULTICAST_ADDR = '239.255.255.250';
      public const PORT = 1900;

      public function __construct(
          private readonly ?LoggerInterface $logger = null,
          private readonly int $timeoutSecs = 5,
      ) {}

      /** Send an SSDP M-SEARCH and return raw responses. */
      public function search(string $st, int $mx = 3): array {}

      /** Send an SSDP NOTIFY announcement. */
      public function announce(string $nt, string $location, string $usn): void {}

      /** Parse a received SSDP response line. */
      public function parseResponse(string $data): ?array {}

      /** Close the socket. */
      public function close(): void {}
  }
  ```

- `src/Discovery/Ssdp/SsdpDevice.php` — discovered SSDP device descriptor:
  ```php
  class SsdpDevice
  {
      public function __construct(
          public readonly string $usn,       // Unique Service Name
          public readonly string $nt,       // Notification Type
          public readonly string $location, // Device description URL
          public readonly string $server,   // Server string
          public readonly int $cacheTimeout, // MAX-AGE seconds
          ?string $deviceType = null,
      ) {}

      public function getDeviceId(): string {}  // Extract UUID from USN
      public function getBaseUrl(): ?string {}   // Parse Location host:port
  }
  ```

- `src/Discovery/Ssdp/SsdpDiscovery.php` — SSDP discovery service:
  ```php
  class SsdpDiscovery
  {
      public function __construct(
          private readonly SsdpSocket $socket,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Discover all DLNA/UPnP devices on the network. */
      public function discoverDevices(string $st = 'urn:schemas-upnp-org:device:*'): array {}

      /** Announce the Phlex server via SSDP NOTIFY. */
      public function announceServer(string $serverId, string $friendlyName, string $baseUrl, int $port): void {}

      /** Parse a device description URL and return a DlnaDevice. */
      public function resolveDeviceDescription(string $locationUrl): ?array {}
  }
  ```

#### New classes — mDNS discovery

- `src/Discovery/Mdns/MdnsSocket.php` — raw UDP socket wrapper for mDNS:
  ```php
  class MdnsSocket
  {
      public const MULTICAST_ADDR = '224.0.0.251';
      public const PORT = 5353;

      public function __construct(
          private readonly ?LoggerInterface $logger = null,
          private readonly int $timeoutSecs = 5,
      ) {}

      /** Send an mDNS query for a service type (e.g., '_googlecast._tcp.local.'). */
      public function query(string $name, int $qtype = 12): array {}

      /** Parse a received mDNS response. */
      public function parseResponse(string $data): ?array {}

      /** Close the socket. */
      public function close(): void {}
  }
  ```

- `src/Discovery/Mdns/MdnsService.php` — resolved mDNS service descriptor:
  ```php
  class MdnsService
  {
      public function __construct(
          public readonly string $name,      // e.g. 'Chromecast-xxxx._googlecast._tcp.local.'
          public readonly string $type,      // e.g. '_googlecast._tcp.local.'
          public readonly int $port,
          public readonly string $host,
          public readonly array $txtRecords = [],
          public readonly string $deviceId = '',
      ) {}

      public function getAddress(): string {}  // "host:port"
  }
  ```

- `src/Discovery/Mdns/MdnsDiscovery.php` — mDNS discovery service:
  ```php
  class MdnsDiscovery
  {
      public const SERVICE_CHROMECAST = '_googlecast._tcp.local.';
      public const SERVICE_AIRPLAY = '_airplay._tcp.local.';
      public const SERVICE_RAOP = '_raop._tcp.local.';        // Audio AirPlay
      public const SERVICE_ROKU = '_ roku-ecnp._tcp.local.';  // Roku ECP

      public function __construct(
          private readonly MdnsSocket $socket,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Discover Chromecast / Google Cast devices. */
      public function discoverChromecast(): array {}

      /** Discover AirPlay 2 devices (includes _airplay._tcp.local. and _raop._tcp.local.). */
      public function discoverAirPlay(): array {}

      /** Discover Roku devices via mDNS. */
      public function discoverRoku(): array {}

      /** Announce Phlex server via mDNS. */
      public function announceServer(string $name, string $type, int $port, array $txt = []): void {}
  }
  ```

#### New classes — unified discovery manager

- `src/Discovery/DiscoveryManager.php` — unified facade for all discovery:
  ```php
  class DiscoveryManager
  {
      public function __construct(
          private readonly SsdpDiscovery $ssdp,
          private readonly MdnsDiscovery $mdns,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Discover all DLNA servers on the network via SSDP. */
      public function discoverDlnaServers(): array {}

      /** Discover all DLNA renderers (TVs, speakers) via SSDP. */
      public function discoverDlnaRenderers(): array {}

      /** Discover Chromecast devices via mDNS. */
      public function discoverChromecastDevices(): array {}

      /** Discover AirPlay devices via mDNS. */
      public function discoverAirPlayDevices(): array {}

      /** Discover Roku devices via mDNS. */
      public function discoverRokuDevices(): array {}

      /** Announce the Phlex server via both SSDP and mDNS. */
      public function announcePhlexServer(string $serverId, string $friendlyName, string $baseUrl, int $port): void {}

      /** Start background listeners for incoming discovery. */
      public function startListeners(callable $onDeviceDiscovered): void {}
  }
  ```

- `src/Discovery/DiscoveryServer.php` — HTTP handler that processes incoming SSDP/mDNS UDP packets via Workerman Timer:
  ```php
  class DiscoveryServer
  {
      public function __construct(
          private readonly DiscoveryManager $manager,
          private readonly ?StructuredLogger $logger = null,
      ) {}

      /** Start listening for SSDP NOTIFY and mDNS responses. */
      public function start(): void {}

      /** Stop listening. */
      public function stop(): void {}
  }
  ```

#### Config

- `config/discovery.php`:
  ```php
  return [
      'ssdp' => [
          'enabled' => true,
          'announce_interval_secs' => 600,  // SSDP NOTIFY interval
          'discovery_timeout_secs' => 5,
      ],
      'mdns' => [
          'enabled' => true,
          'discovery_timeout_secs' => 5,
      ],
      'discovery_port' => 8200,  // Phlex server port for discovery responses
  ];
  ```

#### Tests

- `tests/Unit/Discovery/Ssdp/SsdpSocketTest.php`
- `tests/Unit/Discovery/Ssdp/SsdpDiscoveryTest.php`
- `tests/Unit/Discovery/Mdns/MdnsSocketTest.php`
- `tests/Unit/Discovery/Mdns/MdnsDiscoveryTest.php`
- `tests/Unit/Discovery/DiscoveryManagerTest.php`

#### Documentation

- `docs/developers/discovery.md` — new doc: how SSDP and mDNS discovery work, protocol details, config keys.

### Modify

- `src/Server/Core/Application.php` — inject `DiscoveryServer` start/stop into the Workerman worker lifecycle.
- `composer.json` — no new runtime dependencies (pure PHP sockets).
- `CHANGELOG.md` — add entry: "Added: SSDP + mDNS discovery infrastructure".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch: `git checkout -b j.1-discovery`.
2. **Socket abstraction first.** `SsdpSocket` wraps raw UDP `239.255.255.250:1900`. `MdnsSocket` wraps raw UDP `224.0.0.251:5353`. Use `socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)` with `socket_set_option` for multicast TTL and loopback. Catch all network exceptions and return `[]`.
3. **SSDP.** `SsdpDiscovery::discoverDevices()` sends M-SEARCH with `ST: urn:schemas-upnp-org:device:*`, collects NOTIFY responses, resolves device description URL. `announceServer()` sends periodic NOTIFY with `NT: urn:schemas-upnp-org:device:MediaServer:1`.
4. **mDNS.** `MdnsDiscovery::discoverChromecast()` sends query for `_googlecast._tcp.local.`; `discoverAirPlay()` queries `_airplay._tcp.local.` + `_raop._tcp.local.`; `discoverRoku()` queries `_ roku-ecnp._tcp.local.` (note leading space — Roku uses this unusual name). Parse DNS record format (QTYPE 12 = PTR, 16 = TXT, 33 = SRV).
5. **DiscoveryManager.** Facade that combines both. `startListeners()` uses Workerman `Timer` to periodically send M-SEARCH and mDNS queries; stores discovered devices in `DeviceRegistry`.
6. **DiscoveryServer.** Wires into `Application::run()` so it starts/stops with the server. Background timer sends periodic M-SEARCH (every 60 s) and mDNS queries (every 30 s) to keep device list fresh.
7. **Config.** Write `config/discovery.php`.
8. **Tests.** Write five test files covering all new classes per §5.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `SsdpSocketTest::test_search_sends_msearch_and_returns_responses`
2. `SsdpSocketTest::test_parse_response_extracts_fields`
3. `SsdpSocketTest::test_close_closes_socket`
4. `SsdpDiscoveryTest::test_discover_devices_returns_array`
5. `SsdpDiscoveryTest::test_discover_returns_empty_on_network_error`
6. `MdnsSocketTest::test_query_sends_dns_query`
7. `MdnsSocketTest::test_parse_response_extracts_srv_and_txt`
8. `MdnsDiscoveryTest::test_discover_chromecast_returns_array`
9. `MdnsDiscoveryTest::test_discover_airplay_returns_array`
10. `DiscoveryManagerTest::test_discover_dlna_servers_delegates_to_ssdp`
11. `DiscoveryManagerTest::test_discover_chromecast_devices_delegates_to_mdns`
12. `DiscoveryManagerTest::test_announce_server_calls_both_ssdp_and_mdns`

**Coverage target:** `SsdpSocket` ≥ 85 %, `MdnsSocket` ≥ 85 %, `SsdpDiscovery` ≥ 80 %, `MdnsDiscovery` ≥ 80 %, `DiscoveryManager` ≥ 80 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → `docs/developers/discovery.md` (new) covers SSDP and mDNS protocols, multicast addresses, message formats, and config keys.
- **"New public class/method"** → all new public classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (Phlex now discovers DLNA/Chromecast/AirPlay/Roku devices on the network).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `SsdpSocket` sends M-SEARCH on `239.255.255.250:1900` and returns parsed responses.
- [ ] `SsdpSocket::parseResponse()` correctly extracts `LOCATION`, `SERVER`, `NT`, `USN`, `CACHE-CONTROL` fields.
- [ ] `MdnsSocket` sends queries on `224.0.0.251:5353` and returns parsed responses.
- [ ] `MdnsSocket::parseResponse()` correctly extracts SRV (port, host) and TXT records.
- [ ] `SsdpDiscovery::discoverDevices()` returns `SsdpDevice[]` with working `getDeviceId()` and `getBaseUrl()`.
- [ ] `MdnsDiscovery::discoverChromecast()` returns `MdnsService[]` with correct `deviceId` from TXT record.
- [ ] `MdnsDiscovery::discoverAirPlay()` queries both `_airplay._tcp.local.` and `_raop._tcp.local.`.
- [ ] `DiscoveryManager` combines SSDP + mDNS into a single facade.
- [ ] `DiscoveryManager::announcePhlexServer()` calls both SSDP NOTIFY and mDNS announcement.
- [ ] `DiscoveryServer::start()` wires into Workerman Timer; background discovery runs.
- [ ] `config/discovery.php` exists with all required keys.
- [ ] `./vendor/bin/phpunit` — green; ≥ 12 new tests.
- [ ] Coverage of `SsdpSocket` ≥ 85 %, `MdnsSocket` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/discovery.md` written.
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
git checkout -b j.1-discovery

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Ssdp|Mdns|Discovery'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step J.1: SSDP + mDNS broadcast + listener infrastructure"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step J.1 (Discovery): SSDP + mDNS broadcast + listener" \
  --body  "Adds SSDP (UDP 239.255.255.250:1900) and mDNS (UDP 224.0.0.251:5353) discovery infrastructure. SsdpSocket, MdnsSocket, SsdpDiscovery, MdnsDiscovery, DiscoveryManager, DiscoveryServer. Part of Phase J (Step J.1 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'j.1-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `j.1-discovery-review.md`.

(End of file - total 373 lines)
