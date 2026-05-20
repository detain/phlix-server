# Step C.6 — Relay tunnel: WS reverse proxy

**Phase:** C (Server↔Hub Pairing & Remote Access)
**Step:** C.6
**Depends on:** C.5
**Review:** Yes — see `c.6-relay-tunnel-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Build a WebSocket reverse tunnel that allows clients to reach a server
that is behind a NAT or firewall without any port forwarding on the
server side.

**Architecture:** The server opens a **persistent WSS connection** to
the hub. The hub multiplexes inbound HTTP requests from remote clients
over this tunnel. This is the same pattern as `frp`, `ngrok`, or
`cloudflared` but implemented in PHP/Workerman.

```
Client (remote)                    Hub                       Server (behind NAT)
     |                              |                              |
     |  HTTPS request to           |                              |
     |  https://<id>.phlex.media/* |                              |
     | ---------------------------->                              |
     |                              |  HTTP-over-WebSocket frame   |
     |                              | ---------------------------->
     |                              |                              |
     |                              |  HTTP-over-WebSocket response|
     |                              | <----------------------------
     |  HTTPS response             |                              |
     | <------------------------------                             |
```

## 2. Context (what already exists)

- After C.2: server has `HubClient` with `enrollment_jwt` and `hub_base_url`
- After C.3: hub has `HubDbSchema` with `relay_sessions` table
- After C.5: server can validate hub JWTs
- `PHLEX_EXPANSION_PLAN.md` §6 — relay architecture overview
- `docs/dev/pairing-protocol.md` §6 — relay flow
- `/home/sites/phlex/src/Server/WebSocket/` — existing WebSocket server
  code (used for SyncPlay)
- `/home/sites/phlex/src/Media/Streaming/HlsStreamer.php` — chunked
  response handling (reference for streaming data over WS)

## 3. Scope — files to create / modify

All paths inside `/home/sites/phlex/`.

### Create

#### Hub Side: Relay Session Manager

- `src/Hub/RelaySessionManager.php` — manages the hub's side of relay
  sessions:

  ```php
  final class RelaySessionManager
  {
      public const PROTOCOL_VERSION = 1;

      public function __construct(
          Connection $db,
          LoggerInterface $logger,
      ) { }

      public function registerServer(string $serverId, string $serverName): string { }
          // Returns a relay_session_id (UUID). Stores server info in DB.

      public function handleIncomingConnection(
          Workerman\Connection\ConnectionInterface $hubWssConnection,
          string $relaySessionId,
          string $serverId,
          string $enrollmentJwt,
      ): void { }

      public function routeRequest(
          string $relaySessionId,
          string $method,
          string $path,
          array $headers,
          string $body,
      ): RelayResponse { }
  }
  ```

  The hub-side relay accepts **inbound WSS connections from servers**
  at a dedicated WebSocket endpoint (`/api/v1/servers/{id}/relay`).

#### Hub Side: Relay WebSocket Controller

- `src/Server/WebSocket/RelayServerController.php` — handles the
  server-side of the relay WebSocket:

  ```php
  final class RelayServerController
  {
      public function __construct(
          RelaySessionManager $sessionManager,
          JwtHandler $jwtHandler,
      ) { }

      public function onConnect(ConnectionInterface $conn, array $vars): void { }
      public function onMessage(ConnectionInterface $conn, string $data): void { }
      public function onClose(ConnectionInterface $conn): void { }
  }
  ```

  This runs on the **hub's** WebSocket server, not the server's WS server.

#### Server Side: Relay Consumer

- `src/Hub/RelayConsumer.php` — the server-side component that:

  1. Maintains a persistent WSS connection to the hub's relay endpoint
  2. Receives HTTP request frames over the tunnel
  3. Dispatches them to the local Workerman HTTP router
  4. Sends the response back over the tunnel

  ```php
  final class RelayConsumer
  {
      public function __construct(
          HubClient $hubClient,
          HttpClient $httpClient,
          LoggerInterface $logger,
          string $serverId,
          string $relayEndpoint,  // wss://hub.example.com/api/v1/servers/{id}/relay
      ) { }

      public function start(): void { }   // initiates WSS connection
      public function stop(): void { }    // cleanly closes tunnel
      public function isConnected(): bool { }

      private function handleFrame(RelayRequestFrame $frame): void { }
      private function dispatchLocally(RelayRequestFrame $frame): void { }
  }
  ```

  Uses `Workerman\Worker::popLoopCallback` or a custom `Workerman\Timer`
  to manage the persistent connection with auto-reconnect.

#### Framing Protocol

- `src/Hub/RelayMessageFramer.php` — frames HTTP requests/responses over
  the WebSocket tunnel:

  ```php
  final class RelayMessageFramer
  {
      public const TYPE_HTTP_REQUEST  = 1;
      public const TYPE_HTTP_RESPONSE = 2;
      public const TYPE_PING          = 3;
      public const TYPE_PONG         = 4;

      public function frameRequest(HttpRequest $request, string $requestId): string { }
      public function frameResponse(HttpResponse $response, string $requestId): string { }
      public function parse(string $data): ?RelayFrame { }
  }

  final class RelayFrame
  {
      public function __construct(
          public readonly int $type,
          public readonly string $requestId,
          public readonly string $payload,
      ) { }
  }
  ```

  Each frame is: `[1-byte type][4-byte request_id_len][request_id_bytes][payload]`

#### Relay DB Table

- `migrations/007_relay_sessions.sql` (if not already in B.6):

  ```sql
  CREATE TABLE relay_sessions (
      id            CHAR(36) PRIMARY KEY,
      server_id    CHAR(36) NOT NULL,
      server_name   VARCHAR(255) NOT NULL,
      connected_at  INT UNSIGNED NOT NULL,
      last_frame_at  INT UNSIGNED NOT NULL,
      bytes_sent    BIGINT UNSIGNED NOT NULL DEFAULT 0,
      bytes_received BIGINT UNSIGNED NOT NULL DEFAULT 0,
      FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```

#### Unit Tests

- `tests/unit/Hub/RelayMessageFramerTest.php` — frame/unframe round-trip
- `tests/unit/Hub/RelayConsumerTest.php` — mock HubClient + HttpClient;
  test connect, disconnect, frame handling
- `tests/unit/Hub/HubClientTest.php` — extend to cover `startRelay()`,
  `stopRelay()`

#### Documentation

- `docs/dev/pairing-protocol.md` — update §13 with C.6 completion
- `docs/hub-admin/relay-tuning.md` — new hub-admin doc for relay bandwidth
  limits and configuration (see L.3 and L.4 for the full doc; C.6
  creates the stub)

### Modify

- `src/Server/Core/Application.php` — on startup, if
  `config/hub-enrollment.json` exists AND `config/relay-enabled`
  (`PHLEX_RELAY_ENABLED=true`), call `HubClient::startRelay()` to start
  `RelayConsumer`; stop it on `SIGTERM`
- `src/Common/Container/ContainerFactory.php` — register `RelayConsumer`,
  `RelayMessageFramer`, `RelaySessionManager` (hub side),
  `RelayServerController` (hub side)
- `config/hub.php` — add `relay_enabled` and `relay_max_bytes_per_second`
  settings
- `CHANGELOG.md` entry

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master.
2. **Branch:** `git checkout -b c.6-relay-tunnel`.
3. **Write `RelayMessageFramer`** — the wire protocol for HTTP-over-WS.
4. **Write `RelaySessionManager`** (hub) — manages relay sessions in DB.
5. **Write `RelayServerController`** (hub) — handles server WSS connections.
6. **Write `RelayConsumer`** (server) — opens WSS to hub, receives frames,
   dispatches locally, sends responses.
7. **Wire `HubClient::startRelay()`** to start the consumer on startup.
8. **Write tests.**
9. **Verification bar.**
10. **Doc updates.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `RelayMessageFramerTest::test_frame_request_round_trips`
2. `RelayMessageFramerTest::test_frame_response_round_trips`
3. `RelayMessageFramerTest::test_parse_ping_frame`
4. `RelayMessageFramerTest::test_parse_invalid_frame_returns_null`
5. `RelayConsumerTest::test_start_opens_wss_connection`
6. `RelayConsumerTest::test_stop_closes_connection`
7. `RelayConsumerTest::test_isConnected_reflects_connection_state`
8. `RelayConsumerTest::test_handleFrame_dispatches_to_local_router`
9. `RelayConsumerTest::test_auto_reconnect_on_disconnect`

**Coverage target:** `src/Hub/RelayConsumer`, `RelayMessageFramer` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Hub admin functionality** → `docs/hub-admin/relay-tuning.md` (stub)
- **User-visible behavior change** → CHANGELOG entry

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] Server opens a persistent WSS connection to
      `wss://hub.example.com/api/v1/servers/{id}/relay` on startup (if
      relay enabled)
- [ ] Hub routes HTTP requests from remote clients over the tunnel to
      the correct server session
- [ ] Server dispatches tunneled HTTP requests to the local router and
      returns responses over the tunnel
- [ ] `RelayMessageFramer` correctly frames/unframes messages
- [ ] `RelayConsumer` auto-reconnects on connection drop
- [ ] Relay session is tracked in DB with byte counts
- [ ] `./vendor/bin/phpunit` — green; ≥ 9 new tests
- [ ] Coverage of new classes ≥ 85 %
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean
- [ ] `docs/hub-admin/relay-tuning.md` stub created
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
git checkout -b c.6-relay-tunnel

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Relay|Hub'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step C.6: relay tunnel WebSocket reverse proxy"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step C.6: relay tunnel WebSocket reverse proxy" \
  --body  "Implements RelayConsumer, RelayMessageFramer, RelaySessionManager, and RelayServerController for WebSocket reverse tunnel between server and hub. Part of Phase C (Step C.6 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'c.6-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `c.6-relay-tunnel-review.md`.

Non-obvious point: The relay tunnel is **HTTP only** (not a full TCP
tunnel). It only proxies HTTP requests from clients to the server. This
is intentional — it avoids the complexity of a full TCP tunnel while still
enabling all client→server traffic (API calls, HLS chunks, WebSocket
messages) to flow through the hub.
