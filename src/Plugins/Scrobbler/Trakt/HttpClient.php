<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Psr\Log\NullLogger;

/**
 * HTTP client implementation for Trakt API requests.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
class HttpClient implements HttpClientInterface
{
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
    public function post(string $url, array $data = [], array $headers = []): array
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * Perform an HTTP request using workerman/http-client for non-blocking I/O.
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
     */
    private function request(string $method, string $url, array $data, array $headers): array
    {
        $http = new \Workerman\Http\Client([
            'timeout' => $this->timeout,
        ]);

        $requestHeaders = [
            'User-Agent: PhlixMediaServer/1.0',
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        foreach ($headers as $key => $value) {
            $requestHeaders[] = $key . ': ' . $value;
        }

        $raw = null;
        $error = null;
        $httpCode = null;

        $options = [
            'method' => $method,
            'headers' => $requestHeaders,
        ];

        if ($method === 'POST' && $data !== []) {
            $options['data'] = json_encode($data);
        }

        $http->request($url, $options, function ($response) use (&$raw, &$httpCode) {
            $raw = $response->getBody();
            $httpCode = $response->getStatusCode();
        }, function ($exception) use (&$error) {
            $error = $exception->getMessage();
        });

        // Yield to the event loop to allow the async request to complete.
        $loop = \Workerman\Worker::getEventLoop();
        $start = time();
        while ($raw === null && $error === null && (time() - $start) < $this->timeout) {
            $loop->runOnTick();
        }

        if ($error !== null) {
            throw new TraktApiException('cURL error: ' . $error);
        }

        if ($raw === null) {
            throw new TraktApiException('request timed out: no response body');
        }

        if ($httpCode === 401) {
            throw new TraktAuthenticationException('Unauthorized - token invalid or expired');
        }

        if ($httpCode !== null && $httpCode >= 400) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true) ?? [];
            $message = is_string($decoded['error'] ?? null) ? $decoded['error']
                : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'HTTP ' . $httpCode);
            throw new TraktApiException($message, $httpCode);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true) ?? [];

        return $decoded;
    }
}
