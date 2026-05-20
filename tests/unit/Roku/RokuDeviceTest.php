<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Roku;

use PHPUnit\Framework\TestCase;
use Phlix\Roku\RokuDevice;

class RokuDeviceTest extends TestCase
{
    public function testDeviceIdAndNameExtraction(): void
    {
        $device = new RokuDevice(
            deviceId: 'roku-12345',
            name: 'Living Room Roku',
            host: '192.168.1.100',
            port: 8060,
            model: 'Roku Express',
            softwareVersion: '12.5.0'
        );

        $this->assertEquals('roku-12345', $device->deviceId);
        $this->assertEquals('Living Room Roku', $device->name);
        $this->assertEquals('192.168.1.100', $device->host);
        $this->assertEquals(8060, $device->port);
        $this->assertEquals('Roku Express', $device->model);
        $this->assertEquals('12.5.0', $device->softwareVersion);
    }

    public function testGetAddressReturnsHostAndPort(): void
    {
        $device = new RokuDevice(
            deviceId: 'roku-12345',
            name: 'Bedroom Roku',
            host: '192.168.1.101',
            port: 8060
        );

        $this->assertEquals('192.168.1.101:8060', $device->getAddress());
    }

    public function testDefaultPortIs8060(): void
    {
        $device = new RokuDevice(
            deviceId: 'roku-12345',
            name: 'Test Roku',
            host: '192.168.1.100'
        );

        $this->assertEquals(8060, $device->port);
        $this->assertEquals('192.168.1.100:8060', $device->getAddress());
    }

    public function testDeviceWithMinimalInfo(): void
    {
        $device = new RokuDevice(
            deviceId: 'roku-minimal',
            name: 'Minimal Roku',
            host: '192.168.1.50'
        );

        $this->assertEquals('roku-minimal', $device->deviceId);
        $this->assertEquals('Minimal Roku', $device->name);
        $this->assertEquals('192.168.1.50', $device->host);
        $this->assertEquals(8060, $device->port);
        $this->assertEquals('', $device->model);
        $this->assertEquals('', $device->softwareVersion);
    }
}
