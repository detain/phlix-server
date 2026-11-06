<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Updates;

use RuntimeException;
use Workerman\Http\Client;

/**
 * A {@see Client} double that records EVERY request the fetcher issues — the
 * primary marker leg and, when the Fastly fallback fires, the `api.github.com`
 * leg — instead of opening a socket (S74), so the `success` / `error`
 * callbacks can be invoked deterministically per leg.
 *
 * Named rather than anonymous so `$client->options` is a typed property that
 * survives being passed as a {@see Client}.
 *
 * The scalar `$url`/`$options` fields always mirror the LAST request, so the
 * many single-leg tests read them unchanged; multi-leg (fallback) tests index
 * into {@see $requests} to drive or inspect a specific leg.
 *
 * @package Phlix\Tests\Support\Updates
 */
final class CapturingHttpClient extends Client
{
    /** @var array<string, mixed> The options array of the most recent `request()`. */
    public array $options = [];

    /** @var string The URL the most recent `request()` passed. */
    public string $url = '';

    /**
     * Every request in issue order.
     *
     * @var list<array{url: string, options: array<array-key, mixed>}>
     */
    public array $requests = [];

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
     * Number of `request()` calls the fetcher has issued so far.
     */
    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * URL the fetcher passed to request `$index` (fail-loud if absent).
     */
    public function urlOf(int $index): string
    {
        if (!isset($this->requests[$index])) {
            throw new RuntimeException("no request recorded at index $index");
        }

        return $this->requests[$index]['url'];
    }

    /**
     * The full options array the fetcher passed to request `$index`.
     *
     * @return array<array-key, mixed>
     */
    public function optionsOf(int $index): array
    {
        if (!isset($this->requests[$index])) {
            throw new RuntimeException("no request recorded at index $index");
        }

        return $this->requests[$index]['options'];
    }

    /**
     * The request `$index`'s success callback (fail-loud if not callable).
     */
    public function successOf(int $index): callable
    {
        $callback = $this->optionsOf($index)['success'] ?? null;
        if (!is_callable($callback)) {
            throw new RuntimeException("request $index has no success callback");
        }

        return $callback;
    }

    /**
     * The request `$index`'s error callback (fail-loud if not callable).
     */
    public function errorOf(int $index): callable
    {
        $callback = $this->optionsOf($index)['error'] ?? null;
        if (!is_callable($callback)) {
            throw new RuntimeException("request $index has no error callback");
        }

        return $callback;
    }

    /**
     * @param string                  $url     Request URL.
     * @param array<array-key, mixed> $options Request options.
     *
     * @return mixed Always null; nothing is sent.
     */
    public function request(string $url, array $options = []): mixed
    {
        if ($this->throwOnRequest) {
            throw new RuntimeException('bad address');
        }

        $this->url = $url;
        $this->options = $options;
        $this->requests[] = ['url' => $url, 'options' => $options];

        return null;
    }

    /**
     * The last request's captured `success` callback.
     *
     * @return callable
     */
    public function successCallback(): callable
    {
        $callback = $this->options['success'] ?? null;
        if (!is_callable($callback)) {
            throw new RuntimeException('No success callback was supplied.');
        }

        return $callback;
    }

    /**
     * The last request's captured `error` callback.
     *
     * @return callable
     */
    public function errorCallback(): callable
    {
        $callback = $this->options['error'] ?? null;
        if (!is_callable($callback)) {
            throw new RuntimeException('No error callback was supplied.');
        }

        return $callback;
    }
}
