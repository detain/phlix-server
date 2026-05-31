<?php

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Common\Logger\StructuredLogger;

/**
 * Hub heartbeat background task.
 *
 * Maintains the server's presence on the hub via periodic heartbeat
 * calls. It must be invoked from a worker context that already exists in
 * the Workerman event loop (the dedicated `phlix-hub-heartbeat` worker in
 * `start.php`, created before `Worker::runAll()`): {@see HubClient::startHeartbeatLoop()}
 * registers a `Workerman\Timer`, which only fires inside a running worker.
 *
 * Started automatically when the server is enrolled (has a valid
 * `hub-enrollment.json`) and stopped when the server is de-registered.
 *
 * @package Phlix\Hub
 * @since 0.11.0
 */
final class HubApplication
{
    /** @var HubClient The hub client instance. */
    private HubClient $hubClient;

    /** @var StructuredLogger Logger instance. */
    private StructuredLogger $logger;

    /** @var bool Whether the heartbeat loop is currently running. */
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
     * Starts the hub heartbeat loop in the current worker.
     *
     * No-op unless the server is enrolled. Must be called from within a
     * running Workerman worker (the `phlix-hub-heartbeat` worker's
     * `onWorkerStart`), because {@see HubClient::startHeartbeatLoop()}
     * arms a `Workerman\Timer` that only ticks inside the event loop.
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

        $this->hubClient->startHeartbeatLoop();
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
