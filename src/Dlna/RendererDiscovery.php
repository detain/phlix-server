<?php

declare(strict_types=1);

namespace Phlex\Dlna;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Discovery\Ssdp\SsdpDiscovery;

/**
 * Discovers DLNA MediaRenderer devices on the network via SSDP.
 *
 * Uses SSDP M-SEARCH with the MediaRenderer search target to discover
 * all DLNA-compatible renderers (TVs, speakers, receivers) on the local
 * network. Parses device descriptions to extract AVTransport control URLs.
 *
 * @since 0.12.0
 */
class RendererDiscovery
{
    /** @var SsdpDiscovery SSDP discovery service */
    private SsdpDiscovery $ssdpDiscovery;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /**
     * @param SsdpDiscovery $ssdpDiscovery SSDP discovery service
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(
        SsdpDiscovery $ssdpDiscovery,
        ?StructuredLogger $logger = null
    ) {
        $this->ssdpDiscovery = $ssdpDiscovery;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Create a default logger for standalone/test operation.
     *
     * @return StructuredLogger Configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_dlna_renderer_discovery_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/renderer_discovery.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::DLNA, $config);
    }

    /**
     * Discover all DLNA MediaRenderers on the network.
     *
     * Sends an SSDP M-SEARCH request with the MediaRenderer search target
     * and returns an array of discovered renderer descriptions.
     *
     * @return array<int, array<string, mixed>> Array of renderer descriptors
     *
     * @since 0.12.0
     */
    public function discoverRenderers(): array
    {
        $this->logger->info('Discovering DLNA renderers');

        $devices = $this->ssdpDiscovery->discoverDevices(SsdpDiscovery::ST_MEDIA_RENDERER);

        if (empty($devices)) {
            $this->logger->info('No DLNA renderers discovered');
            return [];
        }

        $renderers = [];
        foreach ($devices as $device) {
            $description = $this->getRendererDescription($device->location);
            if ($description !== null) {
                $renderers[] = $description;
            }
        }

        $this->logger->info('Renderer discovery complete', [
            'discovered' => count($renderers),
        ]);

        return $renderers;
    }

    /**
     * Get the device description for a renderer.
     *
     * Fetches and parses the device description XML from the location URL
     * and extracts the friendly name, manufacturer, model, and AVTransport
     * control URL.
     *
     * @param string $locationUrl Device description URL
     *
     * @return array<string, mixed>|null Renderer descriptor or null on failure
     *
     * @since 0.12.0
     */
    public function getRendererDescription(string $locationUrl): ?array
    {
        if ($locationUrl === '') {
            $this->logger->warning('Empty location URL for renderer description');
            return null;
        }

        $description = $this->ssdpDiscovery->resolveDeviceDescription($locationUrl);

        if ($description === null) {
            $this->logger->warning('Failed to fetch renderer description', [
                'location' => $locationUrl,
            ]);
            return null;
        }

        $xmlContent = $description['xml'] ?? '';
        if (!is_string($xmlContent)) {
            $this->logger->warning('Invalid XML in renderer description', [
                'location' => $locationUrl,
            ]);
            return null;
        }
        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false) {
            $this->logger->warning('Invalid XML in renderer description', [
                'location' => $locationUrl,
            ]);
            return null;
        }

        // Extract device info
        $deviceXml = $xml->device ?? $xml;
        $deviceType = (string)($deviceXml->deviceType ?? '');
        $friendlyName = (string)($deviceXml->friendlyName ?? 'Unknown Renderer');
        $manufacturer = (string)($deviceXml->manufacturer ?? 'Unknown');
        $modelName = (string)($deviceXml->modelName ?? '');
        $modelDescription = (string)($deviceXml->modelDescription ?? '');
        $udn = (string)($deviceXml->UDN ?? '');

        // Find AVTransport service control URL
        $avTransportUrl = $this->extractServiceUrl($xml, 'urn:schemas-upnp-org:service:AVTransport:1');

        // Find icon URL
        $iconUrl = $this->extractIconUrl($xml);

        return [
            'udn' => $udn,
            'device_type' => $deviceType,
            'friendly_name' => $friendlyName,
            'manufacturer' => $manufacturer,
            'model_name' => $modelName,
            'model_description' => $modelDescription,
            'location_url' => $locationUrl,
            'av_transport_url' => $avTransportUrl,
            'icon_url' => $iconUrl,
        ];
    }

    /**
     * Extract the control URL for a UPnP service from device description XML.
     *
     * @param \SimpleXMLElement $xml Parsed device description XML
     * @param string $serviceType UPnP service type (e.g., 'urn:schemas-upnp-org:service:AVTransport:1')
     *
     * @return string|null Control URL or null if not found
     */
    private function extractServiceUrl(\SimpleXMLElement $xml, string $serviceType): ?string
    {
        $deviceXml = $xml->device ?? $xml;
        $serviceList = $deviceXml->serviceList ?? null;

        if (!$serviceList) {
            return null;
        }

        foreach ($serviceList->service ?? [] as $service) {
            $type = (string)($service->serviceType ?? '');
            if ($type === $serviceType) {
                $controlUrl = trim((string)($service->controlURL ?? ''));
                if ($controlUrl === '') {
                    return null;
                }

                // Make absolute URL if relative
                if (strpos($controlUrl, 'http://') !== 0 && strpos($controlUrl, 'https://') !== 0) {
                    // Parse the location from parent to build absolute URL
                    $baseUrl = $this->extractBaseUrl($xml);
                    if ($baseUrl !== null) {
                        return rtrim($baseUrl, '/') . '/' . ltrim($controlUrl, '/');
                    }
                }

                return $controlUrl;
            }
        }

        return null;
    }

    /**
     * Extract base URL from device description XML.
     *
     * @param \SimpleXMLElement $xml Parsed device description XML
     *
     * @return string|null Base URL or null if cannot be determined
     */
    private function extractBaseUrl(\SimpleXMLElement $xml): ?string
    {
        // Try to get URLBase first
        $urlBase = (string)($xml->URLBase ?? '');
        if ($urlBase !== '') {
            return rtrim($urlBase, '/');
        }

        // Fallback: extract from device URL (usually in presentationURL)
        $deviceXml = $xml->device ?? $xml;
        $presentationUrl = (string)($deviceXml->presentationURL ?? '');

        if ($presentationUrl !== '' && strpos($presentationUrl, 'http') === 0) {
            $parsed = parse_url($presentationUrl);
            if ($parsed !== false) {
                $scheme = $parsed['scheme'] ?? 'http';
                $host = $parsed['host'] ?? '';
                $port = $parsed['port'] ?? 80;
                return "{$scheme}://{$host}:{$port}";
            }
        }

        return null;
    }

    /**
     * Extract the best icon URL from device description XML.
     *
     * @param \SimpleXMLElement $xml Parsed device description XML
     *
     * @return string|null Icon URL or null if no icons found
     */
    private function extractIconUrl(\SimpleXMLElement $xml): ?string
    {
        $deviceXml = $xml->device ?? $xml;
        $iconList = $deviceXml->iconList ?? null;

        if (!$iconList) {
            return null;
        }

        $bestIcon = null;
        $bestDepth = 0;

        foreach ($iconList->icon ?? [] as $icon) {
            $depth = (int)($icon->depth ?? 0);
            if ($depth >= $bestDepth) {
                $url = trim((string)($icon->url ?? ''));
                if ($url !== '') {
                    $bestDepth = $depth;
                    $bestIcon = $url;
                }
            }
        }

        if ($bestIcon === null) {
            return null;
        }

        // Make absolute URL if relative
        if (strpos($bestIcon, 'http://') !== 0 && strpos($bestIcon, 'https://') !== 0) {
            $baseUrl = $this->extractBaseUrl($xml);
            if ($baseUrl !== null) {
                return rtrim($baseUrl, '/') . '/' . ltrim($bestIcon, '/');
            }
        }

        return $bestIcon;
    }
}
