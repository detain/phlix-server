<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Dlna\PlayToSession;
use Phlix\Dlna\RendererControlClient;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Workerman\MySQL\Connection;

class PlayToSessionTest extends TestCase
{
    private RendererControlClient $clientMock;
    private PlaybackController $playbackControllerMock;
    private StructuredLogger $logger;

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(RendererControlClient::class);
        $this->playbackControllerMock = $this->createMock(PlaybackController::class);
        $this->logger = $this->createMock(StructuredLogger::class);
    }

    public function testSetMediaItemUpdatesSessionState(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        $this->clientMock
            ->expects($this->once())
            ->method('setAvTransportUri')
            ->with('http://example.com/media.m3u8', '<DIDL>test</DIDL>')
            ->willReturn(['CurrentState' => 'STOPPED']);

        $session->setMediaItem('item-1', 'http://example.com/media.m3u8', '<DIDL>test</DIDL>');

        $this->assertEquals(PlayToSession::STATE_BUFFERING, $session->getState());
        $this->assertEquals('session-123', $session->getSessionId());
        $this->assertEquals('renderer-1', $session->getRendererId());
        $this->assertEquals('Living Room TV', $session->getRendererName());
    }

    public function testPlayTransitionsToPlaying(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // First set media
        $this->clientMock
            ->method('setAvTransportUri')
            ->willReturn(['CurrentState' => 'STOPPED']);

        $session->setMediaItem('item-1', 'http://example.com/media.m3u8', '');

        // Then play
        $this->clientMock
            ->expects($this->once())
            ->method('play')
            ->with('1')
            ->willReturn(['CurrentState' => 'PLAYING']);

        $session->play();

        $this->assertEquals(PlayToSession::STATE_PLAYING, $session->getState());
    }

    public function testPlayFailsWhenMediaNotSet(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // Don't set media, try to play directly
        $this->clientMock
            ->expects($this->once())
            ->method('play')
            ->willReturn(['Error' => ['code' => 702, 'description' => 'Transport not set up']]);

        $session->play();

        // State should not change to playing
        $this->assertNotEquals(PlayToSession::STATE_PLAYING, $session->getState());
    }

    public function testPauseTransitionsToPaused(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // Setup playing state
        $this->clientMock->method('setAvTransportUri')->willReturn(['CurrentState' => 'STOPPED']);
        $this->clientMock->method('play')->willReturn(['CurrentState' => 'PLAYING']);
        $this->clientMock
            ->expects($this->once())
            ->method('pause')
            ->willReturn(['CurrentState' => 'PAUSED_PLAYING']);

        $session->setMediaItem('item-1', 'http://example.com/media.m3u8', '');
        $session->play();
        $session->pause();

        $this->assertEquals(PlayToSession::STATE_PAUSED, $session->getState());
    }

    public function testStopTransitionsToStopped(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // Setup playing state
        $this->clientMock->method('setAvTransportUri')->willReturn(['CurrentState' => 'STOPPED']);
        $this->clientMock->method('play')->willReturn(['CurrentState' => 'PLAYING']);
        $this->clientMock
            ->expects($this->once())
            ->method('stop')
            ->willReturn(['CurrentState' => 'STOPPED']);

        $session->setMediaItem('item-1', 'http://example.com/media.m3u8', '');
        $session->play();
        $session->stop();

        $this->assertEquals(PlayToSession::STATE_STOPPED, $session->getState());
    }

    public function testSeekCallsRendererSeek(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // 5 minutes 30 seconds = 330 seconds = 3300000000 ticks
        $seekTarget = '00:05:30';

        $this->clientMock
            ->expects($this->once())
            ->method('seek')
            ->with($seekTarget)
            ->willReturn(['CurrentState' => 'PLAYING']);

        $session->seek(3300000000);
    }

    public function testSeekConvertsTicksToTimeString(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // 1 hour 2 minutes 3 seconds = 3723 seconds = 37230000000 ticks
        $seekTarget = '01:02:03';

        $this->clientMock
            ->expects($this->once())
            ->method('seek')
            ->with($seekTarget)
            ->willReturn(['CurrentState' => 'PLAYING']);

        $session->seek(37230000000);
    }

    public function testSyncFromRendererUpdatesPosition(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // Set up initial state
        $this->clientMock->method('setAvTransportUri')->willReturn(['CurrentState' => 'STOPPED']);
        $this->clientMock->method('play')->willReturn(['CurrentState' => 'PLAYING']);
        $this->clientMock->method('getPositionInfo')->willReturn([
            'RelTime' => '00:10:30',
            'TrackDuration' => '01:30:00',
        ]);

        $session->setMediaItem('item-1', 'http://example.com/media.m3u8', '');
        $session->play();

        // Initial position should be 0
        $this->assertEquals(0, $session->getPosition());

        // Sync should update position
        $session->syncFromRenderer();

        // 10 minutes 30 seconds = 630 seconds = 6300000000 ticks
        $this->assertEquals(6300000000, $session->getPosition());
    }

    public function testGetPositionReturnsCurrentPosition(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        $this->assertEquals(0, $session->getPosition());
    }

    public function testGetStateReturnsCurrentState(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        $this->assertEquals(PlayToSession::STATE_IDLE, $session->getState());
    }

    public function testOnStateChangeCallbackIsCalled(): void
    {
        $session = new PlayToSession(
            'session-123',
            'renderer-1',
            'Living Room TV',
            $this->clientMock,
            $this->playbackControllerMock,
            $this->logger
        );

        $stateChanges = [];
        $session->onStateChange(function (string $state) use (&$stateChanges) {
            $stateChanges[] = $state;
        });

        // Set media to trigger state change
        $this->clientMock->method('setAvTransportUri')->willReturn(['CurrentState' => 'STOPPED']);

        $session->setMediaItem('item-1', 'http://example.com/media.m3u8', '');

        $this->assertContains(PlayToSession::STATE_BUFFERING, $stateChanges);
    }
}
