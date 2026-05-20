<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\AirPlay;

use PHPUnit\Framework\TestCase;
use Phlix\AirPlay\AirPlayDevice;
use Phlix\AirPlay\AirPlayDiscovery;
use Phlix\AirPlay\AirPlayManager;
use Phlix\AirPlay\AirPlaySession;
use Phlix\AirPlay\RaopClient;

class AirPlayManagerTest extends TestCase
{
    public function test_discover_devices_delegates_to_discovery(): void
    {
        $mockDiscovery = $this->createMock(AirPlayDiscovery::class);

        $devices = [
            new AirPlayDevice(
                deviceId: 'device-1',
                name: 'Device 1',
                host: '192.168.1.10',
                port: 7000,
                raopPort: 7000,
            ),
            new AirPlayDevice(
                deviceId: 'device-2',
                name: 'Device 2',
                host: '192.168.1.20',
                port: 7000,
                raopPort: 7000,
            ),
        ];

        $mockDiscovery->method('discoverDevices')
            ->willReturn($devices);

        $manager = new AirPlayManager($mockDiscovery);

        $result = $manager->discoverDevices();

        $this->assertCount(2, $result);
        $this->assertSame('device-1', $result[0]->deviceId);
        $this->assertSame('device-2', $result[1]->deviceId);
    }

    public function test_start_session_creates_and_starts_stream(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $mockDiscovery = $this->createMock(AirPlayDiscovery::class);
        $mockDiscovery->method('discoverDevices')
            ->willReturn([$device]);

        $manager = new AirPlayManager($mockDiscovery);

        $session = $manager->startSession(
            'device-123',
            'http://example.com/audio.m3u8',
            'audio/mp4',
            180
        );

        $this->assertInstanceOf(AirPlaySession::class, $session);
        $this->assertSame('streaming', $session->getState());
    }

    public function test_start_session_returns_null_for_unknown_device(): void
    {
        $mockDiscovery = $this->createMock(AirPlayDiscovery::class);
        $mockDiscovery->method('discoverDevices')
            ->willReturn([]);

        $manager = new AirPlayManager($mockDiscovery);

        $session = $manager->startSession(
            'unknown-device',
            'http://example.com/audio.m3u8',
            'audio/mp4',
            180
        );

        $this->assertNull($session);
    }

    public function test_stop_session_removes_session(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $mockDiscovery = $this->createMock(AirPlayDiscovery::class);
        $mockDiscovery->method('discoverDevices')
            ->willReturn([$device]);

        $manager = new AirPlayManager($mockDiscovery);

        // Start a session
        $session = $manager->startSession(
            'device-123',
            'http://example.com/audio.m3u8',
            'audio/mp4',
            180
        );

        $this->assertNotNull($session);

        // Stop the session
        $manager->stopSession('device-123');

        // Session should be removed
        $this->assertNull($manager->getSession('device-123'));
    }

    public function test_get_session_returns_active_session(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $mockDiscovery = $this->createMock(AirPlayDiscovery::class);
        $mockDiscovery->method('discoverDevices')
            ->willReturn([$device]);

        $manager = new AirPlayManager($mockDiscovery);

        // No session initially
        $this->assertNull($manager->getSession('device-123'));

        // Start a session
        $session = $manager->startSession(
            'device-123',
            'http://example.com/audio.m3u8',
            'audio/mp4',
            180
        );

        // Session should be retrievable
        $this->assertNotNull($manager->getSession('device-123'));
        $this->assertSame($session, $manager->getSession('device-123'));
    }

    public function test_get_active_sessions_returns_all_sessions(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $mockDiscovery = $this->createMock(AirPlayDiscovery::class);
        $mockDiscovery->method('discoverDevices')
            ->willReturn([$device]);

        $manager = new AirPlayManager($mockDiscovery);

        // Start a session
        $manager->startSession(
            'device-123',
            'http://example.com/audio.m3u8',
            'audio/mp4',
            180
        );

        $sessions = $manager->getActiveSessions();

        $this->assertCount(1, $sessions);
        $this->assertArrayHasKey('device-123', $sessions);
    }
}
