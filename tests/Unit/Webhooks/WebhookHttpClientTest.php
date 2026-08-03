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
     * post() must delegate to postWithHeaders() with the exact wire format its
     * callers (WebhookService's LIVE delivery path) depend on: the target URL
     * verbatim, the Content-Type + X-Phlix-Event + X-Phlix-Delivery header
     * envelope, and a JSON-encoded body — and must return postWithHeaders()'s
     * result unchanged. This genuinely guards the SV-4.4 refactor: if post()
     * ever stops routing through postWithHeaders() (e.g. reverting to a direct
     * postAsync/postCurl call), or changes the header/JSON envelope, the
     * expectation below fails. postWithHeaders() is stubbed so no network is
     * touched and the request shape is inspected directly.
     */
    public function testPostDelegatesToPostWithHeadersPreservingWireFormat(): void
    {
        $client = $this->getMockBuilder(WebhookHttpClient::class)
            ->setConstructorArgs([1, 1])
            ->onlyMethods(['postWithHeaders'])
            ->getMock();

        $expected = [
            'success' => true,
            'response_code' => 202,
            'response_body' => 'accepted',
            'error' => null,
        ];

        $client->expects($this->once())
            ->method('postWithHeaders')
            ->with(
                'https://example.com/hook',
                [
                    'Content-Type' => 'application/json',
                    'X-Phlix-Event' => 'media.added',
                    'X-Phlix-Delivery' => 'delivery-1',
                ],
                '{"foo":"bar","n":42}',
            )
            ->willReturn($expected);

        $result = $client->post(
            'https://example.com/hook',
            'media.added',
            'delivery-1',
            ['foo' => 'bar', 'n' => 42],
        );

        // post() returns postWithHeaders()'s result verbatim (no re-wrapping).
        $this->assertSame($expected, $result);
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
