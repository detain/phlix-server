<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * HTTP client interface for Trakt API requests.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
interface HttpClientInterface
{
    /**
     * Perform a GET request.
     *
     * @param string $url Full URL to request
     * @param array<string, mixed> $params Query parameters
     * @param array<string, string> $headers Additional headers
     *
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws TraktApiException On HTTP error
     * @throws TraktAuthenticationException On 401 Unauthorized
     */
    public function get(string $url, array $params = [], array $headers = []): array;

    /**
     * Perform a POST request.
     *
     * @param string $url Full URL to request
     * @param array<string, mixed> $data JSON-serializable body data
     * @param array<string, string> $headers Additional headers
     *
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws TraktApiException On HTTP error
     * @throws TraktAuthenticationException On 401 Unauthorized
     */
    public function post(string $url, array $data = [], array $headers = []): array;
}
