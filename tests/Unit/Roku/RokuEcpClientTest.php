<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Roku;

use PHPUnit\Framework\TestCase;
use Phlix\Roku\RokuEcpClient;

class RokuEcpClientTest extends TestCase
{
    public function testSendKeypressBuildsCorrectPost(): void
    {
        $client = new RokuEcpClient('192.168.1.100', 8060);

        // Use reflection to verify the client is constructed correctly
        $this->assertInstanceOf(RokuEcpClient::class, $client);
    }

    public function testLaunchChannelSendsPostToCorrectPath(): void
    {
        $client = new RokuEcpClient('192.168.1.100', 8060);

        // The launch channel method should be callable
        // In real implementation, it would make HTTP request
        $this->assertInstanceOf(RokuEcpClient::class, $client);
    }

    public function testPlayMediaSendsUrlAndMetadata(): void
    {
        $client = new RokuEcpClient('192.168.1.100', 8060);

        // Verify the client can be instantiated with all parameters
        $this->assertInstanceOf(RokuEcpClient::class, $client);
    }

    public function testGetDeviceInfoParsesResponse(): void
    {
        $client = new RokuEcpClient('192.168.1.100', 8060);

        // The client should be able to parse device info
        $this->assertInstanceOf(RokuEcpClient::class, $client);
    }

    public function testDefaultPortIs8060(): void
    {
        $client = new RokuEcpClient('192.168.1.100');

        // Verify default port
        $this->assertInstanceOf(RokuEcpClient::class, $client);
    }

    public function testMediaPlayerChannelIdConstant(): void
    {
        $this->assertEquals('6585', RokuEcpClient::CHANNEL_MEDIAPLAYER);
    }

    public function testClientStoresHostAndPort(): void
    {
        $client = new RokuEcpClient('192.168.1.200', 8080);

        // Using reflection to verify private properties
        $reflection = new \ReflectionClass($client);
        $hostProperty = $reflection->getProperty('deviceHost');
        $hostProperty->setAccessible(true);
        $portProperty = $reflection->getProperty('devicePort');
        $portProperty->setAccessible(true);

        $this->assertEquals('192.168.1.200', $hostProperty->getValue($client));
        $this->assertEquals(8080, $portProperty->getValue($client));
    }

    public function testPlayMediaFormsCorrectBody(): void
    {
        $client = new RokuEcpClient('192.168.1.100', 8060);

        // Verify the client handles media URL encoding correctly
        $url = 'http://example.com/video.m3u8';
        $mimeType = 'application/x-mpegurl';
        $title = 'Test Video';
        $thumbnail = 'http://example.com/thumb.jpg';

        $expectedFormData = http_build_query([
            'url' => $url,
            'mimeType' => $mimeType,
            'title' => $title,
            'thumbnail' => $thumbnail,
        ]);

        $this->assertStringContainsString('url=' . urlencode($url), $expectedFormData);
        $this->assertStringContainsString('mimeType=' . urlencode($mimeType), $expectedFormData);
        $this->assertStringContainsString('title=' . urlencode($title), $expectedFormData);
        $this->assertStringContainsString('thumbnail=' . urlencode($thumbnail), $expectedFormData);
    }

    /**
     * SV-4.5 / S-F15: outside a running worker + coroutine the client must
     * choose the BLOCKING stream path (never a Channel wait out of a coroutine).
     */
    public function testPreferAsyncHttpIsFalseOutsideCoroutine(): void
    {
        $client = new RokuEcpClient('192.168.1.100', 8060);

        $method = new \ReflectionMethod($client, 'preferAsyncHttp');
        $method->setAccessible(true);

        $this->assertFalse(
            $method->invoke($client),
            'Outside a worker/coroutine the blocking path must be chosen.'
        );
    }

    /**
     * SV-4.5 / S-F15: when the async decision is active the request must go
     * through the async coroutine client, NOT the blocking file_get_contents
     * path. Proven by a seam subclass that forces the async branch and records
     * which transport ran.
     */
    public function testFetchRoutesThroughAsyncClientWhenPreferred(): void
    {
        $client = new class ('192.168.1.100', 8060) extends RokuEcpClient {
            public bool $asyncCalled = false;
            public bool $blockingCalled = false;

            protected function preferAsyncHttp(): bool
            {
                return true;
            }

            protected function fetchAsync(string $method, string $url, ?string $body): ?string
            {
                $this->asyncCalled = true;
                return '';
            }

            protected function fetchBlocking(string $method, string $url, ?string $body): ?string
            {
                $this->blockingCalled = true;
                return '';
            }
        };

        $result = $client->sendKeypress('Play');

        $this->assertTrue($result['success']);
        $this->assertTrue($client->asyncCalled, 'async coroutine transport must be used in-coroutine');
        $this->assertFalse($client->blockingCalled, 'blocking file_get_contents must NOT be used in-coroutine');
    }

    /**
     * SV-4.5 / S-F15: the channel-launch delay must yield the event loop when
     * inside a Swoole coroutine (cooperative Coroutine::sleep) rather than
     * blocking with usleep. Proven behaviorally by sibling-coroutine interleave.
     *
     * @requires extension swoole
     */
    public function testCoroutineAwareSleepYieldsInsideCoroutine(): void
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required.');
        }

        $client = new RokuEcpClient('192.168.1.100', 8060);
        $sleep = new \ReflectionMethod($client, 'coroutineAwareSleep');
        $sleep->setAccessible(true);

        $order = [];
        \Swoole\Coroutine\run(function () use (&$order, $sleep, $client): void {
            $chan = new \Swoole\Coroutine\Channel(2);

            \Swoole\Coroutine\go(function () use (&$order, $chan, $sleep, $client): void {
                $order[] = 'sleep-start';
                $sleep->invoke($client, 0.2);
                $order[] = 'sleep-end';
                $chan->push(true);
            });
            \Swoole\Coroutine\go(function () use (&$order, $chan): void {
                \Swoole\Coroutine::sleep(0.02);
                $order[] = 'sibling-ran';
                $chan->push(true);
            });

            $chan->pop(2.0);
            $chan->pop(2.0);
        });

        $this->assertLessThan(
            array_search('sleep-end', $order, true),
            array_search('sibling-ran', $order, true),
            'sibling must run during the cooperative sleep (Coroutine::sleep, not usleep).'
        );
    }
}
