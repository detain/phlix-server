<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Relay;

/**
 * Represents a single remote relay session for a live TV stream.
 *
 * This value object holds all information about an HLS relay session
 * that allows remote clients to watch live TV through the hub's relay.
 *
 * @since 0.12.0
 */
final class HlsRelaySession
{
    /**
     * @param string $sessionId        Unique session identifier (UUID).
     * @param string $channelId         Channel being streamed.
     * @param string $tuneRequestId     Associated tune request ID.
     * @param int    $createdAt         Unix timestamp when session was created.
     * @param string $relayPathPrefix   URL prefix for relay paths (e.g., '/relay/live').
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $channelId,
        public readonly string $tuneRequestId,
        public readonly int $createdAt,
        private readonly string $relayPathPrefix = '/relay/live',
    ) {
    }

    /**
     * Get the relay mount URL for this session.
     *
     * Remote clients use this URL to access the HLS stream through the relay.
     * Format: /relay/live/{sessionId}/playlist.m3u8
     *
     * @return string The mount URL path.
     *
     * @since 0.12.0
     */
    public function getMountUrl(): string
    {
        return "{$this->relayPathPrefix}/{$this->sessionId}/playlist.m3u8";
    }

    /**
     * Get the local HLS variant playlist URL.
     *
     * This is the internal URL that the relay uses to fetch segments
     * from the local HLS streamer.
     *
     * @return string The variant playlist URL.
     *
     * @since 0.12.0
     */
    public function getVariantPlaylistUrl(): string
    {
        // The variant playlist URL format depends on the HLS stream setup
        // This returns a path that HlsSegmentPrefetcher can use to prefetch segments
        return "/hls/{$this->sessionId}/stream_0.m3u8";
    }

    /**
     * Get the session ID.
     *
     * @return string The session UUID.
     *
     * @since 0.12.0
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Get the channel ID.
     *
     * @return string The channel UUID.
     *
     * @since 0.12.0
     */
    public function getChannelId(): string
    {
        return $this->channelId;
    }

    /**
     * Get the tune request ID.
     *
     * @return string The tune request UUID.
     *
     * @since 0.12.0
     */
    public function getTuneRequestId(): string
    {
        return $this->tuneRequestId;
    }

    /**
     * Get the creation timestamp.
     *
     * @return int Unix timestamp.
     *
     * @since 0.12.0
     */
    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }
}
