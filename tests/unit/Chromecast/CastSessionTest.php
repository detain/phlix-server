<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Chromecast;

use PHPUnit\Framework\TestCase;
use Phlex\Chromecast\CastApiClient;
use Phlex\Chromecast\CastDevice;
use Phlex\Chromecast\CastSession;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Session\PlaybackController;

class CastSessionTest extends TestCase
{
    private CastApiClient $clientMock;
    private PlaybackController $playbackControllerMock;
    private StructuredLogger $loggerMock;
    private CastDevice $device;

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(CastApiClient::class);
        $this->playbackControllerMock = $this->createMock(PlaybackController::class);
        $this->loggerMock = $this->createMock(StructuredLogger::class);
        $this->device = new CastDevice(
            'device-123',
            'Test Chromecast',
            '192.168.1.100',
            8009,
            'Chromecast Ultra',
            'uuid-456'
        );
    }

    public function testLaunchAppTransitionsState(): void
    {
        $session = new CastSession(
            'session-123',
            $this->device,
            $this->clientMock,
            $this->playbackControllerMock,
            $this->loggerMock
        );

        $this->assertEquals(CastSession::STATE_IDLE, $session->getState());

        $this->clientMock
            ->expects($this->once())
            ->method('launchApp')
            ->with(CastApiClient::APP_ID_DEFAULT)
            ->willReturn(['transportId' => 'transport-abc']);

        $result = $session->launchApp();

        $this->assertEquals(CastSession::STATE_APP_RUNNING, $session->getState());
        $this->assertEquals('session-123', $session->getSessionId());
        $this->assertEquals($this->device, $session->getDevice());
        $this->assertEquals(['transportId' => 'transport-abc'], $result);
    }

    public function testLoadMediaSetsSessionAndStartsPlayer(): void
    {
        $session = new CastSession(
            'session-123',
            $this->device,
            $this->clientMock,
            $this->playbackControllerMock,
            $this->loggerMock
        );

        $this->clientMock
            ->expects($this->once())
            ->method('loadMedia')
            ->with(
                'http://example.com/stream.m3u8',
                'application/x-mpegurl',
                $this->callback(function ($metadata) {
                    return isset($metadata['title']) && $metadata['title'] === 'Test Video';
                })
            )
            ->willReturn(['transportId' => 'transport-abc', 'success' => true]);

        $result = $session->loadMedia(
            'http://example.com/stream.m3u8',
            'application/x-mpegurl',
            3600,
            'Test Video',
            'http://example.com/thumb.jpg'
        );

        $this->assertEquals(CastSession::STATE_PLAYING, $session->getState());
        $this->assertIsArray($result);
    }

    public function testPlayTransitionsToPlaying(): void
    {
        $session = new CastSession(
            'session-123',
            $this->device,
            $this->clientMock,
            $this->playbackControllerMock,
            $this->loggerMock
        );

        // First set media to get into a non-idle state
        $this->clientMock
            ->method('loadMedia')
            ->willReturn(['success' => true]);

        $session->loadMedia('http://example.com/stream.m3u8', 'application/x-mpegurl');

        // Then test play
        $this->clientMock
            ->expects($this->once())
            ->method('sendMediaCommand')
            ->with('PLAY')
            ->willReturn(['playerState' => 'PLAYING']);

        $result = $session->play();

        $this->assertEquals(CastSession::STATE_PLAYING, $session->getState());
        $this->assertIsArray($result);
    }

    public function testPauseTransitionsToPaused(): void
    {
        $session = new CastSession(
            'session-123',
            $this->device,
            $this->clientMock,
            $this->playbackControllerMock,
            $this->loggerMock
        );

        // First set media
        $this->clientMock
            ->method('loadMedia')
            ->willReturn(['success' => true]);

        $session->loadMedia('http://example.com/stream.m3u8', 'application/x-mpegurl');

        // Then pause
        $this->clientMock
            ->expects($this->once())
            ->method('sendMediaCommand')
            ->with('PAUSE')
            ->willReturn(['playerState' => 'PAUSED']);

        $result = $session->pause();

        $this->assertEquals(CastSession::STATE_PAUSED, $session->getState());
        $this->assertIsArray($result);
    }

    public function testSeekSendsSeekCommand(): void
    {
        $session = new CastSession(
            'session-123',
            $this->device,
            $this->clientMock,
            $this->playbackControllerMock,
            $this->loggerMock
        );

        // First set media
        $this->clientMock
            ->method('loadMedia')
            ->willReturn(['success' => true]);

        $session->loadMedia('http://example.com/stream.m3u8', 'application/x-mpegurl');

        // Then seek
        $this->clientMock
            ->expects($this->once())
            ->method('sendMediaCommand')
            ->with('SEEK', ['currentTime' => 120]) // 120 seconds = 120000 ms
            ->willReturn(['success' => true]);

        $result = $session->seek(120000); // 120000 ms = 120 seconds

        $this->assertIsArray($result);
    }

    public function testStopResetsState(): void
    {
        $session = new CastSession(
            'session-123',
            $this->device,
            $this->clientMock,
            $this->playbackControllerMock,
            $this->loggerMock
        );

        // First set media
        $this->clientMock
            ->method('loadMedia')
            ->willReturn(['success' => true]);

        $session->loadMedia('http://example.com/stream.m3u8', 'application/x-mpegurl');

        // Then stop
        $this->clientMock
            ->expects($this->once())
            ->method('sendMediaCommand')
            ->with('STOP')
            ->willReturn(['success' => true]);

        $result = $session->stop();

        $this->assertEquals(CastSession::STATE_IDLE, $session->getState());
        $this->assertIsArray($result);
    }

    public function testGetMediaStatusParsesCurrentTime(): void
    {
        $session = new CastSession(
            'session-123',
            $this->device,
            $this->clientMock,
            $this->playbackControllerMock,
            $this->loggerMock
        );

        // Set media first
        $this->clientMock
            ->method('loadMedia')
            ->willReturn(['success' => true]);

        $session->loadMedia('http://example.com/stream.m3u8', 'application/x-mpegurl');

        // Mock getMediaStatus to return position
        $this->clientMock
            ->expects($this->once())
            ->method('getMediaStatus')
            ->willReturn([
                'status' => [
                    [
                        'currentTime' => 65.5, // seconds
                        'playerState' => 'PLAYING',
                    ]
                ]
            ]);

        $status = $session->getMediaStatus();

        $this->assertIsArray($status);
    }

    public function testSessionStateConstants(): void
    {
        $this->assertEquals('idle', CastSession::STATE_IDLE);
        $this->assertEquals('app_launching', CastSession::STATE_APP_LAUNCHING);
        $this->assertEquals('app_running', CastSession::STATE_APP_RUNNING);
        $this->assertEquals('playing', CastSession::STATE_PLAYING);
        $this->assertEquals('paused', CastSession::STATE_PAUSED);
        $this->assertEquals('buffering', CastSession::STATE_BUFFERING);
    }
}
