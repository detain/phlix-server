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
| Bound | `CURLOPT_TIMEOUT` + `CURLOPT_CONNECTTIMEOUT`, both from the client's own `$timeout` (10 s default for `OidcHttpClient`/`OAuth2HttpClient`). |
| Cost | In production: **none** — see below; the call yields. If the vendor override below ever disappears: ≤10 s of one of the 14 HTTP workers, on a control-plane call. |

### Correction: this fallback does **not** block in production

`start.php` installs a curated coroutine hook allowlist that deliberately drops
`SWOOLE_HOOK_NATIVE_CURL`. Reasoning from that alone says every https OIDC
discovery/token/userinfo/JWKS call freezes the worker. **Measurement says
otherwise**, because `Workerman\Events\Swoole::__construct()` (vendor/workerman/
workerman/src/Events/Swoole.php:59) runs

```php
Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
```

per worker — after `start.php` has set the curated mask in the master. The mask
actually in force inside the worker is therefore `SWOOLE_HOOK_ALL`, and native
cURL is hooked.

A/B inside a real Workerman worker, alternating order, 3 repeats each, same
https URL against a never-answering listener:

```
MODE=asis     IN-FORCE=0x7fbff7ff (SWOOLE_HOOK_ALL) NATIVE_CURL=HOOKED
MODE=asis     elapsed=10005.4ms resp=null SIBLING-TICKS=99   <- worker kept scheduling
MODE=curated  IN-FORCE=0x42fe                       NATIVE_CURL=unhooked
MODE=curated  elapsed=10004.6ms resp=null SIBLING-TICKS=0    <- worker frozen 10 s
```

Two things follow. First, the exception is real but cheap: it is bounded at 10 s
by `CURLOPT_TIMEOUT` (every run landed within 10 ms of it) and today it yields.
Second — and this is a separate, larger issue than S44-b — **`SwooleRuntime`'s
curated allowlist is dead inside the worker**: the SIGSEGV mitigation that mask
exists for (dropping FILE/PROC/CURL/STDIO on the PHP 8.5 / Swoole 6.2.1 /
io_uring stack) is silently reverted by the event-loop constructor. That deserves
its own step; it is recorded here because it is what makes this exception cheap.

Regression guards: `tests/Unit/Common/Http/EventLoopTlsTest.php` (routing
predicate) and `tests/Unit/Plugins/OAuth2/OAuth2HttpClientTimeoutTest.php`
(the cURL bound actually firing).

---

## Not on this list

Everything else. In particular, do not add an entry for a call you have not
measured. The two entries above were both wrong in their own source comments
before they were measured: Exception 1 claimed a bound it did not have, and
Exception 2 claimed a stall it does not cause.
