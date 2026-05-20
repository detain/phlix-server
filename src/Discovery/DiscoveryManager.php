<?php

declare(strict_types=1);

namespace Phlix\Discovery;

use Phlix\Discovery\Mdns\MdnsDiscovery;
use Phlix\Discovery\Mdns\MdnsService;
use Phlix\Discovery\Ssdp\SsdpDevice;
use Phlix\Discovery\Ssdp\SsdpDiscovery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unified facade for all discovery services (SSDP and mDNS).
 *
 * Provides a single entry point for discovering DLNA, Chromecast, AirPlay,
 * and Roku devices on the network.
 *
 * @since 0.12.0
 */
class DiscoveryManager
{
    /** @var SsdpDiscovery */
    private SsdpDiscovery $ssdp;

    /** @var MdnsDiscovery */
    private MdnsDiscovery $mdns;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * @param SsdpDiscovery $ssdp SSDP discovery service
     * @param MdnsDiscovery $mdns mDNS discovery service
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        SsdpDiscovery $ssdp,
        MdnsDiscovery $mdns,
        ?LoggerInterface $logger = null
    ) {
        $this->ssdp = $ssdp;
        $this->mdns = $mdns;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Discover all DLNA servers on the network via SSDP.
     *
     * @return SsdpDevice[] Array of discovered DLNA server devices
     *
     * @since 0.12.0
     */
    public function discoverDlnaServers(): array
    {
        $this->logger->debug('DiscoveryManager: Discovering DLNA servers');
        return $this->ssdp->discoverDevices(SsdpDiscovery::ST_MEDIA_SERVER);
    }

    /**
     * Discover all DLNA renderers (TVs, speakers) via SSDP.
     *
     * @return SsdpDevice[] Array of discovered DLNA renderer devices
     *
     * @since 0.12.0
     */
    public function discoverDlnaRenderers(): array
    {
        $this->logger->debug('DiscoveryManager: Discovering DLNA renderers');
        return $this->ssdp->discoverDevices(SsdpDiscovery::ST_MEDIA_RENDERER);
    }

    /**
     * Discover Chromecast devices via mDNS.
     *
     * @return MdnsService[] Array of discovered Chromecast devices
     *
     * @since 0.12.0
     */
    public function discoverChromecastDevices(): array
    {
        $this->logger->debug('DiscoveryManager: Discovering Chromecast devices');
        return $this->mdns->discoverChromecast();
    }

    /**
     * Discover AirPlay devices via mDNS.
     *
     * @return MdnsService[] Array of discovered AirPlay devices
     *
     * @since 0.12.0
     */
    public function discoverAirPlayDevices(): array
    {
        $this->logger->debug('DiscoveryManager: Discovering AirPlay devices');
        return $this->mdns->discoverAirPlay();
    }

    /**
     * Discover Roku devices via mDNS.
     *
     * @return MdnsService[] Array of discovered Roku devices
     *
     * @since 0.12.0
     */
    public function discoverRokuDevices(): array
    {
        $this->logger->debug('DiscoveryManager: Discovering Roku devices');
        return $this->mdns->discoverRoku();
    }

    /**
     * Announce the Phlix server via both SSDP and mDNS.
     *
     * @param string $serverId Unique server identifier (UUID)
     * @param string $friendlyName Human-readable server name
     * @param string $baseUrl Base URL of the Phlix server
     * @param int $port Phlix server port
     *
     * @since 0.12.0
     */
    public function announcePhlixServer(string $serverId, string $friendlyName, string $baseUrl, int $port): void
    {
        $this->logger->info('DiscoveryManager: Announcing Phlix server', [
            'serverId' => $serverId,
            'friendlyName' => $friendlyName,
            'baseUrl' => $baseUrl,
            'port' => $port,
        ]);

        // Announce via SSDP
        $this->ssdp->announceServer($serverId, $friendlyName, $baseUrl, $port);

        // Announce via mDNS
        $mdnsName = 'Phlix._phlix._tcp.local.';
        $this->mdns->announceServer($mdnsName, '_phlix._tcp.local.', $port, [
            'serverId' => $serverId,
            'friendlyName' => $friendlyName,
        ]);
    }

    /**
     * Start background listeners for incoming discovery.
     *
     * Sets up periodic scanning to keep the device list fresh.
     *
     * @param callable $onDeviceDiscovered Callback when a device is discovered
     *
     * @since 0.12.0
     */
    public function startListeners(callable $onDeviceDiscovered): void
    {
        $this->logger->info('DiscoveryManager: Starting background listeners');

        // This method would be called by DiscoveryServer to set up
        // background periodic scanning via Workerman Timer.
        // The actual timer setup is handled by DiscoveryServer.
    }
}
