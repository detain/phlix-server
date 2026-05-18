<?php

declare(strict_types=1);

namespace Phlex\Arr;

use Phlex\Common\Logger\StructuredLogger;
use RuntimeException;

/**
 * Bazarr API client for subtitle management.
 *
 * @package Phlex\Arr
 * @since 0.12.0
 */
class BazarrClient
{
    private string $baseUrl;
    private string $apiKey;
    private ?StructuredLogger $logger;
    private int $timeout;

    /**
     * Creates a new BazarrClient.
     *
     * @param string $baseUrl Base URL of the Bazarr instance (e.g. `http://localhost:6767`).
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
     * Returns subtitles for a Sonarr series (and optionally a specific episode).
     *
     * @param string $sonarrSeriesId The Sonarr series ID.
     * @param int|null $episodeFileId Optional episode file ID to filter by.
     * @return array<int, array<string, mixed>> Subtitles list.
     */
    public function getSubtitles(string $sonarrSeriesId, ?int $episodeFileId = null): array
    {
        $path = '/api/v1/subtitles?sonarrSeriesId=' . urlencode($sonarrSeriesId);
        if ($episodeFileId !== null) {
            $path .= '&episodeFileId=' . $episodeFileId;
        }

        /** @var array<int, array<string, mixed>> */
        return $this->get($path);
    }

    /**
     * Returns available subtitle languages for a specific video file.
     *
     * @param string $videoFilePath The full path to the video file.
     * @return array<int, array<string, mixed>> Languages list.
     */
    public function getSubtitleLanguages(string $videoFilePath): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v1/languages?path=' . urlencode($videoFilePath));
    }

    /**
     * Downloads a subtitle for a specific video file and language.
     *
     * @param string $videoFilePath The full path to the video file.
     * @param string $languageCode The language code for the subtitle (e.g. `en`, `es`, `pt-BR`).
     * @return array<string, mixed> Download result.
     */
    public function downloadSubtitle(string $videoFilePath, string $languageCode): array
    {
        $payload = [
            'path' => $videoFilePath,
            'language' => $languageCode,
        ];

        return $this->post('/api/v1/subtitles/download', $payload);
    }

    /**
     * Returns all available subtitle languages configured in Bazarr.
     *
     * @return array<int, array<string, mixed>> Languages list.
     */
    public function getLanguages(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v1/languages/list');
    }

    /**
     * Tests connectivity and authentication with the Bazarr instance.
     *
     * @return bool True if connection is successful, false otherwise.
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->get('/api/v1/system');
            return isset($response['version']) || isset($response['bazarr']);
        } catch (RuntimeException $e) {
            $this->logger?->warning('Bazarr connection test failed: ' . $e->getMessage());
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
            throw new RuntimeException('Bazarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Bazarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Bazarr API error: HTTP ' . $httpCode);
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Bazarr');
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
            throw new RuntimeException('json_encode failed for Bazarr request body');
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
            throw new RuntimeException('Bazarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Bazarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Bazarr API error: HTTP ' . $httpCode);
        }

        if ($responseBody === '') {
            return [];
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Bazarr');
        }

        return $decoded;
    }

    /**
     * Builds the HTTP headers for Bazarr API requests.
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
