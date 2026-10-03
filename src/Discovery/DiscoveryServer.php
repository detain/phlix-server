<?php

/**
 * Phlix media server component: Discovery.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Discovery;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Logger\LoggerFactory;
use Workerman\Timer;

/**
 * HTTP handler that processes incoming SSDP/mDNS UDP packets.
 *
 * Uses Workerman Timer to periodically send M-SEARCH and mDNS queries
 * to keep the device list fresh. Runs as part of the Workerman worker lifecycle.
 *
 * Both probe families are gated by {@see DiscoveryPolicy}
 * (`discovery.ssdp.enabled` / `discovery.mdns.enabled`, both default true —
 * pure opt-out). Gate points: timer registration in {@see self::start()}
 * (boot-time read; a process booted with a gate off never holds that timer,
 * hence the schema `restart: true`) and the head of each perform* method
 * (per-tick re-check, so switching OFF goes quiet within one interval with
 * no restart).
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

    /** @var DiscoveryPolicy Settings gate for both probe families */
    private DiscoveryPolicy $policy;

    /** @var int|null Timer ID for SSDP */
    private ?int $ssdpTimerId = null;

    /** @var int|null Timer ID for mDNS */
    private ?int $mdnsTimerId = null;

    /** @var bool Whether the server is running */
    private bool $isRunning = false;

    /**
     * @param DiscoveryManager $manager Discovery manager instance
     * @param StructuredLogger|null $logger Optional structured logger
     * @param DiscoveryPolicy|null $policy Optional settings gate; NULL degrades
     *        to both probes enabled (the pre-key behaviour). NOTE for DI:
     *        PHP-DI SKIPS optional constructor parameters during autowiring —
     *        a binding that wants the settings honoured must name this
     *        parameter (see DlnaServicesProvider).
     */
    public function __construct(
        DiscoveryManager $manager,
        ?StructuredLogger $logger = null,
        ?DiscoveryPolicy $policy = null
    ) {
        $this->manager = $manager;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->policy = $policy ?? new DiscoveryPolicy();
    }

    /**
     * Start listening for SSDP NOTIFY and mDNS responses.
     *
     * Sets up periodic timers to send discovery queries — for each protocol
     * ONLY while its settings gate is enabled at boot. The immediate initial
     * probes run through the same gated perform* methods.
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

        // Set up periodic SSDP discovery — boot-time gate: with the key off
        // this process never registers the timer (the tick re-check inside
        // performSsdpDiscovery() only silences an ALREADY-registered probe).
        if ($this->policy->ssdpEnabled()) {
            $this->ssdpTimerId = Timer::add(self::SSDP_INTERVAL, function () {
                $this->performSsdpDiscovery();
            });
        } else {
            $this->logger->info('DiscoveryServer: SSDP probe disabled by settings — timer not registered');
        }

        // Set up periodic mDNS discovery — same boot-time gate.
        if ($this->policy->mdnsEnabled()) {
            $this->mdnsTimerId = Timer::add(self::MDNS_INTERVAL, function () {
                $this->performMdnsDiscovery();
            });
        } else {
            $this->logger->info('DiscoveryServer: mDNS probe disabled by settings — timer not registered');
        }

        // Perform initial discovery (each method re-checks its own gate, so
        // a disabled protocol sends nothing here either).
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
     *
     * Per-tick gate: an already-registered timer goes silent within one
     * interval of the admin flipping `discovery.ssdp.enabled` off — no
     * restart needed to STOP (re-STARTing needs a process that re-runs
     * start(), where the timer is registered).
     */
    private function performSsdpDiscovery(): void
    {
        if (!$this->policy->ssdpEnabled()) {
            $this->logger->debug('DiscoveryServer: SSDP probe suppressed by settings');
            return;
        }

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
     *
     * Per-tick gate, same semantics as {@see self::performSsdpDiscovery()}.
     */
    private function performMdnsDiscovery(): void
    {
        if (!$this->policy->mdnsEnabled()) {
            $this->logger->debug('DiscoveryServer: mDNS probe suppressed by settings');
            return;
        }

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
