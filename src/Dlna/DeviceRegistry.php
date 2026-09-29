<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Dlna;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Net\LanEndpointGuard;

/**
 * Device Registry for DLNA/UPnP Device Discovery and Management.
 *
 * Handles network device discovery via SSDP (Simple Service Discovery Protocol)
 * multicast and maintains a cache of discovered devices. Supports both
 * MediaServer and MediaRenderer device types.
 *
 * SSDP Discovery:
 * - Sends M-SEARCH multicast to discover devices on the local network
 * - Parses device descriptions from HTTP/XML responses
 * - Maintains device cache with TTL-based expiration
 *
 * @see SSDP Protocol For discovery mechanism details
 * @see DlnaDevice For device representation
 */
class DeviceRegistry
{
    /** SSDP multicast address for device discovery (UPnP standard) */
    public const SSDP_MULTICAST_ADDRESS = '239.255.255.250';

    /** SSDP port for device discovery (UPnP standard) */
    public const SSDP_PORT = 1900;

    /** Default discovery timeout in seconds */
    public const DEFAULT_TIMEOUT = 5;

    /** Default cache expiration in seconds (5 minutes) */
    public const DEFAULT_CACHE_TTL = 300;

    /** @var array<string, DlnaDevice> Device cache keyed by UDN */
    private array $devices = [];

    /** @var array<string, float> Unix timestamps for device cache expiration */
    private array $discoveryTimestamps = [];

    /** @var StructuredLogger Logger instance for debugging and diagnostics */
    private StructuredLogger $logger;

    /** @var int Cache TTL in seconds before devices are considered stale */
    private int $cacheTtl;

    /** @var bool Flag indicating if discovery is currently in progress */
    private bool $isDiscovering = false;

    /** @var array<int, string> Local IP addresses available on this host */
    private array $localAddresses = [];

    /**
     * @var (callable(string): (array{status: int, body: string}|null))|null Test seam.
     *      Null uses the bounded, redirect-free LAN fetcher. Injecting it opens zero sockets.
     */
    private $fetcher = null;

    /**
     * @param int                    $cacheTtl Seconds before cached devices go stale.
     * @param StructuredLogger|null  $logger   Optional injected logger.
     * @param (callable(string): (array{status: int, body: string}|null))|null $fetcher URL fetch seam (see $fetcher).
     */
    public function __construct(
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
        ?StructuredLogger $logger = null,
        ?callable $fetcher = null
    ) {
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->cacheTtl = $cacheTtl;
        $this->fetcher = $fetcher;
        $this->discoverLocalAddresses();
    }

    /**
     * The shared DLNA-channel logger — not a private one in a temp directory.
     *
     * The old body `mkdir()`ed a `sys_get_temp_dir()/phlix_dlna_reg_<uniqid>`
     * directory on every construction and pointed a private `StructuredLogger`
     * at a log file inside it — a per-instance leak that survived for the life
     * of the worker. `LoggerFactory::get()` returns one cached instance per
     * channel, so the whole family shares a single logger.
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
     * Discover devices on the local network via SSDP.
     *
     * @param int $timeout Timeout in seconds
     * @param bool $force Force fresh discovery (ignore cache)
     * @return array<string, DlnaDevice> Discovered devices
     */
    public function discover(int $timeout = self::DEFAULT_TIMEOUT, bool $force = false): array
    {
        if ($this->isDiscovering) {
            $this->logger->debug('Discovery already in progress');
            return $this->devices;
        }

        // Check if cache is still valid
        if (!$force && $this->isCacheValid()) {
            $this->logger->debug('Using cached device list', [
                'count' => count($this->devices),
                'age' => (time() - ($this->discoveryTimestamps['last'] ?? 0)) . 's',
            ]);
            return $this->devices;
        }

        $this->isDiscovering = true;
        $this->logger->info('Starting SSDP device discovery');

        try {
            $this->sendSsdpSearch($timeout);
            $this->discoveryTimestamps['last'] = (float) time();

            // Clean up stale devices
            $this->removeStaleDevices();

            $this->logger->info('Discovery complete', [
                'devices_found' => count($this->devices),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Discovery failed', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->isDiscovering = false;
        }

        return $this->devices;
    }

    /**
     * Send SSDP M-SEARCH broadcast to discover devices.
     */
    private function sendSsdpSearch(int $timeout): void
    {
        $socket = $this->createSsdpSocket();

        if ($socket === null) {
            $this->logger->warning('Could not create SSDP socket');
            return;
        }

        // Set receive timeout
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);

        // Build SSDP M-SEARCH request
        $searchRequest = implode("\r\n", [
            'M-SEARCH * HTTP/1.1',
            'HOST: ' . self::SSDP_MULTICAST_ADDRESS . ':' . self::SSDP_PORT,
            'MAN: "ssdp:discover"',
            'MX: ' . $timeout,
            'ST: ssdp:all',
            'USER-AGENT: Phlix/1.0',
            '',
            '',
        ]);

        // Send multicast request
        $sent = @socket_sendto(
            $socket,
            $searchRequest,
            strlen($searchRequest),
            0,
            self::SSDP_MULTICAST_ADDRESS,
            self::SSDP_PORT
        );
        if ($sent === false) {
            $this->logger->error('Failed to send SSDP search request');
            socket_close($socket);
            return;
        }

        // Receive responses
        $this->receiveSsdpResponses($socket);

        socket_close($socket);
    }

    /**
     * Create UDP socket for SSDP communication.
     *
     * Uses the `socket_*` API. The IP_MULTICAST_TTL / IP_MULTICAST_LOOP
     * constants are part of the `ext-sockets` extension; PHPStan's symbol
     * discovery cannot always see them on environments that build without
     * sockets, so we resolve them via `defined()` guards and the
     * `getprotobyname('ip')` protocol level for portability.
     */
    private function createSsdpSocket(): ?\Socket
    {
        $protoIp = getprotobyname('ip');
        if ($protoIp === false) {
            $protoIp = 0;
        }

        // Try each local address
        foreach ($this->localAddresses as $address) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!($socket instanceof \Socket)) {
                continue;
            }

            // Set socket options
            socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
            if (defined('IP_MULTICAST_TTL')) {
                @socket_set_option($socket, $protoIp, IP_MULTICAST_TTL, 4);
            }
            if (defined('IP_MULTICAST_LOOP')) {
                @socket_set_option($socket, $protoIp, IP_MULTICAST_LOOP, 0);
            }

            // Bind to local address
            if (@socket_bind($socket, $address, 0)) {
                $this->logger->debug('SSDP socket bound', [
                    'address' => $address,
                ]);
                return $socket;
            }

            socket_close($socket);
        }

        // Fallback: create unbound socket
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket instanceof \Socket) {
            socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
            if (defined('IP_MULTICAST_TTL')) {
                @socket_set_option($socket, $protoIp, IP_MULTICAST_TTL, 4);
            }
            return $socket;
        }

        return null;
    }

    /**
     * Receive SSDP responses from the socket.
     */
    private function receiveSsdpResponses(\Socket $socket): void
    {
        $buffer = '';
        $from = '';
        $port = 0;

        while (@socket_recvfrom($socket, $buffer, 4096, 0, $from, $port) !== false) {
            if (strlen($buffer) > 0) {
                $this->parseSsdpResponse($buffer, $from);
            }
            $buffer = '';
        }
    }

    /**
     * Parse an SSDP response and add device to registry.
     */
    private function parseSsdpResponse(string $response, string $sourceAddress): void
    {
        $this->logger->debug('Received SSDP response', [
            'from' => $sourceAddress,
            'length' => strlen($response),
        ]);

        // Parse HTTP-style headers
        $lines = explode("\r\n", $response);
        if (empty($lines)) {
            return;
        }

        // Check response status
        if (!preg_match('/^HTTP\/1\.\d\s+(\d+)/', $lines[0], $matches)) {
            return;
        }

        $statusCode = (int)$matches[1];
        if ($statusCode !== 200) {
            return;
        }

        // Parse headers
        $headers = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        // Extract device information
        $usn = $headers['usn'] ?? '';
        $st = $headers['st'] ?? '';
        $location = $headers['location'] ?? '';
        $cacheControl = $headers['cache-control'] ?? '';
        $server = $headers['server'] ?? '';

        if (empty($usn) || empty($location)) {
            return;
        }

        // Skip if we've already seen this device recently
        if (isset($this->devices[$usn])) {
            $this->devices[$usn]->touch();
            return;
        }

        // Parse cache control for max-age
        $maxAge = $this->parseMaxAge($cacheControl);

        // Fetch device description
        $device = $this->fetchDeviceDescription($usn, $st, $location, $sourceAddress, $server);

        if ($device !== null) {
            $this->devices[$usn] = $device;
            $this->discoveryTimestamps[$usn] = (float) (time() + $maxAge);

            $this->logger->info('Discovered device', [
                'udn' => $device->getUdn(),
                'name' => $device->getFriendlyName(),
                'type' => $device->getDeviceType(),
                'location' => $location,
            ]);
        }
    }

    /**
     * Parse max-age from Cache-Control header.
     */
    private function parseMaxAge(string $cacheControl): int
    {
        if (preg_match('/max-age=(\d+)/', $cacheControl, $matches)) {
            return (int)$matches[1];
        }
        return $this->cacheTtl;
    }

    /**
     * Fetch device description XML from location URL.
     */
    private function fetchDeviceDescription(
        string $usn,
        string $st,
        string $location,
        string $sourceAddress,
        string $server
    ): ?DlnaDevice {
        // Boundary law: the LOCATION host MUST be a literal IP equal to the datagram
        // source. A spoofed/unsolicited reply pointing elsewhere (metadata, another
        // host, a resolvable name) is refused here, BEFORE any socket is opened.
        $url = LanEndpointGuard::normalizeHttpUrl($location);

        if ($url === null) {
            $this->logger->warning('Rejected malformed SSDP LOCATION', ['location' => $location]);
            return null;
        }

        $host = LanEndpointGuard::hostOf($url);

        if (
            $host === null
            || !LanEndpointGuard::literalIpEqualsSource($host, $sourceAddress)
            || LanEndpointGuard::isRefusedAddress($host)
        ) {
            $this->logger->warning('Rejected SSDP LOCATION not pinned to datagram source', [
                'location' => $location,
                'source' => $sourceAddress,
            ]);
            return null;
        }

        $port = $this->locationPort($url);
        $path = parse_url($url, PHP_URL_PATH) ?: '/device.xml';

        // Fetch device description from the pinned origin only.
        $xml = $this->fetchBounded($url);

        if ($xml === null) {
            // Fallback: the root of the SAME pinned host (never a different authority).
            $xml = $this->fetchBounded($this->originUrl($host, $port) . '/');
        }

        if ($xml === null) {
            $this->logger->warning('Could not fetch device description', [
                'location' => $location,
            ]);
            return null;
        }

        return $this->parseDeviceDescription($xml, $usn, $host, $port, $url);
    }

    /**
     * Port advertised by a LOCATION URL, defaulting per scheme.
     */
    private function locationPort(string $url): int
    {
        $port = parse_url($url, PHP_URL_PORT);

        if (is_int($port)) {
            return $port;
        }

        return (parse_url($url, PHP_URL_SCHEME) === 'https') ? 443 : 80;
    }

    /**
     * Origin (scheme://host[:port]) for a pinned LAN host, IPv6 bracketed.
     */
    private function originUrl(string $host, int $port): string
    {
        $authority = str_contains($host, ':') ? "[$host]" : $host;

        return "http://{$authority}:{$port}";
    }

    /**
     * Perform a bounded, redirect-free LAN GET. follow_location=0 means a 3xx is a
     * failure (no redirect laundering to an ungated host); the body is capped at
     * MAX_RESPONSE_BYTES. Route goes through the injected seam when present.
     */
    private function fetchBounded(string $url): ?string
    {
        if ($this->fetcher !== null) {
            $result = ($this->fetcher)($url);

            if (!is_array($result)) {
                return null;
            }

            $status = (int)($result['status'] ?? 0);
            $body = (string)($result['body'] ?? '');

            if ($status < 200 || $status >= 300 || strlen($body) > LanEndpointGuard::MAX_RESPONSE_BYTES) {
                return null;
            }

            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 3,
                'follow_location' => 0,
                'max_redirects' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: Phlix/1.0\r\nConnection: close\r\n",
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);

        if ($handle === false) {
            return null;
        }

        $body = @stream_get_contents($handle, LanEndpointGuard::MAX_RESPONSE_BYTES + 1);
        $statusLine = $http_response_header[0] ?? '';
        @fclose($handle);

        if (!is_string($body) || strlen($body) > LanEndpointGuard::MAX_RESPONSE_BYTES) {
            return null;
        }

        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#i', (string)$statusLine, $m) !== 1) {
            return null;
        }

        $status = (int)$m[1];

        return ($status >= 200 && $status < 300) ? $body : null;
    }

    /**
     * Parse device description XML.
     */
    private function parseDeviceDescription(
        string $xml,
        string $usn,
        string $host,
        int $port,
        string $baseUrl = ''
    ): ?DlnaDevice {
        libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);

        if ($doc === false) {
            $this->logger->warning('Failed to parse device description XML');
            return null;
        }

        // Register namespaces
        $namespaces = $doc->getNamespaces(true);

        // Extract device info
        $deviceXml = $doc->device ?? $doc;

        if (!$deviceXml) {
            return null;
        }

        $deviceType = (string)($deviceXml->deviceType ?? '');
        $friendlyName = (string)($deviceXml->friendlyName ?? 'Unknown Device');
        $manufacturer = (string)($deviceXml->manufacturer ?? 'Unknown');
        $modelDescription = (string)($deviceXml->modelDescription ?? '');
        $modelName = (string)($deviceXml->modelName ?? '');
        $modelNumber = (string)($deviceXml->modelNumber ?? '');
        $serialNumber = (string)($deviceXml->serialNumber ?? '');
        $udn = (string)($deviceXml->UDN ?? $usn);
        $presentationUrl = (string)($deviceXml->presentationURL ?? '');

        // Determine device type
        $type = DlnaDevice::TYPE_SERVER;
        if (stripos($deviceType, 'MediaRenderer') !== false) {
            $type = DlnaDevice::TYPE_RENDERER;
        }

        $device = new DlnaDevice($udn, $type, $friendlyName, $host, $port);
        $device->setManufacturer($manufacturer);
        $device->setModelDescription($modelDescription);
        $device->setModelName($modelName);
        $device->setModelNumber($modelNumber);

        if (!empty($presentationUrl) && $baseUrl !== '') {
            // Secondary law: presentationURL must stay on the pinned host — a document
            // that advertises an off-host presentation page is truncated to nothing.
            $safePresentation = LanEndpointGuard::sameHostAbsolute($baseUrl, $presentationUrl);

            if ($safePresentation !== null) {
                $device->setPresentationUrl($safePresentation);
            }
        }

        // Add icons if present
        $iconList = $deviceXml->iconList ?? null;
        if ($iconList instanceof \SimpleXMLElement) {
            foreach ($iconList->icon ?? [] as $icon) {
                $device->addIcon([
                    'mimetype' => (string)($icon->mimetype ?? 'image/png'),
                    'width' => (int)($icon->width ?? 48),
                    'height' => (int)($icon->height ?? 48),
                    'depth' => (int)($icon->depth ?? 32),
                    'url' => (string)($icon->url ?? '/icon.png'),
                ]);
            }
        }

        return $device;
    }

    /**
     * Get all discovered devices.
     *
     * @return array<string, DlnaDevice>
     */
    public function getDevices(): array
    {
        return $this->devices;
    }

    /**
     * Get all devices of a specific type.
     *
     * @return array<string, DlnaDevice>
     */
    public function getDevicesByType(string $type): array
    {
        return array_filter(
            $this->devices,
            fn(DlnaDevice $device) => $device->getDeviceType() === $type
        );
    }

    /**
     * Get all media servers.
     *
     * @return array<string, DlnaDevice>
     */
    public function getServers(): array
    {
        return $this->getDevicesByType(DlnaDevice::TYPE_SERVER);
    }

    /**
     * Get all media renderers.
     *
     * @return array<string, DlnaDevice>
     */
    public function getRenderers(): array
    {
        return $this->getDevicesByType(DlnaDevice::TYPE_RENDERER);
    }

    /**
     * Get a specific device by UDN.
     */
    public function getDevice(string $udn): ?DlnaDevice
    {
        return $this->devices[$udn] ?? null;
    }

    /**
     * Register a device manually.
     */
    public function registerDevice(DlnaDevice $device): void
    {
        $this->devices[$device->getUdn()] = $device;
        $this->discoveryTimestamps[$device->getUdn()] = (float) time();

        $this->logger->info('Device registered manually', [
            'udn' => $device->getUdn(),
            'name' => $device->getFriendlyName(),
        ]);
    }

    /**
     * Unregister a device.
     */
    public function unregisterDevice(string $udn): bool
    {
        if (isset($this->devices[$udn])) {
            unset($this->devices[$udn]);
            unset($this->discoveryTimestamps[$udn]);

            $this->logger->info('Device unregistered', ['udn' => $udn]);
            return true;
        }

        return false;
    }

    /**
     * Check if cache is still valid.
     */
    private function isCacheValid(): bool
    {
        if (empty($this->discoveryTimestamps)) {
            return false;
        }

        $lastDiscovery = $this->discoveryTimestamps['last'] ?? 0;
        return (time() - $lastDiscovery) < $this->cacheTtl;
    }

    /**
     * Remove stale devices from cache.
     */
    private function removeStaleDevices(): void
    {
        $now = time();

        foreach ($this->devices as $udn => $device) {
            $expiry = $this->discoveryTimestamps[$udn] ?? 0;

            if ($expiry > 0 && $now > $expiry) {
                $this->logger->debug('Removing stale device', [
                    'udn' => $udn,
                    'name' => $device->getFriendlyName(),
                ]);
                unset($this->devices[$udn], $this->discoveryTimestamps[$udn]);
            } elseif ($device->hasTimedOut($this->cacheTtl)) {
                $this->logger->debug('Removing timed-out device', [
                    'udn' => $udn,
                ]);
                unset($this->devices[$udn], $this->discoveryTimestamps[$udn]);
            }
        }
    }

    /**
     * Clear all devices from cache.
     */
    public function clear(): void
    {
        $this->devices = [];
        $this->discoveryTimestamps = [];

        $this->logger->debug('Device registry cleared');
    }

    /**
     * Discover local network addresses.
     */
    private function discoverLocalAddresses(): void
    {
        $this->localAddresses = [];

        // Try to get local IP from system routes
        $output = [];
        @exec('ip route get 1 | head -1', $output, $result);

        if ($result === 0 && !empty($output[0])) {
            if (preg_match('/src\s+(\d+\.\d+\.\d+\.\d+)/', $output[0], $matches)) {
                $this->localAddresses[] = $matches[1];
            }
        }

        // Fallback: get from hostname
        if (empty($this->localAddresses)) {
            $hostname = gethostname();
            if (is_string($hostname)) {
                $addresses = gethostbynamel($hostname);
                if (is_array($addresses)) {
                    $this->localAddresses = array_values(array_filter(
                        $addresses,
                        static fn(string $addr): bool => filter_var(
                            $addr,
                            FILTER_VALIDATE_IP,
                            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                        ) !== false
                    ));
                }
            }
        }

        // Last resort: use 0.0.0.0
        if (empty($this->localAddresses)) {
            $this->localAddresses[] = '0.0.0.0';
        }

        $this->logger->debug('Local addresses discovered', [
            'addresses' => $this->localAddresses,
        ]);
    }

    /**
     * Get device count.
     */
    public function getDeviceCount(): int
    {
        return count($this->devices);
    }

    /**
     * Get servers count.
     */
    public function getServerCount(): int
    {
        return count($this->getServers());
    }

    /**
     * Get renderers count.
     */
    public function getRendererCount(): int
    {
        return count($this->getRenderers());
    }

    /**
     * Check if a device with given UDN exists.
     */
    public function hasDevice(string $udn): bool
    {
        return isset($this->devices[$udn]);
    }

    /**
     * Get devices as array.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->devices as $udn => $device) {
            $result[$udn] = $device->toArray();
        }
        return $result;
    }
}
