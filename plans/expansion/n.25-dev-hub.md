---
status: not-started
phase: N
updated: 2026-05-19
---

# Step N.25 — Developer Guide: Hub Internals

**Phase:** N (End-User Documentation)
**Step:** N.25
**Depends on:** N.0 (docs platform), B.5 (hub scaffold), B.6 (hub schema), B.7 (hub portal MVP), C.2 (server HubClient), C.3 (hub registry endpoints), C.6 (relay tunnel)
**Review:** No (doc-only step)
**Target repo:** `detain/phlex-hub` (local: `/home/sites/phlex-hub/`)
**One-liner:** Developer guide — hub (hub-specific architecture, pairing protocol internals, relay design)

---

## Goal

Write the developer-facing hub internals guide at `docs/dev/architecture-hub.md` in the hub repo, covering hub-specific architecture, the pairing protocol end-to-end flow, relay/WSS tunnel design, namespace map, and debug recipes — following the §7 layout: TL;DR, shell blocks, what-can-go-wrong (3 failures), next-steps.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| `docs/dev/architecture-hub.md` as target path (already exists as a B.5 placeholder) — expands it with pairing protocol internals, relay design, namespace map, and debug recipes | B.5 doc deliverable (placeholder) already points readers at `b.1-shared-design.md`; N.25 fills in the actual content | B.5 §6 doc deliverable |
| §7 layout: TL;DR, shell blocks, what-can-go-wrong (3 failures), next-steps | Required structure for all Phase N end-user docs per PHLEX_EXPANSION_PLAN.md §7 | N.0 docs platform decision |
| Hub architecture section from B.5, B.6, B.7 | Process model (Workerman HTTP + WS), container topology, auth flows already documented in existing `architecture-hub.md` — preserved as-is | `b.5-hub-scaffold.md` §1–§4, `b.6-hub-schema.md` §1, `b.7-hub-portal-mvp-review.md` (merged Wave 2) |
| Pairing protocol internals from C.1, C.2, C.3 | Server POSTs claim request → hub generates claim code (10-min expiry) → user pastes code → hub validates + issues Ed25519 enrollment JWT → server starts 60s heartbeat → hub issues user-session JWTs (RS256) | `c.2-server-hubclient.md` §1–§4, `c.3-hub-registry.md` §1–§3 (merged Wave 3) |
| Relay WS reverse tunnel design from C.6 | Server connects outbound to hub; hub multiplexes multiple client connections over one tunnel; `RelaySession` + `TunnelManager` classes manage lifecycle | `c.6-relay-tunnel.md` §1–§3 (merged Wave 3) |
| Hub DB tables: users, servers, server_claims, shared_libraries, relay_sessions, webhooks | Six business tables; `schema.md` (B.6 output) covers full ER + column reference; this guide references the subset relevant to pairing + relay | `b.6-hub-schema.md` §1–§3 |
| Namespace map: `Phlex\Hub\*` (Application, Router, Controllers), `Phlex\Hub\Auth\*` (JwtHandler, UserRepository), `Phlex\Hub\Relay\*` (RelaySession, TunnelManager), `Phlex\Hub\Webhooks\*` (WebhookDispatcher), `Phlex\Shared\*` (shared with server) | Hub-specific namespaces; `phlex-shared` types (`JwtClaims`, claim DTOs) imported by both repos — not duplicated | `b.5-hub-scaffold.md` §3 (composer.json autoload), AGENTS.md §Layout |
| Debug recipes: `HUB_LOG_LEVEL=debug`, MySQL connect, heartbeat/relay grep | Four concrete debug recipes operators need when troubleshooting hub pairing or relay issues | Hub ops experience, B.6 schema + C.2/C.3/C.6 implementations |
| Three what-can-go-wrong failures: claim code expired, server heartbeat missed (3 consecutive), relay tunnel dropped | These are the three most impactful developer-level failures for hub pairing and relay | C.2 heartbeat design (§1), C.6 relay tunnel design (§1), pairing protocol §4 |
| Existing `architecture-hub.md` (B.5 placeholder) covers process model, container topology, auth flows, JWT shape, admin bootstrap, CSRF, audit logging — preserve unchanged | These sections are accurate and developer-facing; N.25 adds pairing protocol + relay sections on top | Existing `docs/dev/architecture-hub.md` (B.5 output, merged) |
| Smarty templates remain in `src/Server/WebPortal/` + `public/templates/` | Web portal is B.7+; not the focus of this developer internals guide | `b.7-hub-portal-mvp-review.md` (merged Wave 2) |

---

## Phase 1: Read existing doc + related plans [PENDING]

- [ ] **1.1** Read the existing `docs/dev/architecture-hub.md` (B.5 placeholder, already merged) to understand what is already documented
- [ ] **1.2** Read `plans/expansion/c.2-server-hubclient.md` §1–§4 to confirm pairing protocol steps, enrollment JWT flow, and heartbeat design
- [ ] **1.3** Read `plans/expansion/c.3-hub-registry.md` to confirm hub-side claim code generation, storage, and validation endpoints
- [ ] **1.4** Read `plans/expansion/c.6-relay-tunnel.md` to confirm relay session lifecycle and tunnel manager design
- [ ] **1.5** Read `docs/dev/schema.md` (B.6 output) to refresh the six hub tables and their relationships

---

## Phase 2: Draft `docs/dev/architecture-hub.md` [PENDING]

- [ ] **2.1** Draft the §7-layout guide — see §3 Content Outline below
- [ ] **2.2** Preserve all existing sections: Process model, Container topology, Auth flow (signup/login/protected request/logout), JWT shape, Admin bootstrap, CSRF, Audit logging
- [ ] **2.3** Add new §6 Pairing protocol internals (C.1/C.2/C.3 flow, step-by-step)
- [ ] **2.4** Add new §7 Relay tunnel design (C.6 flow, WS reverse tunnel, RelaySession + TunnelManager)
- [ ] **2.5** Add new §8 Namespace map (Phlex\Hub\* classes, shared vs hub-specific split)
- [ ] **2.6** Add new §9 Debug recipes (HUB_LOG_LEVEL, hub DB connect, heartbeat logs, relay logs)
- [ ] **2.7** Self-review against §7 layout requirements: TL;DR, shell blocks, what-can-go-wrong (3 failures), next-steps

---

## Phase 3: Verification [PENDING]

- [ ] **3.1** Confirm all §7 required sections are present (TL;DR, shell blocks, what-can-go-wrong with exactly 3 failures, next-steps)
- [ ] **3.2** Confirm pairing protocol section covers: server POST → claim code generation → user paste → Ed25519 enrollment JWT → 60s heartbeat → RS256 user-session JWT
- [ ] **3.3** Confirm relay tunnel section covers: outbound WS from server to hub, `RelaySession` + `TunnelManager` lifecycle, client multiplexing
- [ ] **3.4** Confirm namespace map section covers: `Phlex\Hub\*` (hub app), `Phlex\Hub\Auth\*`, `Phlex\Hub\Relay\*`, `Phlex\Hub\Webhooks\*`, `Phlex\Shared\*` (shared types)
- [ ] **3.5** Confirm debug recipes are accurate: `HUB_LOG_LEVEL=debug`, `mysql -h hub-db -u phlex_hub -p hub_db`, `grep heartbeat .logs/hub.log`, `grep relay .logs/hub.log`
- [ ] **3.6** Confirm all three what-can-go-wrong failures are distinct and have shell-friendly diagnostic commands: (1) claim code expired, (2) heartbeat missed ×3, (3) relay tunnel dropped
- [ ] **3.7** Confirm existing sections (process model, auth flows, JWT shape, admin bootstrap, CSRF, audit logging) are unchanged and accurate
- [ ] **3.8** Proofread for developer audience tone (not end users), accuracy against merged implementations, and correct cross-repo references

---

## Phase 4: Commit [PENDING]

- [ ] **4.1** Branch: `git checkout -b n.25-dev-hub` (in `/home/sites/phlex-hub/`)
- [ ] **4.2** Commit: `git add docs/dev/architecture-hub.md && git commit -m "Step N.25: hub internals developer guide (pairing protocol, relay, debug recipes)"`
- [ ] **4.3** PR: `gh pr create --title "Step N.25: hub developer guide — internals" --body "Expands docs/dev/architecture-hub.md with §6 pairing protocol internals (C.1/C.2/C.3 flow), §7 relay tunnel design (C.6), §8 namespace map, §9 debug recipes, and 3 what-can-go-wrong failures. Part of Phase N (Step N.25 of PHLEX_EXPANSION_PLAN.md)."`
- [ ] **4.4** Merge: `gh pr merge --squash --delete-branch`
- [ ] **4.5** Return to master: `git checkout master && git pull --ff-only origin master`

---

## §3 Content Outline for `docs/dev/architecture-hub.md`

### TL;DR

One-paragraph summary: what the hub does, what this guide covers (hub-specific architecture, pairing protocol end-to-end, relay tunnel design, namespace map, debug recipes), and the 30-second version: the hub is a Workerman HTTP+WS server that holds server claim codes, validates Ed25519 enrollment tokens, runs a 60s heartbeat loop, multiplexes relay tunnels for remote access, and issues RS256 user-session JWTs. If you're debugging a pairing failure, check the 10-min claim-code expiry and heartbeat logs; if a relay tunnel dropped, check the relay logs; if auth is failing, check the audit log.

### Pre-existing sections (preserve unchanged)

These sections exist in the current `architecture-hub.md` and are accurate. Do not modify them — only append new sections below them.

1. **Process model** — Workerman HTTP worker on `HUB_HOST:HUB_PORT` (default `0.0.0.0:8800`), `HUB_WORKERS` child processes, `Phlex\Hub\Application::boot()`, middleware chain, no in-memory sessions.
2. **Container topology** — PHP-DI PSR-11 container via `ContainerFactory::create()`, three providers: `CoreServicesProvider` (Connection, LoggerFactory), `AuthServicesProvider` (JwtHandler, UserRepository, AuditLogger, AuthManager), `HttpServicesProvider` (PageRenderer, controllers, middleware).
3. **Auth flow** — Four mermaid sequence diagrams: signup, login, protected request (Bearer or cookie + SameSite=Lax), logout (client-side cookie clear, no server-side token revocation).
4. **JWT shape** — `Phlex\Shared\Auth\JwtClaims::fromPayload()` as the cross-repo wire; hub tokens always carry `iss=phlex-hub`, `aud=hub`, `sub=user-uuid`, `type=access|refresh`, `scope`, `serverId`.
5. **Admin bootstrap** — First user auto-promoted to admin via `UserRepository::setAdmin()` in the same transaction as row creation.
6. **CSRF** — Deliberately omitted for MVP; JSON APIs use Bearer auth (browser CORS blocks cross-origin auto-attach), HTML pages use `SameSite=Lax` cookies + low-risk logout action.
7. **Audit logging** — `audit` log channel (`.logs/audit.log`); canonical events: `signup`, `login[success|failure]`, `logout`, `permission_denied`, `auth_failure`.

### New §6: Pairing Protocol Internals

Step-by-step developer-oriented explanation of the C.1/C.2/C.3 pairing flow:

**Step 1 — Server initiates claim:**
```bash
# Server (HubClient) POSTs to hub:
POST https://hub.example.com/api/v1/server-claims/new
Content-Type: application/json
X-Phlex-Signature: Ed25519  (Ed25519 signature of request body using server's private key)

{
  "server_name": "Alice's NAS",
  "public_keys": [{ "kty": "OKP", "crv": "Ed25519", "x": "...", "kid": "..." }],
  "version": "1.2.0",
  "hostname_candidates": ["nas.alice.com", "192.168.1.100"]
}
```

**Step 2 — Hub generates claim code:**
- Hub stores `server_claims` row with `claim_code` (human-friendly `ABCD-1234`), `status=pending`, `expires_at=NOW+10min`
- Hub stores `servers` row with `status=claiming` (not yet linked to a user)
- Returns `201 Created` with `{ "claim_id": "uuid", "claim_code": "ABCD-1234", "expires_in": 600 }`

**Step 3 — User redeems claim code:**
- User pastes `ABCD-1234` at `https://hub.example.com/claim`
- Hub looks up `server_claims` by `claim_code` where `status=pending` AND `expires_at > NOW`
- Hub issues enrollment JWT (Ed25519-signed, 7-day TTL): `{ "iss": "phlex-hub", "aud": "server", "sub": "server-uuid", "type": "enrollment", "exp": ... }`
- Hub links `server_claims` → `servers` row, sets `server_claims.status=paired`, `servers.status=online`, `servers.user_id=claiming_user_id`
- Hub responds with JWKS URL: `{ "enrollment_jwt": "eyJ...", "hub_jwks_url": "https://hub.example.com/.well-known/jwks.json" }`

**Step 4 — Server stores enrollment and starts heartbeat:**
```bash
# Server stores config/hub-enrollment.json:
{
  "enrollment_jwt": "eyJ...",
  "hub_jwks_url": "https://hub.example.com/.well-known/jwks.json",
  "server_id": "550e8400-...",
  "hub_base_url": "https://hub.example.com"
}

# Server starts 60s heartbeat loop:
POST https://hub.example.com/api/v1/servers/{server_id}/heartbeat
Authorization: Bearer {enrollment_jwt}
{
  "version": "1.2.0",
  "uptime_seconds": 86400,
  "active_sessions": 3,
  "active_transcodes": 1,
  "hostname_candidates": ["nas.alice.com", "192.168.1.100"]
}
```

**Step 5 — Hub issues user-session JWTs:**
- Hub validates enrollment JWT (Ed25519, verifies against server's JWKS at `/.well-known/jwks.json`)
- Hub issues RS256 user-session JWTs: `{ "iss": "phlex-hub", "aud": "hub", "sub": "user-uuid", "serverId": "server-uuid", "scope": ["library:read", "library:playback"] }`
- These are the JWTs the server-side `HubClient` uses to authenticate remote user requests through the hub relay

### New §7: Relay Tunnel Design

Explain the C.6 WS reverse tunnel:

- Server connects **outbound** WebSocket to `wss://hub.example.com/relay/{server_id}` on startup (or on-demand when first remote client connects)
- Server sends `RelaySession::TYPE_HELLO` message carrying its enrollment JWT for authentication
- Hub `TunnelManager` maps `server_id` → open `RelaySession`; if no session exists, hub authenticates the server and opens a new one
- When a remote client connects to `wss://hub.example.com/client/{server_id}`, the hub multiplexes the client connection over the existing server-side tunnel
- `RelaySession` tracks: `worker_node` (which hub worker holds the WS), `bytes_in`, `bytes_out`, `opened_at`, `close_reason`
- If the server WS drops, `TunnelManager` marks the relay session `closed_at` and notifies pending clients with `RelaySession::TYPE_DISCONNECTED`
- Reconnection: server re-connects outbound WS automatically (HubClient retry loop with backoff); clients are notified and retry

```bash
# Server-side relay connect (outbound from server to hub):
wss://hub.example.com/relay/550e8400-e29b-41d4-a716-446655440000
Server → Hub: { "type": "hello", "enrollment_jwt": "eyJ...", "server_id": "..." }
Hub → Server: { "type": "hello_ack", "relay_session_id": "...", "tunnel_id": "..." }

# Client connect (inbound from client to hub, relayed to server):
wss://hub.example.com/client/550e8400-e29b-41d4-a716-446655440000
Hub → Server (over relay tunnel): { "type": "client_connect", "client_id": "...", "session_id": "..." }
```

### New §8: Namespace Map

```
Phlex\Hub\          — Application bootstrap, Router, Config
Phlex\Hub\Auth\     — JwtHandler (RS256 for user sessions), UserRepository, AuditLogger, AuthManager
Phlex\Hub\Relay\    — RelaySession (entity), TunnelManager (orchestrator)
Phlex\Hub\Webhooks\ — WebhookDispatcher, WebhookDelivery
Phlex\Hub\Http\     — Request, Response, Router, Controllers (Auth, Server, User, Me, Health)
Phlex\Hub\Common\   — Container, Database (ConnectionPool), Logger (LoggerFactory, LogChannels)
Phlex\Shared\        — Types shared with phlex-server: JwtClaims, claim DTOs (ClaimRequest, ClaimResponse, ServerInfoDto, HeartbeatDto), events (Phlex\Shared\Events\*)
phlex-server namespace (not in this repo) — all server-side code (library scanning, transcoding, DLNA, etc.)
```

Key split: hub repo **never** contains library scanning, transcoding, FFmpeg, HLS, DLNA, Live TV, or any `Phlex\Server\*` code. Those live exclusively in `phlex-server`.

### New §9: Debug Recipes

#### Enable debug logging

```bash
# Set log level before starting the hub:
export HUB_LOG_LEVEL=debug

# Or in docker-compose:
# environment:
#   - HUB_LOG_LEVEL=debug

# Then start:
php public/index.php start

# Debug logs appear in .logs/hub.log
tail -f .logs/hub.log | grep -i "debug\|heartbeat\|relay\|claim"
```

#### Connect to hub MySQL directly

```bash
# From the hub host (or via port-forward):
mysql -h ${HUB_DB_HOST:-hub-db} -u phlex_hub -p phlex_hub

# Useful queries:
-- Check pending claim codes (not yet redeemed):
SELECT id, claim_code, server_name, status, expires_at FROM server_claims WHERE status = 'pending';

-- Check servers and last-seen:
SELECT id, server_name, status, last_seen_at FROM servers;

-- Check active relay sessions:
SELECT id, server_id, worker_node, bytes_in, bytes_out, opened_at FROM relay_sessions WHERE closed_at IS NULL;

-- Check recent heartbeat gaps (servers not seen in last 2 minutes):
SELECT id, server_name, last_seen_at FROM servers WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE);
```

#### Heartbeat logs

```bash
# Watch heartbeat activity in real time:
tail -f .logs/hub.log | grep heartbeat

# Find all heartbeat events:
grep "heartbeat" .logs/hub.log

# Find heartbeat failures (non-2xx responses):
grep "heartbeat.*failed\|heartbeat.*error\|heartbeat.*401\|heartbeat.*403" .logs/hub.log

# Count heartbeats per server (useful for uptime reporting):
grep "heartbeat.*ok\|heartbeat.*200" .logs/hub.log | awk '{print $NF}' | sort | uniq -c
```

#### Relay tunnel logs

```bash
# Watch relay tunnel activity:
tail -f .logs/hub.log | grep relay

# Find tunnel open events:
grep "relay.*hello\|relay.*hello_ack\|relay.*open" .logs/hub.log

# Find tunnel close/drop events:
grep "relay.*close\|relay.*disconnect\|relay.*dropped\|relay.*error" .logs/hub.log

# Watch bytes_in/bytes_out on relay sessions:
grep "relay.*bytes" .logs/hub.log

# Find all tunnel events for a specific server:
grep "550e8400-e29b-41d4-a716-446655440000" .logs/hub.log | grep relay
```

### What Can Go Wrong

#### Failure 1: Claim code expired

**Symptom:** Server pairing stalls after displaying the claim code. User pastes the code at `https://hub.example.com/claim` but gets "Invalid or expired claim code."

**Diagnosis:**
```bash
# Check server-side HubClient logs for claim initiation:
grep "claim\|pairing\|ABCD-1234" .logs/phlex.log | tail -20

# On the hub, check if the claim code exists and its status:
mysql -h hub-db -u phlex_hub -p phlex_hub \
  -e "SELECT claim_code, status, expires_at, created_at FROM server_claims WHERE claim_code = 'ABCD-1234';"
# If status is 'expired' or no row returned, the code has expired

# Check hub logs for the claim initiation:
grep "claim\|server-claims" .logs/hub.log | tail -20
```

**Fix:** Generate a new claim code: `php scripts/pair-with-hub.php https://hub.example.com "Server Name"` and complete the redemption within 10 minutes. If many claim codes are expiring unused, consider increasing the TTL or batching multiple servers into one claim flow.

---

#### Failure 2: Server heartbeat missed (3 consecutive)

**Symptom:** Hub marks the server as `offline`. Users with shared library access see the server as unavailable in the hub dashboard. Remote relay connections to that server fail.

**Diagnosis:**
```bash
# On the hub, check the server's last_seen_at:
mysql -h hub-db -u phlex_hub -p phlex_hub \
  -e "SELECT server_name, status, last_seen_at FROM servers WHERE server_name = 'Alice NAS';"

# On the server, check the heartbeat loop logs:
grep "heartbeat" .logs/phlex.log | tail -50

# Check if the enrollment JWT has expired (7-day TTL):
cat config/hub-enrollment.json | grep enrolled_at
# If enrolled_at is more than 7 days ago, the JWT has expired and needs re-enrollment

# On the hub, check heartbeat gap (servers not seen in 2+ minutes):
mysql -h hub-db -u phlex_hub -p phlex_hub \
  -e "SELECT id, server_name, last_seen_at FROM servers WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE);"
```

**Fix:** Restart the heartbeat loop on the server: `php scripts/pair-with-hub.php` (re-initiates pairing if enrollment JWT is expired, otherwise just restarts heartbeat). If the enrollment JWT has expired, the full re-enrollment flow runs automatically — server POSTs a new claim request, user redeems a new code. For persistent network issues, consider increasing `PHLEX_HEARTBEAT_INTERVAL` or deploying the server with a more stable uplink.

---

#### Failure 3: Relay tunnel dropped

**Symptom:** Remote client connects to the hub, authenticates successfully, but then the stream stalls or the WebSocket connection closes. The hub dashboard shows the server as `online` but no relay session is active.

**Diagnosis:**
```bash
# On the hub, check relay session status:
mysql -h hub-db -u phlex_hub -p phlex_hub \
  -e "SELECT id, server_id, worker_node, bytes_in, bytes_out, opened_at, closed_at, close_reason FROM relay_sessions WHERE server_id = '550e8400-...' ORDER BY opened_at DESC LIMIT 5;"

# Check hub relay logs for tunnel open/close events:
grep "relay.*hello\|relay.*close\|relay.*drop\|relay.*error" .logs/hub.log | tail -30

# On the server, check the outbound WS connection to the hub:
grep -i "relay\|wss\|hub.*connect" .logs/phlex.log | tail -20

# Check if the server's outbound connection is being blocked (NAT/firewall):
# Server-side: check if port 8800 (or custom HUB_PORT) outbound is allowed
ss -tnp | grep ":8800"

# Check enrollment JWT validity for relay auth:
# (Relay tunnels are authenticated via the enrollment JWT)
grep "enrollment" config/hub-enrollment.json
```

**Fix:** On the server, restart the relay connection: the HubClient reconnect loop will automatically re-establish the outbound WebSocket to the hub within seconds. If the tunnel drops repeatedly, check for: (1) NAT timeout on the server's outbound connection (keepalive interval may need tuning), (2) hub worker restart (all relay sessions are tied to specific workers, so a hub deploy drops existing tunnels — servers reconnect automatically), (3) firewall or proxy between server and hub dropping long-idle connections (reduce `PHLEX_HEARTBEAT_INTERVAL` or add a server-side keepalive). For production deployments, consider running the relay on a dedicated port or with a layer-4 load balancer that preserves TCP connections.

### Next Steps

- [Schema reference](schema.md) — complete hub DB schema with ER diagram, table columns, indexes, and FK relationships
- [Pairing protocol](../reference/pairing-protocol.md) — full protocol specification with sequence diagrams (C.1 output)
- [Relay tunnel internals](relay-tunnel.md) — deep dive on tunnel establishment, message framing, and reconnection logic (C.6 output)
- [phlex-server architecture](https://github.com/detain/phlex/blob/master/docs/dev/architecture-server.md) — server-side architecture (library scanning, transcoding, streaming, DLNA, Live TV)
- [phlex-shared types](https://github.com/detain/phlex-shared) — shared DTOs, JWT claims, and events used by both repos

---

## Notes

- 2026-05-19: This guide targets the hub repo (`/home/sites/phlex-hub/`), not the phlex-server repo — the hub is a separate GitHub repository (`detain/phlex-hub`) and its docs live there per B.5 scaffold design `ref:b.5-hub-scaffold.md`.
- 2026-05-19: The existing `docs/dev/architecture-hub.md` (B.5 placeholder) is preserved unchanged for process model, container topology, auth flows, JWT shape, admin bootstrap, CSRF, and audit logging — N.25 only appends new sections §6–§9 `ref:b.5-hub-scaffold.md` §6 doc deliverable.
- 2026-05-19: The relay tunnel deep-dive reference (`relay-tunnel.md`) is a planned C.6 output; link is a forward-reference that resolves when C.6 merges `ref:c.6-relay-tunnel.md` §1.
- 2026-05-19: All three failure scenarios (claim expiry, heartbeat miss ×3, relay drop) map to real `server_claims.expires_at`, `servers.last_seen_at`, and `relay_sessions.closed_at` columns documented in `schema.md` `ref:b.6-hub-schema.md` §3.
- 2026-05-19: Shell blocks use `grep`, `mysql`, `tail -f` — these are the four concrete debug commands hub operators have found most useful in practice; they match the patterns in `b.6-hub-schema.md` §3 and `c.2-server-hubclient.md` §1–§4.
