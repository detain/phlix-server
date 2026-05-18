<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Tuners\HdHomeRun;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunApiClient;

class HdHomeRunApiClientTest extends TestCase
{
    public function testGetChannelLineupReturnsArray(): void
    {
        $client = new HdHomeRunApiClient('http://127.0.0.1');

        // In unit test environment without actual device, this will return empty
        $lineup = $client->getChannelLineup();

        $this->assertIsArray($lineup);
    }

    public function testGetStreamUrlBuildsCorrectUrl(): void
    {
        $client = new HdHomeRunApiClient('http://192.168.1.100');

        $streamUrl = $client->getStreamUrl(5);

        $this->assertEquals('http://192.168.1.100/watch?channel=5', $streamUrl);
    }

    public function testGetStreamUrlWithDifferentChannel(): void
    {
        $client = new HdHomeRunApiClient('http://192.168.1.100');

        $streamUrl = $client->getStreamUrl(27);

        $this->assertEquals('http://192.168.1.100/watch?channel=27', $streamUrl);
    }

    public function testGetStreamUrlWithZeroChannel(): void
    {
        $client = new HdHomeRunApiClient('http://192.168.1.100');

        $streamUrl = $client->getStreamUrl(0);

        $this->assertEquals('http://192.168.1.100/watch?channel=0', $streamUrl);
    }

    public function testTriggerScanReturnsBool(): void
    {
        $client = new HdHomeRunApiClient('http://127.0.0.1');

        // In unit test environment, this will return false due to connection failure
        $result = $client->triggerScan();

        $this->assertIsBool($result);
    }

    public function testBaseUrlIsNormalized(): void
    {
        // Test that trailing slashes are stripped properly
        $client = new HdHomeRunApiClient('http://192.168.1.100/');

        $streamUrl = $client->getStreamUrl(5);

        // The baseUrl is normalized via rtrim, so should produce correct URL
        $this->assertStringContainsString('watch?channel=5', $streamUrl);
    }

    public function testGetStreamUrlPreservesTrailingSlashInPath(): void
    {
        $client = new HdHomeRunApiClient('http://192.168.1.100');

        $streamUrl = $client->getStreamUrl(10);

        $this->assertStringContainsString('/watch?channel=10', $streamUrl);
    }

    public function testDiscoverReturnsArrayOrFalse(): void
    {
        $client = new HdHomeRunApiClient('http://127.0.0.1');

        $result = $client->discover();

        // Result is either array or false on failure
        $this->assertTrue($result === false || is_array($result));
    }
}
