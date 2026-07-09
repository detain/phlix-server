<?php

/**
 * Phlix media server component: Metrics.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Stats\Metrics;

/**
 * Thin, per-worker façade over {@see MetricsRegistry}.
 *
 * This is the single object injected into the request / connection hooks (wired
 * in step S2) and shared with {@see MetricsFlushService}. Every recording method
 * no-ops when the subsystem is disabled (`config.metrics.enabled = false`) so the
 * hot request path carries zero overhead when metrics are off.
 *
 * Clock design: the collector owns "now". It resolves the current Unix timestamp
 * through an injected callable (defaulting to `time()`) and passes it down to the
 * clock-free registry. Callers therefore never deal with timestamps, yet tests
 * can inject a deterministic clock. The registry itself remains fully
 * deterministic (no internal clock calls).
 *
 * @package Phlix\Stats\Metrics
 * @since S1
 */
final class MetricsCollector
{
    /** @var MetricsRegistry Per-worker in-memory accumulator. */
    private MetricsRegistry $registry;

    /** @var bool Whether recording is active. */
    private bool $enabled;

    /** @var callable(): int Returns the current Unix timestamp. */
    private $clock;

    /**
     * @param MetricsRegistry     $registry In-memory accumulator for this worker.
     * @param bool                $enabled  Master on/off switch (config.metrics.enabled).
     * @param (callable(): int)|null $clock  Optional clock override; defaults to time().
     */
    public function __construct(MetricsRegistry $registry, bool $enabled = true, ?callable $clock = null)
    {
        $this->registry = $registry;
        $this->enabled  = $enabled;
        $this->clock    = $clock ?? static fn (): int => time();
    }

    /**
     * Record a completed HTTP request (no-op when disabled).
     *
     * @param string $method    HTTP method.
     * @param string $route     Matched route template.
     * @param int    $status    HTTP status code.
     * @param float  $elapsedMs Request duration in milliseconds.
     * @param int    $bytesIn   Bytes read from the client.
     * @param int    $bytesOut  Bytes written to the client.
     *
     * @return void
     */
    public function recordRequest(
        string $method,
        string $route,
        int $status,
        float $elapsedMs,
        int $bytesIn,
        int $bytesOut
    ): void {
        if (!$this->enabled) {
            return;
        }
        $this->registry->recordRequest($method, $route, $status, $elapsedMs, $bytesIn, $bytesOut, $this->now());
    }

    /**
     * Register a newly opened connection (no-op when disabled).
     *
     * @param string  $id          Connection id.
     * @param string  $kind        One of "http", "websocket", "stream".
     * @param ?string $userId      Authenticated user UUID, if known.
     * @param ?string $remoteIp    Client IP address.
     * @param ?string $sessionId   Session UUID, if any.
     * @param ?string $mediaItemId Media item UUID being served, if any.
     *
     * @return void
     */
    public function openConnection(
        string $id,
        string $kind,
        ?string $userId = null,
        ?string $remoteIp = null,
        ?string $sessionId = null,
        ?string $mediaItemId = null
    ): void {
        if (!$this->enabled) {
            return;
        }
        $this->registry->openConnection($id, $kind, $userId, $remoteIp, $sessionId, $mediaItemId, $this->now());
    }

    /**
     * Update a connection's cumulative byte counts (no-op when disabled).
     *
     * @param string $id                 Connection id.
     * @param int    $bytesInCumulative  Total bytes read so far.
     * @param int    $bytesOutCumulative Total bytes written so far.
     *
     * @return void
     */
    public function touchConnection(string $id, int $bytesInCumulative, int $bytesOutCumulative): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->registry->touchConnection($id, $bytesInCumulative, $bytesOutCumulative, $this->now());
    }

    /**
     * Remove a connection from the active-connection map (no-op when disabled).
     *
     * @param string $id Connection id.
     *
     * @return void
     */
    public function closeConnection(string $id): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->registry->closeConnection($id);
    }

    /**
     * The shared per-worker registry (used by the flush service).
     *
     * @return MetricsRegistry
     */
    public function registry(): MetricsRegistry
    {
        return $this->registry;
    }

    /**
     * Whether the subsystem is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Current Unix timestamp from the injected clock.
     *
     * @return int
     */
    private function now(): int
    {
        return ($this->clock)();
    }
}
