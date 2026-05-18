<?php

declare(strict_types=1);

namespace Phlex\Discovery;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Common\Logger\LoggerFactory;
use Workerman\Timer;

/**
 * HTTP handler that processes incoming SSDP/mDNS UDP packets.
 *
 * Uses Workerman Timer to periodically send M-SEARCH and mDNS queries
 * to keep the device list fresh. Runs as part of the Workerman worker lifecycle.
 *
 * @since 0.12.0
 */
class DiscoveryServer
{
    /** SSDP discovery interval in seconds */
    private const SSDP_INTERVAL = 60;

    /** mDNS discovery interval in seconds */
    private const MDNS_INTERVAL = 30;

    /** @var DiscoveryManager */
    private DiscoveryManager $manager;

    /** @var StructuredLogger */
    private StructuredLogger $logger;

    /** @var int|null Timer ID for SSDP */
    private ?int $ssdpTimerId = null;

    /** @var int|null Timer ID for mDNS */
    private ?int $mdnsTimerId = null;

    /** @var bool Whether the server is running */
    private bool $isRunning = false;

    /**
     * @param DiscoveryManager $manager Discovery manager instance
     * @param StructuredLogger|null $logger Optional structured logger
     */
    public function __construct(
        DiscoveryManager $manager,
        ?StructuredLogger $logger = null
    ) {
        $this->manager = $manager;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Start listening for SSDP NOTIFY and mDNS responses.
     *
     * Sets up periodic timers to send discovery queries.
     *
     * @since 0.12.0
     */
    public function start(): void
    {
        if ($this->isRunning) {
            $this->logger->warning('DiscoveryServer: Already running');
            return;
        }

        $this->isRunning = true;

        $this->logger->info('DiscoveryServer: Starting');

        // Set up periodic SSDP discovery
        $this->ssdpTimerId = Timer::add(self::SSDP_INTERVAL, function () {
            $this->performSsdpDiscovery();
        });

        // Set up periodic mDNS discovery
        $this->mdnsTimerId = Timer::add(self::MDNS_INTERVAL, function () {
            $this->performMdnsDiscovery();
        });

        // Perform initial discovery
        $this->performSsdpDiscovery();
        $this->performMdnsDiscovery();
    }

    /**
     * Stop listening.
     *
     * @since 0.12.0
     */
    public function stop(): void
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('DiscoveryServer: Stopping');

        if ($this->ssdpTimerId !== null) {
            Timer::del($this->ssdpTimerId);
            $this->ssdpTimerId = null;
        }

        if ($this->mdnsTimerId !== null) {
            Timer::del($this->mdnsTimerId);
            $this->mdnsTimerId = null;
        }

        $this->isRunning = false;
    }

    /**
     * Check if the server is running.
     *
     * @return bool True if running
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Perform SSDP discovery.
     */
    private function performSsdpDiscovery(): void
    {
        try {
            $this->logger->debug('DiscoveryServer: Performing SSDP discovery');

            $servers = $this->manager->discoverDlnaServers();
            $renderers = $this->manager->discoverDlnaRenderers();

            $this->logger->info('DiscoveryServer: SSDP discovery complete', [
                'servers' => count($servers),
                'renderers' => count($renderers),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('DiscoveryServer: SSDP discovery failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Perform mDNS discovery.
     */
    private function performMdnsDiscovery(): void
    {
        try {
            $this->logger->debug('DiscoveryServer: Performing mDNS discovery');

            $chromecast = $this->manager->discoverChromecastDevices();
            $airplay = $this->manager->discoverAirPlayDevices();
            $roku = $this->manager->discoverRokuDevices();

            $this->logger->info('DiscoveryServer: mDNS discovery complete', [
                'chromecast' => count($chromecast),
                'airplay' => count($airplay),
                'roku' => count($roku),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('DiscoveryServer: mDNS discovery failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a default logger instance.
     *
     * @return StructuredLogger Default logger
     */
    private function createDefaultLogger(): StructuredLogger
    {
        return LoggerFactory::get(LogChannels::MEDIA);
    }
}
