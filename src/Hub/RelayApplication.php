<?php

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

        $this->logger->info('RelayApplication stopped');
    }

    /**
     * Returns whether the relay worker is currently running.
     *
     * @return bool True if running.
     *
     * @since 0.5.0
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
