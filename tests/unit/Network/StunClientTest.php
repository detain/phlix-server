<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlex\Network\StunClient;
use Psr\Log\NullLogger;

class StunClientTest extends TestCase
{
    public function testClientCanBeInstantiated(): void
    {
        $client = new StunClient();
        $this->assertInstanceOf(StunClient::class, $client);
    }

    public function testClientWithCustomLogger(): void
    {
        $client = new StunClient(new NullLogger());
        $this->assertInstanceOf(StunClient::class, $client);
    }

    public function testClientWithCustomServer(): void
    {
        $client = new StunClient(new NullLogger(), 'stun.example.com', 19302);
        $this->assertInstanceOf(StunClient::class, $client);
    }

    public function testGetPublicIpReturnsNullOnFailure(): void
    {
        $client = new StunClient(new NullLogger(), 'invalid.stun.server', 9999);
        $result = $client->getPublicIp();
        $this->assertNull($result);
    }

    public function testTestPortAccessibilityReturnsFalseForUnreachable(): void
    {
        $client = new StunClient(new NullLogger(), 'stun.l.google.com', 19302);
        $result = $client->testPortAccessibility('192.0.2.1', 32400);
        $this->assertFalse($result);
    }

    public function testTestPortAccessibilityReturnsTrueForLocalhost(): void
    {
        $client = new StunClient(new NullLogger(), 'stun.l.google.com', 19302);
        $result = $client->testPortAccessibility('127.0.0.1', 80);
        $this->assertTrue($result);
    }

    public function testDefaultConstants(): void
    {
        $this->assertEquals('stun.l.google.com', StunClient::DEFAULT_STUN_SERVER);
        $this->assertEquals(19302, StunClient::DEFAULT_STUN_PORT);
    }
}
