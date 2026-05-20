<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\AirPlay;

use PHPUnit\Framework\TestCase;
use Phlix\AirPlay\AirPlayDevice;

class AirPlayDeviceTest extends TestCase
{
    public function test_device_id_and_name_extraction(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'AA:BB:CC:DD:EE:FF',
            name: 'Living Room Apple TV',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
            model: 'AppleTV5,3',
            supportsVideo: true,
        );

        $this->assertSame('AA:BB:CC:DD:EE:FF', $device->deviceId);
        $this->assertSame('Living Room Apple TV', $device->name);
    }

    public function test_get_address_returns_host_port(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.50',
            port: 7000,
            raopPort: 7001,
        );

        $this->assertSame('192.168.1.50:7000', $device->getAddress());
    }

    public function test_get_raop_address_returns_host_raop_port(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.50',
            port: 7000,
            raopPort: 7001,
        );

        $this->assertSame('192.168.1.50:7001', $device->getRaopAddress());
    }

    public function test_supports_video_defaults_to_false(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.50',
            port: 7000,
            raopPort: 7001,
        );

        $this->assertFalse($device->supportsVideo);
    }

    public function test_model_can_be_empty(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.50',
            port: 7000,
            raopPort: 7001,
        );

        $this->assertSame('', $device->model);
    }
}
