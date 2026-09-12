# SyncPlay write-through bridge (S445)

**Model, in one sentence:** the REST side owns persistence; after every durable
write it *publishes* the resulting group over a private unix socket, and the
single WS worker — which never reads the database — applies each frame to its
live in-memory tables, so a room created or mutated through the REST path is
reflected in what the WS worker *serves* without a restart.

## Why this exists

The HTTP and WebSocket sides are **different processes**:

- The WS worker is `count = 1` on `:8097` (`config/server.php`, booted by
  `start.php` §4a). Its `SyncPlayManager` is the authority on live group
  membership and playback state — the state it *serves* on the socket.
- REST requests run on one of the 14 HTTP workers on `:8096`
  (`start.php:169` area). Each HTTP worker holds its **own private**
  `SyncPlayManager` instance, so a room created through
  `POST /api/v1/syncplay/groups` on worker 7 was previously invisible to the
  WS worker (and to the other 13 HTTP workers) until a restart — and restart
  would not help either, because the WS worker never hydrated from the
  snapshot store at boot (verified at time of writing: zero hydrate call-sites;
  `setSnapshotService()` is wired in the WS worker, but only as the *publish*
  target for its own mutations).

Owner ruling (2026-09-12, S445): keep the S415 DB-free HTTP posture *inverted*
— the REST side becomes the writer of record (it already owns
`SyncPlaySnapshotService`), and the WS worker must **receive** mutations
without becoming a DB reader. Single authority on live in-memory state stays
the WS worker, fed by published deltas.

## Components

| Piece | File | Side |
|---|---|---|
| Envelope factory/parser + shared-secret const | `src/Session/SyncPlay/SyncPlayBridge.php` | both |
| Publish (bounded sync write) | `src/Session/SyncPlay/SyncPlayBridgePublisher.php` | HTTP worker |
| Listen + apply | `src/Session/SyncPlay/SyncPlayBridgeListener.php` | WS worker |
| Apply target (`applyBridgeFrame`) | `src/Session/SyncPlay/SyncPlayManager.php` | WS worker |
| Wire-up | `SyncPlayController.php` (3 rails), `SessionServicesProvider.php`, `Application.php`, `start.php` §4a, `SyncPlayWorker.php` (alternate bootstrap) | |

## Transport

- **Unix socket** at `var/syncplay-bridge.sock` (config
  `syncplay_bridge.socket_path`, env override `SYNCPLAY_BRIDGE_SOCKET`). All
  workers are forked by one master (`php start.php start` under systemd /
  supervisord), so they share the filesystem and user: **file mode 0600 is the
  primary access control** — no new port, no public exposure, other users
  cannot even open the path. The listener `unlink()`s a stale socket file at
  bind time (owner-only dir, TOCTOU-safe enough at 0600) and refuses to bind
  over a *live* listener (probe-connect first; see Failure modes).
- **NDJSON, one frame per short-lived connection**: connect → single
  `fwrite($frame."\n")` → close. No interleaving is possible across 14
  concurrent publishers, and there is no persistent-connection state machine.
- **Second factor**: every frame carries `SyncPlayBridge::TOKEN` (a shared
  secret constant); `SyncPlayBridge::parse()` drops a mismatched token with a
  warning. This is defense-in-depth / misconfiguration tripwire (e.g. a
  second checkout on the same host pointed at the same socket path by env
  override), not the primary boundary.
- The frame is a **clearly-separate internal channel**, deliberately *not* a
  `Messages::frame()` type: `Messages::VALID_TYPES` gates the *client-facing*
  inbound protocol on `:8097`, and registering a bridge op there would make
  internal mutations client-spoofable on the public socket (and would collide
  with S417's pinned outbound frame shape). Envelope keys (`op`,
  `bridge_version`, `token`) are written **last** by the factory so a group
  payload can never shadow them; `issued_at_ms` is payload-side and trusted
  only as a monotonic stamp, never as wall truth.

## Data flow (per REST mutation rail)

```
HTTP worker                                   WS worker (count=1)
─────────────────────────────────────────     ─────────────────────────────────
1. hydrate:  loadSerialized(groupId)          (join/leave rails — adopt the
           → adoptGroupFromSerialized)         FRESH snapshot as the base, so
2. mutate:   SyncPlayManager::join/leave/create password checks and member
             (worker-local logic, unchanged)   sets operate on live truth)
3. persist:  SnapshotService::publishGroup()  ← DURABLE FIRST (or removeGroup
             / removeGroup + delete frame       when the last member leaves)
4. publish:  Publisher::publishUpsert()  ───▶  Listener::poll/attachToLoop
   (fire-and-forget, ≤250 ms bound)              → parse (token gate)
                                                → Manager::applyBridgeFrame
                                                → SERVED state updated
```

`start.php` §4a attaches the listener to the **live Workerman event loop**
(`Worker::getEventLoop()->onReadable()` — verified non-null inside
`onWorkerStart`; the Swoole event loop registers arbitrary streams via
`Swoole\Event::add`, the same mechanism Workerman's own acceptors use). If the
loop object is unavailable it degrades to a 100 ms `Timer::add(poll)` tick.
`onWorkerStop` closes the listener and unlinks the socket (chained, never
clobbering the pre-existing handler).

The HTTP-side container `SyncPlayManager` **stays snapshot-less on purpose**
(`SessionServicesProvider`): a manager with a snapshot service fires
`broadcastToGroup()` side effects at `publishSnapshot()` time; on an HTTP
worker the connection pool is empty, so keeping it service-less is inertness
by construction. Persistence moved to the controller (step 3), where the
publish ordering is explicit.

## Ordering, idempotency, staleness

- **Write-through ordering**: publish happens only *after* the durable
  snapshot write returns. A frame never runs ahead of the DB truth.
- **Idempotent application**: every frame carries a per-group
  `issued_at_ms` stamp. The applying manager records the last stamp per group;
  a frame at or below it is dropped (re-delivery is a no-op). A `group.delete`
  tombstones its id, so a late re-delivered upsert of the same age cannot
  resurrect the room; a genuinely newer upsert (larger stamp) re-adopts.
  Tombstones are pruned by `cleanupStaleGroups()` at `GROUP_TIMEOUT`.
- **Facet merge, not clobber** (WS worker authority): applying an upsert to a
  group that *already exists live* replaces only the REST-owned facets — the
  membership set and host — and **preserves the WS-owned facets** (current
  media, playback position/state, queue, chat). The mirror's playback fields
  are stale by construction (the WS worker republishes on its own mutations,
  but a REST-side frame was built from a possibly older base), so they are
  ignored on merge. An upsert for a group the WS worker does **not** have
  adopts the snapshot wholesale — that is the self-heal path.
- Adoption of *new* groups via the bridge respects `MAX_GROUPS`; merges into an
  existing group always proceed (an established room must never be locked out
  by the cap).

## Loss posture — **fire-and-forget** (stated loud)

There is **no ack, no replay, no queue** on this channel. A publish that fails
(listener down, permissions, timeout) is logged as a warning on the HTTP side
and *does not* fail the REST request. This is a deliberate, honest choice:

1. **Durability already lives elsewhere.** The REST response's contract is the
   snapshot row (step 3), and the REST *read* rails serve from that row. The
   bridge only propagates *liveness*.
2. **The WS worker has no boot hydration and restart is connection-death
   anyway.** A WS-worker restart drops every live WebSocket connection
   regardless of the bridge; clients reconnect and rejoin, and — because REST
   joins/leaves hydrate from the shared store — the next mutation for a group
   re-publishes its full current state, so a dropped frame **self-heals**
   rather than persisting divergence.
3. **The alternatives were worse.** An ack/replay layer would need either a
   durable spool (a third store nobody asked for) or would push the WS worker
   toward reading the DB to reconcile — explicitly ruled out. Boot-time
   hydration of the WS worker is a separate concern (its own step; prior notes
   record it touches the S287/S290/S294 broadcast path).

**Known race window (accepted, bounded, disclosed):** two REST mutations for
the same group on different HTTP workers, interleaved *between* each other's
steps 2–3, race read-modify-write on the snapshot row — the later commit wins
per-facet and the surviving frame carries the winner's full membership set, so
the loser's member can vanish from both store and live state until they
re-join. The pre-bridge code was strictly worse (the two workers' tables simply
never converged). Closing this properly is a compare-and-swap on
`updated_at`/row-version in the store — future step, not S445.

## Failure modes

- **Listener absent** (daemon up, WS worker still booting; or `enabled=false`):
  connect to a missing socket file returns instantly → `false`, logged, request
  unaffected. The 250 ms `publish_timeout_ms` bounds only pathological peers.
- **A wedged listener cannot hang a publisher** — measured. Under
  `SWOOLE_HOOK_UNIX` (curated allowlist includes UNIX/UDG), `fwrite` on a
  non-blocking hooked unix stream absorbed **219 264 bytes** into kernel buffers
  on a fresh connection to a never-draining listener, and the *second* chunked
  `fwrite` then blocked **forever** despite `stream_set_blocking(false)` and a
  select reporting writability — a select-retry loop is not a bound here. The
  publisher therefore performs **one single `fwrite` of a frame capped at
  `MAX_PUBLISH_FRAME_BYTES` (160 KiB)**: measured normal frame to a wedged
  listener `true` in 0.1 ms; oversized frame refused `false` in 0.1 ms (never
  chunked); missing listener `false` in 0.1 ms. 160 KiB ≈ 3.3× a
  `MAX_MEMBERS`-full group (measured 48 422 bytes), so legitimate traffic
  always fits under the bound. The listener independently caps inbound
  buffered-line size (`MAX_LINE_BYTES`, 1 MiB) and drops the connection over it.
- **Two live listeners** cannot coexist by construction: the second `listen()`
  probes the path, finds a live accepter and throws — the WS worker logs the
  bridge failure and continues serving without the bridge (the HTTP side
  publishes to the original owner; operator fix = remove the stale socket /
  restart the second daemon).
- **Oversized inbound line** at the listener: connection dropped, logged.
  Malformed frames (bad JSON, missing keys, wrong `op`, wrong version, wrong
  token) are dropped in `parse()` before any applier sees them.

## Configuration

```php
'syncplay_bridge' => [
    'enabled'            => getenv('SYNCPLAY_BRIDGE') !== '0',        // opt-out
    'socket_path'        => getenv('SYNCPLAY_BRIDGE_SOCKET')
        ?: dirname(__DIR__) . '/var/syncplay-bridge.sock',
    'publish_timeout_ms' => (int) (getenv('SYNCPLAY_BRIDGE_TIMEOUT_MS') ?: 250),
],
```

Disabled config makes the DI factory hand the controller a **null** publisher
(legacy local-only rails) — the intent, honestly noted, is mainly for tests and
controlled rollouts; the WS-side listener is likewise not started.

## Verification map

- Unit: `tests/Unit/Session/SyncPlay/SyncPlayBridgeTest.php` (envelope, token,
  parse rejection, real-socket transport, 0600 mode, wedged/missing/stale
  failure modes incl. the measured anti-hang pair).
- Unit: `tests/Unit/Session/SyncPlay/SyncPlayBridgeApplyTest.php` (adopt,
  idempotent re-delivery, staleness gating, merge preserves WS facets, delete
  tombstone blocks resurrection, prune, cap).
- Integration (AC): `tests/Integration/Session/SyncPlay/SyncPlayWriteThroughBridgeTest.php`
  — two real managers + real MySQL + real unix socket; asserts the **served**
  group-list frame the WS worker emits to an authenticated client, after REST
  create/join/leave, without restart; plus the named reddening test for
  REST-only (unpublished) mutations.
- Two-process smoke: `scripts/syncplay-bridge-smoke.php` (pcntl_fork; WS child
  runs listener + loop + served-state probe, never touches the DB).
