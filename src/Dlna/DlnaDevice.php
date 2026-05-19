<?php

namespace Phlex\Dlna;

/**
 * Represents a DLNA/UPnP device on the network.
 *
 * This class models both MediaServer (content providers) and MediaRenderer
 * (playback devices) devices. Each device has a unique UDN (Unique Device Name),
 * supports various UPnP services, and can be discovered via SSDP multicast.
 *
 * @see DeviceRegistry For device discovery and management
 * @see UPnP Device Architecture Specification For device description format
 */
class DlnaDevice
{
    /** Device type constant for MediaServer devices */
    public const TYPE_SERVER = 'MediaServer';

    /** Device type constant for MediaRenderer devices */
    public const TYPE_RENDERER = 'MediaRenderer';

    /** Service name for ContentDirectory service */
    public const SERVICE_CONTENT_DIRECTORY = 'ContentDirectory';

    /** Service name for ConnectionManager service */
    public const SERVICE_CONNECTION_MANAGER = 'ConnectionManager';

    /** Service name for AVTransport service */
    public const SERVICE_AV_TRANSPORT = 'AVTransport';

    /** @var string Unique Device Name (UDN) - the device's unique identifier */
    private string $udn;

    /** @var string Device type (TYPE_SERVER or TYPE_RENDERER) */
    private string $deviceType;

    /** @var string Human-readable device name */
    private string $friendlyName;

    /** @var string Device manufacturer name */
    private string $manufacturer;

    /** @var string Device model description */
    private string $modelDescription;

    /** @var string Device model name */
    private string $modelName;

    /** @var string Device model number */
    private string $modelNumber;

    /** @var string Device serial number */
    private string $serialNumber;

    /** @var string URL for device's web interface */
    private string $presentationUrl;

    /** @var string Device base URL (IP or hostname) */
    private string $baseUrl;

    /** @var int Device port number */
    private int $port;

    /** @var array<int, array{mimetype: string, width: int, height: int, depth: int, url: string}> Device icons */
    private array $icons = [];

    /** @var array<string, array<string, string>> Available services keyed by service name */
    private array $services = [];

    /** @var array<string, bool> Device capabilities */
    private array $capabilities = [];

    /** @var float|null Unix timestamp when device was first discovered */
    private ?float $discoveredAt = null;

    /** @var float|null Unix timestamp when device was last seen */
    private ?float $lastSeenAt = null;

    /** @var bool Whether the device is currently active/online */
    private bool $isActive = true;

    public function __construct(
        string $udn,
        string $deviceType,
        string $friendlyName,
        string $baseUrl,
        int $port = 80
    ) {
        $this->udn = $udn;
        $this->deviceType = $deviceType;
        $this->friendlyName = $friendlyName;
        $this->baseUrl = $baseUrl;
        $this->port = $port;
        $this->manufacturer = 'Phlex';
        $this->modelDescription = 'Phlex Media Server';
        $this->modelName = 'Phlex';
        $this->modelNumber = '1.0';
        $this->serialNumber = $this->generateSerial();
        $this->presentationUrl = "http://{$baseUrl}:{$port}/";
        $this->discoveredAt = microtime(true);
        $this->lastSeenAt = microtime(true);

        $this->initializeServices();
    }

    /**
     * Initialize default services based on device type.
     */
    private function initializeServices(): void
    {
        if ($this->deviceType === self::TYPE_SERVER) {
            $this->services = [
                self::SERVICE_CONTENT_DIRECTORY => [
                    'serviceType' => 'urn:schemas-upnp-org:service:ContentDirectory:1',
                    'serviceId' => 'urn:upnp-org:serviceId:ContentDirectory',
                    'controlUrl' => '/ctl/ContentDirectory',
                    'eventSubUrl' => '/evt/ContentDirectory',
                    'SCPDUrl' => '/scpd/ContentDirectory.xml',
                ],
                self::SERVICE_CONNECTION_MANAGER => [
                    'serviceType' => 'urn:schemas-upnp-org:service:ConnectionManager:1',
                    'serviceId' => 'urn:upnp-org:serviceId:ConnectionManager',
                    'controlUrl' => '/ctl/ConnectionManager',
                    'eventSubUrl' => '/evt/ConnectionManager',
                    'SCPDURL' => '/scpd/ConnectionManager.xml',
                ],
            ];

            $this->capabilities = [
                'Search' => true,
                'Browse' => true,
                'CreateObject' => false,
                'DeleteObject' => false,
                'UpdateObject' => false,
                'DirectContentAccess' => true,
            ];
        } elseif ($this->deviceType === self::TYPE_RENDERER) {
            $this->services = [
                self::SERVICE_AV_TRANSPORT => [
                    'serviceType' => 'urn:schemas-upnp-org:service:AVTransport:1',
                    'serviceId' => 'urn:upnp-org:serviceId:AVTransport',
                    'controlUrl' => '/ctl/AVTransport',
                    'eventSubUrl' => '/evt/AVTransport',
                    'SCPDURL' => '/scpd/AVTransport.xml',
                ],
                self::SERVICE_CONNECTION_MANAGER => [
                    'serviceType' => 'urn:schemas-upnp-org:service:ConnectionManager:1',
                    'serviceId' => 'urn:upnp-org:serviceId:ConnectionManager',
                    'controlUrl' => '/ctl/ConnectionManager',
                    'eventSubUrl' => '/evt/ConnectionManager',
                    'SCPDURL' => '/scpd/ConnectionManager.xml',
                ],
            ];

            $this->capabilities = [
                'Play' => true,
                'Pause' => true,
                'Stop' => true,
                'Seek' => true,
                'Next' => false,
                'Previous' => false,
                'SetVolume' => true,
                'SetMute' => true,
            ];
        }
    }

    /**
     * Get the Unique Device Name (UDN).
     */
    public function getUdn(): string
    {
        return $this->udn;
    }

    /**
     * Get the device type (MediaServer or MediaRenderer).
     */
    public function getDeviceType(): string
    {
        return $this->deviceType;
    }

    /**
     * Get the friendly name of the device.
     */
    public function getFriendlyName(): string
    {
        return $this->friendlyName;
    }

    /**
     * Set the friendly name.
     */
    public function setFriendlyName(string $name): void
    {
        $this->friendlyName = $name;
    }

    /**
     * Get the manufacturer.
     */
    public function getManufacturer(): string
    {
        return $this->manufacturer;
    }

    /**
     * Set the manufacturer.
     */
    public function setManufacturer(string $manufacturer): void
    {
        $this->manufacturer = $manufacturer;
    }

    /**
     * Get the model description.
     */
    public function getModelDescription(): string
    {
        return $this->modelDescription;
    }

    /**
     * Set the model description.
     */
    public function setModelDescription(string $description): void
    {
        $this->modelDescription = $description;
    }

    /**
     * Get the model name.
     */
    public function getModelName(): string
    {
        return $this->modelName;
    }

    /**
     * Set the model name.
     */
    public function setModelName(string $name): void
    {
        $this->modelName = $name;
    }

    /**
     * Get the model number.
     */
    public function getModelNumber(): string
    {
        return $this->modelNumber;
    }

    /**
     * Set the model number.
     */
    public function setModelNumber(string $number): void
    {
        $this->modelNumber = $number;
    }

    /**
     * Get the serial number.
     */
    public function getSerialNumber(): string
    {
        return $this->serialNumber;
    }

    /**
     * Get the presentation URL.
     */
    public function getPresentationUrl(): string
    {
        return $this->presentationUrl;
    }

    /**
     * Set the presentation URL.
     */
    public function setPresentationUrl(string $url): void
    {
        $this->presentationUrl = $url;
    }

    /**
     * Get the base URL for the device.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the port number.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Get the full URL for a specific path on the device.
     */
    public function getUrl(string $path = ''): string
    {
        $path = ltrim($path, '/');
        return "http://{$this->baseUrl}:{$this->port}/{$path}";
    }

    /**
     * Get all device icons.
     *
     * @return array<int, array{mimetype: string, width: int, height: int, depth: int, url: string}>
     */
    public function getIcons(): array
    {
        return $this->icons;
    }

    /**
     * Add an icon to the device.
     *
     * @param array<string, mixed> $icon Icon attributes (mimetype, width, height, depth, url)
     */
    public function addIcon(array $icon): void
    {
        $mimetype = $icon['mimetype'] ?? 'image/png';
        $width = $icon['width'] ?? 48;
        $height = $icon['height'] ?? 48;
        $depth = $icon['depth'] ?? 32;
        $url = $icon['url'] ?? '/icons/small.png';

        $this->icons[] = [
            'mimetype' => is_string($mimetype) ? $mimetype : 'image/png',
            'width' => is_numeric($width) ? (int) $width : 48,
            'height' => is_numeric($height) ? (int) $height : 48,
            'depth' => is_numeric($depth) ? (int) $depth : 32,
            'url' => is_string($url) ? $url : '/icons/small.png',
        ];
    }

    /**
     * Get the primary icon URL.
     */
    public function getPrimaryIconUrl(): ?string
    {
        if (empty($this->icons)) {
            return null;
        }

        // Return the largest icon
        $largest = null;
        $maxArea = 0;

        foreach ($this->icons as $icon) {
            $area = ($icon['width'] ?? 48) * ($icon['height'] ?? 48);
            if ($area > $maxArea) {
                $maxArea = $area;
                $largest = $icon;
            }
        }

        return $largest['url'] ?? null;
    }

    /**
     * Get all services.
     *
     * @return array<string, array<string, string>>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Get a specific service by name.
     *
     * @return array<string, string>|null
     */
    public function getService(string $name): ?array
    {
        return $this->services[$name] ?? null;
    }

    /**
     * Check if device has a specific service.
     */
    public function hasService(string $serviceName): bool
    {
        return isset($this->services[$serviceName]);
    }

    /**
     * Get all device capabilities.
     *
     * @return array<string, bool>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Check if device has a specific capability.
     */
    public function hasCapability(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    /**
     * Get the discovery timestamp.
     */
    public function getDiscoveredAt(): ?float
    {
        return $this->discoveredAt;
    }

    /**
     * Get the last seen timestamp.
     */
    public function getLastSeenAt(): ?float
    {
        return $this->lastSeenAt;
    }

    /**
     * Update the last seen timestamp.
     */
    public function touch(): void
    {
        $this->lastSeenAt = microtime(true);
    }

    /**
     * Check if the device is active.
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Set the active status.
     */
    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    /**
     * Check if the device has timed out (not seen for specified seconds).
     */
    public function hasTimedOut(int $timeoutSeconds = 300): bool
    {
        if ($this->lastSeenAt === null) {
            return true;
        }

        return (microtime(true) - $this->lastSeenAt) > $timeoutSeconds;
    }

    /**
     * Get the device type identifier string.
     */
    public function getDeviceTypeUrn(): string
    {
        if ($this->deviceType === self::TYPE_SERVER) {
            return 'urn:schemas-upnp-org:device:MediaServer:1';
        }

        return 'urn:schemas-upnp-org:device:MediaRenderer:1';
    }

    /**
     * Generate device description XML.
     */
    public function toDeviceDescriptionXml(): string
    {
        $iconsXml = '';
        foreach ($this->icons as $icon) {
            $iconsXml .= sprintf(
                '<icon>
                    <mimetype>%s</mimetype>
                    <width>%d</width>
                    <height>%d</height>
                    <depth>%d</depth>
                    <url>%s</url>
                </icon>',
                htmlspecialchars($icon['mimetype'] ?? 'image/png'),
                $icon['width'] ?? 48,
                $icon['height'] ?? 48,
                $icon['depth'] ?? 32,
                htmlspecialchars($icon['url'] ?? '/icons/small.png')
            );
        }

        $servicesXml = '';
        foreach ($this->services as $service) {
            $servicesXml .= sprintf(
                '<service>
                    <serviceType>%s</serviceType>
                    <serviceId>%s</serviceId>
                    <controlURL>%s</controlURL>
                    <eventSubURL>%s</eventSubURL>
                    <SCPDURL>%s</SCPDURL>
                </service>',
                htmlspecialchars($service['serviceType']),
                htmlspecialchars($service['serviceId']),
                htmlspecialchars($service['controlUrl']),
                htmlspecialchars($service['eventSubUrl']),
                htmlspecialchars($service['SCPDURL'] ?? '')
            );
        }

        return sprintf(
            '<?xml version="1.0"?>
<root xmlns="urn:schemas-upnp-org:device-1-0">
    <specVersion>
        <major>1</major>
        <minor>0</minor>
    </specVersion>
    <device>
        <deviceType>%s</deviceType>
        <friendlyName>%s</friendlyName>
        <manufacturer>%s</manufacturer>
        <manufacturerURL>%s</manufacturerURL>
        <modelDescription>%s</modelDescription>
        <modelName>%s</modelName>
        <modelNumber>%s</modelNumber>
        <serialNumber>%s</serialNumber>
        <UDN>%s</UDN>
        <presentationURL>%s</presentationURL>
        <dlna:X_DLNADOC xmlns:dlna="urn:schemas-dlna-org:device-1-0">DMS-1.50</dlna:X_DLNADOC>
        <dlna:X_DLNACapability xmlns:dlna="urn:schemas-dlna-org:device-1-0"> playable</dlna:X_DLNACapability>
        <iconList>%s</iconList>
        <serviceList>%s</serviceList>
    </device>
</root>',
            htmlspecialchars($this->getDeviceTypeUrn()),
            htmlspecialchars($this->friendlyName),
            htmlspecialchars($this->manufacturer),
            htmlspecialchars($this->presentationUrl),
            htmlspecialchars($this->modelDescription),
            htmlspecialchars($this->modelName),
            htmlspecialchars($this->modelNumber),
            htmlspecialchars($this->serialNumber),
            htmlspecialchars($this->udn),
            htmlspecialchars($this->presentationUrl),
            $iconsXml ?: '<iconList/>',
            $servicesXml ?: '<serviceList/>'
        );
    }

    /**
     * Generate a unique device serial number.
     *
     * Creates a pseudo-random hex string in the format XXXX-XXXX-XXXX
     * for use as a device serial number when the actual serial is unknown.
     *
     * @return string A pseudo-random serial number
     */
    private function generateSerial(): string
    {
        return sprintf(
            '%04x%04x-%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Convert device to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'udn' => $this->udn,
            'device_type' => $this->deviceType,
            'friendly_name' => $this->friendlyName,
            'manufacturer' => $this->manufacturer,
            'model_description' => $this->modelDescription,
            'model_name' => $this->modelName,
            'model_number' => $this->modelNumber,
            'serial_number' => $this->serialNumber,
            'presentation_url' => $this->presentationUrl,
            'base_url' => $this->baseUrl,
            'port' => $this->port,
            'icons' => $this->icons,
            'services' => $this->services,
            'capabilities' => $this->capabilities,
            'discovered_at' => $this->discoveredAt,
            'last_seen_at' => $this->lastSeenAt,
            'is_active' => $this->isActive,
        ];
    }

    /**
     * Create device from array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $udn = isset($data['udn']) && is_string($data['udn']) ? $data['udn'] : '';
        $deviceType = isset($data['device_type']) && is_string($data['device_type'])
            ? $data['device_type']
            : self::TYPE_SERVER;
        $friendlyName = isset($data['friendly_name']) && is_string($data['friendly_name'])
            ? $data['friendly_name']
            : '';
        $baseUrl = isset($data['base_url']) && is_string($data['base_url']) ? $data['base_url'] : '';
        $port = isset($data['port']) && is_int($data['port']) ? $data['port'] : 80;

        $device = new self($udn, $deviceType, $friendlyName, $baseUrl, $port);

        if (isset($data['manufacturer']) && is_string($data['manufacturer'])) {
            $device->setManufacturer($data['manufacturer']);
        }
        if (isset($data['model_description']) && is_string($data['model_description'])) {
            $device->setModelDescription($data['model_description']);
        }
        if (isset($data['model_name']) && is_string($data['model_name'])) {
            $device->setModelName($data['model_name']);
        }
        if (isset($data['model_number']) && is_string($data['model_number'])) {
            $device->setModelNumber($data['model_number']);
        }

        return $device;
    }
}
