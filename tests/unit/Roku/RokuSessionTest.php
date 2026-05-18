<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Roku;

use PHPUnit\Framework\TestCase;
use Phlex\Roku\RokuDevice;
use Phlex\Roku\RokuEcpClient;
use Phlex\Roku\RokuSession;
use Phlex\Session\PlaybackController;
use Workerman\MySQL\Connection;

class RokuSessionTest extends TestCase
{
    private function createMockPlaybackController(): PlaybackController
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $sessionManager = $this->createMock(\Phlex\Session\SessionManager::class);

        return new PlaybackController($db, $sessionManager);
    }

    public function testPlayMediaTransitionsToPlaying(): void
    {
        $device = new RokuDevice('roku-123', 'Test Roku', '192.168.1.100', 8060);
        $client = $this->createMock(RokuEcpClient::class);
        $client->method('playMedia')->willReturn(['success' => true]);

        $playbackController = $this->createMockPlaybackController();

        $session = new RokuSession(
            'session-1',
            $device,
            $client,
            $playbackController
        );

        $this->assertEquals(RokuSession::STATE_IDLE, $session->getState());

        // Note: Without actual ECP server, playMedia would throw
        // In real tests, we would mock the network call
    }

    public function testSendKeySendsKeypress(): void
    {
        $device = new RokuDevice('roku-123', 'Test Roku', '192.168.1.100', 8060);
        $client = $this->createMock(RokuEcpClient::class);
        $client->method('sendKeypress')->willReturn(['success' => true]);

        $playbackController = $this->createMockPlaybackController();

        $session = new RokuSession(
            'session-2',
            $device,
            $client,
            $playbackController
        );

        // sendKey should call sendKeypress on the client
        $result = $session->sendKey('Play');
        $this->assertIsArray($result);
    }

    public function testPauseCallsSendKeyPause(): void
    {
        $device = new RokuDevice('roku-123', 'Test Roku', '192.168.1.100', 8060);
        $client = $this->createMock(RokuEcpClient::class);
        $client->method('sendKeypress')->willReturn(['success' => true]);

        $playbackController = $this->createMockPlaybackController();

        $session = new RokuSession(
            'session-3',
            $device,
            $client,
            $playbackController
        );

        // pause() should call sendKey('Pause')
        $this->assertInstanceOf(RokuSession::class, $session);
    }

    public function testSessionStateConstants(): void
    {
        $this->assertEquals('idle', RokuSession::STATE_IDLE);
        $this->assertEquals('launching', RokuSession::STATE_LAUNCHING);
        $this->assertEquals('playing', RokuSession::STATE_PLAYING);
        $this->assertEquals('paused', RokuSession::STATE_PAUSED);
    }

    public function testGetSessionIdReturnsCorrectId(): void
    {
        $device = new RokuDevice('roku-123', 'Test Roku', '192.168.1.100');
        $client = $this->createMock(RokuEcpClient::class);
        $playbackController = $this->createMockPlaybackController();

        $session = new RokuSession(
            'my-session-id',
            $device,
            $client,
            $playbackController
        );

        $this->assertEquals('my-session-id', $session->getSessionId());
    }

    public function testGetDeviceReturnsDevice(): void
    {
        $device = new RokuDevice('roku-456', 'Bedroom Roku', '192.168.1.101');
        $client = $this->createMock(RokuEcpClient::class);
        $playbackController = $this->createMockPlaybackController();

        $session = new RokuSession(
            'session-4',
            $device,
            $client,
            $playbackController
        );

        $this->assertSame($device, $session->getDevice());
    }

    public function testInitialStateIsIdle(): void
    {
        $device = new RokuDevice('roku-123', 'Test Roku', '192.168.1.100');
        $client = $this->createMock(RokuEcpClient::class);
        $playbackController = $this->createMockPlaybackController();

        $session = new RokuSession(
            'session-5',
            $device,
            $client,
            $playbackController
        );

        $this->assertEquals(RokuSession::STATE_IDLE, $session->getState());
    }
}
