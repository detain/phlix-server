<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Discovery\Ssdp\SsdpDevice;
use Phlix\Discovery\Ssdp\SsdpDiscovery;
use Phlix\Dlna\RendererDiscovery;

class RendererDiscoveryTest extends TestCase
{
    private SsdpDiscovery $ssdpDiscoveryMock;
    private RendererDiscovery $rendererDiscovery;
    private StructuredLogger $logger;

    protected function setUp(): void
    {
        $this->ssdpDiscoveryMock = $this->createMock(SsdpDiscovery::class);
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->rendererDiscovery = new RendererDiscovery($this->ssdpDiscoveryMock, $this->logger);
    }

    public function testDiscoverRenderersReturnsArrayOfRenderers(): void
    {
        $devices = [
            new SsdpDevice(
                'uuid:renderer-1::urn:schemas-upnp-org:device:MediaRenderer:1',
                'urn:schemas-upnp-org:device:MediaRenderer:1',
                'http://192.168.1.100:8200/device.xml',
                'Linux/2.6 UPnP/1.0',
                1800,
                'urn:schemas-upnp-org:device:MediaRenderer:1'
            ),
        ];

        $this->ssdpDiscoveryMock
            ->expects($this->once())
            ->method('discoverDevices')
            ->with(SsdpDiscovery::ST_MEDIA_RENDERER)
            ->willReturn($devices);

        $this->ssdpDiscoveryMock
            ->expects($this->once())
            ->method('resolveDeviceDescription')
            ->with('http://192.168.1.100:8200/device.xml')
            ->willReturn([
                'url' => 'http://192.168.1.100:8200/device.xml',
                'xml' => '<?xml version="1.0"?>
                    <root>
                        <device>
                            <deviceType>urn:schemas-upnp-org:device:MediaRenderer:1</deviceType>
                            <friendlyName>Living Room TV</friendlyName>
                            <manufacturer>Samsung</manufacturer>
                            <modelName>UN55RU7100</modelName>
                            <UDN>uuid:renderer-1</UDN>
                            <serviceList>
                                <service>
                                    <serviceType>urn:schemas-upnp-org:service:AVTransport:1</serviceType>
                                    <controlURL>/avtransport/control</controlURL>
                                </service>
                            </serviceList>
                        </device>
                    </root>',
            ]);

        $renderers = $this->rendererDiscovery->discoverRenderers();

        $this->assertIsArray($renderers);
        $this->assertCount(1, $renderers);
        $this->assertEquals('Living Room TV', $renderers[0]['friendly_name']);
        $this->assertEquals('Samsung', $renderers[0]['manufacturer']);
        $this->assertEquals('uuid:renderer-1', $renderers[0]['udn']);
    }

    public function testDiscoverRenderersReturnsEmptyOnNetworkError(): void
    {
        $this->ssdpDiscoveryMock
            ->expects($this->once())
            ->method('discoverDevices')
            ->with(SsdpDiscovery::ST_MEDIA_RENDERER)
            ->willReturn([]);

        $renderers = $this->rendererDiscovery->discoverRenderers();

        $this->assertIsArray($renderers);
        $this->assertEmpty($renderers);
    }

    public function testDiscoverRenderersSkipsInvalidDevices(): void
    {
        $devices = [
            new SsdpDevice(
                'uuid:renderer-1::urn:schemas-upnp-org:device:MediaRenderer:1',
                'urn:schemas-upnp-org:device:MediaRenderer:1',
                'http://192.168.1.100:8200/device.xml',
                'Linux/2.6 UPnP/1.0',
                1800,
                'urn:schemas-upnp-org:device:MediaRenderer:1'
            ),
            // Second device that will fail description fetch
            new SsdpDevice(
                'uuid:renderer-2::urn:schemas-upnp-org:device:MediaRenderer:1',
                'urn:schemas-upnp-org:device:MediaRenderer:1',
                'http://192.168.1.101:8200/device.xml',
                'Linux/2.6 UPnP/1.0',
                1800,
                'urn:schemas-upnp-org:device:MediaRenderer:1'
            ),
        ];

        $this->ssdpDiscoveryMock
            ->method('discoverDevices')
            ->willReturn($devices);

        $this->ssdpDiscoveryMock
            ->method('resolveDeviceDescription')
            ->willReturnCallback(function ($url) {
                if (strpos($url, '192.168.1.100') !== false) {
                    return [
                        'url' => $url,
                        'xml' => '<?xml version="1.0"?><root><device><deviceType>urn:schemas-upnp-org:device:MediaRenderer:1</deviceType><friendlyName>TV 1</friendlyName><UDN>uuid:renderer-1</UDN></device></root>',
                    ];
                }
                return null; // Second device fails
            });

        $renderers = $this->rendererDiscovery->discoverRenderers();

        $this->assertCount(1, $renderers);
        $this->assertEquals('TV 1', $renderers[0]['friendly_name']);
    }

    public function testGetRendererDescriptionReturnsNullForEmptyLocation(): void
    {
        $result = $this->rendererDiscovery->getRendererDescription('');

        $this->assertNull($result);
    }

    public function testGetRendererDescriptionReturnsNullOnFetchFailure(): void
    {
        $this->ssdpDiscoveryMock
            ->expects($this->once())
            ->method('resolveDeviceDescription')
            ->with('http://192.168.1.100:8200/device.xml')
            ->willReturn(null);

        $result = $this->rendererDiscovery->getRendererDescription('http://192.168.1.100:8200/device.xml');

        $this->assertNull($result);
    }

    public function testGetRendererDescriptionParsesAvTransportUrl(): void
    {
        $this->ssdpDiscoveryMock
            ->method('resolveDeviceDescription')
            ->willReturn([
                'url' => 'http://192.168.1.100:8200/device.xml',
                'xml' => '<?xml version="1.0"?>
                    <root>
                        <URLBase>http://192.168.1.100:8200</URLBase>
                        <device>
                            <deviceType>urn:schemas-upnp-org:device:MediaRenderer:1</deviceType>
                            <friendlyName>Test TV</friendlyName>
                            <manufacturer>LG</manufacturer>
                            <modelName>OLED55C9</modelName>
                            <UDN>uuid:lg-tv-1</UDN>
                            <serviceList>
                                <service>
                                    <serviceType>urn:schemas-upnp-org:service:AVTransport:1</serviceType>
                                    <controlURL>/avtransport/control</controlURL>
                                </service>
                            </serviceList>
                        </device>
                    </root>',
            ]);

        $result = $this->rendererDiscovery->getRendererDescription('http://192.168.1.100:8200/device.xml');

        $this->assertNotNull($result);
        $this->assertEquals('Test TV', $result['friendly_name']);
        $this->assertEquals('LG', $result['manufacturer']);
        $this->assertEquals('uuid:lg-tv-1', $result['udn']);
        $this->assertEquals('http://192.168.1.100:8200/avtransport/control', $result['av_transport_url']);
    }
}
