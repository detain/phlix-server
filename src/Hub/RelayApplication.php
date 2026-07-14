<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Common\Logger\StructuredLogger;
use Workerman\Worker;

/**
 * Workerman Worker entry point for the server-side relay tunnel.
 *
 * This worker runs alongside the main HTTP server worker and is
 * responsible for maintaining the persistent WSS tunnel to the hub.
 *
 * @package Phlix\Hub
 * @since 0.5.0
 */
final class RelayApplication
{
    /** @var RelayConsumer */
    private RelayConsumer $consumer;

    /** @var StructuredLogger */
    private StructuredLogger $logger;

    /** @var Worker|null */
    private ?Worker $worker = null;

    /** @var bool */
    private bool $running = false;

    /** @var bool Tracks whether consumer->start() was actually called in the worker callback. */
    private bool $consumerStarted = false;

    /**
     * @param RelayConsumer    $consumer Relay consumer instance.
     * @param StructuredLogger $logger  Logger instance.
     */
    public function __construct(RelayConsumer $consumer, StructuredLogger $logger)
    {
        $this->consumer = $consumer;
        $this->logger = $logger;
    }

    /**
     * Start the relay worker.
     *
     * Creates a Workerman Worker on the `text://` protocol (no actual
     * network socket needed) purely to get a timer context within
     * the Workerman event loop.
     *
     * @return void
     *
     * @since 0.5.0
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->worker = new Worker('text://0.0.0.0:0');
        $this->worker->name = 'phlix-relay-tunnel';
        $this->worker->count = 1;
        $this->worker->onWorkerStart = function (): void {
            $this->logger->info('RelayApplication worker started');
            $this->consumer->start();
            // Mark that we actually called consumer->start() so isRunning() is honest
            $this->consumerStarted = true;
        };

        $this->worker->onWorkerStop = function (): void {
            $this->logger->info('RelayApplication worker stopping');
            $this->consumer->stop();
        };

        $this->running = true;

        $this->logger->info('RelayApplication started');
    }

    /**
     * Stop the relay worker.
     *
     * @return void
     *
     * @since 0.5.0
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->consumer->stop();

        if ($this->worker !== null) {
            $this->worker->stop();
            $this->worker = null;
        }

        $this->running = false;
        $this->consumerStarted = false;

        $this->logger->info('RelayApplication stopped');
    }

    /**
     * Ensure the relay is started and connected.
     *
     * Smart start that avoids infinite loops when config is disabled:
     * - If already connected, does nothing.
     * - If consumer was started but disconnected, forces a stop+start cycle.
     * - If consumer was never started, calls start() fresh.
     *
     * @return void
     *
     * @since 0.5.0
     */
    public function ensureStarted(): void
    {
        // Already connected — nothing to do
        if ($this->consumer->isConnected()) {
            return;
        }

        // Consumer was started but is not connected — force restart
        if ($this->consumerStarted) {
            $this->stop();
            $this->start();
            return;
        }

        // Never started — just call start()
        $this->start();
    }

    /**
     * Returns whether the relay worker is currently running.
     *
     * True only when the worker is started, consumer->start() was actually
     * invoked, and the consumer has an active tunnel connection.
     *
     * @return bool True if running and connected.
     *
     * @since 0.5.0
     */
    public function isRunning(): bool
    {
        return $this->running && $this->consumerStarted && $this->consumer->isConnected();
    }
}
