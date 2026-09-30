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

`start.php:169` sets `$httpWorker->count = 14`. Workerman/Swoole runs many
coroutines per worker process, so a blocking syscall freezes **every connection
that worker is currently serving**, not just the caller's — one worker is ~1/14
of HTTP capacity. The WS worker (`start.php:546`), the hub-heartbeat worker
(`:711`), the background-timer worker (`:765`) and the relay-tunnel worker
(`:813`) are all `count = 1`, so a stall there is a 100 % outage of that
subsystem. No exception below runs in those workers — every listed exception is
HTTP-side.

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

```
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

This is a separate and larger issue than S44-b; it is recorded here because it is
the only reason exception 2 is currently cheap.

`start.php` installs the curated allowlist (`SwooleRuntime::resolveHookFlags()`,
0x42fe, `SWOOLE_HOOK_NATIVE_CURL` absent) in the master. Per worker,
`Workerman\Events\Swoole::__construct()`
(`vendor/workerman/workerman/src/Events/Swoole.php:59`) runs
`Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])` and clobbers it. `start.php`
already anticipates this — `$applyCuratedCoroutineHooks()` (`:148-152`) re-asserts
the curated mask at the top of every `onWorkerStart` (`:172`, `:549`, `:714`,
`:778`, `:816`, `:1014`).

**The re-assert does not take effect.** Workerman runs `onWorkerStart` inside a
Swoole coroutine, and from there `Coroutine::set(['hook_flags' => …])` updates the
reported option but cannot un-swap handlers that are already installed. Isolated
A/B, 2 repeats, alternating order, both starting from hooks physically installed
as `SWOOLE_HOOK_ALL`:

```
re-assert OUTSIDE any coroutine   REPORTED=0x42fe  curl=3003.7ms TICKS= 0  -> cURL unhooked (intended)
re-assert INSIDE a coroutine      REPORTED=0x42fe  curl=3006.2ms TICKS=29  -> cURL still HOOKED
```

⚠️ **Both report `0x42fe`.** Reading `Swoole\Coroutine::getOptions()['hook_flags']`
after the re-assert therefore **cannot** tell you whether it worked — the obvious
check is the one that lies. The only reliable probe is behavioural: run a blocking
call and see whether a sibling coroutine keeps ticking.

Consequence: the SIGSEGV mitigation the allowlist exists for — keeping the
FILE(io_uring) / PROC / CURL / blocking-function hooks off the PHP 8.5 /
Swoole 6.2.1 / kernel-7 io_uring stack — **is not in force in any worker.** That
needs its own step.

Confirmed in a production-shaped harness that reproduces `start.php`'s master
setup *and* the per-worker re-assert verbatim, 3 repeats each, alternating:

```
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

```
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
| Bound | `discoverGateway()` is hard-bounded to `$timeout` (default 3000 ms) measured in **one** monotonic unit (`hrtime(true)` ms, H3 — the pre-fix code double-scaled elapsed-ms and fed `socket_select()` a negative timeout, busy-spinning one worker at 100 % CPU for the whole window) with `socket_select` slices ≤ 500 ms, matching `NatPmpClient`; `SO_RCVTIMEO`/`SO_SNDTIMEO` 3 s; every HTTP/SOAP socket 5 s connect. H1(d): the SSDP `LOCATION` must be an http(s) **literal IP equal to the datagram source** and every downstream fetch/SOAP target must be LAN-routable, enforced **before any socket** via `LanEndpointGuard`; `<controlURL>`s are same-host-pinned (`resolveUrl()` returns null for foreign absolutes); M4: the https SOAP branch verifies peer cert, name and SNI (self-signed IGD TLS is refused — the service falls back to NAT-PMP). L4: NAT-PMP/STUN replies from a source other than the configured gateway/STUN host are discarded. Reply reads in `asyncHttpGet()` (both arms) and `soapRequest()` go through `readResponseBounded()`: the same 1 MiB ceiling as the fetch paths (`LanEndpointGuard::MAX_RESPONSE_BYTES`) and a 5 s ceiling on the gap between chunks (`RESPONSE_IDLE_TIMEOUT_SECONDS`, mirroring `FETCH_TIMEOUT_SECONDS`); oversize or stalled reads fail loud (null). As in Exception 4, the caps bound bytes and per-gap silence, not wall clock — a drip attacker that keeps a chunk arriving inside every 5 s gap under the 1 MiB ceiling is the pathological bound, never an unbounded read. |
| Cost | Zero against refused or spoofed targets (refused pre-socket); against a silent LAN router worst case ≈ the discovery budget (3 s) plus a bounded 5 s per follow-up — one of the 14 HTTP workers, only while an operator clicks port-forward. |

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
| Site | `src/Server/WebPortal/WebPortalRouter.php::scanMusicDirectory()` → `MusicLibraryService::scanDirectory()` → `MusicLibraryScanner::scanDirectory()` — synchronous recursive tree walk + per-new-file tag/ffprobe work, inline on the request |
| Reached from | `POST /api/v1/music/scan`, registered ONLY inside WebPortalRouter's `AdminMiddleware` group (M-1, security scan @9e765895). Before M-1 this sat behind bare `AuthMiddleware`, so ANY authenticated user could stall a worker on an arbitrary absolute path; the gate now rejects unauthenticated (401) and non-admin (403, audited) callers before the handler runs — a denied caller performs zero disk I/O (`tests/Unit/Server/WebPortal/MusicScanAdminGateTest.php`) |
| Why it is sync | The endpoint takes a raw operator path with no library context. The queue mechanisms that exist are shape-incompatible: `library_scan_jobs` requires `library_id NOT NULL` (FK) and `MaintenanceTask::MODE_QUEUED` covers only `storage-snapshot` and `dedupe-paths` (`MusicLibraryScanner` itself documents that this legacy path runs "WITHOUT a sink — there is no job row"). Building an arbitrary-path scan queue is NEW infrastructure, explicitly out of scope for M-1; the admin gate is the containment shipped instead |
| Bound | **HONEST STATEMENT: there is no enforced timeout on this call.** Unlike Exceptions 1/4, the walk length is unbounded by construction (bounded only by the tree the admin names). What IS bounded: WHO may invoke it (admin role check, `users.is_admin`, pre-handler), HOW OFTEN (an operator action, not a viewer path — no client calls it on playback), and the failure evidence (non-admin attempts reach the audit log via `AdminMiddleware::checkAccess()`). The duration scale is measured upstream: `LibraryController::rescan()`'s S145 note records 9 h 55 m for the 61,111-track production rescan; this endpoint's incremental mode is the "minutes" side of that S145 comparison (post-S151 wall clock is UNMEASURED for the incremental path — stated, not assumed) |
| Cost | One of the 14 HTTP workers (`start.php:169`, `count = 14`) is frozen for the whole scan — ~1/14 of HTTP capacity — for as long as an admin scans a tree. No WS/heartbeat/timer/relay worker is involved. Concurrent scans multiply the stall across workers, one per request |

If a future step moves this route behind a queue (the honest long-term fix,
per this file's own rule that a timerless exception "is an unbounded stall with
a comment next to it"), this entry retires with it. Until then the entry exists
so the stall is REGISTERED rather than discovered: the M-1 scan finding was
about exposure to every user; the residual exposure is admin-triggered only.

Regression guards: `tests/Unit/Server/WebPortal/MusicScanAdminGateTest.php`
(gate + zero-disk-I/O-on-refusal), `tests/Unit/Server/WebPortal/WebPortalRouterWirePathGuardTest.php`
(route lives in the admin-conditional group and vanishes unwired).

---

## Not on this list

Everything else. In particular, do not add an entry for a call you have not
measured. Exceptions 1 and 2 were both wrong in their own source comments
before they were measured: Exception 1 claimed a bound it did not have, and
Exception 2 claimed a stall it does not cause. Exception 3 was measured before
it was written down — the numbers in its table are its birth certificate.
