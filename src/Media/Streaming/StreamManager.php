<?php

declare(strict_types=1);

namespace Phlex\Media\Streaming;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Streaming\Dash\DashStreamer;
use Workerman\MySQL\Connection;

/**
 * Stream Manager - Manages playback sessions and stream lifecycle.
 *
 * Coordinates media playback by creating stream sessions, tracking playback state,
 * managing quality selection, and persisting playback progress to the database.
 * Handles both direct play and transcoded streaming scenarios.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @description Session manager for media playback with quality selection and state persistence
 * @see StreamState For playback state tracking
 * @see QualitySelector For adaptive quality selection
 */
class StreamManager
{
    /** @var array<string, StreamState> Active streams indexed by stream ID */
    private array $activeStreams = [];

    /** @var Connection Database connection for persistence */
    private Connection $db;

    /** @var ItemRepository Media item data access */
    private ItemRepository $itemRepository;

    /** @var QualitySelector Quality selection engine */
    private QualitySelector $qualitySelector;

    /** @var StructuredLogger Structured logger for streaming events */
    private StructuredLogger $logger;

    /** @var HlsStreamer HLS streaming support */
    private HlsStreamer $hlsStreamer;

    /** @var DashStreamer DASH streaming support */
    private ?DashStreamer $dashStreamer = null;

    /** @var string Base URL for streaming endpoints */
    private string $baseUrl;

    /**
     * Creates a new StreamManager instance.
     *
     * @param Connection $db Database connection for playback state persistence
     * @param ItemRepository $itemRepository Media item repository
     * @param QualitySelector $qualitySelector Quality selection engine
     * @param HlsStreamer $hlsStreamer HLS streaming support
     * @param string $baseUrl Base URL for streaming endpoints
     *
     * @example
     * ```php
     * $manager = new StreamManager($db, $itemRepo, new QualitySelector(), $hlsStreamer, 'http://localhost:8096');
     * ```
     */
    public function __construct(
        Connection $db,
        ItemRepository $itemRepository,
        QualitySelector $qualitySelector,
        HlsStreamer $hlsStreamer,
        string $baseUrl = ''
    ) {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->qualitySelector = $qualitySelector;
        $this->hlsStreamer = $hlsStreamer;
        $this->baseUrl = $baseUrl;
        $this->logger = LoggerFactory::get(LogChannels::STREAMING);
    }

    /**
     * Sets the DASH streamer.
     *
     * @param DashStreamer $dashStreamer DASH streaming support
     *
     * @return void
     */
    public function setDashStreamer(DashStreamer $dashStreamer): void
    {
        $this->dashStreamer = $dashStreamer;
    }

    /**
     * Creates a new stream session.
     *
     * Initializes a playback session for a media item, determining the appropriate
     * playback method (direct or transcode) based on source compatibility and
     * device capabilities.
     *
     * @param string $mediaItemId Media item identifier
     * @param string $sessionId Client session identifier
     * @param string $userId Owner user identifier
     * @param array{
     *     device_profile?: string,
     *     transcode_job_id?: string,
     *     auto_transcode?: bool
     * } $options Streaming options including device profile name
     *
     * @return StreamState The created stream state
     *
     * @throws \InvalidArgumentException If media item not found
     *
     * @example
     * ```php
     * $state = $manager->createStream('item-123', $sessionId, $userId, [
     *     'device_profile' => 'mobile-high',
     * ]);
     * ```
     */
    public function createStream(
        string $mediaItemId,
        string $sessionId,
        string $userId,
        array $options = []
    ): StreamState {
        $item = $this->itemRepository->findById($mediaItemId);
        if (!$item) {
            throw new \InvalidArgumentException("Media item not found: {$mediaItemId}");
        }

        $state = new StreamState();
        $state->id = $this->generateUuid();
        $state->mediaItemId = $mediaItemId;
        $state->sessionId = $sessionId;
        $state->userId = $userId;
        $state->durationTicks = $item['metadata']['runtime_ticks'] ?? 0;

        $profileName = $options['device_profile'] ?? 'generic';

        $sourceInfo = $this->probeMedia($item['path']);

        $quality = $this->qualitySelector->selectQuality($sourceInfo, $profileName, $options);
        $state->playMethod = $quality['method'];

        if ($quality['method'] === 'direct') {
            $state->directStreamUrl = $this->buildDirectStreamUrl($mediaItemId);
        } else {
            $state->transcodeJobId = $options['transcode_job_id'] ?? null;
        }

        $this->activeStreams[$state->id] = $state;

        $this->persistStreamState($state);

        $this->logger->info('Stream created', [
            'stream_id' => $state->id,
            'media_item_id' => $mediaItemId,
            'method' => $quality['method'],
        ]);

        return $state;
    }

    /**
     * Gets a stream state by ID.
     *
     * @param string $streamId Stream identifier
     *
     * @return StreamState|null Stream state or null if not found
     */
    public function getStream(string $streamId): ?StreamState
    {
        return $this->activeStreams[$streamId] ?? null;
    }

    /**
     * Gets a stream state by session ID.
     *
     * @param string $sessionId Client session identifier
     *
     * @return StreamState|null Stream state or null if not found
     */
    public function getStreamBySession(string $sessionId): ?StreamState
    {
        foreach ($this->activeStreams as $stream) {
            if ($stream->sessionId === $sessionId) {
                return $stream;
            }
        }
        return null;
    }

    /**
     * Updates playback position.
     *
     * @param string $streamId Stream identifier
     * @param int $positionTicks New position in 100-nanosecond ticks
     */
    public function updatePosition(string $streamId, int $positionTicks): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->positionTicks = $positionTicks;

        $this->persistPlaybackState($stream);
    }

    /**
     * Starts/resumes playback.
     *
     * @param string $streamId Stream identifier
     */
    public function play(string $streamId): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->play();
        $this->logger->debug('Stream playing', ['stream_id' => $streamId]);
    }

    /**
     * Pauses playback.
     *
     * @param string $streamId Stream identifier
     */
    public function pause(string $streamId): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->pause();
        $this->logger->debug('Stream paused', ['stream_id' => $streamId]);
    }

    /**
     * Stops playback and removes the stream session.
     *
     * @param string $streamId Stream identifier
     */
    public function stop(string $streamId): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->stop();
        $this->persistPlaybackState($stream);
        unset($this->activeStreams[$streamId]);

        $this->logger->info('Stream stopped', ['stream_id' => $streamId]);
    }

    /**
     * Seeks to a position.
     *
     * @param string $streamId Stream identifier
     * @param int $positionTicks Target position in 100-nanosecond ticks
     */
    public function seek(string $streamId, int $positionTicks): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->seek($positionTicks);
        $this->logger->debug('Stream seeked', [
            'stream_id' => $streamId,
            'position_ticks' => $positionTicks,
        ]);
    }

    /**
     * Gets all active stream sessions.
     *
     * @return array<int, StreamState> Array of active stream states
     */
    public function getActiveStreams(): array
    {
        return array_values($this->activeStreams);
    }

    /**
     * Gets the count of active streams.
     *
     * @return int Number of currently active streams
     */
    public function getActiveStreamCount(): int
    {
        return count($this->activeStreams);
    }

    /**
     * Probes media file for technical information.
     *
     * In production, this would use FFprobe to extract actual stream details.
     * Currently returns simulated data for development.
     *
     * @param string $path Path to the media file
     *
     * @return array{
     *     streams: array<int, array{codec_type: string, codec: string, width?: int, height?: int, bitrate?: int, channels?: int}>,
     *     format: array{format_name: string}
     * } Media file stream information
     */
    private function probeMedia(string $path): array
    {
        return [
            'streams' => [
                ['codec_type' => 'video', 'codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 5000000],
                ['codec_type' => 'audio', 'codec' => 'aac', 'channels' => 2],
            ],
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
        ];
    }

    /**
     * Builds direct stream URL for a media item.
     *
     * @param string $mediaItemId Media item identifier
     *
     * @return string URL path for direct streaming
     */
    private function buildDirectStreamUrl(string $mediaItemId): string
    {
        return "/media/{$mediaItemId}/stream";
    }

    /**
     * Persists initial stream state to database.
     *
     * @param StreamState $state Stream state to persist
     */
    private function persistStreamState(StreamState $state): void
    {
        $this->db->query(
            "INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE position_ticks = VALUES(position_ticks), playback_status = VALUES(playback_status)",
            [$state->id, $state->sessionId, $state->mediaItemId, $state->positionTicks, $state->durationTicks, $state->status]
        );
    }

    /**
     * Persists playback state update to database.
     *
     * @param StreamState $state Stream state to persist
     */
    private function persistPlaybackState(StreamState $state): void
    {
        $this->db->query(
            "UPDATE playback_state SET position_ticks = ?, duration_ticks = ?, playback_status = ?, updated_at = NOW()
             WHERE id = ?",
            [$state->positionTicks, $state->durationTicks, $state->status, $state->id]
        );
    }

    /**
     * Generates a UUID v4 identifier.
     *
     * @return string UUID string
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

    /**
     * Gets the manifest URL for a streaming protocol.
     *
     * Returns the appropriate manifest URL based on the requested protocol
     * (HLS or DASH). The manifest URL is used by clients to begin streaming.
     *
     * @param string $jobId Transcode job identifier
     * @param string $protocol Streaming protocol ('hls' or 'dash')
     *
     * @return string The manifest URL for the specified protocol
     *
     * @throws \InvalidArgumentException If protocol is not supported
     *
     * @example
     * ```php
     * $hlsUrl = $manager->getManifestUrl('job-123', 'hls');
     * $dashUrl = $manager->getManifestUrl('job-123', 'dash');
     * ```
     */
    public function getManifestUrl(string $jobId, string $protocol): string
    {
        return match ($protocol) {
            'hls' => $this->hlsStreamer->getPlaylistUrl($jobId),
            'dash' => $this->dashStreamer !== null
                ? $this->dashStreamer->getMasterMpdUrl($jobId)
                : throw new \InvalidArgumentException('DASH streaming is not configured'),
            default => throw new \InvalidArgumentException("Unsupported streaming protocol: {$protocol}"),
        };
    }

    /**
     * Gets the HLS streamer instance.
     *
     * @return HlsStreamer The HLS streamer
     */
    public function getHlsStreamer(): HlsStreamer
    {
        return $this->hlsStreamer;
    }

    /**
     * Gets the DASH streamer instance.
     *
     * @return DashStreamer|null The DASH streamer or null if not configured
     */
    public function getDashStreamer(): ?DashStreamer
    {
        return $this->dashStreamer;
    }
}
