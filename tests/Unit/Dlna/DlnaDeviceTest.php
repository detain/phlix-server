<?php

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Dlna\DlnaDevice;

class DlnaDeviceTest extends TestCase
{
    public function testCanCreateServerDevice(): void
    {
        $device = new DlnaDevice(
            'uuid:test-server-123',
            DlnaDevice::TYPE_SERVER,
            'Test Media Server',
            '192.168.1.100',
            8200
        );

        $this->assertInstanceOf(DlnaDevice::class, $device);
        $this->assertEquals('uuid:test-server-123', $device->getUdn());
        $this->assertEquals(DlnaDevice::TYPE_SERVER, $device->getDeviceType());
        $this->assertEquals('Test Media Server', $device->getFriendlyName());
        $this->assertEquals('192.168.1.100', $device->getBaseUrl());
        $this->assertEquals(8200, $device->getPort());
    }

    public function testCanCreateRendererDevice(): void
    {
        $device = new DlnaDevice(
            'uuid:test-renderer-456',
            DlnaDevice::TYPE_RENDERER,
            'Test DLNA Renderer',
            '192.168.1.101',
            80
        );

        $this->assertEquals(DlnaDevice::TYPE_RENDERER, $device->getDeviceType());
        $this->assertTrue($device->hasService(DlnaDevice::SERVICE_AV_TRANSPORT));
        $this->assertTrue($device->hasCapability('Play'));
        $this->assertTrue($device->hasCapability('Pause'));
    }

    public function testServerHasCorrectServices(): void
    {
        $device = new DlnaDevice(
            'uuid:test-server',
            DlnaDevice::TYPE_SERVER,
            'Test Server',
            '127.0.0.1',
            80
        );

        $this->assertTrue($device->hasService(DlnaDevice::SERVICE_CONTENT_DIRECTORY));
        $this->assertTrue($device->hasService(DlnaDevice::SERVICE_CONNECTION_MANAGER));
        $this->assertFalse($device->hasService(DlnaDevice::SERVICE_AV_TRANSPORT));
    }

    public function testDeviceIcons(): void
    {
        $device = new DlnaDevice(
            'uuid:test-device',
            DlnaDevice::TYPE_SERVER,
            'Test Device',
            '127.0.0.1',
            80
        );

        $this->assertEmpty($device->getIcons());

        $device->addIcon([
            'mimetype' => 'image/png',
            'width' => 48,
            'height' => 48,
            'depth' => 32,
            'url' => '/icons/icon48.png',
        ]);

        $icons = $device->getIcons();
        $this->assertCount(1, $icons);
        $this->assertEquals('image/png', $icons[0]['mimetype']);
        $this->assertEquals(48, $icons[0]['width']);
    }

    public function testDeviceCapabilities(): void
    {
        $serverDevice = new DlnaDevice(
            'uuid:test-server',
            DlnaDevice::TYPE_SERVER,
            'Test Server',
            '127.0.0.1',
            80
        );

        $this->assertTrue($serverDevice->hasCapability('Browse'));
        $this->assertTrue($serverDevice->hasCapability('Search'));
        $this->assertFalse($serverDevice->hasCapability('Play'));
    }

    public function testDeviceUrlGeneration(): void
    {
        $device = new DlnaDevice(
            'uuid:test-device',
            DlnaDevice::TYPE_SERVER,
            'Test Device',
            '192.168.1.100',
            8200
        );

        $this->assertEquals('http://192.168.1.100:8200/', $device->getUrl());
        $this->assertEquals('http://192.168.1.100:8200/ctl/ContentDirectory', $device->getUrl('/ctl/ContentDirectory'));
    }

    public function testDeviceDescriptionXml(): void
    {
        $device = new DlnaDevice(
            'uuid:test-device',
            DlnaDevice::TYPE_SERVER,
            'Test Device',
            '127.0.0.1',
            80
        );

        $xml = $device->toDeviceDescriptionXml();

        $this->assertStringContainsString('uuid:test-device', $xml);
        $this->assertStringContainsString('Test Device', $xml);
        $this->assertStringContainsString('urn:schemas-upnp-org:device:MediaServer:1', $xml);
        $this->assertStringContainsString('<deviceType>', $xml);
        $this->assertStringContainsString('<friendlyName>', $xml);
        $this->assertStringContainsString('<UDN>', $xml);
    }

    public function testDeviceTimeout(): void
    {
        $device = new DlnaDevice(
            'uuid:test-device',
            DlnaDevice::TYPE_SERVER,
            'Test Device',
            '127.0.0.1',
            80
        );

        $this->assertFalse($device->hasTimedOut(300));

        // Simulate old last seen time
        $device->touch();
        $this->assertFalse($device->hasTimedOut(300));
    }

    public function testDeviceActiveStatus(): void
    {
        $device = new DlnaDevice(
            'uuid:test-device',
            DlnaDevice::TYPE_SERVER,
            'Test Device',
            '127.0.0.1',
            80
        );

        $this->assertTrue($device->isActive());

        $device->setActive(false);
        $this->assertFalse($device->isActive());

        $device->setActive(true);
        $this->assertTrue($device->isActive());
    }

    public function testDeviceToArray(): void
    {
        $device = new DlnaDevice(
            'uuid:test-device',
            DlnaDevice::TYPE_SERVER,
            'Test Device',
            '192.168.1.100',
            8200
        );

        $array = $device->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('uuid:test-device', $array['udn']);
        $this->assertEquals(DlnaDevice::TYPE_SERVER, $array['device_type']);
        $this->assertEquals('Test Device', $array['friendly_name']);
        $this->assertEquals('192.168.1.100', $array['base_url']);
        $this->assertEquals(8200, $array['port']);
    }

    public function testDeviceFromArray(): void
    {
        $data = [
            'udn' => 'uuid:test-from-array',
            'device_type' => DlnaDevice::TYPE_RENDERER,
            'friendly_name' => 'Restored Device',
            'base_url' => '192.168.1.200',
            'port' => 8080,
        ];

        $device = DlnaDevice::fromArray($data);

        $this->assertEquals('uuid:test-from-array', $device->getUdn());
        $this->assertEquals(DlnaDevice::TYPE_RENDERER, $device->getDeviceType());
        $this->assertEquals('Restored Device', $device->getFriendlyName());
        $this->assertEquals('192.168.1.200', $device->getBaseUrl());
        $this->assertEquals(8080, $device->getPort());
    }

    public function testSetManufacturer(): void
    {
        $device = new DlnaDevice(
            'uuid:test-device',
            DlnaDevice::TYPE_SERVER,
            'Test Device',
            '127.0.0.1',
            80
        );

        $this->assertEquals('Phlix', $device->getManufacturer());

        $device->setManufacturer('Test Corp');
        $this->assertEquals('Test Corp', $device->getManufacturer());
    }

    public function testSetFriendlyName(): void
    {
        $device = new DlnaDevice(
            'uuid:test-device',
            DlnaDevice::TYPE_SERVER,
            'Original Name',
            '127.0.0.1',
            80
        );

        $device->setFriendlyName('New Name');
        $this->assertEquals('New Name', $device->getFriendlyName());
    }
}
