# Accepted blocking-I/O exceptions

The house rule is *"all new I/O in the resident worker must be async/non-blocking"*.
This file is the **complete, closed register of the exceptions to it**. An
exception is only legitimate if it is:

1. **Named** — listed here, with the exact file and symbol.
2. **Bounded** — an enforced timeout, with the setting named and the bound
   *measured firing*, not assumed.
3. **Blast-radius costed** — how much of the service stalls, and for how long.

An "accepted exception" with no enforced timeout is not bounded; it is an
unbounded stall with a comment next to it. If you cannot show the bound firing,
the call does not belong on this list — move it off the event loop.

## Blast radius, once

`start.php` sets `$httpWorker->count = $httpListen['workerCount']` (default
14, via `ListenEndpoints`). Workerman/Swoole runs many coroutines per worker
process, so a blocking syscall freezes **every connection that worker is
currently serving**, not just the caller's — one worker is ~1/14 of HTTP
capacity. The WS worker (`start.php`, `$wsWorker->count = 1`), the
hub-heartbeat worker (`$hubHeartbeatWorker`), the background-timer worker
(`$backgroundTimerWorker`) and the relay-tunnel worker (`$relayTunnelWorker`)
are all `count = 1`, so a stall there is a 100% outage of that subsystem.
Exceptions 1–7 are all HTTP-side; Exception 8 was the first resident exception
in this register, and it was placed on its **own** dedicated `count = 1`
worker (`phlix-natpmp-maintenance`, `start.php`) precisely so its stall
costs nothing outside the NAT-PMP maintenance subsystem. Exceptions 9 and 10
are contained the same way, on the `count = 1` background-timers fork and the
`count = 1` `library-scan` managed worker respectively.

---

## Exception 1 — LDAP bind/search (`ext-ldap`)

| | |
|---|---|
| Site | `src/Plugins/Ldap/LdapConnection.php` — `createConnection()`, `createUserConnection()` |
| Reached from | `AuthManager::loginWithProvider()` (public login path, behind the per-IP brute-force throttle) and `POST /api/v1/admin/auth-providers/ldap/test` (`LdapAdminController::testConnection`) |
| Why it cannot be async | LdapRecord sits on `ext-ldap`, i.e. OpenLDAP's own C socket handling. **No Swoole runtime hook covers it — not even `SWOOLE_HOOK_ALL`.** There is no drop-in async LDAP client for PHP. |
| Bound | `timeout: 5` → `LDAP_OPT_NETWORK_TIMEOUT` (TCP connect) **and** `options[LDAP_OPT_TIMEOUT] = 5` (bind/search result wait). Constants: `LdapConnection::NETWORK_TIMEOUT_SECONDS`, `LdapConnection::OPERATION_TIMEOUT_SECONDS`. |
| Cost | ≤5 s of one HTTP worker per LDAP operation; ~10 s for a whole failed login (the chain issues two operations before short-circuiting). |

### The bound did not exist before S44-b

`LDAP_OPT_NETWORK_TIMEOUT` bounds the TCP *connect* only. Against a server that
accepts the connection and then never answers — a hung or half-open directory,
the common real failure — the operation wait was **unbounded**.

Measured against a TCP-accepting, never-answering listener on 127.0.0.1, in a
real Workerman worker under the Swoole event loop, with a sibling coroutine
appending a timestamp every 100 ms:

```text
# BEFORE (LDAP_OPT_NETWORK_TIMEOUT only)
tick t=102ms  ... tick t=919ms          <- 9 baseline ticks, scheduler healthy
LDAP-START t=1001ms withOpt=no
<no further ticks, no LDAP-END — killed at 30 s>

# AFTER (options[LDAP_OPT_TIMEOUT] = 5)
tick t=102ms  ... tick t=920ms
LDAP-START t=1001ms withOpt=yes
LDAP-END   t=6046ms took=5043ms LdapRecord\LdapRecordException: ldap_start_tls(): Unable to start TLS: Timed out
tick t=6046ms                            <- scheduler resumes immediately
```

Standalone (no worker), the pre-fix `LdapConnection::testConnection()`,
`findUserDn()` and `authenticate()` each ran >300 s without returning. Post-fix:
`testConnection()` 5 031 ms, `findUserDn()` 10 041 ms (it retries via
`searchForUserDn()` when a service bind DN is set → 2 operations),
`authenticate()` 10 039 ms, and the full `LdapProvider::authenticate()` login
chain 10 037 ms.

`LDAP_OPT_TIMEOUT` is passed through the `options` config key deliberately:
`LdapRecord\Connection::configure()` uses `options` as the **base** of an
`array_replace()` and only overrides `LDAP_OPT_PROTOCOL_VERSION`,
`LDAP_OPT_NETWORK_TIMEOUT` and `LDAP_OPT_REFERRALS`, so this key survives.

Regression guard: `tests/Unit/Plugins/Ldap/LdapConnectionTimeoutTest.php`.

---

## Exception 2 — https fetches routed to cURL by `EventLoopTls`

| | |
|---|---|
| Site | `src/Common/Http/EventLoopTls.php::requiresBlockingCurl()` and the `requestCurl()` fallback in `src/Plugins/OAuth2/OAuth2HttpClient.php` (inherited by `OidcHttpClient`), plus the same fallback in `Hub\HttpClient`, `Trakt\HttpClient`, `WebhookHttpClient`, `MetadataHttpClient`, `ArtworkStorage`, `S3Client`, `PluginCatalogService` |
| Why it exists | Client-side TLS under `Workerman\Events\Swoole` stalls after the handshake: the adapter epolls the raw fd and never sees bytes OpenSSL has already buffered, so every async https request dies on a read timeout. |
| Bound | `CURLOPT_TIMEOUT`, from the client's own `$timeout` (10 s default for `OidcHttpClient`/`OAuth2HttpClient`). `CURLOPT_CONNECTTIMEOUT` is set too but is a narrower duplicate — see below. |
| Cost | **≤10 s of one of the 14 HTTP workers**, on a control-plane call. Every coroutine on that process is frozen for the duration, not just the caller's connection. |

### Why the cost is stated as a stall when it currently is not one

Measured in a real worker today, this fetch does **not** freeze the process: a
sibling coroutine ticking every 100 ms recorded **99 of an expected 100** ticks
across a 10 s https fetch, in both the `onWorkerStart` coroutine and a child
coroutine created after the hook re-assert. Control in the same worker: a plain
http URL takes the async branch, 3 012 ms, 30 ticks, HTTP 200.

That yield is **not a design property and must not be relied on.** It happens
because the curated hook allowlist is not actually in force in the worker, which
is a latent defect, not a feature. When it is fixed, this call becomes a genuine
10 s freeze — so the register states the stall, and the guard bounds it.

### The curated hook mask does not reach the worker

This is a separate and larger issue than S44-b (see "The bound did not exist before S44-b" above); it is recorded here because it is
the only reason exception 2 is currently cheap.

`start.php` installs the curated allowlist (`SwooleRuntime::resolveHookFlags()`,
0x42fe, `SWOOLE_HOOK_NATIVE_CURL` absent) in the master. Per worker,
`Workerman\Events\Swoole::__construct()`
(`vendor/workerman/workerman/src/Events/Swoole.php:59`) runs
`Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])` and clobbers it. `start.php`
already anticipates this — `$applyCuratedCoroutineHooks()` re-asserts
the curated mask at the top of every `onWorkerStart`. (Coords rotated
2026-11-10 against this tip: closure `start.php:244-252`, seven arms at `:304`,
`:727`, `:962`, `:1027`, `:1094`, `:1297`, `:1614`; the pre-rotation citation
pointed at the closure at `:148-152` with six arms `:172`, `:549`, `:714`,
`:778`, `:816`, `:1014`.)

**The re-assert does not take effect.** Workerman runs `onWorkerStart` inside a
Swoole coroutine, and from there `Coroutine::set(['hook_flags' => …])` updates the
reported option but cannot un-swap handlers that are already installed. Isolated
A/B, 2 repeats, alternating order, both starting from hooks physically installed
as `SWOOLE_HOOK_ALL`:

```text
re-assert OUTSIDE any coroutine   REPORTED=0x42fe  curl=3003.7ms TICKS= 0  -> cURL unhooked (intended)
re-assert INSIDE a coroutine      REPORTED=0x42fe  curl=3006.2ms TICKS=29  -> cURL still HOOKED
```

⚠️ **Both report `0x42fe`.** Reading `Swoole\Coroutine::getOptions()['hook_flags']`
after the re-assert therefore **cannot** tell you whether it worked — the obvious
check is the one that lies. The only reliable probe is behavioral: run a blocking
call and see whether a sibling coroutine keeps ticking.

Consequence: the SIGSEGV mitigation the allowlist exists for — keeping the
FILE(io_uring) / PROC / CURL / blocking-function hooks off the PHP 8.5 /
Swoole 6.2.1 / kernel-7 io_uring stack — **is not in force in any worker.** That
needs its own step.

Confirmed in a production-shaped harness that reproduces `start.php`'s master
setup *and* the per-worker re-assert verbatim, 3 repeats each, alternating:

```text
MODE=prod     hook_flags BEFORE re-assert=0x7fbff7ff AFTER=0x42fe  (reported)
MODE=prod     onWorkerStart-coroutine  https  elapsed=10006.1ms SIBLING-TICKS=99
MODE=prod     child-coroutine          https  elapsed=10005.7ms SIBLING-TICKS=99
MODE=prod     child-coroutine CONTROL  http   elapsed= 3012.0ms SIBLING-TICKS=30  HTTP 200
```

### Which cURL option is the bound

`CURLOPT_TIMEOUT` is. `CURLOPT_CONNECTTIMEOUT` is a narrower duplicate: in
libcurl the connection phase includes the TLS handshake, so an `https://`
request to a silent peer is ended by `CURLOPT_CONNECTTIMEOUT` regardless.

This matters because it makes the obvious test vacuous. Deleting the
`curl_setopt($ch, CURLOPT_TIMEOUT, ...)` line and re-running an https-only
version of the guard left it **fully green** — the connect timeout was doing the
work. The same mutation against a plain `http://` peer (connection phase
completes, then cURL waits for a response body that never comes) hung past 60 s.
The guard therefore uses http for the load-bearing case. If you add a case here,
make sure it can still distinguish those two options.

Regression guards: `tests/Unit/Common/Http/EventLoopTlsTest.php` (routing
predicate) and `tests/Unit/Plugins/OAuth2/OAuth2HttpClientTimeoutTest.php`
(the cURL bound actually firing). The latter signals a lost bound by hanging,
not by failing.

### Correction (2026-11-10) — "that needs its own step" has since shipped

The sections above narrate the pre-fix world and stay verbatim as the record.
What changed:

- **The curated mask now reaches every worker.** `start.php` replaced the
  in-coroutine `Coroutine::set()` re-assert with `HookDelivery::enforceAndVerify()`
  (`src/Server/Runtime/HookDelivery.php`), called by `$applyCuratedCoroutineHooks()`
  at the top of every `onWorkerStart` (closure at `start.php:244-252`, seven arms
  at `:304`, `:727`, `:962`, `:1027`, `:1094`, `:1297`, `:1614`). The delivery does
  **not** trust the option read that this section proved lies: it installs via
  `Swoole\Runtime::enableCoroutine($flags)` (the full-mask *replacement* API, callable
  from inside a coroutine) and then runs a **behavioral probe** — a sibling
  coroutine ticking ~every 2 ms around a deliberately-stalling cURL call; a hooked
  cURL yields (dozens of ticks), an unhooked one does not. Failure throws
  `HookDeliveryException`, and every worker's start guard re-raises it loudly so
  the child dies at boot instead of running the crash-prone full mask — the
  background-timers fork's comment states the doctrine: "an undelivered hook
  allowlist is NOT best-effort" (`start.php:1038-1043`).
- **Consequence for this entry:** the yield the "Why the cost is stated as a
  stall when it currently is not one" section measured away is gone. The
  prediction made there materialized — this fetch **is** now the ≤10 s
  single-worker stall the Cost row states, and the `CURLOPT_TIMEOUT` bound is the
  live mitigation, not a paper one. Nothing else in this entry needs a rewrite:
  the bound, the guards, and the "which cURL option is the bound" note all
  describe the shipped shape.

---

## Exception 3 — SyncPlay write-through bridge publish (unix socket, S445)

| | |
|---|---|
| Site | `src/Session/SyncPlay/SyncPlayBridgePublisher.php::send()` — `stream_socket_client('unix://…')` + **one single** `fwrite()` |
| Reached from | the three `SyncPlayController` mutation rails (create / join / leave), only *after* the durable snapshot write, on an HTTP worker |
| Why it exists | S445 write-through publish: the REST side owns persistence and hands the mutated group to the single WS worker over a private unix socket (`docs/dev/SYNCPLAY_WRITE_THROUGH_BRIDGE.md`). The WS worker receives only — it never runs this write. |
| Bound | connect timeout `syncplay_bridge.publish_timeout_ms / 1000` (default **0.25 s**), and the write itself is structurally bounded: the frame is capped at `SyncPlayBridge::MAX_PUBLISH_FRAME_BYTES` (160 KiB) and sent in **one** `fwrite` — never chunked, never retried. |
| Cost | ≤0.25 s of one HTTP worker in the pathological case; measured 0.1 ms in the normal case, missing listener, oversized frame, and against a wedged never-draining listener. |

### Why a *single capped* write, not a write-until-done loop

Measured on this venue under `SWOOLE_HOOK_UNIX` (the curated allowlist includes
UNIX/UDG): on a fresh connection to a listener that accepts and then never
drains, one non-blocking `fwrite` absorbed **219 264 bytes** into kernel
buffers — and the *second* chunked `fwrite` blocked **forever**, despite
`stream_set_blocking(false)` and a `select()` reporting the stream writable.
A partial-write retry loop is therefore *unbounded* here, which is exactly what
this register forbids. Capping the frame below the measured single-write
absorption floor and writing it exactly once converts "retry until done" into
"done or refused, instantly": oversize is rejected before any syscall.

```text
normal frame → wedged listener : result=true  elapsed=0.1ms
219264B chunk 1 (of 400KB)     : absorbed whole, then chunk 2 blocked forever  ← why no chunking
oversized frame (200KB name)    : result=false elapsed=0.1ms  (refused, never sent)
no listener (missing file)      : result=false elapsed=0.1ms
```

A 160 KiB cap is ≈3.3× the measured worst legitimate frame (a `MAX_MEMBERS`-full
group serialize = 48 422 bytes), so legitimate publishes always fit under the
bound; a group whose frame would not fit is a defect surfaced by a warning, not
a silent stall. Loss posture (fire-and-forget, self-healing) is stated in the
bridge model doc; failure of this write **never** fails the REST response.

Regression guards: `tests/Unit/Session/SyncPlay/SyncPlayBridgeTest.php`
(including the wedged-listener anti-hang pair).

---

## Exception 4 — SSDP device-description fetch (H1, `stream_context` + `fopen`)

| | |
|---|---|
| Site | `src/Discovery/Ssdp/SsdpDiscovery.php::fetchOnceBounded()` — one `fopen()` + one capped `stream_get_contents()` per hop |
| Reached from | `GET /api/v1/dlna/renderers` (`RendererListController::listRenderers()` → `PlayToManager::discoverRenderers()` → `RendererDiscovery::getRendererDescription()`), synchronously per discovered device, on an HTTP worker |
| Why it is sync | Renderer discovery is an operator-triggered, authenticated, low-frequency listing of LAN devices; the fetch target is a LAN peer whose description is a few KB. Making it async would require threading a promise through the whole DLNA discovery family for a call that real devices answer in milliseconds. |
| Bound | stream `timeout = 5 s` (`FETCH_TIMEOUT_SECONDS`), `follow_location = 0` + a manual hop budget of 3 (`MAX_REDIRECT_HOPS`), and a hard read ceiling of 1 MiB (`MAX_RESPONSE_BYTES` — the read stops at cap + 1 so oversize is refused, not buffered). Additionally the target is gated BEFORE the socket to RFC1918-only addresses, so the peer is on the LAN segment, not an internet host under attacker pace. |
| Cost | Worst case 4 × 5 s of one HTTP worker against a hostile/throttling LAN peer that trickles under the byte cap; sub-second against real devices; zero against refused URLs (the gate refuses before any syscall). |

The `timeout` stream option bounds the connect **and** the gap between reads, not
the whole transfer — a drip attacker that sends one byte every 4 s within the
1 MiB ceiling is the pathological bound above. That is the accepted cost of
keeping the sync fetch; it is deliberately not unbounded like the pre-H1 code,
which followed redirects inside the wrapper with no hop budget and no size cap.

Regression guard: `tests/Unit/Discovery/Ssdp/SsdpDiscoverySsrfTest.php`.

---

## Exception 5 — IGD / NAT-PMP / STUN port-forward chain (H1(d)/H3/M4, raw sockets)

| | |
|---|---|
| Site | `src/Network/UpnpIgdClient.php` — `discoverGateway()` (UDP M-SEARCH + `socket_select` loop), `asyncHttpGet()` (Swoole client or blocking `fsockopen`), `soapRequest()` (`stream_socket_client`, plain or TLS); plus the `NatPmpClient` / `StunClient` legs the same call chain drives |
| Reached from | `POST /api/v1/admin/remote/portforward/{enable,disable}` and `GET …/status` (`AdminHubController::portForward*()`), synchronously via `PortForwardService::autoConfigure()` / `disable()` → `discoverGateway()` → `getExternalIp()` / `addPortMapping()` / `removePortMapping()`, on an HTTP worker |
| Why it is sync | One-shot, admin-triggered control-plane operation against the LAN router; converting the whole `Phlix\Network` socket family to promises would touch every leg (SSDP, SOAP, NAT-PMP, STUN) to accelerate a call no viewer request ever makes. |
| Bound | Bounded on every leg — the long pre-fix paragraph form of this cell outgrew the table; the same content, unedited, now reads itemized under **"The bound, itemized"** below the table (prose-currency pass 2026-11-10). |
| Cost | Zero against refused or spoofed targets (refused pre-socket); against a silent LAN router worst case ≈ the discovery budget (3 s) plus a bounded 5 s per follow-up — one of the 14 HTTP workers, only while an operator clicks port-forward. |

### The bound, itemized

(The table cell this list replaces was the same prose at the same tip, unwrapped
by the 2026-11-10 prose-currency pass; content unchanged.)

- **Discovery loop.** `discoverGateway()` is hard-bounded to `$timeout`
  (default 3000 ms) measured in **one** monotonic unit (`hrtime(true)` ms, H3 —
  the pre-fix code double-scaled elapsed-ms and fed `socket_select()` a negative
  timeout, busy-spinning one worker at 100% CPU for the whole window) with
  `socket_select` slices ≤ 500 ms, matching `NatPmpClient`.
- **Socket options.** `SO_RCVTIMEO`/`SO_SNDTIMEO` 3 s; every HTTP/SOAP socket
  5 s connect.
- **Pre-socket target gate (H1(d)).** The SSDP `LOCATION` must be an http(s)
  **literal IP equal to the datagram source** and every downstream fetch/SOAP
  target must be LAN-routable, enforced **before any socket** via
  `LanEndpointGuard`; `<controlURL>`s are same-host-pinned (`resolveUrl()`
  returns null for foreign absolutes).
- **TLS posture (M4).** The https SOAP branch verifies peer cert, name and SNI
  (self-signed IGD TLS is refused — the service falls back to NAT-PMP).
- **Reply source-pinning (L4).** NAT-PMP/STUN replies from a source other than
  the configured gateway/STUN host are discarded.
- **Bounded reads.** Reply reads in `asyncHttpGet()` (both arms) and
  `soapRequest()` go through `readResponseBounded()`: the same 1 MiB ceiling as
  the fetch paths (`LanEndpointGuard::MAX_RESPONSE_BYTES`) and a 5 s ceiling on
  the gap between chunks (`RESPONSE_IDLE_TIMEOUT_SECONDS`, mirroring
  `FETCH_TIMEOUT_SECONDS`); oversize or stalled reads fail loud (null).
- **Drip-attacker honesty.** As in Exception 4, the caps bound bytes and
  per-gap silence, not wall clock — a drip attacker that keeps a chunk arriving
  inside every 5 s gap under the 1 MiB ceiling is the pathological bound, never
  an unbounded read.

Regression guards: `tests/Unit/Network/UpnpIgdClientTest.php` (LOCATION source-pin
and cross-host `controlURL` refusals via reflection; bounded-read oversize abort,
short-reply passthrough and stalled-stream timeout via reflection on
`readResponseBounded()`),
`tests/Unit/Network/NatPmpClientTest.php` (wrong-source discard then correct-source
accept, forked-responder wire tests),
`tests/Unit/Network/StunClientTest.php` (wrong-source reject, correct-source accept,
forked-responder wire tests),
`tests/Unit/Common/Net/LanEndpointGuardTest.php`.

---

## Exception 6 — DLNA / HDHomeRun description fetch and renderer SOAP control (H1(a)-(c))

| | |
|---|---|
| Site | `src/Dlna/DeviceRegistry.php::fetchBounded()` (UDP-discovered `LOCATION` → `fopen` GET), `src/Dlna/RendererControlClient.php::sendSoapRequest()` (SOAP POST to the pinned control URL), `src/LiveTv/Tuners/HdHomeRun/HdHomeRunDiscovery.php::fetchBounded()` and `HdHomeRunApiClient::get()` (lineup fetch) |
| Reached from | SSDP device registration (discovery loops), DLNA renderer listing/playback, and the LiveTv HDHomeRun tuner scan — admin/device-triggered paths on HTTP workers, not the media-serving hot path |
| Why it is sync | LAN-peer description documents and SOAP frames are kilobyte-sized single request/response exchanges served by the same-segment device; same rationale the register already accepts for Exception 4. |
| Bound | Every `LOCATION` is **source-pinned**: the host must be a literal IP equal to the datagram source (`literalIpEqualsSource`) and not a refused address (loopback / `0.0.0.0` / link-local incl. `169.254.169.254` / CGNAT / multicast), enforced **before any socket**. Device descriptions and lineup JSON are fetched with `follow_location = 0` (`max_redirects = 1`) — no automatic redirect walking — with a 3–5 s stream timeout and a hard 1 MiB read ceiling (`LanEndpointGuard::MAX_RESPONSE_BYTES`, read stops at cap+1). `presentationURL`/`<controlURL>`/`<URLBase>` values are same-host-constrained (`sameHostAbsolute`); foreign absolutes are dropped at parse. SOAP replies are capped the same way; renderer URLs failing the LAN gate are rejected in the `RendererControlClient` constructor (fail-loud). `LIBXML_NONET` closes XXE-external-fetch on every `simplexml_load_string` in the touched family. |
| Cost | Zero against spoofed/off-segment targets (pre-socket refusal); worst case per accepted fetch is the per-site timeout (3–5 s, 10 s for the shipped SOAP control timeout) of one HTTP worker against a silent LAN device; a per-device loop, not a per-viewer-request path. |

Regression guards: `tests/Unit/Dlna/DeviceRegistrySsrfTest.php`,
`tests/Unit/LiveTv/Tuners/HdHomeRun/HdHomeRunDiscoverySsrfTest.php`,
`tests/Unit/Dlna/RendererControlClientLanGateTest.php` (constructor LAN-gate throws,
executed pre-socket — the legacy `RendererControlClientTest.php` is `@group network`
and needs a live renderer, so it guards nothing in the default suite),
`tests/Unit/Common/Net/LanEndpointGuardTest.php`.

---

## Exception 7 — admin-inline arbitrary-path music scan (`POST /api/v1/music/scan`)

| | |
|---|---|
| Site | `src/Server/WebPortal/WebPortalRouter.php::scanMusicDirectory()` → `MusicLibraryService::scanDirectory()` → `MusicLibraryScanner::scanDirectory()` — synchronous recursive tree walk + per-new-file tag/ffprobe work, inline on the request. |
| Reached from | `POST /api/v1/music/scan`, registered ONLY inside WebPortalRouter's `AdminMiddleware` group (M-1, security scan @9e765895). Before M-1 this sat behind bare `AuthMiddleware`, so ANY authenticated user could stall a worker on an arbitrary absolute path; the gate now rejects unauthenticated (401) and non-admin (403, audited) callers before the handler runs — a denied caller performs zero disk I/O (`tests/Unit/Server/WebPortal/MusicScanAdminGateTest.php`). |
| Why it is sync | The endpoint takes a raw operator path with no library context. The queue mechanisms that exist are shape-incompatible: `library_scan_jobs` requires `library_id NOT NULL` (FK) and `MaintenanceTask::MODE_QUEUED` covers only `storage-snapshot` and `dedupe-paths` (`MusicLibraryScanner` itself documents that this legacy path runs "WITHOUT a sink — there is no job row"). Building an arbitrary-path scan queue is NEW infrastructure, explicitly out of scope for M-1; the admin gate is the containment shipped instead. |
| Bound | **HONEST STATEMENT: there is no enforced timeout on this call.** Unlike Exceptions 1/4, the walk length is unbounded by construction (bounded only by the tree the admin names). What IS bounded: WHO may invoke it (admin role check, `users.is_admin`, pre-handler), HOW OFTEN (an operator action, not a viewer path — no client calls it on playback), and the failure evidence (non-admin attempts reach the audit log via `AdminMiddleware::checkAccess()`). The duration scale is measured upstream: `LibraryController::rescan()`'s S145 note records 9 h 55 m for the 61,111-track production rescan; this endpoint's incremental mode is the "minutes" side of that S145 comparison (post-S151 wall clock is UNMEASURED for the incremental path — stated, not assumed). |
| Cost | One of the 14 HTTP workers (`start.php`, `$httpListen['workerCount']`) is frozen for the whole scan — ~1/14 of HTTP capacity — for as long as an admin scans a tree. No WS/heartbeat/timer/relay worker is involved. Concurrent scans multiply the stall across workers, one per request. |

If a future step moves this route behind a queue (the honest long-term fix,
per this file's own rule that a timerless exception "is an unbounded stall with
a comment next to it"), this entry retires with it. Until then the entry exists
so the stall is REGISTERED rather than discovered: the M-1 scan finding was
about exposure to every user; the residual exposure is admin-triggered only.

Regression guards: `tests/Unit/Server/WebPortal/MusicScanAdminGateTest.php`
(gate + zero-disk-I/O-on-refusal), `tests/Unit/Server/WebPortal/WebPortalRouterWirePathGuardTest.php`
(route lives in the admin-conditional group and vanishes unwired).

---

## Exception 8 — resident NAT-PMP maintenance (`phlix-natpmp-maintenance` worker)

| | |
|---|---|
| Site | `src/Network/NatPmpMaintenanceWorker.php` — the persistent 60 s tick and the one-shot 15 s boot-catchup `Workerman\Timer` call `PortForwardService::renewOnce()` inline; the §3.2.1 announcement path `handleAnnouncement()` calls `PortForwardService::autoConfigure()` inline when a source-pinned announcement changes the announced external IP or regresses the gateway uptime stamp. The UDP receive side itself (Workerman `onMessage` on `udp://0.0.0.0:5350`, multicast-joined to `224.0.0.1`) is event-loop-native and never blocks. |
| Reached from | The dedicated `count = 1` worker `phlix-natpmp-maintenance` (`start.php`, §4f-bis — spawned only when `config/port-forward.php` still asks for port forwarding; effective admin overrides are re-read inside the worker via the container). Renewal runs ONLY when due: the persisted `mapping_renew_at` deadline (half the GRANTED lease, DHCP-style, RFC 6886 §3.3) gates every tick through the pure `NatPmpMaintenance::plan()`, and `disable()` nulls the deadline so the tick stops without resurrecting a deleted mapping. Reconfigure runs ONLY on a genuine announcement change and is commit-before-act deduped: the first packet adopts the new baseline synchronously, every retransmit of the same announcement settles to `observed-unchanged` with zero I/O. |
| Why it is sync | The `Phlix\Network` client family is already the registered sync set of Exception 5; maintenance moves those same bounded exchanges OFF the 14 HTTP workers onto a worker whose only job is this subsystem — the daemon previously had NO resident owner for lease renewal (requests were admin-click-only), so the alternative to this entry was mappings silently aging out, not a non-blocking implementation. Duplicating four socket clients as promise-based variants to serve a once-per-half-lease tick is exactly the "accelerate a call no viewer request ever makes" cost Exception 5 declined. |
| Bound | Every leg inherits its Exception 5 bound: NAT-PMP exchanges hard-stop at the ctor `$timeout` (default **3000 ms**) tracked in monotonic `hrtime` with `socket_select` slices ≤ 500 ms, replies source-pinned to the gateway; STUN binding select 3 s; IGD M-SEARCH 3 s with `LanEndpointGuard` pre-socket refusals and 1 MiB / 5 s-idle bounded follow-ups. Failures park `mapping_retry_at` on an exponential backoff — base 60 s, doubling per consecutive failure, capped at **900 s** (`NatPmpMaintenance::nextRetryDelay`) — so a persistently silent gateway costs at most ONE bounded chain per backoff slot instead of hot-looping every 60 s. Announcements arriving during a renewal stall queue in the kernel socket buffer and are processed next loop turn; a bind failure at boot degrades to renewal-only mode with one loud log line and never crashes the worker. |
| Cost | Measured 2026-10-01 on the dev host against a silent-but-reachable peer (loopback, no responder): `discoverGateway` **3004 ms** + `addPortMapping` **3005 ms** → renewal chain ≈ **6.1 s** worst case, at most once per half-lease deadline (default 7200 s lease → every ~1 h, or every 15 min at the smallest honest half-lease), plus a single boot-catchup attempt. An ICMP-refused / unroutable gateway short-circuits `sendto` in ≈ 1 ms (measured). The announcement-triggered full cascade is IGD 3 s + NAT-PMP 6 s + STUN 3 s ≈ **12 s** worst case with every leg silent — and only ever on a real gateway IP change, which is by nature rare (reboot / re-assign). Blast radius: the dedicated worker only; zero HTTP/WS/heartbeat capacity is touched. Non-due ticks are pure state reads (µs). |

Regression guards: `tests/Unit/Network/NatPmpMaintenanceTest.php` (plan
precedence, announcement parser, change detection, backoff curve),
`tests/Unit/Network/NatPmpMaintenanceWorkerTest.php` (timer set exactly
{15 one-shot, 60 persistent}, double-arm idempotence, tick matrix against real
state files, source filter, commit-first dedup, bind-failure degrade),
`tests/Unit/Network/NatPmpAnnouncementWireTest.php` (loopback wire test incl. a
real `224.0.0.1` multicast join → production listener receive, gated on a
pre-built raw-socket round-trip probe).

---

## Exception 9 — the background-timers fork (registered 2026-11-10, prose-currency pass)

| | |
|---|---|
| Site | `start.php:1013-1052` — the `count = 1` worker named `phlix-background-timers`; its body is `Application::startBackgroundTimers()` (`src/Server/Core/Application.php:3070`), which arms, in order: the newsletter sweep (`registerNewsletterTimer` `:2953` — a 60 s due-gated, per-sweep-capped campaign sweep whose send legs do SMTP/file IO; the older unbounded `while (getPendingCount() > 0)` drain that first motivated this entry was itself fixed before this line was written, so the current shape is what is registered here), scheduled backups (`startBackupTimerIfEnabled` `:3277` — archive work inline in the callback), the storage-snapshot timer (`startStorageSnapshotTimer` `:3403`, which "shells out to `du -sb` per vault bucket" per `:3168`), the transcode stale-job reaper (`:3439`, default check every 45 s), the core update check (`:3231`, which arms the `CoreUpdateCheckWorker` fetch legs), the maintenance-queue drain (`startMaintenanceQueueTimer` `:3183` → `src/Admin/Maintenance/MaintenanceQueueWorker.php`: `POLL_SECONDS = 5` (`:52`), `Timer::add` (`:93`), `drain()` (`:107`) — and the queue's own docblock (`:33`) names the blocking profile: `du -sb` children and whole-`media_items` scans, which is exactly why the HTTP side only ENQUEUES (`Application.php:3097`)), and the hourly stats-retention sweep (`:3134`, batched DELETEs, `STATS_RETENTION_SWEEP_INTERVAL = 3600`). |
| Reached from | No request path. Timers fire on the fork's own schedule; the fork is spawned unconditionally next to the HTTP/WS workers (`start.php:1013`). |
| Why it is sync | These tasks are subprocess-bound (`du -sb`, archive writers) or whole-table by nature (`media_items` walks). The design answer is not an async rewrite — it is **containment**: they already run on a dedicated `count = 1` process whose only job is this subsystem. |
| Bound | Subprocess legs are bounded by their own work and the single-runner property (`count = 1` makes "one maintenance job at a time" true); the queue drain is poll-bounded (5 s cadence, one drain per tick) and boot-reaps exhausted-attempt rows so a wedged job cannot spin the queue; the newsletter leg is due-gated with a per-sweep send cap and a 60 s sweep period; the reaper/sweep legs are short batched DB work whose MySQL legs ride the hooked TCP path. The residual unbounded shape is honestly stated where it exists: a maintenance job that runs long (a whole-vault `du -sb` on a big library) delays every other timer on this fork for its duration — including the next `du -sb`. |
| Cost | **Bounded blast radius by construction: the fork is not on any latency-critical path.** Nothing shares it — no HTTP worker, no WS/SyncPlay worker, no heartbeat or relay tunnel process. A stall here costs freshness of backups / storage stats / queue latency / newsletter sends, never playback or API availability. That containment is the exception's whole justification, and it is why the fork's start body re-raises `HookDeliveryException` loudly (`start.php:1038-1043`) while treating *everything else* as best-effort: the timers degrade silently by design, but running them under the crash-prone full hook mask is not a degradation the fork may accept. |

---

## Exception 10 — OMDb plugin https lookups routed to blocking cURL (registered 2026-11-12, OMB-6)

| | |
|---|---|
| Site | The vendored plugin `detain/phlix-plugin-omdb`, `src/OmdbApi.php` (not in this tree): `httpGetJson()` reroutes a cache miss to `httpGetJsonCurl()` → `performCurlRequest()` (one `curl_exec()`) whenever `requiresBlockingCurl()` is true — an `https://` URL **and** `Worker::$eventLoopClass === 'Workerman\Events\Swoole'`. That predicate is a string-compare mirror of `Phlix\Common\Http\EventLoopTls::requiresBlockingCurl()`; the plugin cannot import a server class, so it restates the comparison. Every OMDb URL is https, so under the production adapter **every** OMDb cache miss takes this arm. |
| Reached from | The `library-scan` managed worker only: `BackgroundEnrichmentSubscriber::drainTick()` (a re-arming 1 s `Workerman\Timer`, wired in the library-scan fork at `start.php:1375`) → `PluginMetadataEnricher` → `PluginSourceConsultation::consult()` → `OmdbPlugin::search()` (`OmdbApi::search()`) and then, only on a hit, `OmdbPlugin::getDetails()` (`OmdbApi::getByImdbId()`). Dispatch is due-gated by `SourceRateLimiter` at `config/metadata.php` `background_enrichment.source_intervals.omdb = 90` (s). The HTTP workers bootstrap enabled plugins too (`start.php:370`), so the source is *registered* there, but no HTTP-side path consults it: the resolvers' `includePluginSources` opt-in has no production caller. If one is ever added, this entry's Cost row is void and must be re-costed against the 14 HTTP workers before it ships. |
| Why it is sync | Exception 2's reason, inherited: under `Workerman\Events\Swoole` the plugin's async `workerman/http-client` arm stalls after the TLS handshake and never completes, so the async arm is structurally dead in production. A bounded blocking fetch on a quota-capped, 90 s-paced enrichment call beats a request that can never complete. |
| Bound | `CURLOPT_TIMEOUT => HTTP_TIMEOUT_SEC` (**10 s**). `CURLOPT_CONNECTTIMEOUT` is set to the same 10 s and is the narrower duplicate described under Exception 2. `CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS = CURLPROTO_HTTPS` and `CURLOPT_FOLLOWLOCATION = false` keep the key-bearing request on one https hop. The bound was measured firing (below). |
| Cost | **≤ 10 s of the `library-scan` worker per request**, ≈ 20 s worst case per enriched item (a `search()` that answers just inside its bound, then a `getDetails()` that stalls; a stalled `search()` returns no hit, so `getDetails()` never dispatches and the item costs 10 s). OMDb is consulted at most once per 90 s. The plugin's own 0.25 s inter-request spacing (`RATE_LIMIT_INTERVAL_SEC`) goes through `Timer::sleep()`, which is cooperative on this repo's Workerman 5. While the key's daily-limit cooldown is armed, cache misses short-circuit with zero I/O. Blast radius: the `library-scan` worker (`config/process.php`, `count = 1`, curated hook mask applied at `start.php:1316`, so cURL is unhooked and the stall is real). Scan-job progress, `MediaItemAdded` dispatch and the smart-collection refresh on that worker pause for the stall. Zero HTTP, WS, heartbeat, timer-fork or relay capacity is touched. |

### Measurement (2026-11-12, birth certificate)

Taken on the dev host with PHP 8.3.6 CLI and libcurl 8.5.0, against the plugin at
`baf389fe`. The probe called the real `OmdbApi::performCurlRequest()` by reflection,
with TLS verification off so a self-signed loopback peer was reachable. Two
silent-peer shapes were tested, twice each:

```text
A  TLS peer, handshake completes then silent (openssl s_server, request received)
   elapsed=10003.1ms  "Operation timed out after 10002 milliseconds with 0 bytes received"
   elapsed=10002.8ms  "Operation timed out after 10002 milliseconds with 0 bytes received"
B  TCP peer that never accepts / never handshakes
   elapsed=10002.7ms  "Connection timed out after 10002 milliseconds"
   elapsed=10002.5ms  "Connection timed out after 10002 milliseconds"
```

Shape A is the load-bearing case. With the same option set **minus**
`CURLOPT_TIMEOUT`, the shape-A request was still hanging when it was killed at
30 s (exit 124). The same mutant against shape B ended at 10 003 ms, because the
connect timeout covers the handshake. As in Exception 2, only a peer that
completes the handshake can show the real bound working.

Regression guards (plugin-side, `tests/Unit/OmdbApiTest.php`):
`test_swoole_loop_reroutes_https_dispatch_to_the_blocking_curl_transport`
(routing), `test_default_event_loop_still_uses_the_async_transport_and_never_curl`
(no cURL off the Swoole loop), and
`test_curl_fallback_pins_https_only_bounded_verification_honoring_options` (a
source pin on the `CURLOPT_TIMEOUT`/`CONNECTTIMEOUT`/protocol/TLS options). No
guard in either tree shows the bound firing. The measurement above is the
evidence for that, and it should be taken again whenever `performCurlRequest()`
changes.

---

## Not on this list

Everything else. In particular, do not add an entry for a call you have not
measured. Exceptions 1 and 2 were both wrong in their own source comments
before they were measured: Exception 1 claimed a bound it did not have, and
Exception 2 claimed a stall it does not cause. Exception 3 was measured before
it was written down — the numbers in its table are its birth certificate.
