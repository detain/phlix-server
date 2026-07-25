<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\OAuth2;

use Phlix\Plugins\OAuth2\OAuth2HttpClient;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Response;

/**
 * Test double for {@see OAuth2HttpClient}: returns pre-queued responses keyed by
 * "METHOD url" and records every request — NO real network is performed.
 *
 * @internal Test fixture only.
 */
final class FakeOAuth2HttpClient extends OAuth2HttpClient
{
    /** @var array<string, ResponseInterface|null> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, body: string|null, headers: array<string, string>}> */
    public array $requests = [];

    public function __construct()
    {
        parent::__construct(1);
    }

    /**
     * Queue a canned response for a given method + URL.
     */
    public function queue(string $method, string $url, int $status, string $body): void
    {
        $this->responses[$method . ' ' . $url] = new Response($status, [], $body);
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): ?ResponseInterface
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'body' => null, 'headers' => $headers];

        return $this->responses['GET ' . $url] ?? null;
    }

    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body, array $headers = []): ?ResponseInterface
    {
        $this->requests[] = ['method' => 'POST', 'url' => $url, 'body' => $body, 'headers' => $headers];

        return $this->responses['POST ' . $url] ?? null;
    }
}
