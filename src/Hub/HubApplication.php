<?php

declare(strict_types=1);

namespace Phlex\Hub;

use Phlex\Common\Logger\StructuredLogger;
use Workerman\Worker;

/**
 * Workerman Worker wrapper for the hub heartbeat background task.
 *
 * This worker runs alongside the main HTTP server worker and is
 * responsible for maintaining the server's presence on the hub via
 * periodic heartbeat calls.
 *
 * The worker is started automatically when the server is enrolled
 * (has a valid `hub-enrollment.json`) and stopped when the server
 * is de-registered.
 *
 * @package Phlex\Hub
 * @since 0.11.0
 */
final class HubApplication
{
    /** @var HubClient The hub client instance. */
    private HubClient $hubClient;

    /** @var StructuredLogger Logger instance. */
    private StructuredLogger $logger;

    /** @var Worker|null The Workerman worker instance. */
    private ?Worker $worker = null;

    /** @var bool Whether the worker is currently running. */
    private bool $running = false;

    /**
     * Creates a new HubApplication.
     *
     * @param HubClient        $hubClient Hub client instance.
     * @param StructuredLogger $logger   Logger instance.
     */
    public function __construct(HubClient $hubClient, StructuredLogger $logger)
    {
        $this->hubClient = $hubClient;
        $this->logger = $logger;
    }

    /**
     * Starts the hub heartbeat worker.
     *
     * Creates a Workerman Worker on the `text://` protocol (no actual
     * network socket needed) purely to get a timer context within
     * the Workerman event loop. The Workerman timer subsystem drives
     * the heartbeat loop.
     *
     * @return void
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $enrollment = $this->hubClient->loadEnrollment();
        if ($enrollment === null) {
            $this->logger->info('HubApplication: no enrollment found, not starting');
            return;
        }

        $this->worker = new Worker('text://0.0.0.0:0');
        $this->worker->name = 'phlex-hub-heartbeat';
        $this->worker->count = 1;
        $this->worker->onWorkerStart = function (): void {
            $this->logger->info('HubApplication worker started');
            $this->hubClient->startHeartbeatLoop();
        };

        $this->running = true;

        $this->logger->info('HubApplication started', [
            'server_id' => $enrollment->serverId,
            'hub_base_url' => $enrollment->hubBaseUrl,
        ]);
    }

    /**
     * Stops the hub heartbeat worker.
     *
     * @return void
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->hubClient->stopHeartbeatLoop();

        if ($this->worker !== null) {
            $this->worker->stop();
            $this->worker = null;
        }

        $this->running = false;

        $this->logger->info('HubApplication stopped');
    }

    /**
     * Returns whether the heartbeat worker is currently running.
     *
     * @return bool True if running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
