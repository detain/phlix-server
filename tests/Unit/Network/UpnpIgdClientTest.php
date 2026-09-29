<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\UpnpIgdClient;
use Psr\Log\NullLogger;
use ReflectionMethod;

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

    /**
     * H1(d): the SSDP LOCATION must be a literal IP equal to the datagram
     * source. A spoofed reply advertising the cloud-metadata endpoint from a
     * different source is refused before any socket opens.
     */
    public function testSsdpLocationMustMatchDatagramSource(): void
    {
        $notify = "NOTIFY * HTTP/1.1\r\n"
            . "HOST: 239.255.255.250:1900\r\n"
            . "LOCATION: http://169.254.169.254/latest/meta-data/\r\n\r\n";

        $method = new ReflectionMethod(UpnpIgdClient::class, 'parseSsdpResponse');
        $method->setAccessible(true);

        // Reply arrives from the gateway, but the LOCATION points at metadata.
        $this->assertNull($method->invoke($this->client, $notify, '192.168.1.1'));

        // Same header pinned to its true source is accepted and normalised.
        $pinned = "HTTP/1.1 200 OK\r\n"
            . "LOCATION: http://192.168.1.1:1900/gateway.xml\r\n\r\n";
        $this->assertSame(
            'http://192.168.1.1:1900/gateway.xml',
            $method->invoke($this->client, $pinned, '192.168.1.1')
        );

        // Cross-source (host literal differs from where it came from) refuses.
        $this->assertNull($method->invoke($this->client, $pinned, '10.0.0.5'));
    }

    /**
     * H1(d): a document controlURL pointing at a foreign host must resolve to
     * null; a relative or same-host controlURL is kept.
     */
    public function testResolveUrlRejectsCrossHostControlUrl(): void
    {
        $method = new ReflectionMethod(UpnpIgdClient::class, 'resolveUrl');
        $method->setAccessible(true);

        $base = 'http://192.168.1.1:1900/gateway.xml';

        $this->assertSame(
            'http://192.168.1.1:1900/wanipctrl',
            $method->invoke($this->client, $base, '/wanipctrl')
        );
        $this->assertSame(
            'http://192.168.1.1:1900/ctrl/same',
            $method->invoke($this->client, $base, 'http://192.168.1.1:1900/ctrl/same')
        );
        // Foreign absolute host.
        $this->assertNull($method->invoke($this->client, $base, 'http://10.9.9.9/ctrl'));
        // Metadata host.
        $this->assertNull($method->invoke($this->client, $base, 'http://169.254.169.254/ctrl'));
    }
}
