# WebSocket handshake auth carriers (`:8097`)

**Status: TRANSITIONAL DUAL-CARRIER.** The SyncPlay WebSocket on `:8097` accepts the
handshake JWT through two carriers, in a fixed priority order. This mirrors the carrier
law phlix-hub `:8804` ships (S237/S355, `src/SyncPlay/SyncPlayRelayWorker.php`) so one
estate policy — `WEBSOCKET_URL_QUERY_REFUSED` — has one wire vocabulary.

## The carriers

| Priority | Carrier | Form | State |
|---|---|---|---|
| 1 | Bearer subprotocol | `Sec-WebSocket-Protocol: bearer, <jwt>` | **TARGET** — the carrier clients move to |
| 2 | Legacy query | `GET /…?token=<jwt>` | **RETIRING** — accepted only while clients upgrade |

Browsers cannot set an `Authorization` header on a `WebSocket` upgrade, which is why the
estate carrier is the subprotocol two-entry form, not a header. Server semantics live in
`SyncPlayAuthMiddleware::resolveHandshakeToken()` (SSOT — one credential class, exactly
one place that reads carriers).

### Resolution law

1. A `bearer` entry in the offer list marks the carrier; the **first other non-empty,
   trimmed entry** is the credential (per-entry RFC 7230 token comparison —
   `bearer-chat` is a different protocol-id and does not count as an offer).
2. No bearer credential → the legacy `?token=` query value is used.
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
  violation), and query-only browser clients keep working unchanged.
- Mechanism: Workerman composes the 101 **after** `onWebSocketConnect` returns and
  appends every `$connection->headers` entry verbatim
  (`vendor/workerman/workerman/src/Protocols/Websocket.php:449-455`) — the only echo
  extension point on this path. Clients without an offer see a byte-identical 101.
- Why it matters: a WHATWG browser `new WebSocket(url, ['bearer', …])` that is offered
  subprotocols and answered with none **fails the socket outright** (measured 1006 on
  the ui relay carrier) — the echo is what unlocks the client flips below.

## Logging hygiene

No `:8097` handshake log line may carry a credential. The only URI-bearing handshake log
site (the carrier-mismatch rejection) passes the request URI through
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
| phlix-ui | `syncplay.ts` (~:477 TODO) | remove `?token=`, pass `['bearer', token]` |
| tizen | `useSyncPlayStore` (~:503/:629) | same |
| mobile | `wsEndpoint.ts` direct lane | same |
| roku / console | native `Sec-WebSocket-Protocol` request header | same (non-browser clients may also adopt an `Authorization: Bearer` lane later; not accepted by `:8097` today) |

The query carrier stays accepted until every shipping client version has the flip
(clients are old by default — server-side removal must trail fleet update, owner
timing call). When it is removed, this document and `resolveHandshakeToken()` shrink to
the bearer law only.

## Cross-references

- Hub implementation of the same law: phlix-hub
  `src/SyncPlay/SyncPlayRelayWorker.php` (S237 extraction, S355 echo) and
  `src/Relay/ClientRelayWorker.php::extractClientToken()` (query carrier already removed
  at `:8803`).
- Hub carrier documentation: phlix-hub `docs/websockets.md`.
- Wire spec: phlix-syncplay `SPEC.md` §8.4 — server `:8097` was documented
  CURRENT=`?token` query / TARGET=bearer subprotocol; **this change implements the
  TARGET alongside CURRENT (transitional)**. The SPEC's status line needs a doc-lane
  flip to record that `:8097` now accepts the bearer carrier.
