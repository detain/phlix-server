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
 * **Caching (SV-3.5):** Response cache is LRU-bound ({@see CACHE_MAX} entries)
 * with a monotonic-time TTL ({@see CACHE_TTL_MS}) — never grows unbounded in a
 * resident worker.
 *
 * **Retry with backoff (SV-3.5):** HTTP 429 and 5xx responses trigger jittered
 * exponential backoff retry ({@see RETRY_MAX_RETRIES}) so rate-limiting and
 * transient server errors are honored instead of silently producing empty
 * results.
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

    /**
     * LRU-bound response cache keyed by endpoint+params hash.
     *
     * Each entry is `array{body: array<string, mixed>, expires_at: int}` — the
     * `expires_at` is a monotonic ms clock value so the cache is immune to
     * NTP/DST clock jumps (see the same pattern in {@see ItemRepository::$genreFacetCache}).
     * The unset-then-reassign eviction idiom is identical to that cache's:
     * without the `unset()` a stale-entry recompute would leave the re-refreshed
     * key at its original array position instead of the logical "now it was
     * touched" end, causing array_key_first() to incorrectly evict the freshly
     * used entry on the next overflow.
     *
     * @var array<string, array{body: array<string, mixed>, expires_at: int}>
     */
    private array $cache = [];

    /** @var int Response cache TTL in milliseconds (1 hour). */
    private const CACHE_TTL_MS = 3_600_000;

    /** @var int Maximum cache entries before LRU eviction kicks in. */
    private const CACHE_MAX = 4096;

    /** @var int Maximum retry attempts for 429/5xx backoff. */
    private const RETRY_MAX_RETRIES = 5;

    /** @var int Base delay for exponential backoff in milliseconds. */
    private const RETRY_BASE_DELAY_MS = 1_000;

    /** @var int Maximum delay cap for exponential backoff in milliseconds. */
    private const RETRY_MAX_DELAY_MS = 32_000;

    /** HTTP status codes that trigger retry with backoff. */
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

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
     * Perform GET request to metadata API with caching, retry, and backoff.
     *
     * Uses async HTTP client with cooperative wait to avoid blocking event loop.
     * Caches successful 2xx JSON responses with a monotonic-time TTL and LRU
     * eviction. Retries 429/5xx responses with jittered exponential backoff.
     *
     * @param string $endpoint API endpoint path (e.g., '/search/movie')
     * @param array<string, mixed> $params Query parameters to include in request
     * @param array<string, string>|null $headers Optional custom headers
     * @return array<string, mixed>|null Decoded JSON response or null on failure
     */
    public function get(string $endpoint, array $params = [], ?array $headers = null): ?array
    {
        $cacheKey = md5($endpoint . json_encode($params) . json_encode($headers ?? []));
        $now = $this->monotonicMs();

        // LRU cache hit with valid TTL: unset + reassign keeps it at the
        // logical "most recently used" end of the array.
        if (isset($this->cache[$cacheKey])) {
            $entry = $this->cache[$cacheKey];
            if ($entry['expires_at'] > $now) {
                unset($this->cache[$cacheKey]);
                $this->cache[$cacheKey] = $entry;
                return $entry['body'];
            }
            // Expired: drop and refetch.
            unset($this->cache[$cacheKey]);
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

        // SV-3.5: retry loop with jittered exponential backoff for rate-limit
        // (429) and server errors (5xx). Loop retries on retryable statuses
        // until success, non-retryable failure, or max retries exhausted.
        for ($attempt = 0; $attempt <= self::RETRY_MAX_RETRIES; $attempt++) {
            // https + Swoole event loop: async TLS reads stall (see EventLoopTls),
            // so those requests must take the blocking cURL path.
            $isEventLoop = \Phlix\Common\Runtime\WorkerContext::isEventLoopRunning();
            $needsBlocking = !$isEventLoop
                || \Phlix\Common\Http\EventLoopTls::requiresBlockingCurl($url);
            $response = $needsBlocking
                ? $this->requestCurl($url, $requestHeaders)
                : $this->requestAsync($url, $requestHeaders);

            if ($response === null) {
                $this->logger->error('Metadata HTTP request failed', [
                    'url' => $url,
                ]);
                return null;
            }

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            // SV-3.5: check retryable status before processing body. If we have
            // more attempts left, sleep with jittered backoff and retry.
            if (in_array($statusCode, self::RETRYABLE_STATUS_CODES, true)) {
                if ($attempt < self::RETRY_MAX_RETRIES) {
                    $delayMs = $this->computeBackoffDelayMs($attempt);
                    $this->logger->info('Metadata HTTP retrying after rate-limit/server error', [
                        'url' => $url,
                        'status' => $statusCode,
                        'attempt' => $attempt + 1,
                        'delay_ms' => $delayMs,
                    ]);
                    // Use usleep for cooperative yield in coroutine context.
                    usleep((int) ($delayMs * 1_000));
                    continue;
                }
                // Exhausted retries: log and treat as failure.
                $this->logger->error('Metadata HTTP request exhausted retries', [
                    'url' => $url,
                    'status' => $statusCode,
                ]);
                return null;
            }

            // Non-retryable status (including 2xx, 4xx other than 429):
            // parse and return. A 404 "no results" is a normal empty-result.
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Invalid JSON response from metadata API', [
                    'url' => $url,
                    'status' => $statusCode,
                    'json_error' => json_last_error_msg(),
                ]);
                return null;
            }

            if (!is_array($data)) {
                $this->logger->error('Metadata API returned non-object JSON', [
                    'url' => $url,
                    'status' => $statusCode,
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

            // Only cache successful 2xx responses.
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->cacheWithLruEviction($cacheKey, $normalized, $now);
            }

            return $normalized;
        }

        // Should not reach here, but strictly returning null for safety.
        return null;
    }

    /**
     * Store a cache entry with LRU eviction when at capacity.
     *
     * Uses the same unset-then-reassign pattern as {@see ItemRepository::$genreFacetCache}
     * to ensure freshly-touched entries stay at the logical "end" of the PHP
     * array so array_key_first() correctly identifies the oldest entry for
     * eviction.
     *
     * @param string $cacheKey Cache key.
     * @param array<string, mixed> $body Decoded JSON body.
     * @param int $now Monotonic ms timestamp from {@see monotonicMs()}.
     */
    private function cacheWithLruEviction(string $cacheKey, array $body, int $now): void
    {
        unset($this->cache[$cacheKey]);
        $this->cache[$cacheKey] = [
            'body' => $body,
            'expires_at' => $now + self::CACHE_TTL_MS,
        ];

        if (count($this->cache) > self::CACHE_MAX) {
            $oldest = array_key_first($this->cache);
            if ($oldest !== null) {
                unset($this->cache[$oldest]);
            }
        }
    }

    /**
     * Compute jittered exponential backoff delay for retry attempt $attempt.
     *
     * Delay = min(RETRY_BASE_DELAY_MS * 2^attempt, RETRY_MAX_DELAY_MS) + jitter.
     * Jitter is a random value in [0, delay] to prevent thundering-herd when
     * multiple clients recover from a shared outage simultaneously.
     *
     * @param int $attempt Zero-based attempt index (0 = first retry).
     *
     * @return float Delay in milliseconds (float for usleep compatibility).
     */
    private function computeBackoffDelayMs(int $attempt): float
    {
        $delay = min(self::RETRY_BASE_DELAY_MS * (2 ** $attempt), self::RETRY_MAX_DELAY_MS);
        // Uniform jitter in [0, delay].
        $jitter = mt_rand(0, (int) $delay);
        return (float) ($delay + $jitter);
    }

    /**
     * Return a monotonic millisecond timestamp for cache TTL and backoff timing.
     *
     * Uses hrtime(true) which is monotonically increasing and immune to NTP
     * clock steps, DST changes, and manual clock adjustments.
     *
     * @return int Monotonic milliseconds.
     */
    private function monotonicMs(): int
    {
        return (int) (hrtime(true) / 1_000_000);
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
