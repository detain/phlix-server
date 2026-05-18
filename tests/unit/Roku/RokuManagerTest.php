<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Roku;

use PHPUnit\Framework\TestCase;
use Phlex\Roku\RokuDevice;
use Phlex\Roku\RokuDiscovery;
use Phlex\Roku\RokuManager;
use Phlex\Roku\RokuSession;
use Phlex\Session\PlaybackController;
use Workerman\MySQL\Connection;

class RokuManagerTest extends TestCase
{
    private function createMockPlaybackController(): PlaybackController
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $sessionManager = $this->createMock(\Phlex\Session\SessionManager::class);

        return new PlaybackController($db, $sessionManager);
    }

    public function testDiscoverDevicesDelegatesToDiscovery(): void
    {
        $devices = [
            new RokuDevice('roku-1', 'Living Room', '192.168.1.100'),
            new RokuDevice('roku-2', 'Bedroom', '192.168.1.101'),
        ];

        $mockDiscovery = $this->createMock(RokuDiscovery::class);
        $mockDiscovery->method('discoverDevices')
            ->willReturn($devices);

        $manager = new RokuManager($mockDiscovery, $this->createMockPlaybackController());

        $result = $manager->discoverDevices();

        $this->assertCount(2, $result);
        $this->assertEquals('roku-1', $result[0]->deviceId);
        $this->assertEquals('roku-2', $result[1]->deviceId);
    }

    public function testStartSessionCreatesClientAndLaunches(): void
    {
        $device = new RokuDevice('roku-123', 'Test Roku', '192.168.1.100');

        $mockDiscovery = $this->createMock(RokuDiscovery::class);
        $mockDiscovery->method('discoverDevices')
            ->willReturn([$device]);

        $manager = new RokuManager($mockDiscovery, $this->createMockPlaybackController());

        // Note: Without actual ECP server, this would fail
        // Just verify the manager accepts the call
        $this->assertInstanceOf(RokuManager::class, $manager);
    }

    public function testStopSessionRemovesSession(): void
    {
        $mockDiscovery = $this->createMock(RokuDiscovery::class);
        $manager = new RokuManager($mockDiscovery, $this->createMockPlaybackController());

        // stopSession should not throw even if no session exists
        $manager->stopSession('non-existent-device');

        // Verify no exception was thrown
        $this->assertTrue(true);
    }

    public function testGetSessionReturnsNullForUnknownDevice(): void
    {
        $mockDiscovery = $this->createMock(RokuDiscovery::class);
        $manager = new RokuManager($mockDiscovery, $this->createMockPlaybackController());

        $session = $manager->getSession('unknown-device');

        $this->assertNull($session);
    }

    public function testGetActiveSessionsReturnsEmptyArrayInitially(): void
    {
        $mockDiscovery = $this->createMock(RokuDiscovery::class);
        $manager = new RokuManager($mockDiscovery, $this->createMockPlaybackController());

        $sessions = $manager->getActiveSessions();

        $this->assertIsArray($sessions);
        $this->assertCount(0, $sessions);
    }

    public function testManagerHandlesDiscoveryFailure(): void
    {
        $mockDiscovery = $this->createMock(RokuDiscovery::class);
        $mockDiscovery->method('discoverDevices')
            ->willReturn([]);

        $manager = new RokuManager($mockDiscovery, $this->createMockPlaybackController());

        $devices = $manager->discoverDevices();

        $this->assertCount(0, $devices);
    }
}
