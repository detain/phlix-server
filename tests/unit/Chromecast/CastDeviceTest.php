<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Chromecast;

use PHPUnit\Framework\TestCase;
use Phlix\Chromecast\CastDevice;

class CastDeviceTest extends TestCase
{
    public function testDeviceIdAndNameExtraction(): void
    {
        $device = new CastDevice(
            'device-123-abc',
            'Living Room TV',
            '192.168.1.100',
            8009,
            'Chromecast Ultra',
            'uuid-456-def'
        );

        $this->assertEquals('device-123-abc', $device->deviceId);
        $this->assertEquals('Living Room TV', $device->name);
        $this->assertEquals('192.168.1.100', $device->host);
        $this->assertEquals(8009, $device->port);
        $this->assertEquals('Chromecast Ultra', $device->model);
        $this->assertEquals('uuid-456-def', $device->uuid);
    }

    public function testGetAddressReturnsHostAndPort(): void
    {
        $device = new CastDevice(
            'device-123',
            'Bedroom Speaker',
            '192.168.1.101',
            8009,
            'Nest Hub',
            'uuid-789'
        );

        $this->assertEquals('192.168.1.101:8009', $device->getAddress());
    }

    public function testAddressWithDifferentPort(): void
    {
        $device = new CastDevice(
            'device-123',
            'Kitchen Display',
            '10.0.0.50',
            9000,
            'Chromecast with Google TV',
            'uuid-abc'
        );

        $this->assertEquals('10.0.0.50:9000', $device->getAddress());
    }

    public function testDevicePropertiesAreReadonly(): void
    {
        $device = new CastDevice(
            'device-id',
            'Test Device',
            '127.0.0.1',
            8009,
            'Test Model',
            'test-uuid'
        );

        // Properties should be readonly via constructor promotion
        $this->assertEquals('device-id', $device->deviceId);
        $this->assertEquals('Test Device', $device->name);
        $this->assertEquals('127.0.0.1', $device->host);
        $this->assertEquals(8009, $device->port);
        $this->assertEquals('Test Model', $device->model);
        $this->assertEquals('test-uuid', $device->uuid);
    }
}
