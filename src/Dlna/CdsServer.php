<?php

declare(strict_types=1);

namespace Phlix\Dlna;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Discovery\DiscoveryManager;

/**
 * CdsServer provides the HTTP endpoints for the DLNA Content Directory Service.
 *
 * This server handles:
 * - Device description XML at /description.xml
 * - SCPD (Service Description) XML at /scpd/{service}.xml
 * - CDS control SOAP requests at /cds/control
 *
 * @since 0.12.0
 * @see CdsControlHandler For SOAP request processing
 * @see ContentDirectory For the actual content directory implementation
 */
class CdsServer
{
    /** @var DlnaServer The parent DLNA server */
    private DlnaServer $dlnaServer;

    /** @var DiscoveryManager|null Discovery manager for SSDP/mDNS announcements */
    private ?DiscoveryManager $discoveryManager;

    /** @var StructuredLogger|null Optional logger */
    private ?StructuredLogger $logger;

    /** @var CdsControlHandler|null Cached control handler instance */
    private ?CdsControlHandler $controlHandler = null;

    /**
     * @param DlnaServer $dlnaServer The parent DLNA server
     * @param DiscoveryManager|null $discoveryManager Discovery manager for announcements
     * @param StructuredLogger|null $logger Optional logger
     *
     * @since 0.12.0
     */
    public function __construct(
        DlnaServer $dlnaServer,
        ?DiscoveryManager $discoveryManager = null,
        ?StructuredLogger $logger = null
    ) {
        $this->dlnaServer = $dlnaServer;
        $this->discoveryManager = $discoveryManager;
        $this->logger = $logger;
    }

    /**
     * Start the CDS server.
     *
     * If a DiscoveryManager is available, announces the server via SSDP and mDNS.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function start(): void
    {
        $this->logger?->info('CdsServer: Starting');

        // Announce via SSDP if discovery is available
        if ($this->discoveryManager !== null) {
            $this->announceViaSsdp();
            $this->announceViaMdns();
        }
    }

    /**
     * Announce this CDS server via SSDP multicast.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function announceViaSsdp(): void
    {
        $device = $this->dlnaServer->getServerDevice();
        $this->logger?->debug('CdsServer: Announcing via SSDP', [
            'udn' => $device->getUdn(),
            'location' => $device->getUrl('/description.xml'),
        ]);
    }

    /**
     * Announce this CDS server via mDNS.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function announceViaMdns(): void
    {
        $device = $this->dlnaServer->getServerDevice();
        $this->logger?->debug('CdsServer: Announcing via mDNS', [
            'name' => $device->getFriendlyName(),
        ]);
    }

    /**
     * Stop the CDS server.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stop(): void
    {
        $this->logger?->info('CdsServer: Stopping');
    }

    /**
     * Handle an incoming HTTP CDS request.
     *
     * Routes to appropriate handler based on path and method.
     *
     * @param string $path Request path
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array<string, string> $headers HTTP headers
     * @param string $body Request body (for POST requests)
     * @return string|null Response body, or null if path not handled
     *
     * @since 0.12.0
     */
    public function handleRequest(string $path, string $method, array $headers, string $body): ?string
    {
        $this->logger?->debug('CdsServer: Handling request', [
            'path' => $path,
            'method' => $method,
        ]);

        // Route based on path
        return match (true) {
            $path === '/description.xml' && $method === 'GET' => $this->getDeviceDescriptionXml(),
            preg_match('#^/scpd/([^/]+)\.xml$#', $path, $matches) === 1 && $method === 'GET'
                => $this->getScpdXml($matches[1]),
            $path === '/cds/control' && $method === 'POST' => $this->processControl($body),
            default => null,
        };
    }

    /**
     * Get device description XML for /description.xml endpoint.
     *
     * Returns the full UPnP device description XML that allows DLNA renderers
     * to discover and identify this media server.
     *
     * @return string Device description XML
     *
     * @since 0.12.0
     */
    public function getDeviceDescriptionXml(): string
    {
        return $this->dlnaServer->getDeviceDescriptionXml();
    }

    /**
     * Get SCPD XML for a service.
     *
     * @param string $service Service name (ContentDirectory, AVTransport, ConnectionManager)
     * @return string|null SCPD XML or null if service not found
     *
     * @since 0.12.0
     */
    public function getScpdXml(string $service): ?string
    {
        $scpd = $this->dlnaServer->getScpdXml($service);

        if ($scpd === null) {
            $this->logger?->warning('CdsServer: Unknown service SCPD requested', ['service' => $service]);
            return null;
        }

        return $scpd;
    }

    /**
     * Process CDS control SOAP request.
     *
     * @param string $soapBody Raw SOAP request body
     * @return string SOAP XML response
     *
     * @since 0.12.0
     */
    public function processControl(string $soapBody): string
    {
        $handler = $this->getControlHandler();
        return $handler->handle($soapBody);
    }

    /**
     * Get the CDS control handler, creating it if necessary.
     *
     * @return CdsControlHandler
     *
     * @since 0.12.0
     */
    private function getControlHandler(): CdsControlHandler
    {
        if ($this->controlHandler === null) {
            $this->controlHandler = new CdsControlHandler(
                $this->dlnaServer->getContentDirectory(),
                $this->dlnaServer,
                $this->logger
            );
        }

        return $this->controlHandler;
    }

    /**
     * Get the DlnaServer instance.
     *
     * @return DlnaServer
     *
     * @since 0.12.0
     */
    public function getDlnaServer(): DlnaServer
    {
        return $this->dlnaServer;
    }

    /**
     * Check if this CDS server is running.
     *
     * @return bool True if running
     *
     * @since 0.12.0
     */
    public function isRunning(): bool
    {
        return $this->dlnaServer->isRunning();
    }

    /**
     * Get the server's UDN.
     *
     * @return string Server UDN
     *
     * @since 0.12.0
     */
    public function getServerUdn(): string
    {
        return $this->dlnaServer->getServerUdn();
    }

    /**
     * Get the base URL for this CDS server.
     *
     * @return string Base URL
     *
     * @since 0.12.0
     */
    public function getBaseUrl(): string
    {
        return $this->dlnaServer->getBaseUrl();
    }

    /**
     * Get the port for this CDS server.
     *
     * @return int Port number
     *
     * @since 0.12.0
     */
    public function getPort(): int
    {
        return $this->dlnaServer->getPort();
    }
}
