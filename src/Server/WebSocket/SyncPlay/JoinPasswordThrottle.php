<?php

/**
 * Phlix media server component: WebSocket.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\WebSocket\SyncPlay;

/**
 * R17-04: per-connection sliding-window throttle for FAILED group-join
 * password attempts on the SyncPlay WebSocket.
 *
 * A password-protected room is a brute-force target: without a limit, a
 * single authenticated socket can try candidate digests back-to-back as
 * fast as it can write frames. This throttle counts only FAILED join
 * attempts per connection id inside a fixed window; when the count
 * exceeds the ceiling the caller reject-closes the socket (mirroring the
 * inline-close discipline of the WS_CONNECT handshake limiter in
 * {@see \Phlix\Server\WebSocket\WebSocketServer::onWebSocketConnect()} —
 * the connection is closed, never merely warned).
 *
 * Deliberately in-memory and process-local: the `:8097` WS worker runs
 * `count=1`, so per-worker state IS server-wide state for this socket,
 * the same rationale as the WS_CONNECT limiter. A successful join (or a
 * connection close) resets the window so honest re-joins stay free.
 *
 * Pure logic — no clock, socket, or global reads inside; the caller
 * supplies the timestamp, which keeps the window arithmetic directly
 * testable.
 */
final class JoinPasswordThrottle
{
    /** Failed attempts allowed per window before the socket is reject-closed. */
    public const MAX_FAILED_ATTEMPTS_PER_WINDOW = 10;

    /** Sliding window length in seconds. */
    public const WINDOW_SECONDS = 60;

    /** @var array<string, list<int>> connection id -> failure timestamps (ascending by arrival) */
    private array $failures = [];

    private readonly int $maxAttempts;

    private readonly int $windowSeconds;

    public function __construct(
        int $maxAttempts = self::MAX_FAILED_ATTEMPTS_PER_WINDOW,
        int $windowSeconds = self::WINDOW_SECONDS,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1');
        }
        if ($windowSeconds < 1) {
            throw new \InvalidArgumentException('windowSeconds must be >= 1');
        }
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Records one failed password attempt for a connection at `$now`.
     *
     * @return bool True when the connection has now EXCEEDED the ceiling for
     *               the window — the caller must reject-close it.
     */
    public function recordFailure(string $connectionId, int $now): bool
    {
        $recent = [];
        foreach ($this->failures[$connectionId] ?? [] as $timestamp) {
            if ($timestamp > $now - $this->windowSeconds) {
                $recent[] = $timestamp;
            }
        }
        $recent[] = $now;
        $this->failures[$connectionId] = $recent;

        return count($recent) > $this->maxAttempts;
    }

    /**
     * Clears the failure window for a connection (successful join, or the
     * connection closing — the latter keeps the map bounded).
     */
    public function reset(string $connectionId): void
    {
        unset($this->failures[$connectionId]);
    }
}
