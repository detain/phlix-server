<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\UpnpIgdClient;
use Psr\Log\NullLogger;

class UpnpIgdClientTest extends TestCase
{
    private UpnpIgdClient $client;

    protected function setUp(): void
    {
        $this->client = new UpnpIgdClient(new NullLogger(), 500);
    }

    public function testDiscoverGatewayReturnsNullOnTimeout(): void
    {
        $result = $this->client->discoverGateway();
        $this->assertNull($result);
    }

    public function testGetExternalIpReturnsNullForInvalidUrl(): void
    {
        $result = $this->client->getExternalIp('http://invalid.example.com:9999/xml/device.xml');
        $this->assertNull($result);
    }

    public function testAddPortMappingReturnsFalseForInvalidUrl(): void
    {
        $result = $this->client->addPortMapping(
            'http://invalid.example.com:9999/ctrl',
            '32400',
            '192.168.1.100',
            '32400'
        );
        $this->assertFalse($result);
    }

    public function testRemovePortMappingReturnsFalseForInvalidUrl(): void
    {
        $result = $this->client->removePortMapping(
            'http://invalid.example.com:9999/ctrl',
            '32400'
        );
        $this->assertFalse($result);
    }

    public function testClientCanBeInstantiated(): void
    {
        $client = new UpnpIgdClient();
        $this->assertInstanceOf(UpnpIgdClient::class, $client);
    }

    public function testClientWithCustomTimeout(): void
    {
        $client = new UpnpIgdClient(new NullLogger(), 5000);
        $this->assertInstanceOf(UpnpIgdClient::class, $client);
    }
}
