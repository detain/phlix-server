<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
     * Perform an HTTP request using synchronous cURL.
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
        if ($url === '') {
            throw new TraktApiException('URL cannot be empty');
        }

        $requestHeaders = [
            'User-Agent: PhlixMediaServer/1.0',
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        foreach ($headers as $key => $value) {
            $requestHeaders[] = $key . ': ' . $value;
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new TraktApiException('Failed to initialize cURL');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== []) {
                $encoded = json_encode($data);
                if ($encoded === false) {
                    curl_close($ch);
                    throw new TraktApiException('Failed to encode request body');
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
            }
        }

        // Capture response headers to extract Retry-After on 429
        $responseHeaders = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders): int {
            $responseHeaders .= $header;
            return strlen($header);
        });

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new TraktApiException('cURL error: ' . ($curlError ?: 'Unknown error'));
        }

        if (!is_string($raw)) {
            throw new TraktApiException('cURL error: Unexpected non-string response');
        }

        if ($httpCode === 401) {
            throw new TraktAuthenticationException('Unauthorized - token invalid or expired');
        }

        // Handle rate limiting with Retry-After header support
        if ($httpCode === 429) {
            $retryAfter = 0;
            // Parse Retry-After from response headers (case-insensitive)
            foreach (explode("\r\n", $responseHeaders) as $header) {
                if (str_starts_with(strtolower($header), 'retry-after:')) {
                    $retryAfter = (int) trim(substr($header, 11));
                    break;
                }
            }
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

        return is_array($decoded) ? $decoded : [];
    }
}
