# Step M.0 — Phase M Intro + Claim-Flow Contract

**Phase:** M (Client Hub-Mode)
**Step:** M.0
**Depends on:** None (prerequisite to all other M steps)
**Review:** No (meta step — produces plan files, not code)
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Goal

Produce all 9 Phase M step plan files (m.0–m.8) and verify the
existing claim-flow contract between server and hub. This step does
NOT implement code — it creates the planning artifacts that the M.1–M.8
subagents will follow.

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §3 rows M.* — the 9-step definition
- `HANDOFF_WAVE5_PLUS.md` §Wave 6 — step list and caveats
- `docs/dev/pairing-protocol.md` — full pairing protocol (server↔hub contract)
- `docs/dev/relay-protocol.md` — WS reverse-tunnel framing
- `docs/clients/skip-button-integration-brief.md` — skip-intro client contract
- `src/Hub/HubClient.php` — verified present from C.2
- `src/Server/Http/Router.php` — JWKS route already wired
- Client repos at `/home/sites/phlex-{mobile,tizen,roku,windows}-client`

## 3. Scope

### Verify existing implementation

Before writing the plan files, verify the following are already implemented:

1. **HubClient exists** at `src/Hub/HubClient.php` (from C.2)
2. **Claim-code flow** (C.2–C.3): server POSTs to hub for claim code,
   displays it — verify `HubClient::initiatePairing()` and
   `HubClient::pollClaimStatus()` work end-to-end
3. **Heartbeat loop** (C.2): verify `HubClient::startHeartbeatLoop()` is
   called on server startup when enrolled
4. **JWKS published** at `/.well-known/jwks.json` — verify route is wired
   and returns Ed25519 JWK

### Produce plan files

Create these 9 files in `/home/sites/phlex/plans/expansion/`:

#### `m.0-phase-m.md` (this file)
Phase intro + claim-flow contract overview (meta step)

#### `m.1-mobile-hub.md`
React Native + Zustand hub-mode for `phlex-mobile-client`:
- Sign in to hub (authenticate with hub JWT)
- List claimed servers via hub API
- Switch active server (direct-LAN vs hub-relay)
- All API calls work in both direct-LAN and hub-relay modes

#### `m.2-tizen-hub.md`
Vanilla JS hub-mode for `phlex-tizen-client`:
- Hub-mode toggle in settings
- Server picker UI
- Relay-aware playback

#### `m.3-roku-hub.md`
BrightScript hub-mode for `phlex-roku-client`:
- Relay-aware HLS playback
- Server picker
- Hub JWT authentication

#### `m.4-windows-hub.md`
Electron hub-mode for `phlex-windows-client`:
- Hub URL configuration
- Server switcher UI
- Persistent hub session

#### `m.5-offline.md`
Offline download manager (mobile priority, then Windows):
- Download manager + cache
- Resume interrupted downloads
- Offline playback of downloaded content

#### `m.6-syncplay-clients.md`
SyncPlay on all 4 clients:
- Consume server TimeSync API (already exists: `src/Session/SyncPlay/TimeSync.php`)
- Join/leave SyncPlay groups
- Play/pause/seek propagation

#### `m.7-skip-clients.md`
Intro-skip button in all 4 players:
- Consume `docs/clients/skip-button-integration-brief.md`
- Render Skip Intro / Skip Outro buttons
- Handle button press → seek to marker end

#### `m.8-new-platforms.md`
Android TV + Apple TV apps (earmark for v2):
- Recommendation: defer to v2 due to toolchain complexity
- Document the earmark decision
- Point to relevant upstream docs

## 4. Claim-Flow Contract Summary

This section documents the exact HTTP contract between server and hub
that all 4 client repos will call. It is the authoritative reference
for M.1–M.4 implementers.

### Server → Hub

```
POST https://hub.example.com/api/v1/server-claims/new
Accept-Phlex-Protocol: v1
Content-Type: application/json

{
  "server_name": "Alice's NAS",
  "version": "0.12.0",
  "public_keys": { "kty": "OKP", "crv": "Ed25519", ... },
  "hostname_candidates": ["https://192.168.1.100:32400", ...],
  "protocol_version": "v1"
}
```

Response:
```json
{
  "claim_code": "ABCD-1234",
  "expires_in": 600,
  "claim_id": "uuid",
  "hub_base_url": "https://hub.example.com"
}
```

### User → Hub (claim flow)

```
POST https://hub.example.com/api/v1/server-claims/claim
{ "claim_code": "ABCD-1234" }
```

Response:
```json
{
  "enrollment_jwt": "eyJ...",
  "hub_jwks_url": "https://hub.example.com/.well-known/jwks.json",
  "server_id": "uuid"
}
```

### Hub → User (list servers)

```
GET https://hub.example.com/api/v1/me/servers
Authorization: Bearer <user-session-jwt>
```

Response:
```json
{
  "servers": [
    {
      "server_id": "uuid",
      "server_name": "Alice's NAS",
      "version": "0.12.0",
      "status": "online",
      "hostname": "https://192.168.1.100:32400",
      "capabilities": ["direct-play", "transcode-h264", "syncplay"]
    }
  ]
}
```

### Hub → User (user-session JWT)

Issued when user logs into hub. Server validates against hub JWKS.
Claims:

```json
{
  "iss": "phlex-hub",
  "sub": "user-uuid",
  "aud": "server",
  "server_id": "server-uuid",
  "exp": 1747434000,
  "iat": 1747430400,
  "kid": "2026-05-17T00:00:00Z",
  "scope": ["library:read", "playback:write"]
}
```

### Client → Server (with hub JWT)

```
GET /api/v1/libraries
Authorization: Bearer <hub-minted-jwt>
```

Server validates against hub JWKS URL stored in `config/hub-jwks-url`.

## 5. Approach

1. Read `docs/dev/pairing-protocol.md` and `docs/dev/relay-protocol.md`
   to confirm contract completeness
2. Verify HubClient, JWKS endpoint, and heartbeat loop exist
3. Write all 9 plan files using the b.5-hub-scaffold.md format as
   template (step header, goal, context, scope, approach, tests,
   documentation, acceptance criteria, git ritual)
4. Verify each plan file is internally consistent (no broken cross-references)
5. Commit all plan files in a single commit `M.0: add Phase M plan files`
6. No PR needed (this is a planning artifact in the phlex-server repo)

## 6. Acceptance criteria

- [ ] `src/Hub/HubClient.php` verified present and implements the contract
- [ ] `/.well-known/jwks.json` route verified working
- [ ] `m.0-phase-m.md` through `m.8-new-platforms.md` all created
- [ ] Each plan file references correct repo, dependencies, and
      acceptance criteria
- [ ] M.8 plan contains explicit deferral recommendation for v2
- [ ] All 9 files committed to phlex-server master
- [ ] Git status clean in phlex-server after commit

## 7. Git ritual

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git checkout -b m.0-phase-m

# Write all 9 plan files

git add plans/expansion/m.*.md
git status --short
git commit -m "M.0: add Phase M plan files (m.0 through m.8)"

unset GITHUB_TOKEN
git push -u origin m.0-phase-m
# No PR/merge needed — plan files land via direct master commit

git checkout master && git pull
git branch -d m.0-phase-m
git log --oneline -1
```
