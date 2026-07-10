<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Workerman\Coroutine;
use Workerman\Http\Client;

/**
 * Lightweight HTTP client for hub API communication.
 *
 * Uses workerman/http-client for non-blocking async HTTP that yields to the
 * event loop instead of blocking with cURL.
 *
 * @package Phlix\Hub
 * @since 0.11.0
 */
class HttpClient implements HttpClientInterface
{
    /** @var string Base URL for all requests (no trailing slash). */
    private string $baseUrl;

    /** @var string|null Optional Bearer token for authenticated requests. */
    private ?string $bearerToken;

    /** @var int Request timeout in seconds. */
    private int $timeout;

    /** @var string Optional explicit Host header value for co-located hub routing. */
    private string $hostOverride = '';

    /** @var Client|null Async HTTP client instance (lazy initialized). */
    private ?Client $asyncClient = null;

    /**
     * Creates a new HttpClient.
     *
     * @param string      $baseUrl      Base URL for all requests (e.g.
     *                                  `https://hub.example.com`).
     * @param string|null $bearerToken   Optional Bearer token for auth.
     * @param int         $timeout      Request timeout in seconds (default 30).
     */
    public function __construct(string $baseUrl, ?string $bearerToken = null, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->bearerToken = $bearerToken;
        $this->timeout = $timeout;
    }

    /**
     * Sets an explicit Host header value for co-located hub routing.
     *
     * When the hub hostname resolves to the local machine's public IP,
     * connecting via 127.0.0.1 would set the Host header to "127.0.0.1"
     * which HAProxy cannot use for routing. This method allows forcing
     * the original hub hostname in the Host header while connecting
     * to 127.0.0.1.
     *
     * @param string $host The host name to use in the Host header (e.g. "hub.phlix.interserver.net").
     */
    public function setHostOverride(string $host): void
    {
        $this->hostOverride = $host;
    }

    /**
     * Gets the async HTTP client, lazy initialized.
     */
    private function getAsyncClient(): Client
    {
        if ($this->asyncClient === null) {
            $this->asyncClient = new Client([
                'timeout' => $this->timeout,
                'context' => [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ],
            ]);
        }
        return $this->asyncClient;
    }

    /**
     * Performs a GET request.
     *
     * @param string                     $path    Request path (e.g. `/api/v1/server-claims/new`).
     * @param array<string, string>      $headers Additional headers (merged with defaults).
     *
     * @return HttpResponse The parsed response.
     */
    public function get(string $path, array $headers = []): HttpResponse
    {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * Performs a POST request with a JSON body.
     *
     * @param string                     $path    Request path.
     * @param array<string, mixed>       $body    JSON-serialisable body payload.
     * @param array<string, string>     $headers Additional headers.
     *
     * @return HttpResponse The parsed response.
     */
    public function post(string $path, array $body, array $headers = []): HttpResponse
    {
        return $this->request('POST', $path, $body, $headers);
    }

    /**
     * Performs a DELETE request.
     *
     * @param string                     $path    Request path.
     * @param array<string, string>      $headers Additional headers.
     *
     * @return HttpResponse The parsed response.
     */
    public function delete(string $path, array $headers = []): HttpResponse
    {
        return $this->request('DELETE', $path, null, $headers);
    }

    /**
     * Performs an HTTP request using workerman/http-client (non-blocking async).
     *
     * Uses coroutine context when available for true async execution, otherwise
     * falls back to a cooperative wait that allows event loop to process other tasks.
     * When not in a workerman context (e.g., unit tests), falls back to synchronous curl.
     *
     * @param string                     $method  HTTP method (GET, POST, etc.).
     * @param string                     $path    Request path.
     * @param array<string, mixed>|null  $body    JSON body (null for GET/DELETE).
     * @param array<string, string>      $headers Additional headers.
     *
     * @return HttpResponse Parsed response.
     *
     * @throws RuntimeException On HTTP errors.
     */
    private function request(string $method, string $path, ?array $body, array $headers): HttpResponse
    {
        $url = $this->buildUrl($path);

        if ($url === '') {
            throw new RuntimeException('Cannot perform HTTP request: empty URL');
        }

        $requestHeaders = $this->buildHeaders($headers);

        if ($body !== null) {
            $encodedBody = json_encode($body);
            if ($encodedBody === false) {
                throw new RuntimeException('json_encode failed');
            }
            $body = $encodedBody;
        }

        // Use coroutine if available for true async behavior
        if (Coroutine::isCoroutine()) {
            return $this->requestCoroutine($method, $url, $body, $requestHeaders);
        }

        // When in workerman context, use async client with cooperative wait
        // Otherwise fall back to synchronous curl (for unit tests etc.)
        if ($this->isWorkermanContext()) {
            return $this->requestAsync($method, $url, $body, $requestHeaders);
        }

        return $this->requestCurl($method, $url, $body, $requestHeaders);
    }

    /**
     * Check if we're running inside a workerman worker context.
     *
     * @return bool True if in workerman context, false otherwise
     */
    private function isWorkermanContext(): bool
    {
        if (!class_exists('Workerman\Worker')) {
            return false;
        }
        // Worker::$_instance is set when running in worker context
        return defined('\Workerman\Worker::$_instance');
    }

    /**
     * Fallback synchronous cURL request for CLI/testing contexts.
     *
     * @param array<string, string> $headers
     */
    private function requestCurl(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        if ($url === '') {
            throw new InvalidArgumentException('URL cannot be empty');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(
            array_map(
                fn(string $k, string $v): string => "$k: $v",
                array_keys($headers),
                $headers
            )
        ));

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } elseif ($method !== 'GET') {
            assert($method !== '');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $rawResponse = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false || $rawResponse === true) {
            throw new RuntimeException("cURL error: $curlError");
        }

        /** @var string $headerBlock */
        $headerBlock = substr($rawResponse, 0, $headerSize);
        $responseHeaders = [];
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $responseHeaders[trim($key)] = trim($value);
            }
        }

        /** @var string $responseBody */
        $responseBody = substr($rawResponse, $headerSize);
        /** @var array<string, mixed> $bodyArray */
        $bodyArray = json_decode($responseBody, true) ?? [];

        return new HttpResponse($statusCode, $responseHeaders, $bodyArray);
    }

    /**
     * Build the URL from path, handling both relative and absolute URLs.
     */
    private function buildUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }
        if ($this->baseUrl === '' && $path === '') {
            return '';
        }
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Build request headers including protocol headers and auth.
     *
     * @param array<string, string> $headers Additional headers.
     * @return array<string, string> Complete headers array.
     */
    private function buildHeaders(array $headers): array
    {
        $requestHeaders = [
            'Accept-Phlix-Protocol' => 'v1',
            'Content-Type' => 'application/json',
        ];

        if ($this->bearerToken !== null) {
            $requestHeaders['Authorization'] = 'Bearer ' . $this->bearerToken;
        }

        if ($this->hostOverride !== '') {
            $requestHeaders['Host'] = $this->hostOverride;
        }

        foreach ($headers as $key => $value) {
            $requestHeaders[$key] = $value;
        }

        return $requestHeaders;
    }

    /**
     * Perform request in coroutine context (truly async).
     *
     * @param string $method HTTP method.
     * @param string $url Full URL.
     * @param string|null $body Request body.
     * @param array<string, string> $headers Request headers.
     * @return HttpResponse Parsed response.
     * @throws RuntimeException On errors.
     */
    private function requestCoroutine(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        $client = $this->getAsyncClient();
        $options = [
            'method' => $method,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $options['data'] = $body;
        }

        /** @var ResponseInterface|null $response */
        $response = $client->request($url, $options);

        if ($response === null) {
            throw new RuntimeException('Server did not respond to heartbeat');
        }

        return $this->psr7ResponseToHttpResponse($response);
    }

    /**
     * Perform request using async client with cooperative wait.
     *
     * This uses callbacks but blocks the worker cooperatively, allowing
     * the event loop to process other tasks while waiting for the response.
     *
     * @param string $method HTTP method.
     * @param string $url Full URL.
     * @param string|null $body Request body.
     * @param array<string, string> $headers Request headers.
     * @return HttpResponse Parsed response.
     * @throws RuntimeException On errors or timeout.
     */
    private function requestAsync(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        $client = $this->getAsyncClient();
        $options = [
            'method' => $method,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $options['data'] = $body;
        }

        // State shared between callback and waiting loop
        $state = [
            'response' => null,
            'error' => null,
            'done' => false,
        ];

        $options['success'] = function (ResponseInterface $response) use (&$state) {
            $state['response'] = $response;
            $state['done'] = true;
        };

        $options['error'] = function (\Throwable $error) use (&$state) {
            $state['error'] = $error;
            $state['done'] = true;
        };

        // Initiate async request (non-blocking)
        $client->request($url, $options);

        // Cooperative wait: run event loop until done or timeout
        $maxWait = $this->timeout;
        $waited = 0;
        $interval = 0.001; // 1ms interval for event loop processing

        while (!$state['done'] && $waited < $maxWait) {
            // This yields to the event loop, allowing it to process callbacks
            usleep((int) ($interval * 1000000));
            $waited += $interval;
        }

        if ($state['error'] !== null) {
            throw new RuntimeException('Async HTTP error: ' . $state['error']->getMessage(), 0, $state['error']);
        }

        if ($state['response'] === null) {
            throw new RuntimeException('Async HTTP request timed out after ' . $this->timeout . ' seconds');
        }

        return $this->psr7ResponseToHttpResponse($state['response']);
    }

    /**
     * Convert PSR-7 response to our HttpResponse.
     *
     * @param \Psr\Http\Message\ResponseInterface $response PSR-7 response
     * @return HttpResponse
     */
    private function psr7ResponseToHttpResponse(\Psr\Http\Message\ResponseInterface $response): HttpResponse
    {
        $statusCode = $response->getStatusCode();
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        $bodyContent = (string) $response->getBody();
        $bodyDecoded = json_decode($bodyContent, true);
        if (!is_array($bodyDecoded)) {
            $bodyDecoded = [];
        }

        return new HttpResponse($statusCode, $headers, $bodyDecoded);
    }
}
