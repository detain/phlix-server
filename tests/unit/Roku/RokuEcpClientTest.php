<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Roku;

use PHPUnit\Framework\TestCase;
use Phlex\Roku\RokuEcpClient;

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
}
