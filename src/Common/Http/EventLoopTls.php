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
 * Swoole event loop drives the process must use blocking cURL instead. cURL
 * is deliberately excluded from the curated coroutine hook mask
 * ({@see \Phlix\Server\Runtime\SwooleRuntime}), so it runs as a plain
 * blocking call — acceptable for the low-frequency control-plane and
 * metadata requests these clients serve.
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
