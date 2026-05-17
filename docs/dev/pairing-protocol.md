# Pairing Protocol Specification

**Version:** 1.0  
**Status:** Design (Phase C.1)  
**Audience:** Developers implementing Phase C (server↔hub pairing)

---

## Overview

The pairing protocol establishes a trust relationship between a self-hosted
`phlex-server` instance and a `phlex-hub` instance. Once paired, the hub can:

- Broker authentication so clients can access the server from anywhere
- Provide relay connectivity when direct LAN access is unavailable
- Offer a unified "my servers" dashboard for users with multiple homes

---

## Protocol Flow (Summary)

```
┌──────────────────────────────────────────────────────────────────────┐
│                         PAIRING FLOW                                  │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  1. Server starts                                                    │
│     → generates Ed25519 keypair                                      │
│     → stores JWKS at /.well-known/jwks.json (self-hosted)             │
│     → sends POST /api/v1/server-claims/new to hub                    │
│                                                                       │
│  2. Hub responds                                                      │
│     ← { claim_code: "ABCD-1234", expires_in: 600, claim_id }         │
│                                                                       │
│  3. Server displays claim_code on screen/CLI                         │
│                                                                       │
│  4. User logs into hub web portal                                    │
│     → POST /api/v1/server-claims/claim with { claim_code }           │
│                                                                       │
│  5. Hub atomically: validates code + associates server with user     │
│     ← returns { enrollment_jwt, hub_jwks_url }                        │
│                                                                       │
│  6. Server stores enrollment_jwt + hub_jwks_url                      │
│     → starts heartbeat loop (POST /api/v1/servers/{id}/heartbeat)     │
│                                                                       │
│  7. Server continues publishing JWKS at /.well-known/jwks.json       │
│                                                                       │
│  8. Hub issues user-session JWTs with user_id + server_id audience   │
│                                                                       │
│  9. Client receives JWT from hub, presents to server                 │
│     → Server validates against hub's JWKS URL                        │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 1. Server Keypair

### Algorithm Selection

**Ed25519** (EdDSA) is used for the server's signing keypair.

Rationale:
- **Modern and secure** — not susceptible to RSA's many implementation
  pitfalls, no need for large key sizes
- **Small keys** — 32-byte public key fits comfortably in JSON
- **Small signatures** — 64 bytes, lower overhead than RSA/ECDSA
- **Fast signing** — less CPU overhead on low-power NAS devices
- **RFC 8032** compliant — widely supported, including `sodium_crypto_sign_*`

**Rejected alternatives:**
- **RSA 2048** — larger keys (256 bytes), larger signatures (256 bytes),
  slower to sign, more attack surface
- **ECDSA P-256** — smaller than RSA but has several implementation pitfalls
  (curve non-monotonicity, timing leaks); Ed25519 is cleaner
- **X25519** — key exchange only, not signing; wrong tool

### Key Storage

Server stores its Ed25519 **private key** in:

```
config/hub-server-key.pem     # raw PEM-encoded Ed25519 private key
```

The corresponding **public key** is extracted and embedded in the JWKS
document (see §2).

The private key file must have `0600` permissions. If it does not exist
on first boot, the server generates one automatically:

```php
$privateKey = sodium_crypto_sign_keypair();        // 64-byte seed + 32-byte pub
$secretKey  = substr($privateKey, 0, 32);          // first 32 bytes = secret
$publicKey  = substr($privateKey, 32);            // last 32 bytes = public

// Store PEM
file_put_contents($keyPath, "-----BEGIN ED25519 PRIVATE KEY-----\n"
    . base64_encode($secretKey) . "\n-----END ED25519 PRIVATE KEY-----\n");
chmod($keyPath, 0600);
```

### Key Rotation

- Keys are **rotated** when the server operator explicitly triggers it
  (e.g., `php scripts/rotate-hub-key.php`)
- On rotation, a **new keypair is generated**, the new JWKS is published,
  and heartbeats carry both the new key ID and the old key ID (for a
  24-hour overlap window where both old and new signatures are accepted)
- After 24 hours, only the new key is accepted
- The **old private key is deleted** after the overlap window

---

## 2. JWKS — Server's Own Keys

### URL

The server **self-hosts** its JWKS. This is the canonical and preferred
approach — it avoids the hub having to store and proxy keys.

```
https://<server-hostname>:32400/.well-known/jwks.json
https://<server-hostname>:32400/.well-known/jwks.json?kty=OKP&alg=Ed25519
```

The path `/.well-known/jwks.json` is **always relative to the server's
root**, not the web portal root. It is served by the Workerman HTTP
server directly (not Smarty).

### Document Format

```json
{
  "keys": [
    {
      "kty": "OKP",
      "crv": "Ed25519",
      "x": "11qYjhK5HRVDum2bHqDQD0gRNYVWg0Wmg2TTKJSbZ-g",
      "kid": "2026-05-17T00:00:00Z",
      "use": "sig",
      "alg": "EdDSA"
    }
  ]
}
```

- `kty: "OKP"` — Octet Key Pair (Ed25519/Ed448)
- `crv: "Ed25519"` — curve identifier
- `x` — base64url-encoded 32-byte public key
- `kid` — key ID (ISO 8601 timestamp; changes on rotation)
- `use: "sig"` — signature key
- `alg: "EdDSA"` — algorithm identifier

### Serving the JWKS

The Workerman HTTP server handles this directly:

```php
$router->get('/.well-known/jwks.json', function ($request) {
    $keys = $this->hubClient->getPublicKeysJwk();
    return (new Response())
        ->status(200)
        ->header('Content-Type', 'application/json')
        ->header('Cache-Control', 'public, max-age=3600')
        ->json(['keys' => $keys]);
});
```

Cache-Control allows CDN edge-caching of the public document without
sensitive material.

---

## 3. Claim Code

### Generation

Claim codes are **6-character alphanumeric**, uppercase letters + digits
(excluding 0, O, I, 1 to avoid ambiguity):

```
Pattern: [A-Z2-9]{4}-[A-Z2-9]{4}
Example: "ABCD-1234"
```

- **Entropy:** 32^4 × 32^4 = 2^40 ≈ 1 trillion possibilities
- Generated by the hub using a cryptographically secure RNG
- **Stored in the hub DB** with a 10-minute TTL (configurable)
- Single-use: atomic validation deletes the code on successful claim

### Generation Algorithm

```php
function generateClaimCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0, O, I, 1
    $code  = '';
    for ($i = 0; $i < 4; $i++) {
        $code .= $chars[random_int(0, 31)];
    }
    $code .= '-';
    for ($i = 0; $i < 4; $i++) {
        $code .= $chars[random_int(0, 31)];
    }
    return $code;
}
```

---

## 4. Server → Hub: Claim Request

### Endpoint

```
POST https://hub.example.com/api/v1/server-claims/new
```

### Request Headers

```
Accept-Phlex-Protocol: v1
Content-Type: application/json
```

### Request Body

```json
{
  "server_name": "Alice's NAS",
  "version": "0.11.0",
  "public_keys": {
    "kty": "OKP",
    "crv": "Ed25519",
    "x": "11qYjhK5HRVDum2bHqDQD0gRNYVWg0Wmg2TTKJSbZ-g",
    "kid": "2026-05-17T00:00:00Z",
    "use": "sig",
    "alg": "EdDSA"
  },
  "hostname_candidates": [
    "https://192.168.1.100:32400",
    "https://alice-nas.local:32400"
  ],
  "protocol_version": "v1"
}
```

### Field Descriptions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `server_name` | `string` | Yes | Operator-chosen friendly name shown on hub dashboard |
| `version` | `string` | Yes | Server semver (e.g., `0.11.0`). Hub may reject incompatible versions |
| `public_keys` | `object` | Yes | JWK of the server's Ed25519 public key (kid references current active key) |
| `hostname_candidates` | `list<string>` | Yes | Hostnames/IPs the server believes it is reachable at. Hub uses the first publicly reachable one; falls back to relay |
| `protocol_version` | `string` | Yes | Fixed at `"v1"`. Hub validates this header value |

### Hub Validation on Claim Request

Before accepting a claim request, the hub:

1. **Validates `protocol_version`** — must be `"v1"`; rejects with
   `HUB_PROTOCOL_UNSUPPORTED` if not
2. **Checks `version`** against `hub_min_server_version` — rejects if
   server version is too old with `SERVER_VERSION_INCOMPATIBLE`
3. **Validates the JWK structure** — must be a well-formed Ed25519
   public key; rejects with `SERVER_KEY_INVALID` if malformed
4. **Checks for duplicate `server_name`** — allowed (different users
   might name their servers the same thing); no uniqueness constraint
5. **Checks existing claim** — if this server (matched by public key
   fingerprint) already has a pending (unclaimed) claim, returns the
   existing claim_code rather than issuing a new one (avoids burning
   claim codes on retries)

### Response

```json
{
  "claim_code": "ABCD-1234",
  "expires_in": 600,
  "claim_id": "550e8400-e29b-41d4-a716-446655440000",
  "hub_base_url": "https://hub.example.com"
}
```

### Error Responses

| HTTP Status | Error Code | Meaning |
|-------------|-----------|---------|
| `400` | `SERVER_KEY_INVALID` | JWK malformed or not Ed25519 |
| `400` | `HUB_PROTOCOL_UNSUPPORTED` | `protocol_version` not `"v1"` |
| `400` | `SERVER_VERSION_INCOMPATIBLE` | Server version below hub's minimum |
| `500` | `HUB_INTERNAL_ERROR` | Unexpected hub error |

---

## 5. Hub → User: Claim Flow (Web UI)

### User Action

1. User logs into `https://hub.example.com`
2. Clicks "Claim a Server" button
3. Enters the 6-char claim code (`ABCD-1234`) into a form field
4. Submits

### Internal Hub Action

```
POST /api/v1/server-claims/claim
{
  "claim_code": "ABCD-1234"
}
```

The hub uses the **user's authenticated session** (not an explicit
user_id field) to associate the server with the currently logged-in user.

### Atomic Claim Process

```
BEGIN TRANSACTION
  1. SELECT * FROM server_claims
     WHERE claim_code = ? AND expires_at > NOW()
     FOR UPDATE
  2. IF not found → ROLLBACK, return CLAIM_CODE_NOT_FOUND or CLAIM_CODE_EXPIRED
  3. IF already claimed_by IS NOT NULL → ROLLBACK, return CLAIM_CODE_ALREADY_CLAIMED
  4. UPDATE server_claims SET claimed_by = ?, claimed_at = NOW()
  5. INSERT INTO servers (id, user_id, server_name, version, public_key_jwk,
       hostname_candidates, status, last_seen_at, created_at)
     VALUES (?, ?, ?, ?, ?, ?, 'online', NOW(), NOW())
  6. DELETE FROM server_claims WHERE id = ?   ← code is single-use
COMMIT
```

### Response on Success

```json
{
  "enrollment_jwt": "eyJhbGciOiJFZERTQSJ9...",
  "hub_jwks_url": "https://hub.example.com/.well-known/jwks.json",
  "server_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

### Enrollment JWT

The `enrollment_jwt` is signed by the hub with **its own Ed25519 key**.

**Claims:**

| Claim | Value | Description |
|-------|-------|-------------|
| `iss` | `phlex-hub` | Issuer identifier |
| `sub` | `server_id` | UUID assigned by hub |
| `aud` | `server` | Audience: this token is for the server |
| `exp` | `now + 7d` | 7-day validity — server must re-enroll before expiry |
| `iat` | `now` | Issued-at |
| `kid` | key ID | Which hub signing key was used |
| `hub_base_url` | `https://hub.example.com` | Hub API base for heartbeat destination |
| `server_id` | UUID | Same as `sub` |

The server stores this token and uses it to authenticate heartbeats.

### Error Responses

| HTTP Status | Error Code | Meaning |
|-------------|-----------|---------|
| `404` | `CLAIM_CODE_NOT_FOUND` | Code doesn't exist |
| `410` | `CLAIM_CODE_EXPIRED` | Code TTL elapsed |
| `409` | `CLAIM_CODE_ALREADY_CLAIMED` | Already claimed by another user |
| `401` | `UNAUTHENTICATED` | User not logged in |

---

## 6. Heartbeat

### Endpoint

```
POST https://hub.example.com/api/v1/servers/{server_id}/heartbeat
Authorization: Bearer <enrollment_jwt>
Accept-Phlex-Protocol: v1
Content-Type: application/json
```

### Payload

```json
{
  "server_id": "550e8400-e29b-41d4-a716-446655440000",
  "version": "0.11.0",
  "timestamp": 1747430400,
  "uptime_seconds": 86400,
  "active_sessions": 2,
  "active_transcodes": 1,
  "hostname_candidates": [
    "https://192.168.1.100:32400",
    "https://alice-nas.local:32400",
    "https://alice-nas.duckdns.org:32400"
  ],
  "libraries": [
    { "id": "lib-uuid-1", "name": "Movies", "item_count": 1247 },
    { "id": "lib-uuid-2", "name": "TV Shows", "item_count": 312 }
  ],
  "capabilities": ["direct-play", "transcode-h264", "transcode-h265", "syncplay"]
}
```

### Field Descriptions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `server_id` | `string` (UUID) | Yes | Hub-assigned server UUID |
| `version` | `string` | Yes | Server semver |
| `timestamp` | `int` | Yes | UNIX seconds at send time |
| `uptime_seconds` | `int` | Yes | Process uptime |
| `active_sessions` | `int` | Yes | Concurrent playback sessions |
| `active_transcodes` | `int` | Yes | Concurrent active transcode processes |
| `hostname_candidates` | `list<string>` | Yes | All hostnames the server thinks it's reachable at; first publicly reachable is used |
| `libraries` | `list<object>` | No | Summary of connected libraries with item counts |
| `capabilities` | `list<string>` | No | Server capabilities for hub dashboard display |

### Hub Behavior on Heartbeat

1. Validates the `enrollment_jwt` (signature + expiry)
2. Updates `servers.last_seen_at = NOW()`
3. Updates `servers.status = 'online'`
4. Updates `servers.version`, `servers.hostname_candidates` from payload
5. If `server_id` is unknown → `404 SERVER_NOT_FOUND`

### Heartbeat Frequency

- Server sends heartbeat **every 60 seconds**
- If hub misses **3 consecutive heartbeats** (3 minutes), it marks the
  server as `offline`
- Server can request a **longer interval** by passing
  `"heartbeat_interval": 300` in the payload; hub will only mark offline
  after `3 × interval` seconds

---

## 7. Hub JWKS

### URL

```
https://hub.example.com/.well-known/jwks.json
```

Served by the hub's Workerman HTTP server. Same format as server JWKS.

### Document Format

```json
{
  "keys": [
    {
      "kty": "OKP",
      "crv": "Ed25519",
      "x": "hN3d2GhVKGYoCpad3qDQD0gRNYVWg0Wmg2TTKJSbZ-g",
      "kid": "2026-05-17T00:00:00Z",
      "use": "sig",
      "alg": "EdDSA"
    }
  ]
}
```

### Key Rotation

Hub operator triggers rotation via admin CLI. Overlap window: 24 hours
during which both old and new signing keys are accepted.

---

## 8. User-Session JWT (Delegated Auth)

### Issuance

When a user who has claimed servers wants to access one remotely, the hub
issues a JWT that:

1. Identifies the **user** (`sub: user_id`)
2. Authorizes access to a **specific server** (`server_id` claim)
3. Is **signed by the hub** (`iss: phlex-hub`)

### Token Claims

```json
{
  "iss": "phlex-hub",
  "sub": "user-uuid",
  "aud": "server",
  "exp": 1747434000,
  "iat": 1747430400,
  "kid": "2026-05-17T00:00:00Z",
  "server_id": "550e8400-e29b-41d4-a716-446655440000",
  "scope": ["library:read", "playback:write"],
  "jti": "unique-token-id"
}
```

### Server Validation of Hub-Minted Tokens

1. Server fetches JWKS from `hub_jwks_url` (cached, refreshed every
   15 minutes or on 401 response)
2. Extracts the `kid` from the token header
3. Looks up the matching key in the JWKS
4. Validates the signature with EdDSA
5. Validates `iss == 'phlex-hub'`
6. Validates `aud == 'server'`
7. Validates `server_id` matches the server's own ID (prevents token
   from one server being used against another)
8. Validates `exp`, `iat`, `nbf` as usual

---

## 9. Protocol Versioning

### Header

Every request and response on pairing-related endpoints carries:

```
Accept-Phlex-Protocol: v1
```

If the hub receives a request without this header or with an unexpected
value, it returns:

```json
{ "error": "HUB_PROTOCOL_UNSUPPORTED", "message": "Accept-Phlex-Protocol: v1 required" }
```

### Version Compatibility Matrix

| Protocol Version | Hub Min | Server Min | Notes |
|-----------------|---------|------------|-------|
| `v1` | 1.0.0 | 0.11.0 | Initial release |

Future versions will increment the header value and include migration
instructions.

---

## 10. Error Code Reference

All pairing protocol errors use this envelope:

```json
{
  "error": "ERROR_CODE",
  "message": "Human-readable description",
  "details": {}  // optional additional context
}
```

### Server-Side Errors (Server → Hub requests)

| Error Code | HTTP Status | Description |
|-----------|------------|-------------|
| `SERVER_KEY_INVALID` | 400 | Server's JWK is malformed or not Ed25519 |
| `HUB_PROTOCOL_UNSUPPORTED` | 400 | Hub doesn't support the server's protocol version |
| `SERVER_VERSION_INCOMPATIBLE` | 400 | Server version below hub minimum |
| `HUB_UNREACHABLE` | 503 | Server cannot reach hub (network issue) |
| `HUB_JWKS_FETCH_FAILED` | 503 | Server cannot fetch hub's JWKS |

### Hub-Side Errors (Hub → Server or User → Hub requests)

| Error Code | HTTP Status | Description |
|-----------|------------|-------------|
| `CLAIM_CODE_NOT_FOUND` | 404 | Claim code doesn't exist in DB |
| `CLAIM_CODE_EXPIRED` | 410 | Claim code TTL has elapsed |
| `CLAIM_CODE_ALREADY_CLAIMED` | 409 | Claim code already used by another user |
| `SERVER_NOT_FOUND` | 404 | Server ID not known to hub |
| `UNAUTHENTICATED` | 401 | User not logged in to hub |
| `AUTHORIZATION_FAILED` | 403 | User doesn't own this server |
| `ENROLLMENT_TOKEN_EXPIRED` | 401 | Server's enrollment JWT has expired |
| `HUB_INTERNAL_ERROR` | 500 | Unexpected hub error |

---

## 11. Security Considerations

### Claim Code Security

- 6-char alphanumeric is ~40 bits of entropy — sufficient for a
  short-lived, rate-limited code entry
- Hub rate-limits claim attempts: max 5 attempts per IP per 10 minutes
- Single-use: atomic delete on successful claim prevents replay
- 10-minute TTL prevents indefinite exposure

### Token Storage

- Server stores enrollment JWT in `config/hub-enrollment-token` (mode 0600)
- Hub stores user session JWTs in httpOnly cookies (not localStorage)
- Server stores hub JWKS URL in `config/hub-jwks-url` (plain text, mode 0644)

### Signature Verification

- Server **always** validates hub JWT signatures against JWKS from
  `hub_jwks_url` — never hardcodes the hub's public key
- Server caches JWKS for 15 minutes; refetches on 401 to handle rotation
- Ed25519 signature verification is constant-time and resistant to
  timing attacks

### Relay Security

- The relay tunnel (Phase C.6) uses the existing enrollment JWT to
  authenticate the server connection
- Each relayed request carries a separate per-request token (not the
  enrollment JWT directly)
- Hub validates the per-request token before forwarding any bytes

---

## 12. Database Schema (Hub-Side)

See `plans/expansion/c.3-hub-registry.md` §3 for full schema details.

---

## 13. Cross-Reference

- **Step C.2** implements `Phlex\Hub\HubClient` on the server side
- **Step C.3** implements the hub registry endpoints on the hub side
- **Step C.4** implements the "My Servers" dashboard using registry data
- **Step C.5** implements delegated auth (hub JWKS + user-session JWTs)
- **Step C.6** implements the relay tunnel
- **Step C.7** implements UPnP + port-forward helper
- **Step C.8** implements public hostname claim
- **Step C.9** implements shared libraries
- **phlex-shared** provides: `ClaimRequest`, `ClaimResponse`,
  `ServerInfoDto`, `HeartbeatDto` DTOs shipped in B.3 v0.2.0
