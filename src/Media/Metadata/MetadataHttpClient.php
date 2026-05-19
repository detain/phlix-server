<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

/**
 * MetadataHttpClient provides HTTP communication with metadata provider APIs.
 *
 * This client handles HTTP requests to external metadata services with built-in
 * caching, error handling, and API key authentication. It validates JSON responses
 * and logs errors appropriately.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description HTTP client for metadata provider API communication with caching
 */
class MetadataHttpClient
{
    /** @var string Base URL for API requests (without trailing slash) */
    private string $baseUrl;

    /** @var string API key for authentication */
    private string $apiKey;

    /** @var int Request timeout in seconds */
    private int $timeout;

    /** @var \Phlex\Common\Logger\StructuredLogger Structured logger instance */
    private \Phlex\Common\Logger\StructuredLogger $logger;

    /** @var array<string, array<string, mixed>> Response cache keyed by endpoint and parameters */
    private array $cache = [];

    /**
     * Constructor for MetadataHttpClient.
     *
     * @param string $baseUrl Base URL for the API (e.g., 'https://api.themoviedb.org/3')
     * @param string $apiKey API key for authentication
     * @param int $timeout Request timeout in seconds (default: 10)
     */
    public function __construct(string $baseUrl, string $apiKey, int $timeout = 10)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->logger = LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Perform GET request to metadata API with caching.
     *
     * @param string $endpoint API endpoint path (e.g., '/search/movie')
     * @param array<string, mixed> $params Query parameters to include in request
     * @param array<string, string>|null $headers Optional custom headers
     * @return array<string, mixed>|null Decoded JSON response or null on failure
     */
    public function get(string $endpoint, array $params = [], ?array $headers = null): ?array
    {
        $cacheKey = md5($endpoint . json_encode($params) . json_encode($headers ?? []));

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $params['api_key'] = $this->apiKey;
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);

        $httpOptions = [
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ];

        if ($headers !== null) {
            $headerStrings = [];
            foreach ($headers as $key => $value) {
                $headerStrings[] = "$key: $value";
            }
            $httpOptions['header'] = implode("\r\n", $headerStrings);
        }

        $context = stream_context_create([
            'http' => $httpOptions,
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->error('Metadata HTTP request failed', [
                'url' => $url,
                'error' => error_get_last()['message'] ?? 'Unknown error',
            ]);
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON response from metadata API', [
                'url' => $url,
                'json_error' => json_last_error_msg(),
            ]);
            return null;
        }

        if (!is_array($data)) {
            $this->logger->error('Metadata API returned non-object JSON', [
                'url' => $url,
                'type' => get_debug_type($data),
            ]);
            return null;
        }

        // Metadata APIs return JSON objects (string-keyed). Normalize numeric
        // keys to strings so the documented array<string, mixed> contract holds.
        $normalized = [];
        foreach ($data as $k => $v) {
            $normalized[(string) $k] = $v;
        }

        $this->cache[$cacheKey] = $normalized;
        return $normalized;
    }

    /**
     * Clear the response cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
