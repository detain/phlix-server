<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Common\Http\EventLoopTls;
use Phlix\Common\Runtime\WorkerContext;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Client;

/**
 * HTTP client implementation for Trakt API requests.
 *
 * Uses workerman/http-client for non-blocking async HTTP that yields to the
 * event loop instead of blocking with synchronous cURL. When no coroutine /
 * event loop is running (CLI, PHPUnit, non-coroutine timer callbacks) — or the
 * URL is https under the Swoole event loop, where async TLS reads stall (see
 * {@see EventLoopTls}) — it falls back to a blocking cURL request. This mirrors
 * the de-blocked pattern in {@see \Phlix\Media\Metadata\MetadataHttpClient} and
 * {@see \Phlix\Hub\HttpClient}.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
class HttpClient implements HttpClientInterface
{
    /** @var Client|null Async HTTP client instance (lazy initialized). */
    private ?Client $asyncClient = null;

    /**
     * @param int $timeout Request timeout in seconds
     */
    public function __construct(
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function get(string $url, array $params = [], array $headers = []): array
    {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $url, [], $headers);
    }

    /**
     * @inheritDoc
     */
    public function getWithHeaders(string $url, array $params = [], array $headers = []): array
    {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $this->requestWithHeaders('GET', $url, [], $headers);
    }

    /**
     * @inheritDoc
     */
    public function post(string $url, array $data = [], array $headers = []): array
    {
        return $this->request('POST', $url, $data, $headers);
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
     * Perform an HTTP request, returning only the decoded body.
     *
     * Thin wrapper over {@see self::requestWithHeaders()} for callers that do
     * not need the response headers (the common case).
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array<string, mixed> $data Request body
     * @param array<string, string> $headers Additional headers
     *
     * @return array<string, mixed>
     *
     * @throws TraktApiException
     * @throws TraktAuthenticationException
     * @throws TraktRateLimitException
     */
    private function request(string $method, string $url, array $data, array $headers): array
    {
        return $this->requestWithHeaders($method, $url, $data, $headers)['body'];
    }

    /**
     * Perform an HTTP request, de-blocking the transport under the event loop.
     *
     * Selects the async (coroutine cooperative-wait) client when a Workerman
     * worker event loop is running inside a coroutine and the URL is not
     * https-under-Swoole; otherwise falls back to blocking cURL. Status handling
     * (401/429/4xx) is identical regardless of transport.
     *
     * Returns both the decoded body and a lowercased map of response headers so
     * paginated callers can read `X-Pagination-Page-Count` (headers are captured
     * on both transports — the async PSR-7 response and the cURL fallback).
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array<string, mixed> $data Request body
     * @param array<string, string> $headers Additional headers
     *
     * @return array{body: array<string, mixed>, headers: array<string, string>}
     *
     * @throws TraktApiException
     * @throws TraktAuthenticationException
     * @throws TraktRateLimitException
     */
    private function requestWithHeaders(string $method, string $url, array $data, array $headers): array
    {
        if ($url === '') {
            throw new TraktApiException('URL cannot be empty');
        }

        $requestHeaders = [
            'User-Agent' => 'PhlixMediaServer/1.0',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        foreach ($headers as $key => $value) {
            $requestHeaders[$key] = $value;
        }

        $body = null;
        if ($method === 'POST' && $data !== []) {
            $encoded = json_encode($data);
            if ($encoded === false) {
                throw new TraktApiException('Failed to encode request body');
            }
            $body = $encoded;
        }

        // SV-3.6b: choose the transport that yields the coroutine instead of
        // stalling the resident worker. The async Channel path is only valid
        // inside a coroutine (getCid() > 0) with a running event loop; outside
        // one (CLI/PHPUnit/non-coroutine timer callback) Channel::pop() returns
        // false immediately (a false timeout), so we must use blocking cURL.
        // https + Swoole event loop: async TLS reads also stall (EventLoopTls),
        // so those requests take the blocking cURL path too. cURL is excluded
        // from the curated hook mask (start.php), so it runs as a plain blocking
        // call — acceptable for these low-frequency control-plane requests.
        $needsBlocking = EventLoopTls::requiresBlockingCurl($url)
            || !WorkerContext::isEventLoopRunning()
            || !WorkerContext::inCoroutine();

        $response = $needsBlocking
            ? $this->requestCurl($method, $url, $body, $requestHeaders)
            : $this->requestAsync($method, $url, $body, $requestHeaders);

        if ($response === null) {
            throw new TraktApiException('Trakt HTTP request failed or timed out');
        }

        $httpCode = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($httpCode === 401) {
            throw new TraktAuthenticationException('Unauthorized - token invalid or expired');
        }

        // Handle rate limiting with Retry-After header support (case-insensitive
        // via the PSR-7 message trait).
        if ($httpCode === 429) {
            $retryAfter = (int) trim($response->getHeaderLine('Retry-After'));
            throw new TraktRateLimitException(
                'Rate limit exceeded' . ($retryAfter > 0 ? ' - retry after ' . $retryAfter . 's' : ''),
                $retryAfter
            );
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $message = is_string($decoded['error'] ?? null) ? $decoded['error']
                    : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'HTTP ' . $httpCode);
            } else {
                $message = 'HTTP ' . $httpCode;
            }
            throw new TraktApiException($message, $httpCode);
        }

        $decoded = json_decode($raw, true);

        return [
            'body' => is_array($decoded) ? $decoded : [],
            'headers' => $this->extractHeaders($response),
        ];
    }

    /**
     * Flatten a PSR-7 response's headers into a lowercased name => value map.
     *
     * Header names are lowercased so callers can look up (case-insensitive)
     * fields such as `x-pagination-page-count` uniformly regardless of the
     * transport's original casing.
     *
     * @param ResponseInterface $response Response to read headers from.
     *
     * @return array<string, string>
     */
    private function extractHeaders(ResponseInterface $response): array
    {
        $map = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            $map[strtolower((string) $name)] = $response->getHeaderLine((string) $name);
        }
        return $map;
    }

    /**
     * Perform an async HTTP request with cooperative wait via a Channel.
     *
     * The success/error callbacks push to the Channel; Channel::pop() yields to
     * the coroutine scheduler until the request completes or the timeout fires,
     * so the worker event loop keeps serving other connections. Non-2xx HTTP
     * responses (401/429/4xx/5xx) arrive via the success callback — only
     * connection/timeout failures hit the error callback (return null).
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param string|null $body Encoded request body (null for GET)
     * @param array<string, string> $headers Request headers
     *
     * @return ResponseInterface|null Response, or null on connection error/timeout
     */
    private function requestAsync(string $method, string $url, ?string $body, array $headers): ?ResponseInterface
    {
        $client = $this->getAsyncClient();

        $options = [
            'method' => $method,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $options['data'] = $body;
        }

        $channel = new \Swoole\Coroutine\Channel(1);

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

        // Initiate async request (non-blocking), then yield until done/timeout.
        $client->request($url, $options);
        $channel->pop((float) $this->timeout);

        if ($state['error'] !== null) {
            return null;
        }

        return $state['response'];
    }

    /**
     * Fallback synchronous cURL request for CLI/testing/https-under-Swoole.
     *
     * Captures response headers so Retry-After remains available on 429, and
     * returns a PSR-7 response so the caller's status handling is transport
     * agnostic.
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param string|null $body Encoded request body (null for GET)
     * @param array<string, string> $headers Request headers
     *
     * @return ResponseInterface
     *
     * @throws TraktApiException On cURL init/transport failure
     */
    private function requestCurl(string $method, string $url, ?string $body, array $headers): ResponseInterface
    {
        if ($url === '') {
            throw new TraktApiException('URL cannot be empty');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new TraktApiException('Failed to initialize cURL');
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null && $body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        // Capture response headers into an associative array so getHeaderLine()
        // (e.g. Retry-After on 429) works on the returned PSR-7 response.
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $header) use (&$responseHeaders): int {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[trim($parts[0])] = trim($parts[1]);
            }
            return $length;
        });

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new TraktApiException('cURL error: ' . ($curlError !== '' ? $curlError : 'Unknown error'));
        }

        if (!is_string($raw)) {
            throw new TraktApiException('cURL error: Unexpected non-string response');
        }

        return new \Workerman\Http\Response((int) $httpCode, $responseHeaders, $raw);
    }
}
