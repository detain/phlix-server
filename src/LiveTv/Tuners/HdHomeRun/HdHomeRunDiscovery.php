<?php

/**
 * Phlix media server component: HdHomeRun.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\HdHomeRun;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Net\LanEndpointGuard;
use Psr\Log\LoggerInterface;

/**
 * Discovers HDHomeRun devices on the local network via SSDP.
 *
 * Sends an M-SEARCH broadcast on UDP port 1900 and collects NOTIFY responses
 * from HDHomeRun devices, then fetches and parses their device description XML.
 *
 * @since 0.12.0
 */
class HdHomeRunDiscovery
{
    /** SSDP multicast address for device discovery */
    private const SSDP_MULTICAST_ADDR = '239.255.255.250';

    /** SSDP port for device discovery */
    private const SSDP_PORT = 1900;

    /** @var array<string, true> Track seen device IDs to avoid duplicates */
    private array $seenDeviceIds = [];

    /** @var StructuredLogger|LoggerInterface|null Optional logger */
    private StructuredLogger|LoggerInterface|null $logger;

    /** @var int Socket timeout in seconds */
    private int $timeoutSecs;

    /**
     * @var (callable(string): (array{status: int, body: string}|null))|null Test seam.
     *      Null uses the bounded, redirect-free LAN fetcher.
     */
    private $fetcher = null;

    /**
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger instance
     * @param int $timeoutSecs Socket timeout in seconds (default 5)
     * @param (callable(string): (array{status: int, body: string}|null))|null $fetcher URL fetch seam (see $fetcher)
     */
    public function __construct(
        StructuredLogger|LoggerInterface|null $logger = null,
        int $timeoutSecs = 5,
        ?callable $fetcher = null
    ) {
        $this->logger = $logger;
        $this->timeoutSecs = $timeoutSecs;
        $this->fetcher = $fetcher;
    }

    /**
     * Discover all HDHomeRun devices on the network.
     *
     * Sends SSDP M-SEARCH, collects responses, fetches device XML,
     * and returns fully-populated HdHomeRunDevice objects.
     *
     * @return HdHomeRunDevice[] Array of discovered devices (empty if none found)
     */
    public function discover(): array
    {
        $this->logger?->info('Starting HDHomeRun SSDP discovery');

        try {
            $responses = $this->sendSearch();
        } catch (\Throwable $e) {
            $this->logger?->warning('SSDP search failed', ['error' => $e->getMessage()]);
            return [];
        }

        $devices = [];

        foreach ($responses as $packet) {
            $response = (string) ($packet['response'] ?? '');
            $source = (string) ($packet['source'] ?? '');

            $locationUrl = $this->extractLocation($response);
            if ($locationUrl === null) {
                continue;
            }

            $deviceInfo = $this->fetchDeviceDescription($locationUrl, $source);
            if ($deviceInfo === null) {
                continue;
            }

            $deviceId = is_string($deviceInfo['device_id'] ?? null) ? $deviceInfo['device_id'] : null;
            if ($deviceId === null || isset($this->seenDeviceIds[$deviceId])) {
                continue;
            }

            $this->seenDeviceIds[$deviceId] = true;

            $hostFromUrl = parse_url($locationUrl, PHP_URL_HOST);
            $ipAddress = is_string($deviceInfo['ip_address'] ?? null)
                ? $deviceInfo['ip_address']
                : (is_string($hostFromUrl) ? $hostFromUrl : '');
            $tunerCount = is_int($deviceInfo['tuner_count'] ?? null) || is_numeric($deviceInfo['tuner_count'] ?? null)
                ? (int) $deviceInfo['tuner_count']
                : 1;
            $lineupUrl = is_string($deviceInfo['lineup_url'] ?? null) ? $deviceInfo['lineup_url'] : $locationUrl .
                '/lineup.json';

            $devices[] = new HdHomeRunDevice(
                deviceId: $deviceId,
                ipAddress: $ipAddress,
                tunerCount: $tunerCount,
                lineupUrl: $lineupUrl,
            );
        }

        $this->logger?->info('HDHomeRun discovery complete', ['device_count' => count($devices)]);

        return $devices;
    }

    /**
     * Send SSDP M-SEARCH broadcast and collect responses.
     *
     * Each entry carries the reply body AND the datagram source address, so the caller
     * can pin the advertised LOCATION to whoever actually sent it (SSRF defence).
     *
     * @return list<array{response: string, source: string}>
     */
    private function sendSearch(): array
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new \RuntimeException('Failed to create UDP socket: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeoutSecs, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeoutSecs, 'usec' => 0]);

        $msearch = "M-SEARCH * HTTP/1.1\r\n"
            . "HOST: " . self::SSDP_MULTICAST_ADDR . ":" . self::SSDP_PORT . "\r\n"
            . "MAN: \"ssdp:discover\"\r\n"
            . "MX: {$this->timeoutSecs}\r\n"
            . "ST: urn:schemas-upnp-org:device:MediaServer:1\r\n"
            . "USER-AGENT: Phlix/1.0\r\n"
            . "\r\n";

        $sent = @socket_sendto($socket, $msearch, strlen($msearch), 0, self::SSDP_MULTICAST_ADDR, self::SSDP_PORT);
        if ($sent === false) {
            socket_close($socket);
            throw new \RuntimeException('Failed to send SSDP M-SEARCH: ' . socket_strerror(socket_last_error($socket)));
        }

        $responses = [];
        while (true) {
            $buf = '';
            $from = '';
            $port = 0;

            $recv = @socket_recvfrom($socket, $buf, 65536, 0, $from, $port);
            if ($recv === false) {
                $err = socket_last_error($socket);
                if ($err !== 11 && $err !== 0) { // EAGAIN/EWOULDBLOCK means no more data
                    $this->logger?->warning('Socket recv error', ['error' => socket_strerror($err)]);
                }
                break;
            }

            if ($recv === 0) {
                break;
            }

            $response = trim($buf);
            if (stripos($response, 'hdhomerun') !== false) {
                $responses[] = ['response' => $response, 'source' => $from];
            }
        }

        socket_close($socket);

        return $responses;
    }

    /**
     * Extract the Location header URL from an SSDP response.
     *
     * @param string $response The SSDP response string
     * @return string|null The location URL or null if not found
     */
    private function extractLocation(string $response): ?string
    {
        if (preg_match('/^Location:\s*(.+)$/mi', $response, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/^LOCATION:\s*(.+)$/mi', $response, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Fetch and parse a device's XML description.
     *
     * SSRF defence: the advertised LOCATION must be an http(s) URL whose host is a
     * literal IP EQUAL to the datagram source (a spoofed reply pointing at metadata or
     * another host is refused BEFORE any socket opens), inside RFC1918, and not a refused
     * range. The description fetch is bounded (no redirects, capped body).
     *
     * @param string $locationUrl The device's advertised location URL
     * @param string $source      Datagram source address of the SSDP reply
     * @return array<string, mixed>|null Parsed device info or null on failure
     */
    private function fetchDeviceDescription(string $locationUrl, string $source): ?array
    {
        $url = LanEndpointGuard::normalizeHttpUrl($locationUrl);
        $host = $url !== null ? LanEndpointGuard::hostOf($url) : null;

        if (
            $url === null
            || $host === null
            || !LanEndpointGuard::literalIpEqualsSource($host, $source)
            || LanEndpointGuard::isRefusedAddress($host)
            || !LanEndpointGuard::isLanAddress($host)
        ) {
            $this->logger?->warning('Rejected HDHomeRun LOCATION not pinned to source', [
                'url' => $locationUrl,
                'source' => $source,
            ]);
            return null;
        }

        $xmlContent = $this->fetchBounded($url);
        if ($xmlContent === null) {
            $this->logger?->warning('Failed to fetch device description', ['url' => $url]);
            return null;
        }

        $previousErrorHandling = libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);

        if ($xml === false) {
            $this->logger?->warning('Failed to parse device XML', ['url' => $url]);
            return null;
        }

        // Parse XML and extract device info
        // HDHomeRun typically returns a simple XML with base URL info
        $deviceInfo = [
            'device_id' => null,
            'ip_address' => null,
            'tuner_count' => 1,
            'lineup_url' => null,
        ];

        // Try to get device ID from the friendly name or serial
        $friendlyName = (string) ($xml->friendlyName ?? '');
        if (preg_match('/([0-9A-F]{8})/i', $friendlyName, $matches)) {
            $deviceInfo['device_id'] = $matches[1];
        }

        // IP is the pinned source literal — never the (already-checked) URL authority alone.
        $deviceInfo['ip_address'] = $host;

        // HDHomeRun device XML typically has a specific structure
        // Extract available tuner count if present
        $tunerCount = (int) ($xml->tunerCount ?? $xml->tuner_count ?? 1);
        if ($tunerCount > 0) {
            $deviceInfo['tuner_count'] = $tunerCount;
        }

        // Build lineup URL on the pinned origin (scheme + host + advertised port).
        $origin = LanEndpointGuard::originOf($url) ?? ('http://' . $host);
        $deviceInfo['lineup_url'] = $origin . '/lineup.json';

        return $deviceInfo;
    }

    /**
     * Bounded, redirect-free GET for the pinned device origin.
     *
     * @return string|null Response body (2xx, <= MAX_RESPONSE_BYTES) or null.
     */
    private function fetchBounded(string $url): ?string
    {
        if ($this->fetcher !== null) {
            $result = ($this->fetcher)($url);

            if (!is_array($result)) {
                return null;
            }

            $status = (int) ($result['status'] ?? 0);
            $body = (string) ($result['body'] ?? '');

            if ($status < 200 || $status >= 300 || strlen($body) > LanEndpointGuard::MAX_RESPONSE_BYTES) {
                return null;
            }

            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSecs,
                'follow_location' => 0,
                'max_redirects' => 1,
                'ignore_errors' => true,
                'user_agent' => 'Phlix/1.0',
                'header' => "Connection: close\r\n",
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

        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#i', (string) $statusLine, $m) !== 1) {
            return null;
        }

        $status = (int) $m[1];

        return ($status >= 200 && $status < 300) ? $body : null;
    }
}
