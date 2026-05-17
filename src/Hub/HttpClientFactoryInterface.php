<?php

declare(strict_types=1);

namespace Phlex\Hub;

/**
 * Factory for creating HTTP clients used by HubJwtValidator to fetch JWKS.
 *
 * This abstraction allows HubJwtValidator to be tested without depending
 * on the actual HubClient or cURL infrastructure.
 *
 * @package Phlex\Hub
 * @since 0.11.0
 */
interface HttpClientFactoryInterface
{
    /**
     * Creates an HTTP client for the given base URL.
     *
     * @param string $baseUrl The base URL for the HTTP client (e.g. the hub's JWKS endpoint base).
     *
     * @return HttpClientInterface A configured HTTP client instance.
     */
    public function create(string $baseUrl): HttpClientInterface;
}
