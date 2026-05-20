<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Epg\SchedulesDirect;

use Phlix\Common\Logger\StructuredLogger;
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
     */
    public function getStations(string $systemId): array
    {
        /** @var array<string, mixed>|null $response */
        $response = $this->get("/headend/{$systemId}/station");

        if ($response === null) {
            return [];
        }

        return $response;
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

        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            $error = error_get_last();
            $this->logger?->error('SD API request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $error['message'] ?? 'Unknown error',
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
