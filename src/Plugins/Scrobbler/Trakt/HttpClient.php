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
     * Perform an HTTP request.
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
        $ch = curl_init();

        $requestHeaders = [
            'User-Agent: PhlixMediaServer/1.0',
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        foreach ($headers as $key => $value) {
            $requestHeaders[] = $key . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $jsonData = json_encode($data);
            if (is_string($jsonData)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            }
        }

        if ($url !== '') {
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        /** @var string|false $raw */
        $raw = curl_exec($ch);
        /** @var int */
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        /** @var string */
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new TraktApiException('cURL error: ' . $error);
        }

        if ($httpCode === 401) {
            throw new TraktAuthenticationException('Unauthorized - token invalid or expired');
        }

        if ($httpCode >= 400) {
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
