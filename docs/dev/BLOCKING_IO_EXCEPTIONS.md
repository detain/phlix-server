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
subsystem. Neither exception below runs in those workers.

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

## Not on this list

Everything else. In particular, do not add an entry for a call you have not
measured. The two entries above were both wrong in their own source comments
before they were measured: Exception 1 claimed a bound it did not have, and
Exception 2 claimed a stall it does not cause.
