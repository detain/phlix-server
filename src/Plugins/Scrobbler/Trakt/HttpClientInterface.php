<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
     * Perform a GET request, returning the decoded body plus response headers.
     *
     * Trakt's paginated endpoints report the total page count in the
     * `X-Pagination-Page-Count` response header; `get()` discards headers after
     * decoding the body, so this sibling surfaces them for paginated pulls (see
     * {@see TraktApi::getWatchedHistory()}). Header keys are lowercased for
     * case-insensitive lookup (HTTP header names are case-insensitive per
     * RFC 7230 §3.2).
     *
     * @param string $url Full URL to request
     * @param array<string, mixed> $params Query parameters
     * @param array<string, string> $headers Additional headers
     *
     * @return array{body: array<string, mixed>, headers: array<string, string>}
     *   Decoded JSON body plus lowercased response headers.
     *
     * @throws TraktApiException On HTTP error
     * @throws TraktAuthenticationException On 401 Unauthorized
     * @throws TraktRateLimitException On 429 Too Many Requests
     */
    public function getWithHeaders(string $url, array $params = [], array $headers = []): array;

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
