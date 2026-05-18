<?php

declare(strict_types=1);

namespace Phlex\Arr;

use Phlex\Common\Logger\StructuredLogger;
use RuntimeException;

/**
 * Sonarr v3 API client for TV series management.
 *
 * @package Phlex\Arr
 * @since 0.12.0
 */
class SonarrClient implements ArrClientInterface
{
    private string $baseUrl;
    private string $apiKey;
    private ?StructuredLogger $logger;
    private int $timeout;

    /**
     * Creates a new SonarrClient.
     *
     * @param string $baseUrl Base URL of the Sonarr instance (e.g. `http://localhost:8989`).
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
     * {@inheritdoc}
     */
    public function getQueue(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/queue');
    }

    /**
     * Returns all tracked series.
     *
     * @return array<int, array<string, mixed>> Series list.
     */
    public function getSeries(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/series');
    }

    /**
     * Returns a specific series by its Sonarr ID.
     *
     * @param int $sonarrSeriesId The Sonarr series ID.
     * @return array<string, mixed> Series data.
     */
    public function getSeriesById(int $sonarrSeriesId): array
    {
        return $this->get('/api/v3/series/' . $sonarrSeriesId);
    }

    /**
     * Returns a specific episode file by its ID.
     *
     * @param int $episodeId The episode file ID.
     * @return array<string, mixed> Episode file data.
     */
    public function getEpisodeFile(int $episodeId): array
    {
        return $this->get('/api/v3/episodefile/' . $episodeId);
    }

    /**
     * Returns missing episodes (wanted).
     *
     * @param int|null $startSeason Optional season number to filter by.
     * @return array<int, array<string, mixed>> Missing episodes.
     */
    public function getWantedMissing(?int $startSeason = null): array
    {
        $path = '/api/v3/wanted/missing';
        if ($startSeason !== null) {
            $path .= '?season=' . $startSeason;
        }

        /** @var array<int, array<string, mixed>> */
        return $this->get($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getQualityProfiles(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/qualityprofile');
    }

    /**
     * {@inheritdoc}
     */
    public function getTagList(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/tag');
    }

    /**
     * Adds a new series to Sonarr.
     *
     * @param int|array<int> $tvdbId The TVDB ID or array of TVDB IDs.
     * @param int $qualityProfileId The quality profile ID to use.
     * @param int $rootFolder The root folder path index.
     * @param string|null $monitor Monitoring option (default 'all').
     * @return array<string, mixed> Created series data.
     */
    public function addSeries(
        int|array $tvdbId,
        int $qualityProfileId,
        int $rootFolder,
        ?string $monitor = 'all'
    ): array {
        $payload = [
            'tvdbId' => $tvdbId,
            'qualityProfileId' => $qualityProfileId,
            'rootFolder' => $rootFolder,
            'monitor' => $monitor ?? 'all',
        ];

        return $this->post('/api/v3/series', $payload);
    }

    /**
     * Triggers a download for an episode by marking it as wanted and forcing a search.
     *
     * @param int $episodeId The episode ID to trigger download for.
     * @return bool True if successful, false otherwise.
     */
    public function triggerDownload(int $episodeId): bool
    {
        try {
            $this->post('/api/v3/release/' . $episodeId, []);
            return true;
        } catch (RuntimeException $e) {
            $this->logger?->warning('Sonarr trigger download failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->get('/api/v3/system/status');
            return isset($response['version']);
        } catch (RuntimeException $e) {
            $this->logger?->warning('Sonarr connection test failed: ' . $e->getMessage());
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
            throw new RuntimeException('Sonarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Sonarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Sonarr API error: HTTP ' . $httpCode);
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Sonarr');
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
            throw new RuntimeException('json_encode failed for Sonarr request body');
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
            throw new RuntimeException('Sonarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Sonarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Sonarr API error: HTTP ' . $httpCode);
        }

        if ($responseBody === '') {
            return [];
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Sonarr');
        }

        return $decoded;
    }

    /**
     * Builds the HTTP headers for Sonarr API requests.
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
