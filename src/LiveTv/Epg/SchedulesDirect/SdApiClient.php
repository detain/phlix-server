<?php

/**
 * Phlix media server component: SchedulesDirect.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Epg\SchedulesDirect;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\BoundedBodyReader;
use Psr\Log\LoggerInterface;

/**
 * HTTP JSON client for Schedules Direct API.
 *
 * Communicates with the SD API at https://api.schedulesdirect.tmsglobal.com
 * using token-based authentication. Tokens are passed via Authorization header.
 *
 * @since 0.12.0
 */
class SdApiClient
{
    /**
     * Base URL for the Schedules Direct API.
     */
    public const BASE_URL = 'https://api.schedulesdirect.tmsglobal.com';

    /**
     * Default HTTP request timeout in seconds.
     */
    private const DEFAULT_TIMEOUT = 30;

    /**
     * Hard cap on a single SD API response body.
     *
     * SD `/schedules` and `/programs` batches are legitimately large (multi-day
     * guides for hundreds of stations), so this sits ABOVE the XMLTV guide cap
     * (64 MiB): 128 MiB keeps real full-account syncs working while still
     * bounding worker memory against a hostile/compromised endpoint — the
     * transport host is constant-pinned to {@see BASE_URL}, but the byte bound
     * guards the (smaller) trust that the pinned host keeps its TLS/private-key.
     */
    public const MAX_RESPONSE_BYTES = 128 * 1024 * 1024;

    /**
     * Format every SD lineup/system ID must satisfy before it is embedded in a
     * request path ("USA-OTA-00000" style). Anything else is rejected loudly —
     * the value has upstream string sources (account lineup rows, config) and
     * path interpolation must not become a surface, even on a pinned host.
     */
    private const SYSTEM_ID_PATTERN = '/^[A-Za-z0-9._-]+$/';

    /** @var string SD API token */
    private string $token;

    /** @var StructuredLogger|null Optional logger */
    private ?StructuredLogger $logger;

    /** @var int Request timeout in seconds */
    private int $timeoutSecs;

    /**
     * Creates a new SdApiClient instance.
     *
     * @param string $token Pre-seeded SD API token
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger
     * @param int $timeoutSecs HTTP timeout in seconds (default: 30)
     */
    public function __construct(
        string $token,
        StructuredLogger|LoggerInterface|null $logger = null,
        int $timeoutSecs = self::DEFAULT_TIMEOUT
    ) {
        $this->token = $token;
        $this->logger = $logger instanceof StructuredLogger ? $logger : null;
        $this->timeoutSecs = $timeoutSecs;
    }

    /**
     * Validate the current token by calling the token endpoint.
     *
     * @return bool True if token is valid, false on 401 or failure
     */
    public function validateToken(): bool
    {
        $response = $this->get('/token');

        if ($response === null) {
            return false;
        }

        // A 200 with valid token returns an object with a token boolean or empty
        // A 401 returns null or empty response
        if (isset($response['token'])) {
            return true;
        }

        return false;
    }

    /**
     * Obtain a new token using username/password credentials.
     *
     * Uses HTTP Basic Auth with the SD account credentials.
     *
     * @param string $username SD account username
     * @param string $password SD account password
     * @return string|null New token on success, null on failure
     */
    public function fetchToken(string $username, string $password): ?string
    {
        $credentials = base64_encode("{$username}:{$password}");
        $headers = [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/json',
        ];

        $response = $this->post('/token', [], $headers);

        if ($response === null) {
            $this->logger?->error('Failed to fetch SD token', ['username' => $username]);
            return null;
        }

        // SD returns { "token": "..." } on success
        $token = $response['token'] ?? null;
        if (is_string($token)) {
            $this->token = $token;
            $this->logger?->info('Successfully fetched SD token');
            return $token;
        }

        $this->logger?->error('SD token response missing token field', ['response' => $response]);
        return null;
    }

    /**
     * Get available stations for a given lineup system ID.
     *
     * @param string $systemId The SD lineup/system ID (e.g., "USA-XXX-XXXXX")
     * @return array<string, mixed> Station list
     *
     * @throws \InvalidArgumentException When the ID is not a bare SD lineup
     *         token — refused loud at the boundary (parse-don't-validate) so
     *         path building below never sees interpolation-capable input.
     */
    public function getStations(string $systemId): array
    {
        /** @var array<string, mixed>|null $response */
        $response = $this->get(self::stationPath($systemId));

        if ($response === null) {
            return [];
        }

        return $response;
    }

    /**
     * Build the (validated + encoded) stations path for a lineup system ID.
     *
     * Pure and static so the boundary is unit-testable without any HTTP: the
     * format allowlist is the law; {@see rawurlencode()} rides along purely as
     * defence-in-depth — every byte the allowlist accepts is already
     * RFC-3986 path-safe, so the encoder is an identity map on valid input.
     *
     * @throws \InvalidArgumentException On any character outside [A-Za-z0-9._-]
     *         (empty strings included), and on dot-only tokens — a bare run of
     *         dots passes that byte allowlist yet {@see rawurlencode()} keeps
     *         "." literal (it is an RFC-3986 unreserved character), so
     *         ".." would ride through as the traversal segment
     *         "/headend/../station".
     */
    public static function stationPath(string $systemId): string
    {
        if (!preg_match(self::SYSTEM_ID_PATTERN, $systemId)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Schedules Direct lineup/system ID %s: expected a bare token matching %s.',
                json_encode($systemId),
                self::SYSTEM_ID_PATTERN
            ));
        }

        if (preg_match('/^\.+$/', $systemId)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Schedules Direct lineup/system ID %s: dot-only tokens are refused (path-segment traversal).',
                json_encode($systemId)
            ));
        }

        return '/headend/' . rawurlencode($systemId) . '/station';
    }

    /**
     * Get schedule MD5 hashes for a list of stations.
     *
     * Used to detect whether schedules have changed without fetching full data.
     *
     * @param array<int, string> $stationIds List of SD station IDs
     * @return array<string, mixed> MD5 hash entries keyed by station ID
     */
    public function getScheduleMd5(array $stationIds): array
    {
        if (empty($stationIds)) {
            return [];
        }

        /** @var array<string, mixed>|null $response */
        $response = $this->post('/schedules/md5', $stationIds);

        if ($response === null) {
            return [];
        }

        return $response;
    }

    /**
     * Get full schedule data for stations in a time window.
     *
     * @param array<int, string> $stationIds List of SD station IDs
     * @param int $startDate Start date as Unix timestamp
     * @param int $endDate End date as Unix timestamp
     * @return array<string, mixed> Schedule entries
     */
    public function getSchedules(array $stationIds, int $startDate, int $endDate): array
    {
        if (empty($stationIds)) {
            return [];
        }

        // SD API expects { "stationID": ["ID1", "ID2"], "date": "YYYY-MM-DDTHH:MM:SSZ" }
        $payload = [
            'stationID' => $stationIds,
            'date' => gmdate('Y-m-d\TH:i:s\Z', $startDate),
        ];

        /** @var array<string, mixed>|null $response */
        $response = $this->post('/schedules', $payload);

        if ($response === null) {
            $this->logger?->error('Failed to fetch SD schedules', [
                'station_count' => count($stationIds),
                'start_date' => $startDate,
            ]);
            return [];
        }

        return $response;
    }

    /**
     * Get program metadata for a list of program IDs.
     *
     * @param array<int, string> $programIds List of SD program IDs
     * @return array<string, mixed> Program metadata entries
     */
    public function getPrograms(array $programIds): array
    {
        if (empty($programIds)) {
            return [];
        }

        /** @var array<string, mixed>|null $response */
        $response = $this->post('/programs', $programIds);

        if ($response === null) {
            $this->logger?->error('Failed to fetch SD programs', [
                'program_count' => count($programIds),
            ]);
            return [];
        }

        return $response;
    }

    /**
     * Get available lineups for the account's country.
     *
     * @return array<string, mixed> Available lineups
     */
    public function getAvailableLineups(): array
    {
        /** @var array<string, mixed>|null $response */
        $response = $this->get('/lineups');

        if ($response === null) {
            return [];
        }

        return $response;
    }

    /**
     * Set the authentication token (e.g., after loading from cache).
     *
     * @param string $token New token to use
     * @return void
     */
    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    /**
     * Perform a GET request to the SD API.
     *
     * @param string $path API endpoint path
     * @return array<string, mixed>|null Decoded JSON response or null on failure
     */
    private function get(string $path): ?array
    {
        return $this->request('GET', $path);
    }

    /**
     * Perform a POST request to the SD API.
     *
     * @param string $path API endpoint path
     * @param mixed[] $payload Request body payload
     * @param array<int, string>|null $additionalHeaders Additional headers
     * @return array<string, mixed>|null Decoded JSON response or null on failure
     */
    private function post(string $path, array $payload = [], ?array $additionalHeaders = null): ?array
    {
        return $this->request('POST', $path, $payload, $additionalHeaders);
    }

    /**
     * Make an HTTP request to the SD API.
     *
     * @param string $method HTTP method (GET or POST)
     * @param string $path API endpoint path
     * @param mixed[]|null $payload Request body for POST
     * @param array<int, string>|null $additionalHeaders Extra headers
     * @return array<string, mixed>|null Decoded JSON or null on failure
     */
    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        ?array $additionalHeaders = null
    ): ?array {
        $url = self::BASE_URL . $path;

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
        ];

        if ($additionalHeaders !== null) {
            foreach ($additionalHeaders as $header) {
                $headers[] = $header;
            }
        }

        $requestOptions = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeoutSecs,
                'ignore_errors' => true,
            ],
        ];

        if ($payload !== null && $method === 'POST') {
            $requestOptions['http']['content'] = json_encode($payload);
            $requestOptions['http']['header'] .= "\r\nContent-Type: application/json";
        }

        $context = stream_context_create($requestOptions);

        $this->logger?->debug('SD API request', [
            'method' => $method,
            'url' => $url,
        ]);

        // Bounded read (F-5): fopen/stream so at most MAX_RESPONSE_BYTES + 1
        // bytes can ever buffer in the worker. The fetch STAYS in this scope
        // because $http_response_header below is the per-scope magic variable
        // populated by the stream open.
        $handle = @fopen($url, 'r', false, $context);

        if ($handle === false) {
            $error = error_get_last();
            $this->logger?->error('SD API request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $error['message'] ?? 'Unknown error',
            ]);
            return null;
        }

        try {
            $responseBody = BoundedBodyReader::read($handle, self::MAX_RESPONSE_BYTES);
        } catch (\RuntimeException $e) {
            $this->logger?->error('SD API response read failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            fclose($handle);
        }

        if (strlen($responseBody) > self::MAX_RESPONSE_BYTES) {
            $this->logger?->error('SD API response exceeds size cap', [
                'method' => $method,
                'url' => $url,
                'max_bytes' => self::MAX_RESPONSE_BYTES,
            ]);
            return null;
        }

        // Check for HTTP error codes in the response headers
        if (isset($http_response_header[0])) {
            preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $http_response_header[0], $matches);
            $statusCode = (int) ($matches[1] ?? 200);

            if ($statusCode === 401) {
                $this->logger?->warning('SD API returned 401 Unauthorized');
                return null;
            }

            if ($statusCode >= 400) {
                $this->logger?->warning('SD API error', [
                    'status_code' => $statusCode,
                    'response' => substr($responseBody, 0, 500),
                ]);
                return null;
            }
        }

        /** @var mixed $decoded */
        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            $this->logger?->error('Failed to decode SD API response as JSON', [
                'error' => json_last_error_msg(),
                'response' => substr($responseBody, 0, 500),
            ]);
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
