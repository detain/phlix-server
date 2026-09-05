<?php

/**
 * Phlix media server component: Common HTTP.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Http;

use Workerman\Events\Swoole;
use Workerman\Worker;

/**
 * Detects the async-client/TLS combination that is broken under the Swoole
 * event loop so HTTP clients can fall back to blocking cURL.
 *
 * Under `Worker::$eventLoopClass = Workerman\Events\Swoole`, client-side TLS
 * streams (`AsyncTcpConnection` with the `ssl` transport, which
 * `workerman/http-client` uses for https URLs) stall after the handshake:
 * Swoole\Event epolls the raw fd and never sees response bytes that OpenSSL
 * has already buffered internally, so the read callback never fires and every
 * request dies with "read <addr> timeout after N seconds". PHP's own
 * stream_select (used by the default Fiber/Select event loop) special-cases
 * TLS-buffered data, which is why the same code works there.
 *
 * Until the event adapter is fixed upstream, https requests made while the
 * Swoole event loop drives the process must use cURL instead.
 *
 * ## Accepted blocking-I/O exception #2 (S44-b)
 *
 * Treat this call as a **bounded ≤10 s stall of one of the 14 HTTP workers**
 * ({@see \Workerman\Worker}, `start.php:169`), on a low-frequency control-plane
 * request. A stalled worker freezes every coroutine on that process, not just
 * the caller's connection. The bound is `CURLOPT_TIMEOUT`, taken from the
 * client's own timeout (10 s by default for
 * {@see \Phlix\Plugins\OAuth2\OAuth2HttpClient}); measured, it fires to within
 * 10 ms. This is a **named, registered exception**; see
 * `docs/dev/BLOCKING_IO_EXCEPTIONS.md`.
 *
 * ⚠️ **Do not "optimise" this on the grounds that cURL is currently hooked.**
 * Measured in a real worker today, the fetch does NOT freeze the process — a
 * sibling coroutine ticking every 100 ms recorded 99 of an expected 100 ticks
 * across a 10 s fetch, in both the `onWorkerStart` coroutine and a child
 * coroutine. That is **not** by design. It happens because the curated hook
 * allowlist is not actually in force: `Workerman\Events\Swoole::__construct()`
 * installs `SWOOLE_HOOK_ALL` per worker, and `start.php`'s remedy
 * (`$applyCuratedCoroutineHooks()`, called at the top of each `onWorkerStart`)
 * runs from INSIDE the worker coroutine, where
 * `Swoole\Coroutine::set(['hook_flags' => …])` updates the reported option but
 * cannot un-swap the already-installed handlers. So
 * `Coroutine::getOptions()['hook_flags']` reports the curated 0x42fe while
 * `SWOOLE_HOOK_NATIVE_CURL` is still physically hooked. Isolated A/B, 2 repeats,
 * alternating: re-assert OUTSIDE a coroutine → 0 sibling ticks (unhooked, the
 * intended state); re-assert INSIDE a coroutine → 29 ticks (still hooked), with
 * BOTH reporting 0x42fe.
 *
 * Whenever that is fixed — and it should be, the mask exists to keep the
 * io_uring/proc/curl hooks off a stack where they caused repeated SIGSEGVs —
 * this call becomes a genuine 10 s freeze. The exception is written to be true
 * either way: bounded, never unbounded. Do not build on the yield.
 *
 * S433 has since closed that gap: the per-worker remedy now re-asserts via
 * `Swoole\Runtime::enableCoroutine()` — the full-mask replacement API that
 * physically un-swaps installed handlers, unlike the `Coroutine::set()` the
 * old remedy used — and proves delivery with a behavioural sibling-tick probe
 * before the container is built ({@see \Phlix\Server\Runtime\HookDelivery}).
 * On a delivered worker the yield above is gone and this call is exactly what
 * its registered exception says it is: a genuine, bounded stall.
 *
 * @package Phlix\Common\Http
 * @since 0.15.0
 */
final class EventLoopTls
{
    /**
     * Returns true when the given URL must be fetched with blocking cURL
     * because the async client cannot complete TLS reads under the current
     * event loop.
     *
     * @param string $url Absolute request URL.
     */
    public static function requiresBlockingCurl(string $url): bool
    {
        if (stripos($url, 'https://') !== 0) {
            return false;
        }
        return Worker::$eventLoopClass === Swoole::class;
    }
}
