<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Relay;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Hub\RelayConsumer;
use Phlix\LiveTv\LiveTvManager;
use Phlix\Media\Streaming\HlsStreamer;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * Factory for building HlsRelayManager instances from configuration.
 *
 * Wires together all dependencies including LiveTvManager, HlsStreamer,
 * RelayConsumer, and HlsSegmentPrefetcher with proper configuration.
 *
 * @since 0.12.0
 */
final class HlsRelaySessionFactory
{
    /**
     * Build a fully-wired HlsRelayManager from configuration.
     *
     * @param LiveTvManager       $liveTvManager    Live TV manager for tuner access.
     * @param HlsStreamer         $hlsStreamer      HLS streamer for variant playlists.
     * @param RelayConsumer       $relayConsumer    Hub relay consumer for mount registration.
     * @param Connection          $db               Database connection.
     * @param array<string, mixed> $relayConfig      The relay section from livetv.php config.
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger.
     *
     * @return HlsRelayManager Configured manager instance.
     *
     * @since 0.12.0
     */
    public static function build(
        LiveTvManager $liveTvManager,
        HlsStreamer $hlsStreamer,
        RelayConsumer $relayConsumer,
        Connection $db,
        array $relayConfig,
        StructuredLogger|LoggerInterface|null $logger = null,
    ): HlsRelayManager {
        /** @var StructuredLogger $relayLogger */
        $relayLogger = $logger instanceof StructuredLogger ? $logger : LoggerFactory::get('livetv');

        $enabled = (bool) ($relayConfig['enabled'] ?? true);
        if (!$enabled) {
            throw new \RuntimeException('HLS relay is not enabled in configuration');
        }

        $prefetchSegments = self::toInt($relayConfig['prefetch_segments'] ?? 3);
        $maxConcurrentSessions = self::toInt($relayConfig['max_concurrent_sessions'] ?? 10);
        $segmentCacheTtlSeconds = self::toInt($relayConfig['segment_cache_ttl_seconds'] ?? 30);
        $relayPathPrefix = self::toString($relayConfig['relay_path_prefix'] ?? '/relay/live');

        // Build segment prefetcher with config; StructuredLogger
        // implements LoggerInterface so it is passed directly.
        $segmentPrefetcher = new HlsSegmentPrefetcher(
            $relayLogger,
            $prefetchSegments,
            10 * 1024 * 1024, // 10 MB max cache size
            $segmentCacheTtlSeconds,
        );

        // Build and return the manager
        return new HlsRelayManager(
            $liveTvManager,
            $hlsStreamer,
            $relayConsumer,
            $db,
            $segmentPrefetcher,
            $relayLogger,
            $relayPathPrefix,
            $maxConcurrentSessions,
        );
    }

    /**
     * Safely convert a value to string.
     *
     * @param mixed $value Value to convert.
     *
     * @return string Resulting string.
     *
     * @since 0.12.0
     */
    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Safely convert a value to int.
     *
     * @param mixed $value Value to convert.
     *
     * @return int Resulting int.
     *
     * @since 0.12.0
     */
    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return 0;
    }
}
