<?php

namespace Phlex\Dlna;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Media\Library\ItemRepository;
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
    public const SERVER_UDN_PREFIX = 'uuid:phlex-server-';

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

    /** @var array<string, callable> SOAP action handlers */
    private array $soapHandlers = [];

    /** @var array<string, string> Service SCPD URLs */
    private array $scpdUrls = [];

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

        // Initialize services
        $this->contentDirectory = new ContentDirectory(
            $itemRepository ?? $this->createDummyItemRepository(),
            $this->logger
        );
        $this->avTransport = new AvTransport($this->logger);
        $this->deviceRegistry = new DeviceRegistry();

        $this->setupSoapHandlers();
        $this->setupScpdUrls();

        $this->logger->info('DLNA Server initialized', [
            'server_id' => $serverId,
            'friendly_name' => $friendlyName,
            'base_url' => $baseUrl,
            'port' => $port,
        ]);
    }

    /**
     * Create a default logger for standalone/test operation.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_dlna_server_' . uniqid();
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
     * Create a dummy item repository for standalone operation.
     *
     * @return object An object implementing item repository methods
     */
    private function createDummyItemRepository()
    {
        // Return an object that returns empty/null results
        // The ContentDirectory handles this gracefully
        return new class {
            public function findById(string $id): ?array
            {
                return null;
            }
            public function findByPath(string $path): ?array
            {
                return null;
            }
            public function findByParent(string $parentId): array
            {
                return [];
            }
            public function getByType(string $libraryId, string $type, int $limit = 100, int $offset = 0): array
            {
                return [];
            }
            public function getByLibrary(string $libraryId, int $limit = 100, int $offset = 0): array
            {
                return [];
            }
            public function search(string $query, int $limit = 50): array
            {
                return [];
            }
            public function searchFuzzy(string $query, int $limit = 50): array
            {
                return [];
            }
            public function create(array $data): string
            {
                return '';
            }
            public function update(string $id, array $data): void
            {
            }
            public function delete(string $id): void
            {
            }
            public function deleteByLibrary(string $libraryId): void
            {
            }
            public function countByType(string $libraryId, string $type): int
            {
                return 0;
            }
            public function getRecentlyAdded(string $libraryId, int $limit = 20): array
            {
                return [];
            }
            public function getItemStreams(string $itemId): array
            {
                return [];
            }
            public function addStream(string $itemId, array $streamData): string
            {
                return '';
            }
            public function batchCreate(array $items): array
            {
                return [];
            }
        };
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
     * Setup SCPD URLs for services.
     */
    private function setupScpdUrls(): void
    {
        $this->scpdUrls = [
            'ContentDirectory' => '/scpd/ContentDirectory.xml',
            'AVTransport' => '/scpd/AVTransport.xml',
            'ConnectionManager' => '/scpd/ConnectionManager.xml',
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

        $device->setModelDescription('Phlex Media Server - DLNA/UPnP Media Server');
        $device->setModelName('Phlex Media Server');
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
     * @return array The result data
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

            return $result;
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
     * Parse SOAP body to extract action parameters.
     */
    private function parseSoapBody(string $body, string $action): array
    {
        $params = [];

        // Simple XML parsing for common parameters
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($body);

        if ($doc === false) {
            return $params;
        }

        // Handle different action parameter patterns
        $paramPatterns = [
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

        $pattern = $paramPatterns[$action] ?? [];

        foreach ($pattern as $param) {
            // Try various XML paths
            $value = $this->extractXmlValue($doc, $param);

            // Type conversion
            if (in_array($param, ['InstanceID', 'StartingIndex', 'RequestedCount', 'ConnectionID'])) {
                $value = $value !== null ? (int)$value : 0;
            }

            if ($value !== null) {
                $params[] = $value;
            }
        }

        return $params;
    }

    /**
     * Extract value from XML document.
     */
    private function extractXmlValue(\SimpleXMLElement $doc, string $name): ?string
    {
        // Try direct child (no namespace)
        if (isset($doc->{$name})) {
            return (string)$doc->{$name};
        }

        // Try body/ + name (non-namespaced)
        if (isset($doc->Body->{$name})) {
            return (string)$doc->Body->{$name};
        }

        // Use XPath for better namespace handling
        $xpath = new \SimpleXMLElement($doc->asXML());
        $xpath->registerXPathNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');

        // Try to find the element in Body/ActionName/parameterName pattern
        // This handles both namespaced and non-namespaced elements
        $result = $xpath->xpath("//s:Body/*/*[local-name()='{$name}']");
        if (!empty($result)) {
            return (string)$result[0];
        }

        // Also try direct path without namespace
        $result2 = $xpath->xpath("//Body/*[local-name()='{$name}']");
        if (!empty($result2)) {
            return (string)$result2[0];
        }

        return null;
    }

    /**
     * Build SOAP response XML.
     */
    public function buildSoapResponse(string $action, array $result): string
    {
        $actionLower = strtolower($action);

        $responseXml = match ($action) {
            'Browse', 'Search' => $this->buildBrowseResponse($result),
            default => $this->buildGenericResponse($action, $result),
        };

        return $responseXml;
    }

    /**
     * Build browse/search response.
     */
    private function buildBrowseResponse(array $result): string
    {
        $resultXml = $result['Result'] ?? '';
        $numberReturned = $result['NumberReturned'] ?? 0;
        $totalMatches = $result['TotalMatches'] ?? 0;
        $updateId = $result['UpdateID'] ?? 1;

        return '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
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
     */
    private function buildGenericResponse(string $action, array $result): string
    {
        $resultTags = '';
        foreach ($result as $key => $value) {
            if ($key === 'Error') {
                continue;
            }
            $resultTags .= sprintf('<%s>%s</%s>', $key, htmlspecialchars((string)$value), $key);
        }

        $responseAction = $action . 'Response';

        return '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
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
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
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
