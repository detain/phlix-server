<?php

declare(strict_types=1);

namespace Phlex\Arr;

use Phlex\Common\Logger\StructuredLogger;
use RuntimeException;

/**
 * Radarr v3 API client for movie management.
 *
 * @package Phlex\Arr
 * @since 0.12.0
 */
class RadarrClient implements ArrClientInterface
{
    private string $baseUrl;
    private string $apiKey;
    private ?StructuredLogger $logger;
    private int $timeout;

    /**
     * Creates a new RadarrClient.
     *
     * @param string $baseUrl Base URL of the Radarr instance (e.g. `http://localhost:7878`).
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
     * Returns all tracked movies.
     *
     * @return array<int, array<string, mixed>> Movies list.
     */
    public function getMovies(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/movie');
    }

    /**
     * Returns a specific movie by its Radarr ID.
     *
     * @param int $radarrId The Radarr movie ID.
     * @return array<string, mixed> Movie data.
     */
    public function getMovieById(int $radarrId): array
    {
        return $this->get('/api/v3/movie/' . $radarrId);
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
     * Returns all custom formats.
     *
     * @return array<int, array<string, mixed>> Custom formats.
     */
    public function getCustomFormats(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->get('/api/v3/customformat');
    }

    /**
     * Creates a new custom format in Radarr.
     *
     * @param array<string, mixed> $payload The custom format payload.
     * @return int The ID of the created custom format.
     */
    public function createCustomFormat(array $payload): int
    {
        $response = $this->post('/api/v3/customformat', $payload);
        $id = $response['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Updates an existing custom format in Radarr.
     *
     * @param int $id The custom format ID to update.
     * @param array<string, mixed> $payload The custom format payload.
     * @return bool True on success.
     */
    public function updateCustomFormat(int $id, array $payload): bool
    {
        $this->put('/api/v3/customformat/' . $id, $payload);
        return true;
    }

    /**
     * Deletes a custom format from Radarr.
     *
     * @param int $id The custom format ID to delete.
     * @return bool True on success.
     */
    public function deleteCustomFormat(int $id): bool
    {
        $this->delete('/api/v3/customformat/' . $id);
        return true;
    }

    /**
     * Creates a new quality profile in Radarr.
     *
     * @param array<string, mixed> $payload The quality profile payload.
     * @return int The ID of the created quality profile.
     */
    public function createQualityProfile(array $payload): int
    {
        $response = $this->post('/api/v3/qualityprofile', $payload);
        $id = $response['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Updates an existing quality profile in Radarr.
     *
     * @param int $id The quality profile ID to update.
     * @param array<string, mixed> $payload The quality profile payload.
     * @return bool True on success.
     */
    public function updateQualityProfile(int $id, array $payload): bool
    {
        $this->put('/api/v3/qualityprofile/' . $id, $payload);
        return true;
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
     * Adds a new movie to Radarr.
     *
     * @param int|array<int> $tmdbId The TMDB ID or array of TMDB IDs.
     * @param int $qualityProfileId The quality profile ID to use.
     * @param string $rootFolder The root folder path.
     * @param bool $monitored Whether to monitor the movie (default true).
     * @return array<string, mixed> Created movie data.
     */
    public function addMovie(
        int|array $tmdbId,
        int $qualityProfileId,
        string $rootFolder,
        bool $monitored = true
    ): array {
        $payload = [
            'tmdbId' => $tmdbId,
            'qualityProfileId' => $qualityProfileId,
            'rootFolder' => $rootFolder,
            'monitored' => $monitored,
            'addOptions' => [
                'searchForMovie' => true,
            ],
        ];

        return $this->post('/api/v3/movie', $payload);
    }

    /**
     * Triggers a download for a movie by marking it as wanted and forcing a search.
     *
     * @param int $movieId The movie ID to trigger download for.
     * @return bool True if successful, false otherwise.
     */
    public function triggerDownload(int $movieId): bool
    {
        try {
            $this->post('/api/v3/release/' . $movieId, []);
            return true;
        } catch (RuntimeException $e) {
            $this->logger?->warning('Radarr trigger download failed: ' . $e->getMessage());
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
            $this->logger?->warning('Radarr connection test failed: ' . $e->getMessage());
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
            throw new RuntimeException('Radarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Radarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Radarr API error: HTTP ' . $httpCode);
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Radarr');
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
            throw new RuntimeException('json_encode failed for Radarr request body');
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
            throw new RuntimeException('Radarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Radarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Radarr API error: HTTP ' . $httpCode);
        }

        if ($responseBody === '') {
            return [];
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Radarr');
        }

        return $decoded;
    }

    /**
     * Performs a PUT request with a JSON body.
     *
     * @param string $path Request path.
     * @param array<string, mixed> $body JSON-serializable body.
     * @return array<mixed, mixed> Decoded JSON response.
     * @throws RuntimeException On network or HTTP errors.
     */
    protected function put(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        assert($url !== '');
        $encodedBody = json_encode($body);

        if ($encodedBody === false) {
            throw new RuntimeException('json_encode failed for Radarr request body');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => 'PUT',
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
            throw new RuntimeException('Radarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Radarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Radarr API error: HTTP ' . $httpCode);
        }

        if ($responseBody === '') {
            return [];
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Radarr');
        }

        return $decoded;
    }

    /**
     * Performs a DELETE request.
     *
     * @param string $path Request path.
     * @return array<mixed, mixed> Decoded JSON response.
     * @throws RuntimeException On network or HTTP errors.
     */
    protected function delete(string $path): array
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
            CURLOPT_CUSTOMREQUEST => 'DELETE',
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
            throw new RuntimeException('Radarr API authentication failed (401)');
        }

        if ($httpCode === 404) {
            throw new RuntimeException('Radarr API resource not found (404): ' . $path);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Radarr API error: HTTP ' . $httpCode);
        }

        if ($responseBody === '') {
            return [];
        }

        /** @var array<mixed, mixed> */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Radarr');
        }

        return $decoded;
    }

    /**
     * Builds the HTTP headers for Radarr API requests.
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
