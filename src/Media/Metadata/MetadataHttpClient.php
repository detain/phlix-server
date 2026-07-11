<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Client;

/**
 * MetadataHttpClient provides HTTP communication with metadata provider APIs.
 *
 * Uses workerman/http-client for non-blocking async HTTP that yields to the
 * event loop instead of blocking with file_get_contents.
 *
 * This client handles HTTP requests to external metadata services with built-in
 * caching, error handling, and API key authentication. It validates JSON responses
 * and logs errors appropriately.
 *
 * @author Phlix Development Team
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

    /** @var \Phlix\Common\Logger\StructuredLogger Structured logger instance */
    private \Phlix\Common\Logger\StructuredLogger $logger;

    /** @var array<string, array<string, mixed>> Response cache keyed by endpoint and parameters */
    private array $cache = [];

    /** @var Client|null Async HTTP client instance (lazy initialized). */
    private ?Client $asyncClient = null;

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
     * Gets the async HTTP client, lazy initialized.
     */
    private function getAsyncClient(): Client
    {
        if ($this->asyncClient === null) {
            $this->asyncClient = new Client([
                'timeout' => $this->timeout,
            ]);
        }
        return $this->asyncClient;
    }

    /**
     * Perform GET request to metadata API with caching.
     *
     * Uses async HTTP client with cooperative wait to avoid blocking event loop.
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

        $requestHeaders = [
            'Accept' => 'application/json',
        ];

        if ($headers !== null) {
            foreach ($headers as $key => $value) {
                $requestHeaders[$key] = $value;
            }
        }

        // https + Swoole event loop: async TLS reads stall (see EventLoopTls),
        // so those requests must take the blocking cURL path.
        $response = \Phlix\Common\Runtime\WorkerContext::isEventLoopRunning() && !\Phlix\Common\Http\EventLoopTls::requiresBlockingCurl($url)
            ? $this->requestAsync($url, $requestHeaders)
            : $this->requestCurl($url, $requestHeaders);

        if ($response === null) {
            $this->logger->error('Metadata HTTP request failed', [
                'url' => $url,
            ]);
            return null;
        }

        $data = json_decode((string) $response->getBody(), true);

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
     * Perform an async HTTP request with cooperative wait via Channel.
     *
     * @param string $url Full URL to request
     * @param array<string, string> $headers Request headers
     * @return ResponseInterface|null Response or null on error/timeout
     */
    private function requestAsync(string $url, array $headers): ?ResponseInterface
    {
        $client = $this->getAsyncClient();

        $options = [
            'method' => 'GET',
            'headers' => $headers,
        ];

        // Channel-based wait: push is called by success/error callbacks,
        // and pop() yields to the coroutine scheduler until done or timeout.
        $channel = new \Swoole\Coroutine\Channel(1);

        // State shared between callback and channel
        $state = [
            'response' => null,
            'error' => null,
        ];

        $options['success'] = function (ResponseInterface $response) use (&$state, $channel): void {
            $state['response'] = $response;
            $channel->push(true);
        };

        $options['error'] = function (\Throwable $error) use (&$state, $channel): void {
            $state['error'] = $error;
            $channel->push(true);
        };

        // Initiate async request (non-blocking)
        $client->request($url, $options);

        // Yield to event loop via Channel pop with timeout
        $channel->pop((float) $this->timeout);

        if ($state['error'] !== null) {
            return null;
        }

        return $state['response'];
    }

    /**
     * Fallback synchronous cURL request for CLI/testing contexts where no
     * Workerman event loop is running.
     *
     * @param array<string, string> $headers Request headers
     * @return ResponseInterface|null Response or null on error
     */
    private function requestCurl(string $url, array $headers): ?ResponseInterface
    {
        if ($url === '') {
            return null;
        }

        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "$key: $value";
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        $body = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body)) {
            return null;
        }

        return new \Workerman\Http\Response($statusCode, [], $body);
    }

    /**
     * Build the `stream_context_create()` options for an outbound metadata
     * request, attaching an `ssl` block that verifies the peer certificate and
     * hostname (F2). Metadata providers (TMDB, TVDB, Fanart, …) are reached over
     * HTTPS; without TLS verification a MITM could feed forged metadata.
     *
     * Exposed (public, static, pure) so it is unit-testable without reflection
     * or a live network request.
     *
     * @param array<string, mixed> $httpOptions The `http` stream-context block.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function buildStreamContextOptions(array $httpOptions): array
    {
        return [
            'http' => $httpOptions,
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];
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
