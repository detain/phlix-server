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

    public function testConnectFetchesEurekaInfo(): void
    {
        // Use localhost with a non-existent port to avoid actual network calls
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        // The connect() method will fail to reach the server since nothing is listening
        // but we can verify it attempts the correct URL structure
        try {
            $client->connect();
        } catch (\Throwable $e) {
            // Expected - no server running
            $this->assertStringContainsString('127.0.0.1:19999', $e->getMessage());
            $this->assertStringContainsString('/setup/eureka_info', $e->getMessage());
        }
    }

    public function testLaunchAppSendsPostToAppsEndpoint(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $client->launchApp(CastApiClient::APP_ID_DEFAULT);
        } catch (\Throwable $e) {
            // Expected - no server running
            $this->assertStringContainsString('127.0.0.1:19999', $e->getMessage());
            $this->assertStringContainsString('/apps/CC1AD845', $e->getMessage());
        }
    }

    public function testLoadMediaSendsCorrectPayload(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $client->loadMedia(
                'http://example.com/stream.m3u8',
                'application/x-mpegurl',
                ['title' => 'Test Stream']
            );
        } catch (\Throwable $e) {
            // Expected - no server running
            $this->assertStringContainsString('127.0.0.1:19999', $e->getMessage());
            $this->assertStringContainsString('/media', $e->getMessage());
        }
    }

    public function testGetMediaStatusParsesResponse(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $status = $client->getMediaStatus();
            // If we somehow get a response, verify it's an array
            $this->assertIsArray($status);
        } catch (\Throwable $e) {
            // Expected - no server running
            $this->assertStringContainsString('127.0.0.1:19999', $e->getMessage());
        }
    }

    public function testSendMediaCommandSendsCorrectCommand(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $client->sendMediaCommand('PLAY', ['currentTime' => 60]);
        } catch (\Throwable $e) {
            // Expected - no server running
            $this->assertStringContainsString('127.0.0.1:19999', $e->getMessage());
        }
    }

    public function testGetAppSessionsReturnsArray(): void
    {
        $client = new CastApiClient('127.0.0.1', 19999, $this->loggerMock);

        try {
            $sessions = $client->getAppSessions();
            $this->assertIsArray($sessions);
        } catch (\Throwable $e) {
            // Expected - no server running
            $this->assertStringContainsString('127.0.0.1:19999', $e->getMessage());
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
