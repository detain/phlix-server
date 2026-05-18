<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Dlna\PlayToManager;
use Phlex\Dlna\RendererControlClient;
use Phlex\Dlna\RendererDiscovery;
use Phlex\Dlna\PlayToSession;
use Phlex\Session\PlaybackController;
use Phlex\Session\SessionManager;

class PlayToManagerTest extends TestCase
{
    private RendererDiscovery $rendererDiscoveryMock;
    private PlaybackController $playbackControllerMock;
    private StructuredLogger $logger;

    protected function setUp(): void
    {
        $this->rendererDiscoveryMock = $this->createMock(RendererDiscovery::class);
        $this->playbackControllerMock = $this->createMock(PlaybackController::class);
        $this->logger = $this->createMock(StructuredLogger::class);
    }

    public function testDiscoverRenderersDelegatesToRendererDiscovery(): void
    {
        $renderers = [
            [
                'udn' => 'uuid:renderer-1',
                'friendly_name' => 'Living Room TV',
                'manufacturer' => 'Samsung',
                'av_transport_url' => 'http://192.168.1.100:8200/avtransport/control',
            ],
            [
                'udn' => 'uuid:renderer-2',
                'friendly_name' => 'Bedroom Speaker',
                'manufacturer' => 'Sonos',
                'av_transport_url' => 'http://192.168.1.101:8200/avtransport/control',
            ],
        ];

        $this->rendererDiscoveryMock
            ->expects($this->once())
            ->method('discoverRenderers')
            ->willReturn($renderers);

        $manager = new PlayToManager(
            $this->rendererDiscoveryMock,
            $this->playbackControllerMock,
            $this->logger
        );

        $result = $manager->discoverRenderers();

        $this->assertEquals($renderers, $result);
    }

    public function testStartSessionCreatesClientAndSetsUri(): void
    {
        $rendererId = 'uuid:renderer-1';

        $this->rendererDiscoveryMock
            ->method('discoverRenderers')
            ->willReturn([
                [
                    'udn' => $rendererId,
                    'friendly_name' => 'Living Room TV',
                    'av_transport_url' => 'http://192.168.1.100:8200/avtransport/control',
                ],
            ]);

        $manager = new PlayToManager(
            $this->rendererDiscoveryMock,
            $this->playbackControllerMock,
            $this->logger
        );

        $session = $manager->startSession(
            $rendererId,
            'media-item-1',
            'http://example.com/media.m3u8',
            '<DIDL>test</DIDL>'
        );

        $this->assertInstanceOf(PlayToSession::class, $session);
        $this->assertEquals($rendererId, $session->getRendererId());
    }

    public function testStartSessionReturnsNullWhenRendererNotFound(): void
    {
        $this->rendererDiscoveryMock
            ->method('discoverRenderers')
            ->willReturn([]);

        $manager = new PlayToManager(
            $this->rendererDiscoveryMock,
            $this->playbackControllerMock,
            $this->logger
        );

        $session = $manager->startSession(
            'uuid:nonexistent',
            'media-item-1',
            'http://example.com/media.m3u8',
            ''
        );

        $this->assertNull($session);
    }

    public function testGetSessionReturnsActiveSession(): void
    {
        $rendererId = 'uuid:renderer-1';

        $this->rendererDiscoveryMock
            ->method('discoverRenderers')
            ->willReturn([
                [
                    'udn' => $rendererId,
                    'friendly_name' => 'Living Room TV',
                    'av_transport_url' => 'http://192.168.1.100:8200/avtransport/control',
                ],
            ]);

        $manager = new PlayToManager(
            $this->rendererDiscoveryMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // Start a session
        $session = $manager->startSession(
            $rendererId,
            'media-item-1',
            'http://example.com/media.m3u8',
            ''
        );

        // Get the same session
        $retrievedSession = $manager->getSession($rendererId);

        $this->assertSame($session, $retrievedSession);
    }

    public function testGetSessionReturnsNullForInactiveRenderer(): void
    {
        $manager = new PlayToManager(
            $this->rendererDiscoveryMock,
            $this->playbackControllerMock,
            $this->logger
        );

        $session = $manager->getSession('uuid:inactive-renderer');

        $this->assertNull($session);
    }

    public function testStopSessionRemovesSession(): void
    {
        $rendererId = 'uuid:renderer-1';

        $this->rendererDiscoveryMock
            ->method('discoverRenderers')
            ->willReturn([
                [
                    'udn' => $rendererId,
                    'friendly_name' => 'Living Room TV',
                    'av_transport_url' => 'http://192.168.1.100:8200/avtransport/control',
                ],
            ]);

        $manager = new PlayToManager(
            $this->rendererDiscoveryMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // Start and then stop a session
        $session = $manager->startSession(
            $rendererId,
            'media-item-1',
            'http://example.com/media.m3u8',
            ''
        );

        $this->assertNotNull($session);

        $manager->stopSession($rendererId);

        $this->assertNull($manager->getSession($rendererId));
    }

    public function testStopSessionOnInactiveRendererDoesNotError(): void
    {
        $manager = new PlayToManager(
            $this->rendererDiscoveryMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // Should not throw
        $manager->stopSession('uuid:nonexistent');

        $this->assertTrue(true); // If we get here, no error was thrown
    }

    public function testGetActiveSessionsReturnsAllActiveSessions(): void
    {
        $this->rendererDiscoveryMock
            ->method('discoverRenderers')
            ->willReturn([
                [
                    'udn' => 'uuid:renderer-1',
                    'friendly_name' => 'Living Room TV',
                    'av_transport_url' => 'http://192.168.1.100:8200/avtransport/control',
                ],
                [
                    'udn' => 'uuid:renderer-2',
                    'friendly_name' => 'Bedroom Speaker',
                    'av_transport_url' => 'http://192.168.1.101:8200/avtransport/control',
                ],
            ]);

        $manager = new PlayToManager(
            $this->rendererDiscoveryMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // Start two sessions
        $manager->startSession('uuid:renderer-1', 'media-1', 'http://example.com/1.m3u8', '');
        $manager->startSession('uuid:renderer-2', 'media-2', 'http://example.com/2.m3u8', '');

        $sessions = $manager->getActiveSessions();

        $this->assertCount(2, $sessions);
    }

    public function testStopAllSessionsStopsAllActiveSessions(): void
    {
        $this->rendererDiscoveryMock
            ->method('discoverRenderers')
            ->willReturn([
                [
                    'udn' => 'uuid:renderer-1',
                    'friendly_name' => 'TV 1',
                    'av_transport_url' => 'http://192.168.1.100:8200/avtransport/control',
                ],
                [
                    'udn' => 'uuid:renderer-2',
                    'friendly_name' => 'TV 2',
                    'av_transport_url' => 'http://192.168.1.101:8200/avtransport/control',
                ],
            ]);

        $manager = new PlayToManager(
            $this->rendererDiscoveryMock,
            $this->playbackControllerMock,
            $this->logger
        );

        // Start two sessions
        $manager->startSession('uuid:renderer-1', 'media-1', 'http://example.com/1.m3u8', '');
        $manager->startSession('uuid:renderer-2', 'media-2', 'http://example.com/2.m3u8', '');

        $this->assertCount(2, $manager->getActiveSessions());

        $manager->stopAllSessions();

        $this->assertEmpty($manager->getActiveSessions());
    }
}
