<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Dlna;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

/**
 * Main DLNA Server class implementing UPnP/DLNA MediaServer.
 *
 * Provides:
 * - SSDP device discovery and announcement
 * - SOAP-based content directory service
 * - HTTP streaming to DLNA renderers
 * - Device description XML generation
 */
class DlnaServer
{
    /** Server UDN prefix */
    public const SERVER_UDN_PREFIX = 'uuid:phlix-server-';

    /** Default HTTP port */
    public const DEFAULT_PORT = 8200;

    /** SSDP announcement interval in seconds */
    public const SSDP_ANNOUNCE_INTERVAL = 600;

    private string $serverId;
    private string $friendlyName;
    private string $baseUrl;
    private int $port;
    private ContentDirectory $contentDirectory;
    private AvTransport $avTransport;
    private DeviceRegistry $deviceRegistry;
    private StructuredLogger $logger;
    private bool $isRunning = false;

    /** @var array<string, array<string, callable>> SOAP action handlers, indexed by service then action */
    private array $soapHandlers = [];

    /**
     * @since 0.12.0 Requires real ItemRepository
     */
    public function __construct(
        string $serverId,
        string $friendlyName,
        string $baseUrl,
        int $port = self::DEFAULT_PORT,
        ?ItemRepository $itemRepository = null,
        ?StructuredLogger $logger = null
    ) {
        $this->serverId = $serverId;
        $this->friendlyName = $friendlyName;
        $this->baseUrl = $baseUrl;
        $this->port = $port;

        $this->logger = $logger ?? $this->createDefaultLogger();

        // Require real item repository since 0.12.0
        if ($itemRepository === null) {
            throw new \InvalidArgumentException(
                'ItemRepository is required since 0.12.0. Provide a valid ItemRepository instance.'
            );
        }

        // Initialize ContentDirectory with real item repository
        $this->contentDirectory = new ContentDirectory($itemRepository, $this->logger);
        $this->avTransport = new AvTransport($this->logger);
        $this->deviceRegistry = new DeviceRegistry();

        $this->setupSoapHandlers();

        $this->logger->info('DLNA Server initialized', [
            'server_id' => $serverId,
            'friendly_name' => $friendlyName,
            'base_url' => $baseUrl,
            'port' => $port,
        ]);
    }

    /**
     * Set the LibraryBridge to connect ContentDirectory to the real media library.
     *
     * @param LibraryBridge $bridge The library bridge instance
     * @return void
     *
     * @since 0.12.0
     */
    public function setLibraryBridge(LibraryBridge $bridge): void
    {
        $this->contentDirectory->setLibraryBridge($bridge);
    }

    /**
     * Create a default logger for standalone/test operation.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlix_dlna_server_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/server.log',
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
     * Setup SOAP action handlers for content directory.
     */
    private function setupSoapHandlers(): void
    {
        $this->soapHandlers['ContentDirectory'] = [
            'Browse' => [$this->contentDirectory, 'browse'],
            'Search' => [$this->contentDirectory, 'search'],
            'GetSearchCapabilities' => function () {
                return ['SearchCaps' => 'dc:title,dc:creator,upnp:artist,upnp:album'];
            },
            'GetSortCapabilities' => function () {
                return ['SortCaps' => 'dc:title,dc:date,dc:creator'];
            },
            'GetSystemUpdateID' => function () {
                return ['Id' => $this->contentDirectory->getSystemUpdateId()];
            },
        ];

        $this->soapHandlers['AVTransport'] = [
            'SetAVTransportURI' => [$this->avTransport, 'setAvTransportUri'],
            'Play' => [$this->avTransport, 'play'],
            'Pause' => [$this->avTransport, 'pause'],
            'Stop' => [$this->avTransport, 'stop'],
            'Seek' => [$this->avTransport, 'seek'],
            'GetTransportInfo' => [$this->avTransport, 'getTransportInfo'],
            'GetPositionInfo' => [$this->avTransport, 'getPositionInfo'],
            'GetMediaInfo' => [$this->avTransport, 'getMediaInfo'],
            'GetDeviceCapabilities' => [$this->avTransport, 'getDeviceCapabilities'],
            'GetTransportSettings' => [$this->avTransport, 'getTransportSettings'],
            'SetPlayMode' => [$this->avTransport, 'setPlayMode'],
            'GetCurrentTransportActions' => [$this->avTransport, 'getCurrentTransportActions'],
        ];

        $this->soapHandlers['ConnectionManager'] = [
            'GetCurrentConnectionInfo' => function () {
                return [
                    'ConnectionID' => 0,
                    'AVTransportID' => 0,
                    'ProtocolInfo' => 'http-get:*:application/octet-stream:*',
                    'Direction' => 'Output',
                    'Status' => 'OK',
                ];
            },
            'GetProtocolInfo' => function () {
                return [
                    'Source' => 'http-get:*:*:*',
                    'Sink' => '',
                ];
            },
        ];
    }

    /**
     * Start the DLNA server.
     */
    public function start(): void
    {
        if ($this->isRunning) {
            $this->logger->warning('DLNA Server already running');
            return;
        }

        $this->isRunning = true;

        $this->logger->info('Starting DLNA Server');

        // Register this server with device registry
        $serverDevice = $this->createServerDevice();
        $this->deviceRegistry->registerDevice($serverDevice);
    }

    /**
     * Stop the DLNA server.
     */
    public function stop(): void
    {
        if (!$this->isRunning) {
            return;
        }

        $this->isRunning = false;
        $this->logger->info('Stopping DLNA Server');
    }

    /**
     * Check if server is running.
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Create the server device representation.
     */
    public function createServerDevice(): DlnaDevice
    {
        $udn = self::SERVER_UDN_PREFIX . $this->serverId;

        $device = new DlnaDevice(
            $udn,
            DlnaDevice::TYPE_SERVER,
            $this->friendlyName,
            $this->baseUrl,
            $this->port
        );

        $device->setModelDescription('Phlix Media Server - DLNA/UPnP Media Server');
        $device->setModelName('Phlix Media Server');
        $device->setModelNumber('1.0');

        // Add device icons
        $device->addIcon([
            'mimetype' => 'image/png',
            'width' => 48,
            'height' => 48,
            'depth' => 32,
            'url' => '/icons/small.png',
        ]);
        $device->addIcon([
            'mimetype' => 'image/png',
            'width' => 120,
            'height' => 120,
            'depth' => 32,
            'url' => '/icons/large.png',
        ]);
        $device->addIcon([
            'mimetype' => 'image/jpeg',
            'width' => 48,
            'height' => 48,
            'depth' => 24,
            'url' => '/icons/small.jpg',
        ]);
        $device->addIcon([
            'mimetype' => 'image/jpeg',
            'width' => 120,
            'height' => 120,
            'depth' => 24,
            'url' => '/icons/large.jpg',
        ]);

        return $device;
    }

    /**
     * Get the server UDN.
     */
    public function getServerUdn(): string
    {
        return self::SERVER_UDN_PREFIX . $this->serverId;
    }

    /**
     * Get the server device.
     */
    public function getServerDevice(): DlnaDevice
    {
        return $this->createServerDevice();
    }

    /**
     * Get device description XML.
     */
    public function getDeviceDescriptionXml(): string
    {
        return $this->createServerDevice()->toDeviceDescriptionXml();
    }

    /**
     * Get SCPD XML for a service.
     */
    public function getScpdXml(string $service): ?string
    {
        return match ($service) {
            'ContentDirectory' => $this->contentDirectory->getScpdXml(),
            'AVTransport' => $this->avTransport->getScpdXml(),
            default => null,
        };
    }

    /**
     * Process a SOAP request.
     *
     * @param string $service The service name (e.g., 'ContentDirectory')
     * @param string $action The action name (e.g., 'Browse')
     * @param string $body The SOAP body XML
     * @return array<string, mixed> The result data
     */
    public function processSoapRequest(string $service, string $action, string $body): array
    {
        $this->logger->debug('SOAP Request', [
            'service' => $service,
            'action' => $action,
        ]);

        if (!isset($this->soapHandlers[$service])) {
            return ['error' => 401, 'description' => 'Invalid service'];
        }

        if (!isset($this->soapHandlers[$service][$action])) {
            return ['error' => 401, 'description' => 'Invalid action'];
        }

        $handler = $this->soapHandlers[$service][$action];

        // Parse SOAP body to extract parameters
        $params = $this->parseSoapBody($body, $action);

        try {
            $result = call_user_func_array($handler, $params);

            $this->logger->debug('SOAP Response', [
                'service' => $service,
                'action' => $action,
                'result' => $result,
            ]);

            return is_array($result) ? $result : ['Result' => $result];
        } catch (\Throwable $e) {
            $this->logger->error('SOAP Error', [
                'service' => $service,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 501, 'description' => $e->getMessage()];
        }
    }

    /**
     * The declared IN-argument order for each supported SOAP action.
     *
     * The handlers are invoked POSITIONALLY via `call_user_func_array`, so the
     * order here is load-bearing — it must match each handler's signature.
     *
     * @var array<string, list<string>>
     */
    private const ACTION_ARGUMENTS = [
        'Browse' => ['ObjectID', 'BrowseFlag', 'Filter', 'StartingIndex', 'RequestedCount', 'SortCriteria'],
        'Search' => ['ContainerID', 'SearchCriteria', 'Filter', 'StartingIndex', 'RequestedCount', 'SortCriteria'],
        'SetAVTransportURI' => ['InstanceID', 'CurrentURI', 'CurrentURIMetaData'],
        'Play' => ['InstanceID', 'Speed'],
        'Pause' => ['InstanceID'],
        'Stop' => ['InstanceID'],
        'Seek' => ['InstanceID', 'Unit', 'Target'],
        'GetTransportInfo' => ['InstanceID'],
        'GetPositionInfo' => ['InstanceID'],
        'GetMediaInfo' => ['InstanceID'],
        'GetDeviceCapabilities' => ['InstanceID'],
        'GetTransportSettings' => ['InstanceID'],
        'SetPlayMode' => ['InstanceID', 'NewPlayMode'],
        'GetCurrentTransportActions' => ['InstanceID'],
        'GetSearchCapabilities' => [],
        'GetSortCapabilities' => [],
        'GetSystemUpdateID' => [],
        'GetCurrentConnectionInfo' => ['ConnectionID'],
        'GetProtocolInfo' => [],
    ];

    /** Arguments that must be coerced to int before the handler is called. */
    private const INT_ARGUMENTS = ['InstanceID', 'StartingIndex', 'RequestedCount', 'ConnectionID'];

    /**
     * Parse a SOAP body into the positional argument list for an action.
     *
     * Locates the action element namespace-agnostically as a direct child of
     * the SOAP `Body` (matched by LOCAL name, so `Browse`, `u:Browse` and a
     * default-namespaced `<Browse xmlns="urn:...">` are all handled), then reads
     * each declared argument from that element's OWN direct children. Scoping to
     * the action element — rather than the previous loose "any descendant with
     * this local-name, anywhere in the document" match — means embedded DIDL-Lite
     * metadata (e.g. inside `CurrentURIMetaData`) can no longer bleed a same-named
     * node into the wrong argument.
     *
     * Argument ORDER is reconstructed from {@see self::ACTION_ARGUMENTS}, not
     * from document order, and an absent INTERIOR argument is filled with a
     * type-appropriate default so a later argument keeps its slot. Trailing
     * absent arguments are dropped so each handler's own default applies. This
     * fixes the previous positional corruption where a missing element silently
     * shifted every following value one slot to the left.
     *
     * @return list<mixed>
     */
    private function parseSoapBody(string $body, string $action): array
    {
        $pattern = self::ACTION_ARGUMENTS[$action] ?? [];
        if ($pattern === []) {
            return [];
        }

        $actionElement = $this->findActionElement($body, $action);
        if ($actionElement === null) {
            return [];
        }

        // Collect only the arguments actually present, keyed by name.
        $present = [];
        foreach ($pattern as $name) {
            $value = $this->extractXmlValue($actionElement, $name);
            if ($value !== null) {
                $present[$name] = $value;
            }
        }

        // Determine the highest present index so trailing absentees can be
        // dropped (letting the handler default apply) while interior gaps are
        // filled to preserve positional alignment.
        $lastPresent = -1;
        foreach ($pattern as $index => $name) {
            if (array_key_exists($name, $present)) {
                $lastPresent = $index;
            }
        }

        $params = [];
        for ($index = 0; $index <= $lastPresent; $index++) {
            $name = $pattern[$index];
            $value = $present[$name] ?? null;

            if (in_array($name, self::INT_ARGUMENTS, true)) {
                $params[] = $value !== null ? (int) $value : 0;
            } else {
                $params[] = $value ?? '';
            }
        }

        return $params;
    }

    /**
     * Locate a SOAP action element by LOCAL name, namespace-agnostically.
     *
     * Prefers the action element sitting directly under the SOAP `Body`, then
     * falls back to a bare action element posted without the envelope wrapper
     * (some minimalist control points do this). Returns null when the body is
     * not well-formed XML or the action element is absent.
     */
    private function findActionElement(string $body, string $action): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            return null;
        }

        $literal = $this->xpathLiteral($action);

        // Action element as a direct child of the SOAP Body (any prefix).
        $matches = $doc->xpath("//*[local-name()='Body']/*[local-name()={$literal}]");
        if (is_array($matches) && $matches !== []) {
            return $matches[0];
        }

        // The document root itself is the bare action element.
        if ($doc->getName() === $action) {
            return $doc;
        }

        // A bare action element that is not the root but carries no Body wrapper.
        $bare = $doc->xpath("//*[local-name()={$literal}]");
        if (is_array($bare) && $bare !== []) {
            return $bare[0];
        }

        return null;
    }

    /**
     * Read a single argument value from an action element's direct children.
     *
     * Matches by LOCAL name so the child may be unprefixed, carry the action's
     * default namespace, or use any prefix. Returns null when the element is
     * absent and '' when it is present but empty (`<SortCriteria/>`), so the
     * caller can distinguish "omitted" from "explicitly empty".
     */
    private function extractXmlValue(\SimpleXMLElement $actionElement, string $name): ?string
    {
        $literal = $this->xpathLiteral($name);
        $matches = $actionElement->xpath("*[local-name()={$literal}]");

        if (is_array($matches) && $matches !== []) {
            return (string) $matches[0];
        }

        return null;
    }

    /**
     * Quote a string as an XPath 1.0 literal, safe against embedded quotes.
     *
     * Argument names come from a fixed allow-list here, but quoting defensively
     * keeps the extraction correct if that ever changes and documents intent.
     */
    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        // Contains both quote kinds: assemble with concat().
        $parts = [];
        foreach (explode("'", $value) as $i => $segment) {
            if ($i > 0) {
                $parts[] = '"\'"';
            }
            if ($segment !== '') {
                $parts[] = "'" . $segment . "'";
            }
        }

        return 'concat(' . implode(', ', $parts) . ')';
    }

    /**
     * Build SOAP response XML.
     *
     * @param array<string, mixed> $result
     */
    public function buildSoapResponse(string $action, array $result): string
    {
        $responseXml = match ($action) {
            'Browse', 'Search' => $this->buildBrowseResponse($result),
            default => $this->buildGenericResponse($action, $result),
        };

        return $responseXml;
    }

    /**
     * Build browse/search response.
     *
     * @param array<string, mixed> $result
     */
    private function buildBrowseResponse(array $result): string
    {
        $resultXmlRaw = $result['Result'] ?? '';
        $resultXml = is_string($resultXmlRaw) ? $resultXmlRaw : '';
        $numberReturned = is_numeric($result['NumberReturned'] ?? null) ? (int) $result['NumberReturned'] : 0;
        $totalMatches = is_numeric($result['TotalMatches'] ?? null) ? (int) $result['TotalMatches'] : 0;
        $updateId = is_numeric($result['UpdateID'] ?? null) ? (int) $result['UpdateID'] : 1;

        return '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
    <s:Body>
        <BrowseResponse xmlns="urn:schemas-upnp-org:service:ContentDirectory:1">
            <Result>' . $this->encodeXml($resultXml) . '</Result>
            <NumberReturned>' . $numberReturned . '</NumberReturned>
            <TotalMatches>' . $totalMatches . '</TotalMatches>
            <UpdateID>' . $updateId . '</UpdateID>
        </BrowseResponse>
    </s:Body>
</s:Envelope>';
    }

    /**
     * Build generic SOAP response.
     *
     * @param array<string, mixed> $result
     */
    private function buildGenericResponse(string $action, array $result): string
    {
        $resultTags = '';
        foreach ($result as $key => $value) {
            if ($key === 'Error') {
                continue;
            }
            $valueStr = is_scalar($value) ? (string) $value : '';
            $resultTags .= sprintf('<%s>%s</%s>', $key, htmlspecialchars($valueStr), $key);
        }

        $responseAction = $action . 'Response';

        return '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
    <s:Body>
        <' . $responseAction . ' xmlns="urn:schemas-upnp-org:service:AVTransport:1">
            ' . $resultTags . '
        </' . $responseAction . '>
    </s:Body>
</s:Envelope>';
    }

    /**
     * Encode string for XML.
     */
    private function encodeXml(string $str): string
    {
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Build SOAP fault response.
     */
    public function buildSoapFault(string $faultCode, string $faultString): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
    <s:Body>
        <s:Fault>
            <faultcode>s:Client</faultcode>
            <faultstring>' . $this->encodeXml($faultString) . '</faultstring>
        </s:Fault>
    </s:Body>
</s:Envelope>';
    }

    /**
     * Get the device registry for discovered devices.
     */
    public function getDeviceRegistry(): DeviceRegistry
    {
        return $this->deviceRegistry;
    }

    /**
     * Get the content directory service.
     */
    public function getContentDirectory(): ContentDirectory
    {
        return $this->contentDirectory;
    }

    /**
     * Get the AV transport service.
     */
    public function getAvTransport(): AvTransport
    {
        return $this->avTransport;
    }

    /**
     * Get the server base URL.
     */
    public function getBaseUrl(): string
    {
        return "http://{$this->baseUrl}:{$this->port}";
    }

    /**
     * Get the server port.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Get friendly name.
     */
    public function getFriendlyName(): string
    {
        return $this->friendlyName;
    }

    /**
     * Get content as array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'server_id' => $this->serverId,
            'friendly_name' => $this->friendlyName,
            'base_url' => $this->baseUrl,
            'port' => $this->port,
            'is_running' => $this->isRunning,
            'server_udn' => $this->getServerUdn(),
            'device_count' => $this->deviceRegistry->getDeviceCount(),
        ];
    }
}
