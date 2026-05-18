<?php

declare(strict_types=1);

namespace Phlex\Arr;

use Phlex\Common\Logger\StructuredLogger;
use RuntimeException;

/**
 * Prowlarr API client for indexer management.
 *
 * @package Phlex\Arr
 * @since 0.12.0
 */
class ProwlarrClient
{
    private string $baseUrl;
    private string $apiKey;
    private ?StructuredLogger $logger;
    private int $timeout;

    /**
     * Creates a new ProwlarrClient.
     *
     * @param string $baseUrl Base URL of the Prowlarr instance (e.g. `http://localhost:9696`).
     * @param string $apiKey  API key for authentication.
     * @param StructuredLogger|null $logger Optional logger instance.
     * @param int $timeout Request timeout in seconds (default 30).
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        ?StructuredLogger $logger = null,
        int $timeout = 30
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->timeout = $timeout;
    }

    /**
     * Returns all configured indexers.
     *
     * @return array<int, array<string, mixed>> Indexers list.
     */
    public function getIndexers(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v1/indexer');
    }

    /**
     * Returns statistics for a specific indexer.
     *
     * @param int $indexerId The indexer ID.
     * @return array<string, mixed> Indexer stats.
     */
    public function getIndexerStats(int $indexerId): array
    {
        return $this->get('/api/v1/indexer/' . $indexerId);
    }

    /**
     * Returns the health check results for Prowlarr.
     *
     * @return array<int, array<string, mixed>> Health issues.
     */
    public function getHealth(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v1/health');
    }

    /**
     * Triggers a reindex check for a specific indexer.
     *
     * @param int $indexerId The indexer ID to recheck.
     * @return bool True if the recheck was triggered successfully, false otherwise.
     */
    public function triggerReindexerCheck(int $indexerId): bool
    {
        try {
            $this->post('/api/v1/indexer/' . $indexerId . '/recheck', []);
            return true;
        } catch (RuntimeException $e) {
            $this->logger?->warning('Prowlarr trigger reindexer check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Tests connectivity and authentication with the Prowlarr instance.
     *
     * @return bool True if connection is successful, false otherwise.
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->get('/api/v1/system/status');
            return isset($response['version']);
        } catch (RuntimeException $e) {
            $this->logger?->warning('Prowlarr connection test failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Performs a GET request.
     *
     * @param string $path Request path.
     * @return array<mixed, mixed> Decoded JSON response.
     * @throws RuntimeException On network or HTTP errors.
     */
    protected function get(string $path): array
    {
        $url = $this->baseUrl . $path;
        assert($url !== '');

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);

        /** @var string|false */
        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlErrno !== 0) {
            throw new RuntimeException('cURL error: ' . $curlError, $curlErrno);
        }

        if ($httpCode === 401) {
            throw new RuntimeException('Prowlarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Prowlarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Prowlarr API error: HTTP ' . $httpCode);
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Prowlarr');
        }

        return $decoded;
    }

    /**
     * Performs a POST request with a JSON body.
     *
     * @param string $path Request path.
     * @param array<string, mixed> $body JSON-serializable body.
     * @return array<mixed, mixed> Decoded JSON response.
     * @throws RuntimeException On network or HTTP errors.
     */
    protected function post(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        assert($url !== '');
        $encodedBody = json_encode($body);

        if ($encodedBody === false) {
            throw new RuntimeException('json_encode failed for Prowlarr request body');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encodedBody,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);

        /** @var string|false */
        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlErrno !== 0) {
            throw new RuntimeException('cURL error: ' . $curlError, $curlErrno);
        }

        if ($httpCode === 401) {
            throw new RuntimeException('Prowlarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Prowlarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Prowlarr API error: HTTP ' . $httpCode);
        }

        if ($responseBody === '') {
            return [];
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Prowlarr');
        }

        return $decoded;
    }

    /**
     * Builds the HTTP headers for Prowlarr API requests.
     *
     * @return array<string> Headers array.
     */
    private function buildHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Api-Key: ' . $this->apiKey,
        ];
    }
}
