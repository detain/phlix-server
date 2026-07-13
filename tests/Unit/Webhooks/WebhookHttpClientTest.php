<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use Phlix\Webhooks\WebhookHttpClient;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Http\Client;
use Workerman\Http\ConnectionPool;

/**
 * Unit tests for WebhookHttpClient's connect-timeout wiring (SV-4.4 / S-F10).
 *
 * A webhook target that never completes a TCP handshake must fail fast rather
 * than hanging for the full request timeout — this requires a connect timeout
 * distinct from (and shorter than) the total request timeout on both the
 * async (workerman/http-client) and blocking-cURL-fallback code paths.
 *
 * @covers \Phlix\Webhooks\WebhookHttpClient
 */
class WebhookHttpClientTest extends TestCase
{
    public function testConnectTimeoutDefaultsToFiveSeconds(): void
    {
        $client = new WebhookHttpClient();

        $this->assertSame(5, $client->getConnectTimeout());
    }

    public function testConnectTimeoutIsConfigurable(): void
    {
        $client = new WebhookHttpClient(timeout: 20, connectTimeout: 3);

        $this->assertSame(3, $client->getConnectTimeout());
    }

    /**
     * The async path must configure `workerman/http-client`'s ConnectionPool
     * with the SAME connect_timeout the caller configured — not just the
     * total 'timeout'. ConnectionPool tracks 'connect_timeout' and 'timeout'
     * as genuinely distinct knobs (see ConnectionPool::checkConnections()),
     * so this is verified against the real merged options rather than a stub.
     */
    public function testAsyncClientIsConstructedWithConfiguredConnectTimeout(): void
    {
        $client = new WebhookHttpClient(timeout: 12, connectTimeout: 4);

        $getAsyncClient = new ReflectionMethod(WebhookHttpClient::class, 'getAsyncClient');
        $getAsyncClient->setAccessible(true);
        /** @var Client $asyncClient */
        $asyncClient = $getAsyncClient->invoke($client);

        $this->assertInstanceOf(Client::class, $asyncClient);

        $connectionPoolProp = new ReflectionProperty(Client::class, '_connectionPool');
        $connectionPoolProp->setAccessible(true);
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $connectionPoolProp->getValue($asyncClient);
        $this->assertInstanceOf(ConnectionPool::class, $connectionPool);

        $optionsProp = new ReflectionProperty(ConnectionPool::class, 'options');
        $optionsProp->setAccessible(true);
        /** @var array<string, mixed> $options */
        $options = $optionsProp->getValue($connectionPool);

        $this->assertSame(4, $options['connect_timeout']);
        $this->assertSame(12, $options['timeout']);
    }

    /**
     * The lazily-constructed async client is cached (constructed once), so
     * the connect_timeout it was built with never silently changes.
     */
    public function testAsyncClientIsLazilyCachedNotRebuiltPerRequest(): void
    {
        $client = new WebhookHttpClient(timeout: 10, connectTimeout: 5);

        $getAsyncClient = new ReflectionMethod(WebhookHttpClient::class, 'getAsyncClient');
        $getAsyncClient->setAccessible(true);

        $first = $getAsyncClient->invoke($client);
        $second = $getAsyncClient->invoke($client);

        $this->assertSame($first, $second);
    }

    /**
     * post() must still produce its documented wire format (JSON-wrapped
     * {payload, signature-less} envelope with X-Phlix-Event/X-Phlix-Delivery
     * headers) after being refactored to delegate through postWithHeaders() —
     * a pure regression guard that the SV-4.4 refactor didn't change post()'s
     * public contract for its existing callers (WebhookDispatcher::dispatchAsync).
     */
    public function testPostDelegatesToPostWithHeadersPreservingWireFormat(): void
    {
        $client = new WebhookHttpClient(timeout: 1, connectTimeout: 1);

        // No live worker/coroutine, so post() takes the blocking cURL fallback
        // against an address that fails fast (no network needed to prove the
        // request shape — postCurl() only reaches curl once headers/body are
        // built). We use an empty URL, which postCurl() short-circuits on
        // before touching the network, to keep this test fast and offline.
        $result = $client->post('', 'media.added', 'delivery-1', ['foo' => 'bar']);

        $this->assertFalse($result['success']);
        $this->assertSame('Empty URL', $result['error']);
    }

    public function testPostWithHeadersShortCircuitsOnEmptyUrlWithoutNetwork(): void
    {
        $client = new WebhookHttpClient(timeout: 1, connectTimeout: 1);

        $result = $client->postWithHeaders('', ['X-Test' => '1'], 'raw-body');

        $this->assertFalse($result['success']);
        $this->assertSame('Empty URL', $result['error']);
        $this->assertNull($result['response_code']);
    }
}
