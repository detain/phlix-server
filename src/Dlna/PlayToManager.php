<?php

declare(strict_types=1);

namespace Phlex\Dlna;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Session\PlaybackController;

/**
 * Manages multiple "play to" sessions with DLNA renderers.
 *
 * Maintains a map of renderer ID to active PlayToSession, providing
 * a facade for discovering renderers and managing play-to sessions.
 * Acts as a factory for PlayToSession instances, creating the
 * RendererControlClient and wiring everything together.
 *
 * @since 0.12.0
 */
class PlayToManager
{
    /** @var RendererDiscovery SSDP renderer discovery service */
    private RendererDiscovery $rendererDiscovery;

    /** @var PlaybackController Phlex playback controller */
    private PlaybackController $playbackController;

    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /** @var array<string, PlayToSession> Active sessions keyed by renderer ID */
    private array $sessions = [];

    /**
     * @param RendererDiscovery $rendererDiscovery SSDP renderer discovery service
     * @param PlaybackController $playbackController Phlex playback controller
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(
        RendererDiscovery $rendererDiscovery,
        PlaybackController $playbackController,
        ?StructuredLogger $logger = null
    ) {
        $this->rendererDiscovery = $rendererDiscovery;
        $this->playbackController = $playbackController;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Create a default logger for standalone/test operation.
     *
     * @return StructuredLogger Configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_dlna_play_to_manager_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/play_to_manager.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::DLNA, $config);
    }

    /**
     * Discover available renderers on the network.
     *
     * @return array<int, array<string, mixed>> Array of renderer descriptors
     *
     * @since 0.12.0
     */
    public function discoverRenderers(): array
    {
        $this->logger->info('Discovering renderers');

        $renderers = $this->rendererDiscovery->discoverRenderers();

        $this->logger->info('Renderer discovery complete', [
            'count' => count($renderers),
        ]);

        return $renderers;
    }

    /**
     * Start a "play to" session for a media item.
     *
     * Creates a new RendererControlClient, sets the media URI on the renderer,
     * and starts playback. If a session already exists for this renderer,
     * it will be stopped first.
     *
     * @param string $rendererId Renderer identifier (UDN)
     * @param string $mediaItemId Media item ID
     * @param string $uri Media URI (HLS stream URL)
     * @param string $metadata DIDL-Lite metadata
     *
     * @return PlayToSession|null New session or null on failure
     *
     * @since 0.12.0
     */
    public function startSession(string $rendererId, string $mediaItemId, string $uri, string $metadata = ''): ?PlayToSession
    {
        $this->logger->info('Starting play-to session', [
            'renderer_id' => $rendererId,
            'media_item_id' => $mediaItemId,
            'uri' => $uri,
        ]);

        // Stop existing session if any
        if (isset($this->sessions[$rendererId])) {
            $this->stopSession($rendererId);
        }

        // Create session ID
        $sessionId = $this->generateUuid();

        // Get renderer info - we need the AVTransport URL
        $renderers = $this->discoverRenderers();
        $rendererInfo = null;
        foreach ($renderers as $renderer) {
            if (($renderer['udn'] ?? '') === $rendererId) {
                $rendererInfo = $renderer;
                break;
            }
        }

        if ($rendererInfo === null) {
            $this->logger->error('Renderer not found', ['renderer_id' => $rendererId]);
            return null;
        }

        $avTransportUrlRaw = $rendererInfo['av_transport_url'] ?? null;
        $avTransportUrl = is_string($avTransportUrlRaw) ? $avTransportUrlRaw : '';
        if ($avTransportUrl === '') {
            $this->logger->error('Renderer has no AVTransport URL', ['renderer_id' => $rendererId]);
            return null;
        }

        // Create the renderer control client
        $client = new RendererControlClient($avTransportUrl, $this->logger);

        // Create the play-to session
        $friendlyNameRaw = $rendererInfo['friendly_name'] ?? null;
        $friendlyName = is_string($friendlyNameRaw) ? $friendlyNameRaw : 'Unknown Renderer';
        $session = new PlayToSession(
            $sessionId,
            $rendererId,
            $friendlyName,
            $client,
            $this->playbackController,
            $this->logger
        );

        // Set media and start playing
        $session->setMediaItem($mediaItemId, $uri, $metadata);
        $session->play();

        // Store the session
        $this->sessions[$rendererId] = $session;

        $this->logger->info('Play-to session started', [
            'session_id' => $sessionId,
            'renderer_id' => $rendererId,
        ]);

        return $session;
    }

    /**
     * Get the active session for a renderer.
     *
     * @param string $rendererId Renderer identifier (UDN)
     *
     * @return PlayToSession|null Active session or null if none
     *
     * @since 0.12.0
     */
    public function getSession(string $rendererId): ?PlayToSession
    {
        return $this->sessions[$rendererId] ?? null;
    }

    /**
     * Stop and remove a session.
     *
     * @param string $rendererId Renderer identifier (UDN)
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stopSession(string $rendererId): void
    {
        if (!isset($this->sessions[$rendererId])) {
            return;
        }

        $session = $this->sessions[$rendererId];
        $session->destroy();

        unset($this->sessions[$rendererId]);

        $this->logger->info('Play-to session stopped', [
            'renderer_id' => $rendererId,
        ]);
    }

    /**
     * Get all active sessions.
     *
     * @return PlayToSession[] Array of active sessions
     *
     * @since 0.12.0
     */
    public function getActiveSessions(): array
    {
        return array_values($this->sessions);
    }

    /**
     * Stop all active sessions.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stopAllSessions(): void
    {
        foreach (array_keys($this->sessions) as $rendererId) {
            $this->stopSession($rendererId);
        }
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string UUID in standard format
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
