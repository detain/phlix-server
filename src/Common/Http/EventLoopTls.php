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
 * ## Accepted blocking-I/O exception #2 (S44-b) — and a correction
 *
 * This docblock used to assert that the cURL fallback "runs as a plain blocking
 * call" because cURL is excluded from the curated hook mask
 * ({@see \Phlix\Server\Runtime\SwooleRuntime}). **Measured
 * inside a real Workerman worker, that is false.**
 * `Workerman\Events\Swoole::__construct()` executes
 * `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])`, which runs per worker —
 * i.e. AFTER `start.php` installs the curated allowlist in the master — so the
 * mask actually in force in the worker is `SWOOLE_HOOK_ALL` (0x7fbff7ff), not
 * the curated 0x42fe, and `SWOOLE_HOOK_NATIVE_CURL` IS set.
 *
 * A/B measurement, same worker, same https URL against a server that accepts and
 * never answers, with a sibling coroutine ticking every 100 ms:
 *
 *  - mask as Workerman leaves it (`SWOOLE_HOOK_ALL`): fetch returned at 10 006 ms,
 *    sibling ticked **99** times → the worker kept scheduling; the fetch YIELDS.
 *  - curated mask re-applied in `onWorkerStart`: fetch returned at 10 005 ms,
 *    sibling ticked **0** times → the worker was frozen for the full 10 s.
 *
 * Either way the stall is BOUNDED: both `CURLOPT_TIMEOUT` and
 * `CURLOPT_CONNECTTIMEOUT` are set from the client's own timeout (10 s by
 * default), and the measurements land on it to within 10 ms. So the worst case
 * — if the vendor override is ever removed — is a bounded ≤10 s stall of one of
 * the 14 HTTP workers on a low-frequency control-plane call, never an unbounded
 * one. This is a **named, registered exception**; see
 * `docs/dev/BLOCKING_IO_EXCEPTIONS.md`.
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
