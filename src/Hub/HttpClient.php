<?php

declare(strict_types=1);

namespace Phlex\Hub;

use InvalidArgumentException;
use RuntimeException;

/**
 * Lightweight HTTP client for hub API communication.
 *
 * Wraps cURL to provide a minimal, tested HTTP layer that always sends
 * `Accept-Phlex-Protocol: v1` and supports optional Bearer authentication.
 *
 * @package Phlex\Hub
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
     * Performs an HTTP request using cURL.
     *
     * @param string                     $method  HTTP method (GET, POST, etc.).
     * @param string                     $path    Request path.
     * @param array<string, mixed>|null  $body    JSON body (null for GET/DELETE).
     * @param array<string, string>      $headers Additional headers.
     *
     * @return HttpResponse Parsed response.
     *
     * @throws RuntimeException On cURL errors.
     */
    private function request(string $method, string $path, ?array $body, array $headers): HttpResponse
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed');
        }

        $requestHeaders = [
            'Accept-Phlex-Protocol: v1',
            'Content-Type: application/json',
        ];

        if ($this->bearerToken !== null) {
            $requestHeaders[] = 'Authorization: Bearer ' . $this->bearerToken;
        }

        foreach ($headers as $key => $value) {
            $requestHeaders[] = "$key: $value";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADER => true,
        ]);

        if ($method !== 'GET' && $method !== '') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($body !== null) {
            $encodedBody = json_encode($body);
            if ($encodedBody === false) {
                throw new RuntimeException('json_encode failed');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedBody);
        }

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($responseBody === false || $curlErrno !== 0) {
            throw new RuntimeException('cURL error: ' . $curlError, $curlErrno);
        }

        $responseHeaders = [];
        if ($headerSize > 0) {
            assert(is_string($responseBody));
            $headerBlock = substr($responseBody, 0, $headerSize);
            $responseHeaders = $this->parseHeaders($headerBlock);
        }

        assert(is_string($responseBody));
        $bodyContent = substr($responseBody, $headerSize);
        $bodyDecoded = json_decode($bodyContent, true);
        if (!is_array($bodyDecoded)) {
            $bodyDecoded = [];
        }

        return new HttpResponse($httpCode, $responseHeaders, $bodyDecoded);
    }

    /**
     * Parses the raw HTTP response header block.
     *
     * @param string $headerBlock Raw header text from cURL.
     *
     * @return array<string, string> Map of lowercase header name to value.
     */
    private function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        foreach (explode("\r\n", trim($headerBlock)) as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return $headers;
    }
}
