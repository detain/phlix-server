<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\HdHomeRun;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
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
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger instance
     * @param int $timeoutSecs Socket timeout in seconds (default 5)
     */
    public function __construct(
        StructuredLogger|LoggerInterface|null $logger = null,
        int $timeoutSecs = 5
    ) {
        $this->logger = $logger;
        $this->timeoutSecs = $timeoutSecs;
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

        foreach ($responses as $response) {
            $locationUrl = $this->extractLocation($response);
            if ($locationUrl === null) {
                continue;
            }

            $deviceInfo = $this->fetchDeviceDescription($locationUrl);
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
            $lineupUrl = is_string($deviceInfo['lineup_url'] ?? null) ? $deviceInfo['lineup_url'] : $locationUrl . '/lineup.json';

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
     * @return string[] Array of response strings
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
                $responses[] = $response;
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
     * @param string $locationUrl The device's location URL
     * @return array<string, mixed>|null Parsed device info or null on failure
     */
    private function fetchDeviceDescription(string $locationUrl): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeoutSecs,
                'method' => 'GET',
                'user_agent' => 'Phlix/1.0',
            ],
        ]);

        $xmlContent = @file_get_contents($locationUrl, false, $context);
        if ($xmlContent === false) {
            $this->logger?->warning('Failed to fetch device description', ['url' => $locationUrl]);
            return null;
        }

        $previousErrorHandling = libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($xmlContent);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);

        if ($xml === false) {
            $this->logger?->warning('Failed to parse device XML', ['url' => $locationUrl]);
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

        // Try to get IP from URL
        $host = parse_url($locationUrl, PHP_URL_HOST);
        if ($host !== null) {
            $deviceInfo['ip_address'] = $host;
        }

        // HDHomeRun device XML typically has a specific structure
        // Extract available tuner count if present
        $tunerCount = (int) ($xml->tunerCount ?? $xml->tuner_count ?? 1);
        if ($tunerCount > 0) {
            $deviceInfo['tuner_count'] = $tunerCount;
        }

        // Build lineup URL
        $deviceInfo['lineup_url'] = 'http://' . $deviceInfo['ip_address'] . '/lineup.json';

        return $deviceInfo;
    }
}
