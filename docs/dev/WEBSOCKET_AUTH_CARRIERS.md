# WebSocket handshake auth carriers (`:8097`)

**Status: TRANSITIONAL DUAL-CARRIER.** The SyncPlay WebSocket on `:8097` accepts the
handshake JWT through two carriers, in a fixed priority order. This mirrors the carrier
law phlix-hub `:8804` ships (`src/SyncPlay/SyncPlayRelayWorker.php`) so one
estate policy — `WEBSOCKET_URL_QUERY_REFUSED` — has one wire vocabulary.

## The carriers

| Priority | Carrier | Form | State |
|---|---|---|---|
| 1 | Bearer subprotocol | `Sec-WebSocket-Protocol: bearer, <jwt>` | **TARGET** — the carrier clients move to |
| 2 | Legacy query | `GET /…?token=<jwt>` | **REFUSED BY DEFAULT** since CP-024 PHASE B — opt in per install via the admin setting `auth.ws_query_token=allow`, or per host via `PHLIX_WS_QUERY_TOKEN=allow` |

Browsers cannot set an `Authorization` header on a `WebSocket` upgrade, which is why the
estate carrier is the subprotocol two-entry form, not a header. Server semantics live in
`SyncPlayAuthMiddleware::resolveHandshakeToken()` (SSOT — one credential class, exactly
one place that reads carriers).

### Resolution law

1. A `bearer` entry in the offer list marks the carrier; the **first other non-empty,
   trimmed entry** is the credential (per-entry RFC 7230 token comparison —
   `bearer-chat` is a different protocol-id and does not count as an offer).
2. No bearer credential → the legacy `?token=` query value is used — subject to the
   retirement gate below, which since PHASE B refuses the query carrier by default.
3. Both carriers carry credentials and they **differ** → the handshake is rejected
   pre-101 (pool removal + TCP close). A half-migrated client must fail loudly instead
   of authenticating on a credential other than the one it presents.
4. Both present and **identical** → accepted (bearer carrier wins the record).
5. An empty `?token=` carries no credential and counts as absent.
6. Neither carrier (and a JWT secret is configured) → rejected, as before.

## The 101 echo

A client that **offered** `bearer` and passes the gate gets exactly one response header
line back:

```
Sec-WebSocket-Protocol: bearer
```

- The echo is the **marker only — never the token**. A credential is not a protocol-id;
  echoing it would re-publish on the response wire the secret the carrier law exists to
  keep off logs (RFC 6455 §4.2.2 permits answering only with a supported protocol-id).
- The echo is **gated on the offer**: a legacy query-only client that negotiates nothing
  gets no echo (selecting a protocol the client never offered is itself the §4.1
  violation), and — while the gate resolves to `allow` — query-only browser clients
  keep working unchanged.
- Mechanism: Workerman composes the 101 **after** `onWebSocketConnect` returns and
  appends every `$connection->headers` entry verbatim
  (`vendor/workerman/workerman/src/Protocols/Websocket.php:449-455`) — the only echo
  extension point on this path. Clients without an offer see a byte-identical 101.
- Why it matters: a WHATWG browser `new WebSocket(url, ['bearer', …])` that is offered
  subprotocols and answered with none **fails the socket outright** (measured 1006 on
  the ui relay carrier) — the echo is what unlocks the client flips below.

## Logging hygiene

No `:8097` handshake log line may carry a credential. The URI-bearing handshake log
sites (the carrier-mismatch rejection and the CP-007 query-carrier telemetry/rejection
line) both pass the request URI through
`SyncPlayAuthMiddleware::redactTokenQuery()` first, which masks every whole-name
`token=` query value (`?token=[redacted]`) while leaving the path and other params
intact. This closes the URL-in-logs exposure for **legacy query clients too** — the
retirement of `?token=` is also what removes it from access/proxy logs and `Referer`
headers upstream, which redaction here cannot cover.

## Client retirement path

Each client flips its `:8097` connect to the two-entry subprotocol form
(`new WebSocket(url, ['bearer', token])`) and drops the query param from the URL:

| Client | Site | Follow-up |
|---|---|---|
| phlix-ui | `syncplay.ts` | **DONE** — flipped in phlix-ui (delivered by the v0.99.8 re-pin; the served `/app` bundle now dials `['bearer', token]` with zero `?token=` in its corpus) |
| tizen | `useSyncPlayStore` (~:503/:629) | same |
| mobile | `wsEndpoint.ts` direct lane | same |
| roku / console | native `Sec-WebSocket-Protocol` request header | same (non-browser clients may also adopt an `Authorization: Bearer` lane later; not accepted by `:8097` today) |

The fleet upgrade is complete: the estate producer census run for CP-024 found no live
`?token=` WS producer outside the server (phlix-ui, hub, tizen, windows, console and roku
dial bearer/cookie carriers; mobile — the last query producer — flipped at
phlix-mobile-client `bdbe1e1`). PHASE B therefore **flips the gate default to `deny`**;
a host running straggler clients restores the byte-identical Phase-A acceptance with an
explicit `PHLIX_WS_QUERY_TOKEN=allow`. Removal itself (PHASE C) still trails fleet
update — clients are old by default, owner timing call — and when the branch goes, this
document and `resolveHandshakeToken()` shrink to the bearer law only.

### Server-side retirement gate (CP-007 + CP-024, COOKIE-DESIGN.md §4.1)

`PHLIX_WS_QUERY_TOKEN=allow|deny` (config `websocket.query_token`) gates the legacy query
carrier, and one redacted `websocket` warn line rides every query-carrier handshake
under either disposition. PHASE A shipped the gate with default `allow`; **PHASE B
(CP-024) flipped the default to `deny`** — unset or empty now refuses a query-carrier
handshake pre-101 with the existing reject idiom (pool reap + TCP close — no envelope,
zero new wire codes). An explicit `allow` is the straggler-host escape hatch and
restores the byte-identical Phase-A acceptance. The parser vocabulary is unchanged by
the flip: `deny` is the only value that denies — unrecognized values still fail open to
`allow` (opt-in-tightening law). Bearer and admitted-cookie handshakes are byte-ident
under every setting — gate absent, `allow` or `deny` (precedence law CP-006 UNMOVED).
PHASE C removes the branch; that remains an owner timing call trailing fleet update.
### The admin-row tier (wired 2026-11-10, off-kit owner ruling)

The stored-home settings key `auth.ws_query_token` (phlix-shared
server-settings schema, string enum `allow`/`deny`) landed its read-through —
it is now the middle tier of a single SSOT resolver,
`src/Server/WebSocket/QueryTokenGatePolicy.php`, consulted per query-carrier
handshake (the same staged env-first shape as `auth.cookie.insecure`):

```
EXPLICIT ENV PHLIX_WS_QUERY_TOKEN  >  ADMIN ROW auth.ws_query_token  >  PHASE B DEFAULT DENY
```

- **Env wins both ways.** `PHLIX_WS_QUERY_TOKEN=deny` outranks an admin-row
  `allow` (host lockdown); `=allow` outranks an admin-row `deny` — the
  straggler escape hatch works without DB access. Env stays restart-class.
  An unset/empty knob is SILENCE: it now falls THROUGH to the row tier
  instead of synthesizing `'deny'` at the config surface — with no row the
  answer is the same shipped deny, byte-ident to the CP-024 posture
  (carrier precedence CP-006, the reject path, and the redacted warn-log
  laws all UNMOVED; zero new wire codes).
- **The row is live.** An admin flip lands on the very next handshake — no
  restart, no worker recycle. Only the exact strings `allow` / `deny` speak;
  any other stored value (near-miss case, surrounding whitespace, a
  non-string from a raw-SQL or restored write) normalizes to DENY with one
  bounded, redacted warning — the type name only, never the stored bytes.
  The env tier's opt-in-tightening rule (garbage fails open to `allow`)
  deliberately does NOT apply here: a garbage env is an operator typo
  mid-boot, a garbage row is an explicit-but-broken admin statement, and at
  a security gate whose shipped default is already deny, only the safe
  direction may answer. An unreachable store likewise fails CLOSED to deny
  with a bounded warning — never a throw (a throw out of
  `onWebSocketConnect` takes the `:8097` worker down with it).
- **Query-only, as ever.** Bearer and admitted-cookie legs never consult
  the row: no SELECT, no log difference, byte-ident outcomes.
- The two warn-line literals still name the env seam
  (`PHLIX_WS_QUERY_TOKEN=allow|deny`) for continuity of the log
  vocabulary; the disposition they describe may now also come from the
  admin row or the default.
- The key becomes PUT-able through the admin settings API when the server
  re-vendors the phlix-shared schema that carries it (schema prose +
  admission ride that sibling wave); the resolver reads the row through the
  store whatever the write path — a row landed by SQL or a settings restore
  is honored identically.

## Cross-references

- Hub implementation of the same law: phlix-hub
  `src/SyncPlay/SyncPlayRelayWorker.php` (bearer extraction and echo legs) and
  `src/Relay/ClientRelayWorker.php::extractClientToken()` (query carrier already removed
  at `:8803`).
- Hub carrier documentation: phlix-hub `docs/websockets.md`.
- Wire spec: phlix-syncplay `SPEC.md` §8.4 — server `:8097` was documented
  CURRENT=`?token` query / TARGET=bearer subprotocol; **this change implements the
  TARGET alongside CURRENT (transitional)**. The SPEC's status line needs a doc-lane
  flip to record that `:8097` now accepts the bearer carrier. (Still open 2026-11-10 —
  owned by the phlix-syncplay spec tracker, not an action in this repo.)
