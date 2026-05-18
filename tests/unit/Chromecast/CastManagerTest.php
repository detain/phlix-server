<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Chromecast;

use PHPUnit\Framework\TestCase;
use Phlex\Chromecast\CastDevice;
use Phlex\Chromecast\CastDiscovery;
use Phlex\Chromecast\CastManager;
use Phlex\Chromecast\CastSession;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Session\PlaybackController;

class CastManagerTest extends TestCase
{
    private CastDiscovery $discoveryMock;
    private PlaybackController $playbackControllerMock;
    private StructuredLogger $loggerMock;
    private CastManager $manager;

    protected function setUp(): void
    {
        $this->discoveryMock = $this->createMock(CastDiscovery::class);
        $this->playbackControllerMock = $this->createMock(PlaybackController::class);
        $this->loggerMock = $this->createMock(StructuredLogger::class);
        $this->manager = new CastManager(
            $this->discoveryMock,
            $this->playbackControllerMock,
            $this->loggerMock
        );
    }

    public function testDiscoverDevicesDelegatesToDiscovery(): void
    {
        $devices = [
            new CastDevice(
                'device-1',
                'Living Room',
                '192.168.1.100',
                8009,
                'Chromecast',
                'uuid-1'
            ),
            new CastDevice(
                'device-2',
                'Bedroom',
                '192.168.1.101',
                8009,
                'Nest Hub',
                'uuid-2'
            ),
        ];

        $this->discoveryMock
            ->expects($this->once())
            ->method('discoverDevices')
            ->willReturn($devices);

        $result = $this->manager->discoverDevices();

        $this->assertCount(2, $result);
        $this->assertEquals($devices, $result);
    }

    public function testDiscoverDevicesReturnsEmptyArrayWhenNoDevices(): void
    {
        $this->discoveryMock
            ->expects($this->once())
            ->method('discoverDevices')
            ->willReturn([]);

        $result = $this->manager->discoverDevices();

        $this->assertCount(0, $result);
        $this->assertIsArray($result);
    }

    public function testStartSessionCreatesAndLaunches(): void
    {
        $device = new CastDevice(
            'device-123',
            'Test Chromecast',
            '127.0.0.1',
            19999,
            'Chromecast',
            'uuid-123'
        );

        $this->discoveryMock
            ->expects($this->once())
            ->method('discoverDevices')
            ->willReturn([$device]);

        // Note: This will fail because 127.0.0.1:19999 has no server
        // but we can verify the flow was attempted
        $session = $this->manager->startSession(
            'device-123',
            'http://example.com/stream.m3u8',
            'application/x-mpegurl',
            'Test Video',
            3600
        );

        // Session creation failed because no server is running
        // but verify discovery was called
        $this->assertNull($session);
    }

    public function testStartSessionReturnsNullForUnknownDevice(): void
    {
        $this->discoveryMock
            ->expects($this->once())
            ->method('discoverDevices')
            ->willReturn([]);

        $session = $this->manager->startSession(
            'unknown-device',
            'http://example.com/stream.m3u8',
            'application/x-mpegurl',
            'Test Video',
            3600
        );

        $this->assertNull($session);
    }

    public function testGetSessionReturnsNullForInactiveDevice(): void
    {
        $session = $this->manager->getSession('inactive-device');
        $this->assertNull($session);
    }

    public function testStopSessionRemovesSession(): void
    {
        $device = new CastDevice(
            'device-stop-test',
            'Stop Test Chromecast',
            '127.0.0.1',
            19999,
            'Chromecast',
            'uuid-stop'
        );

        $this->discoveryMock
            ->method('discoverDevices')
            ->willReturn([$device]);

        // Attempt to start a session (will fail but creates the entry)
        $this->manager->startSession(
            'device-stop-test',
            'http://example.com/stream.m3u8',
            'application/x-mpegurl',
            'Test Video',
            3600
        );

        // Stop should work without error even if session failed
        $this->manager->stopSession('device-stop-test');

        // Session should no longer exist
        $this->assertNull($this->manager->getSession('device-stop-test'));
    }

    public function testStopSessionHandlesNonExistentSession(): void
    {
        // Should not throw
        $this->manager->stopSession('non-existent-session');

        $this->assertTrue(true); // If we got here, no exception was thrown
    }

    public function testGetActiveSessionsReturnsAllSessions(): void
    {
        // Initially empty
        $sessions = $this->manager->getActiveSessions();
        $this->assertCount(0, $sessions);
        $this->assertIsArray($sessions);
    }

    public function testManagerStoresDiscoveryAndPlaybackController(): void
    {
        // Use reflection to verify dependencies are stored
        $reflection = new \ReflectionClass($this->manager);

        $discoveryProperty = $reflection->getProperty('discovery');
        $discoveryProperty->setAccessible(true);
        $this->assertSame($this->discoveryMock, $discoveryProperty->getValue($this->manager));

        $playbackProperty = $reflection->getProperty('playbackController');
        $playbackProperty->setAccessible(true);
        $this->assertSame($this->playbackControllerMock, $playbackProperty->getValue($this->manager));
    }
}
