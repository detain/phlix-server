<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Relay;

use Phlex\Hub\RelayConsumer;
use Phlex\LiveTv\LiveTvManager;
use Phlex\Media\Streaming\HlsStreamer;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * Orchestrates relay sessions and the hub WebSocket tunnel.
 *
 * Manages the lifecycle of HLS relay sessions for remote live TV streaming,
 * including session creation, tuner allocation, mount registration, and cleanup.
 *
 * @since 0.12.0
 */
class HlsRelayManager
{
    /** @var LiveTvManager Live TV manager for tuner access. */
    private LiveTvManager $liveTvManager;

    /** @var HlsStreamer HLS streamer for variant playlist access. */
    private HlsStreamer $hlsStreamer;

    /** @var object Hub relay consumer for mount registration (must have registerMount method). */
    private object $relayConsumer;

    /** @var Connection Database connection. */
    private Connection $db;

    /** @var LoggerInterface|null Optional logger. */
    private ?LoggerInterface $logger;

    /** @var HlsSegmentPrefetcher Segment prefetcher for relay caching. */
    private HlsSegmentPrefetcher $segmentPrefetcher;

    /** @var string Relay path prefix for mount URLs. */
    private string $relayPathPrefix;

    /** @var int Maximum concurrent relay sessions. */
    private int $maxConcurrentSessions;

    /**
     * @param LiveTvManager       $liveTvManager    Live TV manager for tuner access.
     * @param HlsStreamer         $hlsStreamer      HLS streamer for variant playlists.
     * @param object             $relayConsumer    Hub relay consumer for mount registration
     *                                           (must have registerMount method).
     * @param Connection          $db               Database connection.
     * @param HlsSegmentPrefetcher $segmentPrefetcher Segment prefetcher instance.
     * @param LoggerInterface|null $logger           Optional logger.
     * @param string              $relayPathPrefix   Relay path prefix (e.g., '/relay/live').
     * @param int                $maxConcurrentSessions Maximum concurrent sessions.
     *
     * @since 0.12.0
     */
    public function __construct(
        LiveTvManager $liveTvManager,
        HlsStreamer $hlsStreamer,
        object $relayConsumer,
        Connection $db,
        HlsSegmentPrefetcher $segmentPrefetcher,
        ?LoggerInterface $logger = null,
        string $relayPathPrefix = '/relay/live',
        int $maxConcurrentSessions = 10,
    ) {
        $this->liveTvManager = $liveTvManager;
        $this->hlsStreamer = $hlsStreamer;
        $this->relayConsumer = $relayConsumer;
        $this->db = $db;
        $this->segmentPrefetcher = $segmentPrefetcher;
        $this->logger = $logger;
        $this->relayPathPrefix = $relayPathPrefix;
        $this->maxConcurrentSessions = $maxConcurrentSessions;
    }

    /**
     * Start a relay session for a channel.
     *
     * Creates a tune request via tuneToChannel(), creates a relay session,
     * stores it in the database, and registers the mount with RelayConsumer.
     *
     * @param string $channelId Channel ID to relay.
     * @param string $userId      User ID initiating the relay.
     *
     * @return HlsRelaySession The created relay session.
     *
     * @throws \RuntimeException If no tuner is available or max sessions reached.
     *
     * @since 0.12.0
     */
    public function startRelaySession(string $channelId, string $userId): HlsRelaySession
    {
        // Check max concurrent sessions
        $activeSessions = $this->getActiveSessions();
        if (count($activeSessions) >= $this->maxConcurrentSessions) {
            throw new \RuntimeException('Maximum concurrent relay sessions reached');
        }

        // Check if user already has an active session
        $existingSession = $this->getUserSession($userId);
        if ($existingSession !== null) {
            $this->stopRelaySession($existingSession->getSessionId());
        }

        // Create tune request via LiveTvManager
        $tuneResult = $this->liveTvManager->tuneToChannel($channelId);
        $tuneRequestId = $tuneResult['id'];
        $streamUrl = $tuneResult['stream_url'];

        // Generate session ID
        $sessionId = $this->generateUuid();

        // Build mount URL
        $mountUrl = "{$this->relayPathPrefix}/{$sessionId}/playlist.m3u8";

        // Create relay session value object
        $session = new HlsRelaySession(
            $sessionId,
            $channelId,
            $tuneRequestId,
            time(),
            $this->relayPathPrefix,
        );

        // Store in database
        $this->db->query(
            "INSERT INTO livetv_relay_sessions
             (session_id, user_id, channel_id, tune_request_id, mount_url, started_at, last_activity_at, bytes_relayed)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 0)",
            [$sessionId, $userId, $channelId, $tuneRequestId, $mountUrl]
        );

        // Get variant playlist URL and start prefetching
        $variantPlaylistUrl = $this->hlsStreamer->getVariantPlaylistUrl($sessionId, 0);
        $this->segmentPrefetcher->startPrefetch($sessionId, $variantPlaylistUrl);

        // Register mount with RelayConsumer
        $handler = function (string $path) use ($sessionId): ?string {
            return $this->handleRelayRequest($sessionId, $path);
        };
        // @phpstan-ignore-next-line - relayConsumer is guaranteed to have registerMount method
        $this->relayConsumer->registerMount(
            "/relay/live/{$sessionId}",
            $handler
        );

        $this->logger?->info('HlsRelayManager: started relay session', [
            'session_id' => $sessionId,
            'channel_id' => $channelId,
            'user_id' => $userId,
            'mount_url' => $mountUrl,
        ]);

        return $session;
    }

    /**
     * Handle a relay request for a session.
     *
     * @param string $sessionId Session ID.
     * @param string $path       Request path.
     *
     * @return string|null Response content or null if not found.
     *
     * @since 0.12.0
     */
    private function handleRelayRequest(string $sessionId, string $path): ?string
    {
        // Get session from database
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT * FROM livetv_relay_sessions WHERE session_id = ?",
            [$sessionId]
        );

        if (empty($rows)) {
            return null;
        }

        /** @var array<string, mixed> $sessionRow */
        $sessionRow = $rows[0];

        // Update last activity
        $this->db->query(
            "UPDATE livetv_relay_sessions SET last_activity_at = NOW() WHERE session_id = ?",
            [$sessionId]
        );

        // Extract the relative path after /relay/live/{sessionId}/
        $relativePath = ltrim(str_replace("/relay/live/{$sessionId}", '', $path), '/');

        // Check if it's the playlist request
        if ($relativePath === 'playlist.m3u8') {
            // Get tune result to build playlist
            /** @var string $tuneRequestId */
            $tuneRequestId = $sessionRow['tune_request_id'];
            $tuneRequest = $this->liveTvManager->getTuneRequest($tuneRequestId);

            if ($tuneRequest === null) {
                return null;
            }

            // Generate variant playlist
            $variantPlaylistUrl = $this->hlsStreamer->getVariantPlaylistUrl($sessionId, 0);

            // Return redirect or proxy to variant playlist
            return $this->fetchPlaylistContent($variantPlaylistUrl);
        }

        // Check segment cache first
        $segmentContent = $this->segmentPrefetcher->getSegment($path);
        if ($segmentContent !== null) {
            return $segmentContent;
        }

        // Fetch from local HLS streamer
        return $this->fetchFromLocalStreamer($sessionId, $relativePath);
    }

    /**
     * Fetch playlist content from a URL.
     *
     * @param string $url Playlist URL.
     *
     * @return string|null Content or null on failure.
     *
     * @since 0.12.0
     */
    private function fetchPlaylistContent(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Phlex Media Server/1.0',
            ],
        ]);

        return @file_get_contents($url, false, $context) ?: null;
    }

    /**
     * Fetch segment from local HLS streamer.
     *
     * @param string $sessionId    Session ID.
     * @param string $relativePath  Relative path within session.
     *
     * @return string|null Content or null on failure.
     *
     * @since 0.12.0
     */
    private function fetchFromLocalStreamer(string $sessionId, string $relativePath): ?string
    {
        // Parse segment path like "segment_0_001.ts"
        if (preg_match('/^segment_(\d+)_(\d+)\.ts$/', $relativePath, $matches)) {
            $variantIndex = (int) $matches[1];
            $segmentNumber = (int) $matches[2];
            return $this->hlsStreamer->getSegmentContent($sessionId, $variantIndex, $segmentNumber);
        }

        return null;
    }

    /**
     * Stop a relay session and release the tuner.
     *
     * @param string $sessionId Session ID to stop.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function stopRelaySession(string $sessionId): void
    {
        // Get session from database
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT * FROM livetv_relay_sessions WHERE session_id = ?",
            [$sessionId]
        );

        if (empty($rows)) {
            return;
        }

        /** @var array<string, mixed> $sessionRow */
        $sessionRow = $rows[0];

        // Stop prefetching
        $this->segmentPrefetcher->stopPrefetch($sessionId);

        // Release the tuner
        /** @var string $tuneRequestId */
        $tuneRequestId = $sessionRow['tune_request_id'];
        $this->liveTvManager->stopTuning($tuneRequestId);

        // Delete from database
        $this->db->query(
            "DELETE FROM livetv_relay_sessions WHERE session_id = ?",
            [$sessionId]
        );

        $this->logger?->info('HlsRelayManager: stopped relay session', [
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Get active relay sessions for the hub.
     *
     * @return array<int, array<string, mixed>> Active relay sessions.
     *
     * @since 0.12.0
     */
    public function getActiveSessions(): array
    {
        /** @var array<int, array<string, mixed>> $result */
        $result = $this->db->query(
            "SELECT * FROM livetv_relay_sessions ORDER BY started_at DESC"
        );
        return $result;
    }

    /**
     * Check if a user has an active relay session.
     *
     * @param string $userId User ID to check.
     *
     * @return HlsRelaySession|null The user's active session or null.
     *
     * @since 0.12.0
     */
    public function getUserSession(string $userId): ?HlsRelaySession
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT * FROM livetv_relay_sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT 1",
            [$userId]
        );

        if (empty($rows)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];

        /** @var string $sessionId */
        $sessionId = $row['session_id'];
        /** @var string $channelId */
        $channelId = $row['channel_id'];
        /** @var string $tuneRequestId */
        $tuneRequestId = $row['tune_request_id'];
        /** @var string $startedAt */
        $startedAt = $row['started_at'];
        $createdAt = strtotime($startedAt);
        if ($createdAt === false) {
            $createdAt = time();
        }

        return new HlsRelaySession(
            $sessionId,
            $channelId,
            $tuneRequestId,
            $createdAt,
            $this->relayPathPrefix,
        );
    }

    /**
     * Get the segment prefetcher instance.
     *
     * @return HlsSegmentPrefetcher The segment prefetcher.
     *
     * @since 0.12.0
     */
    public function getSegmentPrefetcher(): HlsSegmentPrefetcher
    {
        return $this->segmentPrefetcher;
    }

    /**
     * Generate a unique UUID v4 string.
     *
     * @return string UUID in format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx.
     *
     * @since 0.12.0
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
