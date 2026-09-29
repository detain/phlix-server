<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Net\LanEndpointGuard;
use Phlix\Discovery\Ssdp\SsdpDiscovery;

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
     * The shared DLNA-channel logger — not a private one in a temp directory.
     *
     * The old body `mkdir()`ed a
     * `sys_get_temp_dir()/phlix_dlna_renderer_discovery_<uniqid>` directory on
     * every construction and pointed a private `StructuredLogger` at a log
     * file inside it — a per-instance leak that survived for the life of the
     * worker. `LoggerFactory::get()` returns one cached instance per channel,
     * so the whole family shares a single logger.
     *
     * @return StructuredLogger The shared DLNA channel logger, routed by
     *         `config/logger.php` to `.logs/app.log` and `.logs/error.log` —
     *         an install-dir destination that creates no directory.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        return LoggerFactory::get(LogChannels::DLNA);
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
        $xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET);
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

        // Find AVTransport service control URL — authority is pinned to the LOCATION host
        $avTransportUrl = $this->extractServiceUrl($xml, 'urn:schemas-upnp-org:service:AVTransport:1', $locationUrl);

        // Find icon URL — same-host constrained
        $iconUrl = $this->extractIconUrl($xml, $locationUrl);

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
     * The controlURL is a client-controlled string read from a fetched document and is
     * later POSTed SOAP/DIDL to. It MUST stay on the authority of the (already
     * LAN-gated) LOCATION the description came from — an absolute cross-host or
     * non-http(s) controlURL is dropped, not followed.
     *
     * @param \SimpleXMLElement $xml Parsed device description XML
     * @param string $serviceType UPnP service type (e.g., 'urn:schemas-upnp-org:service:AVTransport:1')
     * @param string $locationUrl Absolute LOCATION of the already-pinned description
     *
     * @return string|null Control URL or null if not found / not same-host
     */
    private function extractServiceUrl(\SimpleXMLElement $xml, string $serviceType, string $locationUrl): ?string
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

                return $this->constrainToLocationHost($controlUrl, $locationUrl);
            }
        }

        return null;
    }

    /**
     * Resolve a document-advertised path/URL to an absolute http(s) URL on the SAME
     * authority as the pinned LOCATION. Relative values join the LOCATION origin;
     * absolute values must already match it. Returns null when the candidate names any
     * other host (or an unsafe scheme) — the caller then drops the endpoint.
     */
    private function constrainToLocationHost(string $candidate, string $locationUrl): ?string
    {
        $normalized = LanEndpointGuard::normalizeHttpUrl($candidate);

        if ($normalized !== null) {
            // Absolute (or scheme-less) URL: only honored if it is the same host as
            // the pinned LOCATION.
            $sameHost = LanEndpointGuard::sameHostAbsolute($locationUrl, $normalized);

            if ($sameHost !== null) {
                return $sameHost;
            }

            $this->logger->warning('Dropped renderer endpoint on a foreign host', [
                'candidate' => $candidate,
                'location' => $locationUrl,
            ]);

            return null;
        }

        // No parseable scheme/host — a bare relative path. Join it onto the LOCATION
        // origin so the authority can never escape the pinned host.
        $origin = LanEndpointGuard::originOf($locationUrl);

        if ($origin === null) {
            return null;
        }

        return $origin . '/' . ltrim($candidate, '/');
    }

    /**
     * Extract the best icon URL from device description XML.
     *
     * @param \SimpleXMLElement $xml Parsed device description XML
     * @param string $locationUrl Absolute LOCATION of the already-pinned description
     *
     * @return string|null Icon URL or null if no icons found / not same-host
     */
    private function extractIconUrl(\SimpleXMLElement $xml, string $locationUrl): ?string
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

        return $this->constrainToLocationHost($bestIcon, $locationUrl);
    }
}
