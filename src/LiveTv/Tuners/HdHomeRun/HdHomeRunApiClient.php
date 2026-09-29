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
use Phlix\Common\Net\SsrfGuard;
use Psr\Log\LoggerInterface;

/**
 * HTTP API client for HDHomeRun devices.
 *
 * Communicates with HDHomeRun devices via their HTTP API on port 80.
 * All API calls are GET requests; responses are JSON.
 *
 * @since 0.12.0
 */
class HdHomeRunApiClient
{
    /** Default HTTP timeout in seconds */
    private const DEFAULT_TIMEOUT = 10;

    /** @var string Base URL for the device API (e.g. "http://192.168.1.100") */
    private string $baseUrl;

    /** @var StructuredLogger|LoggerInterface|null Optional logger */
    private StructuredLogger|LoggerInterface|null $logger;

    /**
     * @param string $baseUrl Base URL for the device (e.g. "http://192.168.1.100")
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        string $baseUrl,
        StructuredLogger|LoggerInterface|null $logger = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger;
    }

    /**
     * Get device information via /discover.json.
     *
     * @return array<string, mixed>|false Device info hash or false on failure
     */
    public function discover(): array|false
    {
        $result = $this->get('/discover.json');
        return is_array($result) ? $result : false;
    }

    /**
     * Get available channel lineup via /lineup.json.
     *
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:int,
     * program_id:int|null}> Channel list
     */
    public function getChannelLineup(): array
    {
        $lineup = $this->get('/lineup.json');

        if (!is_array($lineup)) {
            return [];
        }

        // HDHomeRun /lineup.json returns array of channel objects
        /** @var array<int, array<string, mixed>> $lineup */
        return array_values(array_map(function (array $item): array {
            $channelNum = 0;
            if (isset($item['channel']) && is_numeric($item['channel'])) {
                $channelNum = (int) $item['channel'];
            } elseif (isset($item['channel_number']) && is_numeric($item['channel_number'])) {
                $channelNum = (int) $item['channel_number'];
            }

            return [
                'channel_number' => $channelNum,
                'name' => isset($item['name']) && is_string($item['name']) ? $item['name'] : '',
                'type' => isset($item['type']) && is_string($item['type']) ? $item['type'] : 'off',
                'transport_stream_id' => isset($item['transport_stream_id']) &&
                    is_numeric($item['transport_stream_id']) ? (int) $item['transport_stream_id'] : 0,
                'program_id' => isset($item['program_id']) && is_numeric($item['program_id']) ?
                    (int) $item['program_id'] : null,
            ];
        }, $lineup));
    }

    /**
     * Trigger a channel scan via /lineup.post.
     *
     * @return bool True if scan was triggered successfully
     */
    public function triggerScan(): bool
    {
        $result = $this->get('/lineup.post');

        // HDHomeRun returns empty body or "<?xml" on success
        // Any non-exception result is considered success
        return $result !== false;
    }

    /**
     * Get tuning result for a physical channel via /tuningformatail.
     *
     * @param string $channel The channel to tune (e.g. "2", "2.1", or "vchan:29")
     * @return array<string, mixed>|false Tuning result or false on failure
     */
    public function getTuningResult(string $channel): array|false
    {
        $query = http_build_query(['channel' => $channel]);
        $result = $this->get('/tuningformatail?' . $query);
        return is_array($result) ? $result : false;
    }

    /**
     * Return the HLS stream URL for a channel number.
     *
     * HDHomeRun devices provide HLS streams via a specific URL format.
     *
     * @param int $channelNumber The channel number to tune
     * @return string The HLS stream URL
     */
    public function getStreamUrl(int $channelNumber): string
    {
        // HDHomeRun HLS stream URL format
        // The stream URL uses the channel number directly
        return $this->baseUrl . '/watch?channel=' . $channelNumber;
    }

    /**
     * Perform a GET request to the HDHomeRun device API.
     *
     * @param string $path API endpoint path
     * @return array<string, mixed>|false|string Decoded JSON response, raw string, or false on failure
     */
    private function get(string $path): array|false|string
    {
        $url = $this->baseUrl . $path;

        // Boundary gate: a tuner host is either a LAN address or this machine (localhost
        // self-hosted setups). Scheme must be http(s). Cloud-metadata / other ranges and
        // any other scheme are refused BEFORE the socket — closing the SSRF vector where a
        // spoofed discovery reply seeded a hostile lineup/stream host.
        $normalized = LanEndpointGuard::normalizeHttpUrl($url);
        $host = $normalized !== null ? LanEndpointGuard::hostOf($normalized) : null;

        if ($normalized === null || $host === null || !self::isAllowedTunerHost($host)) {
            $this->logger?->warning('Refused HDHomeRun API request to a disallowed host', ['url' => $url]);
            return false;
        }

        $this->logger?->debug('HDHomeRun API request', ['url' => $normalized]);

        $context = stream_context_create([
            'http' => [
                'timeout' => self::DEFAULT_TIMEOUT,
                'method' => 'GET',
                'follow_location' => 0,
                'max_redirects' => 1,
                'user_agent' => 'Phlix/1.0',
                'ignore_errors' => true,
                'header' => "Connection: close\r\n",
            ],
        ]);

        $handle = @fopen($normalized, 'rb', false, $context);
        if ($handle === false) {
            $this->logger?->warning('HDHomeRun API request failed', ['url' => $normalized]);
            return false;
        }

        $response = @stream_get_contents($handle, LanEndpointGuard::MAX_RESPONSE_BYTES + 1);
        @fclose($handle);

        if (!is_string($response) || strlen($response) > LanEndpointGuard::MAX_RESPONSE_BYTES) {
            $this->logger?->warning('HDHomeRun API response unreadable or oversized', ['url' => $normalized]);
            return false;
        }

        // Try to decode as JSON - $response is guaranteed to be string here (not false)
        $decoded = json_decode($response, true);
        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        // Return raw response if not JSON
        return '';
    }

    /**
     * Is a tuner host allowed for API traffic? RFC1918 LAN space or loopback (a tuner
     * on this host). Everything else — link-local/metadata (169.254.169.254), CGNAT,
     * multicast, reserved, and public addresses — is refused.
     */
    private static function isAllowedTunerHost(string $host): bool
    {
        if (!LanEndpointGuard::isIpLiteral($host)) {
            // Hostname tuner URLs are not supported by this LAN path; only literals pin.
            return false;
        }

        if (LanEndpointGuard::isLanAddress($host)) {
            return true;
        }

        $effective = SsrfGuard::embeddedIpv4($host) ?? $host;

        return SsrfGuard::ipMatchesAnyCidr($effective, ['127.0.0.0/8', '::1/128']);
    }
}
