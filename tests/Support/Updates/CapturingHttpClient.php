<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Updates;

use Workerman\Http\Client;

/**
 * A {@see Client} double that captures the request instead of opening a socket
 * (S74), so the `success` / `error` callbacks can be invoked deterministically.
 *
 * Named rather than anonymous so `$client->options` is a typed property that
 * survives being passed as a {@see Client}.
 *
 * @package Phlix\Tests\Support\Updates
 */
final class CapturingHttpClient extends Client
{
    /** @var array<string, mixed> The options array the fetcher passed. */
    public array $options = [];

    /** @var string The URL the fetcher passed. */
    public string $url = '';

    /** @var bool When true, {@see request()} throws synchronously. */
    private bool $throwOnRequest;

    /**
     * @param bool $throwOnRequest Simulate `parseAddress()` throwing synchronously.
     */
    public function __construct(bool $throwOnRequest = false)
    {
        // No parent::__construct() — this double never builds a connection pool.
        $this->throwOnRequest = $throwOnRequest;
    }

    /**
     * @param string               $url     Request URL.
     * @param array<string, mixed> $options Request options.
     *
     * @return mixed Always null; nothing is sent.
     */
    public function request(string $url, array $options = []): mixed
    {
        if ($this->throwOnRequest) {
            throw new \RuntimeException('bad address');
        }

        $this->url = $url;
        $this->options = $options;

        return null;
    }

    /**
     * The captured `success` callback.
     *
     * @return callable
     */
    public function successCallback(): callable
    {
        $callback = $this->options['success'] ?? null;
        if (!is_callable($callback)) {
            throw new \RuntimeException('No success callback was supplied.');
        }

        return $callback;
    }

    /**
     * The captured `error` callback.
     *
     * @return callable
     */
    public function errorCallback(): callable
    {
        $callback = $this->options['error'] ?? null;
        if (!is_callable($callback)) {
            throw new \RuntimeException('No error callback was supplied.');
        }

        return $callback;
    }
}
