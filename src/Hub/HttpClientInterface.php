<?php

declare(strict_types=1);

namespace Phlex\Hub;

/**
 * Contract for the HTTP client used for hub API communication.
 *
 * @package Phlex\Hub
 * @since 0.11.0
 */
interface HttpClientInterface
{
    /**
     * Performs a GET request.
     *
     * @param string                 $path    Request path.
     * @param array<string, string>  $headers Additional headers.
     *
     * @return HttpResponse The parsed response.
     */
    public function get(string $path, array $headers = []): HttpResponse;

    /**
     * Performs a POST request with a JSON body.
     *
     * @param string                 $path    Request path.
     * @param array<string, mixed>   $body    JSON-serialisable body payload.
     * @param array<string, string>  $headers Additional headers.
     *
     * @return HttpResponse The parsed response.
     */
    public function post(string $path, array $body, array $headers = []): HttpResponse;
}
