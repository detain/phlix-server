<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Chromecast;

use PHPUnit\Framework\TestCase;
use Phlix\Chromecast\CastApiClient;
use Phlix\Common\Logger\StructuredLogger;

class CastApiClientTest extends TestCase
{
    private StructuredLogger $loggerMock;

    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(StructuredLogger::class);
    }

    /**
     * L2: nothing listening on the probe port — the thrown RuntimeException is
     * what ChromecastController renders into its API response, so it MUST be a
     * constant with no internal LAN URL. The URL belongs in the log context.
     */
    public function testConnectFailureThrowsConstantMessageWithoutLanUrl(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $client->connect();
            $this->fail('Expected RuntimeException for an unreachable device.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cast API request failed', $e->getMessage());
            $this->assertStringNotContainsString('127.0.0.1', $e->getMessage());
        }
    }

    public function testLaunchAppFailureThrowsConstantMessageWithoutLanUrl(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $client->launchApp(CastApiClient::APP_ID_DEFAULT);
            $this->fail('Expected RuntimeException for an unreachable device.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cast API request failed', $e->getMessage());
        }
    }

    public function testLoadMediaFailureThrowsConstantMessageWithoutLanUrl(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $client->loadMedia(
                'http://example.com/stream.m3u8',
                'application/x-mpegurl',
                ['title' => 'Test Stream']
            );
            $this->fail('Expected RuntimeException for an unreachable device.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cast API request failed', $e->getMessage());
        }
    }

    public function testGetMediaStatusFailureThrowsConstantMessage(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $client->getMediaStatus();
            $this->fail('Expected RuntimeException for an unreachable device.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cast API request failed', $e->getMessage());
        }
    }

    public function testSendMediaCommandFailureThrowsConstantMessage(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $client->sendMediaCommand('PLAY', ['currentTime' => 60]);
            $this->fail('Expected RuntimeException for an unreachable device.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cast API request failed', $e->getMessage());
        }
    }

    public function testGetAppSessionsFailureThrowsConstantMessage(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $client->getAppSessions();
            $this->fail('Expected RuntimeException for an unreachable device.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cast API request failed', $e->getMessage());
        }
    }

    public function testDefaultMediaReceiverAppId(): void
    {
        $this->assertEquals('CC1AD845', CastApiClient::APP_ID_DEFAULT);
    }

    public function testClientStoresHostAndPort(): void
    {
        $client = new CastApiClient('192.168.1.50', 8444);

        // Use reflection to verify private properties
        $reflection = new \ReflectionClass($client);
        $hostProperty = $reflection->getProperty('deviceHost');
        $hostProperty->setAccessible(true);
        $portProperty = $reflection->getProperty('devicePort');
        $portProperty->setAccessible(true);

        $this->assertEquals('192.168.1.50', $hostProperty->getValue($client));
        $this->assertEquals(8444, $portProperty->getValue($client));
    }
}
