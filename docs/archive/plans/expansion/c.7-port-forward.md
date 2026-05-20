# Step C.7 — UPnP-IGD + manual port-forward helper

**Phase:** C (Server↔Hub Pairing & Remote Access)
**Step:** C.7
**Depends on:** C.6
**Review:** Yes — see `c.7-port-forward-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Help server operators achieve direct internet access (without relay) by:

1. **UPnP-IGD auto-discovery** — probe the LAN for a UPnP InternetGatewayDevice,
   try to add a port mapping for port 32400 (configurable)
2. **Manual port-forward instructions** — if UPnP fails, show the user
   a clear, platform-specific guide (router model detection via mDNS
   service type `_natpmp._tcp` fallback to static instructions)
3. **Hostname discovery** — run STUN to determine the server's public IP
   and test port accessibility, so `hostname_candidates` in heartbeats
   includes the public hostname when successful

## 2. Context (what already exists)

- After C.2: server has `HubClient::sendHeartbeat()` with
  `hostname_candidates` field
- After C.6: relay tunnel available as fallback when direct access fails
- `/home/sites/phlex/src/Media/Library/FolderWatcher.php` — filesystem
  watcher patterns (not related, but shows project patterns)
- `config/server.php` — existing config structure
- `PHLEX_EXPANSION_PLAN.md` §1 — current-state: UPnP-IGD helper is **Missing**

## 3. Scope — files to create / modify

All paths inside `/home/sites/phlex/`.

### Create

#### UPnP-IGD Client

- `src/Hub/UpnpIgdClient.php` — discovers UPnP gateways and adds port
  mappings:

  ```php
  final class UpnpIgdClient
  {
      public function __construct(
          LoggerInterface $logger,
          int $timeout = 3000,
      ) { }

      public function discoverGateway(): ?string { }
          // Returns the gateway's local IP or null if none found.
          // Uses UDP multicast to 239.255.255.250:1900 (SSDP M-SEARCH).

      public function getExternalIp(string $gatewayUrl): ?string { }
          // Fetches the gateway's WAN IP from GetExternalIPAddress action.

      public function addPortMapping(
          string $gatewayUrl,
          string $externalPort,
          string $internalIp,
          string $internalPort,
          string $protocol = 'TCP',
          int $leaseDuration = 0,
      ): bool { }
          // Attempts to add a port mapping via AddPortMapping action.
          // Returns true on success, false on failure.

      public function removePortMapping(
          string $gatewayUrl,
          string $externalPort,
          string $protocol = 'TCP',
      ): bool { }
          // Removes a port mapping via DeletePortMapping action.
  }
  ```

  Implementation uses raw UDP sockets (no external dependencies).
  SSDP discovery: send `M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: "ssdp:discover"\r\nMX: 3\r\nST: urn:schemas-upnp-org:device:InternetGatewayDevice:1\r\n`.

#### STUN Client

- `src/Hub/StunClient.php` — determines public IP and port
  accessibility via STUN:

  ```php
  final class StunClient
  {
      public const DEFAULT_STUN_SERVER = 'stun.l.google.com';
      public const DEFAULT_STUN_PORT   = 19302;

      public function __construct(
          LoggerInterface $logger,
          string $stunServer = self::DEFAULT_STUN_SERVER,
          int $stunPort = self::DEFAULT_STUN_PORT,
      ) { }

      public function getPublicIp(): ?string { }
          // Returns the server's public IP as seen from outside.

      public function testPortAccessibility(string $ip, int $port): bool { }
          // Attempts a TCP connect to the given IP:port.
          // Returns true if reachable from outside.
  }
  ```

  STUN uses RFC 5389. The simplest approach: send a binding request to
  the STUN server and read the XOR-MAPPED-ADDRESS from the response.

#### Port Forward Manager

- `src/Hub/PortForwardManager.php` — orchestrates the full port-forward
  workflow:

  ```php
  final class PortForwardManager
  {
      public const PORT = 32400;

      public function __construct(
          UpnpIgdClient $upnp,
          StunClient $stun,
          LoggerInterface $logger,
      ) { }

      public function attemptAutomaticPortForward(): ?string { }
          // Attempts UPnP port mapping. Returns the public IP:port
          // string on success, null on failure.

      public function getManualInstructions(): array { }
          // Returns platform-specific manual port-forward instructions.

      public function discoverHostnameCandidates(): array { }
          // Returns a list of hostname/IP candidates the server
          // believes it's reachable at. Includes:
          // - 192.168.x.x:32400 (LAN)
          // - <hostname>.local:32400 (mDNS)
          // - <public-ip>:32400 (if STUN shows port is open)
          // - <upnp-discovered-public-ip>:32400 (if UPnP succeeded)
  }
  ```

#### CLI Command

- `scripts/check-connectivity.php` — diagnostic CLI that runs all
  connectivity checks and reports results:

  ```
  php scripts/check-connectivity.php

  # Output:
  # LAN IP:        192.168.1.100
  # Gateway:       192.168.1.1
  # Public IP:     203.0.113.42  (via STUN)
  # Port 32400:     OPEN (STUN connectivity test passed)
  # UPnP IGD:      Found (http://192.168.1.1:1900/xml/gateway.xml)
  # Port mapping:  ACTIVE (mapped 203.0.113.42:32400 → 192.168.1.100:32400)
  # mDNS:          Responding as "phlex.local"
  # Direct access: AVAILABLE — clients can connect directly
  # Relay mode:    Available as fallback
  ```

- `scripts/setup-port-forward.php` — interactive setup script:

  ```
  php scripts/setup-port-forward.php [--auto] [--dry-run]

  # --auto   Attempt automatic UPnP without user interaction
  # --dry-run Show what would be done without making changes
  ```

#### Unit Tests

- `tests/Unit/Hub/UpnpIgdClientTest.php` — mock UDP socket; test
  gateway discovery, add/remove port mapping
- `tests/Unit/Hub/StunClientTest.php` — mock STUN server response; test
  public IP extraction, port accessibility test
- `tests/Unit/Hub/PortForwardManagerTest.php` — mock UpnpIgdClient +
  StunClient; test full workflow

#### Documentation

- `docs/advanced/remote-access-without-hub.md` — new doc (or section in
  existing remote-access doc) covering:
  - How to set up port forwarding manually
  - How UPnP works
  - What STUN does and when to use it
  - Troubleshooting guide

### Modify

- `src/Hub/HubClient.php` — call `PortForwardManager::discoverHostnameCandidates()`
  on startup and include results in heartbeat payload
- `config/server.php` — add `port_forwarding.port` (default 32400),
  `port_forwarding.upnp_enabled` (default true), `port_forwarding.stun_server`
- `src/Common/Container/ContainerFactory.php` — register `UpnpIgdClient`,
  `StunClient`, `PortForwardManager`
- `composer.json` — no new deps (raw sockets only)
- `CHANGELOG.md` entry

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master.
2. **Branch:** `git checkout -b c.7-port-forward`.
3. **Write `UpnpIgdClient`** — SSDP M-SEARCH discovery + SOAP actions
   for port mapping. Raw sockets only.
4. **Write `StunClient`** — RFC 5389 STUN client using raw sockets.
5. **Write `PortForwardManager`** — orchestrates discovery + UPnP +
   STUN + manual instructions.
6. **Write CLI scripts** for diagnostics and interactive setup.
7. **Wire into `HubClient::discoverHostnameCandidates()`** — run on
   startup and include in heartbeat.
8. **Write tests.**
9. **Verification bar.**
10. **Doc updates.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `UpnpIgdClientTest::test_discoverGateway_returns_url_on_valid_response`
2. `UpnpIgdClientTest::test_discoverGateway_returns_null_on_timeout`
3. `UpnpIgdClientTest::test_getExternalIp_extracts_ip`
4. `UpnpIgdClientTest::test_addPortMapping_success_returns_true`
5. `UpnpIgdClientTest::test_addPortMapping_failure_returns_false`
6. `UpnpIgdClientTest::test_removePortMapping`
7. `StunClientTest::test_getPublicIp_extracts_from_stun_response`
8. `StunClientTest::test_getPublicIp_returns_null_on_error`
9. `StunClientTest::test_testPortAccessibility_reachable`
10. `StunClientTest::test_testPortAccessibility_unreachable`
11. `PortForwardManagerTest::test_attemptAutomatic_upnp_success_returns_public_ip`
12. `PortForwardManagerTest::test_attemptAutomatic_upnp_fails_returns_null`
13. `PortForwardManagerTest::test_discoverHostnameCandidates_includes_lan_ip`
14. `PortForwardManagerTest::test_discoverHostnameCandidates_includes_stun_ip_when_open`

**Coverage target:** `src/Hub/UpnpIgdClient`, `StunClient`,
`PortForwardManager` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Hub functionality** → `docs/advanced/remote-access-without-hub.md` (new)
- **User-visible behavior change** → CHANGELOG entry
- **CLI command** → `docs/reference/cli.md`

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `UpnpIgdClient` discovers UPnP gateway and adds/removes port
      mappings
- [ ] `StunClient` returns the server's public IP via STUN
- [ ] `PortForwardManager::discoverHostnameCandidates()` returns a list
      including LAN IP and public IP when available
- [ ] `HubClient` includes hostname candidates from `PortForwardManager`
      in heartbeat payload
- [ ] `scripts/check-connectivity.php` runs all checks and reports
      results
- [ ] `./vendor/bin/phpunit` — green; ≥ 14 new tests
- [ ] Coverage of new classes ≥ 85 %
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean
- [ ] `docs/advanced/remote-access-without-hub.md` created
- [ ] `docs/reference/cli.md` updated
- [ ] CHANGELOG entry added
- [ ] Git ritual §8 executed; postcondition checks PASS

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b c.7-port-forward

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Upnp|Stun|Port'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step C.7: UPnP-IGD, STUN, and manual port-forward helper"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step C.7: UPnP-IGD and port-forward helper" \
  --body  "Implements UpnpIgdClient, StunClient, PortForwardManager, and CLI scripts for automatic and manual port forwarding. Part of Phase C (Step C.7 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'c.7-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `c.7-port-forward-review.md`.

Non-obvious point: **No external dependencies** for UPnP or STUN. Raw
sockets only (`socket_create`, `socket_sendto`, `socket_recvfrom`) to
keep the dependency list minimal and avoid incompatibilities with
minimal PHP installations on NAS devices.
