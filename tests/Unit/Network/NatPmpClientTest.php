<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\NatPmpClient;
use Psr\Log\NullLogger;

class NatPmpClientTest extends TestCase
{
    private NatPmpClient $client;

    protected function setUp(): void
    {
        $this->client = new NatPmpClient(new NullLogger(), 500);
    }

    public function testClientCanBeInstantiated(): void
    {
        $client = new NatPmpClient();
        $this->assertInstanceOf(NatPmpClient::class, $client);
    }

    public function testClientWithCustomTimeout(): void
    {
        $client = new NatPmpClient(new NullLogger(), 5000);
        $this->assertInstanceOf(NatPmpClient::class, $client);
    }

    public function testDiscoverGatewayReturnsNullForInvalidIp(): void
    {
        $result = $this->client->discoverGateway('192.0.2.1');
        $this->assertNull($result);
    }

    public function testAddPortMappingReturnsNullForInvalidIp(): void
    {
        $result = $this->client->addPortMapping('192.0.2.1', 32400, 32400);
        $this->assertNull($result);
    }

    public function testRemovePortMappingReturnsFalseForInvalidIp(): void
    {
        $result = $this->client->removePortMapping('192.0.2.1', 32400);
        $this->assertFalse($result);
    }

    public function testRemovePortMappingWithUdpProtocol(): void
    {
        $result = $this->client->removePortMapping('192.0.2.1', 32400, 'UDP');
        $this->assertFalse($result);
    }
}
