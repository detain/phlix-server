<?php

/**
 * Phlix media server component: Providers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Config\HwAccelConfig;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Media\Transcoding\TranscodeManager;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Registers the on-demand transcoding subsystem: the FFmpeg runner and the
 * {@see TranscodeManager} that owns HLS job lifecycle.
 *
 * The crucial wiring here is the segment directory: the TranscodeManager WRITES
 * HLS variants to the very same `config['hls']['segment_dir']` that
 * {@see \Phlix\Media\Streaming\HlsStreamer} and
 * {@see \Phlix\Server\Http\Controllers\HlsController} READ from. Without that
 * single source of truth the transcoder would produce segments nobody serves.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.23.0
 */
final class TranscodeServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the transcoding bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.23.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $ffmpegConfig = is_array($appConfig['ffmpeg'] ?? null) ? $appConfig['ffmpeg'] : [];
        $hlsConfig = is_array($appConfig['hls'] ?? null) ? $appConfig['hls'] : [];

        $ffmpegPath = is_string($ffmpegConfig['ffmpeg_path'] ?? null)
            ? $ffmpegConfig['ffmpeg_path'] : '/usr/bin/ffmpeg';
        $ffprobePath = is_string($ffmpegConfig['ffprobe_path'] ?? null)
            ? $ffmpegConfig['ffprobe_path'] : '/usr/bin/ffprobe';
        $transcodeDir = is_string($ffmpegConfig['transcode_dir'] ?? null)
            ? $ffmpegConfig['transcode_dir'] : sys_get_temp_dir() . '/phlix_transcodes';

        // Single source of truth for the HLS output dir — shared with HlsStreamer.
        $segmentDir = is_string($hlsConfig['segment_dir'] ?? null)
            ? $hlsConfig['segment_dir']
            : (is_string($ffmpegConfig['segment_dir'] ?? null)
                ? $ffmpegConfig['segment_dir']
                : sys_get_temp_dir() . '/phlix_hls');

        $segmentSeconds = is_int($hlsConfig['segment_seconds'] ?? null)
            ? $hlsConfig['segment_seconds'] : 6;

        // On-demand segment concurrency ceiling + cache budget. Null lets the
        // TranscodeManager apply its own safe defaults.
        $maxConcurrentSegments = is_int($hlsConfig['max_concurrent_segments'] ?? null)
            ? $hlsConfig['max_concurrent_segments'] : null;
        $cacheMaxBytes = is_int($hlsConfig['cache_max_bytes'] ?? null)
            ? $hlsConfig['cache_max_bytes'] : null;
        $cacheMaxAge = is_int($hlsConfig['cache_max_age'] ?? null)
            ? $hlsConfig['cache_max_age'] : null;

        $builder->addDefinitions([
            // SV-4.2: shared per-worker registry of detached segment-encode PIDs so
            // abandoned/timed-out on-demand encodes can be killed (see FfmpegRunner
            // + TranscodeManager). A singleton within each worker process.
            SegmentProcessRegistry::class => factory(
                static function (ContainerInterface $c): SegmentProcessRegistry {
                    /** @var \Psr\Log\LoggerInterface $logger */
                    $logger = $c->get('logger.media');
                    return new SegmentProcessRegistry($logger);
                }
            ),

            FfmpegRunner::class => factory(
                static function (ContainerInterface $c) use ($ffmpegPath, $ffprobePath, $transcodeDir): FfmpegRunner {
                    // Static cache: only instantiate once per worker process.
                    static $runner = null;

                    if ($runner !== null) {
                        return $runner;
                    }

                    /** @var \Psr\Log\LoggerInterface $logger */
                    $logger = $c->get('logger.media');

                    // Get the merged hwaccel config (single source of truth).
                    // This combines hwaccel_base.php settings (enabled, prefer_hardware,
                    // vendor_priority, etc.) with transcoding.php settings
                    // (tone_mapping_mode, preferred_accelerator, etc.).
                    $mergedConfig = \Phlix\Config\HwAccelConfig::get();

                    $runner = new FfmpegRunner($ffmpegPath, $ffprobePath, $transcodeDir, $logger);
                    $runner->setConfig($mergedConfig);

                    // SV-4.2: wire the segment-process registry so on-demand encode
                    // PIDs are tracked for cancellation / wait-timeout kill.
                    /** @var SegmentProcessRegistry $segmentRegistry */
                    $segmentRegistry = $c->get(SegmentProcessRegistry::class);
                    $runner->setSegmentProcessRegistry($segmentRegistry);

                    // Probe hardware acceleration once at container build time and cache
                    // the result on the runner. This avoids per-request probing (which
                    // uses shell_exec/Coroutine\System::exec).
                    // The probe respects vendor_priority from the merged config.
                    $runner->probeHardwareAcceleration();

                    return $runner;
                }
            ),

            TranscodeManager::class => factory(
                static function (
                    ContainerInterface $c
                ) use (
                    $segmentDir,
                    $segmentSeconds,
                    $maxConcurrentSegments,
                    $cacheMaxBytes,
                    $cacheMaxAge
                ): TranscodeManager {
                    /** @var \Psr\Log\LoggerInterface $logger */
                    $logger = $c->get('logger.media');
                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    /** @var FfmpegRunner $ffmpeg */
                    $ffmpeg = $c->get(FfmpegRunner::class);
                    $manager = new TranscodeManager(
                        $db,
                        $ffmpeg,
                        $segmentDir,
                        $logger,
                        $segmentSeconds,
                        null,
                        null,
                        null,
                        $maxConcurrentSegments,
                        $cacheMaxBytes,
                        $cacheMaxAge
                    );

                    // SV-4.2-disconnect (SS-2): make the shared per-worker
                    // segment-process registry waiter-aware. A cancel/disconnect
                    // kill must SKIP (defer) an encode a SECOND client is still
                    // piggybacked on (the shared-encode landmine). This is the SAME
                    // registry singleton already injected into FfmpegRunner above,
                    // so this one wiring covers every kill path (relay killGroup and
                    // any direct kill). Resolving TranscodeManager always precedes
                    // launching — hence registering — any encode, so the guard is
                    // set before a kill can ever find a key to reap. Shared provider
                    // → both entrypoints (public/index.php + start.php) get it.
                    /** @var SegmentProcessRegistry $registry */
                    $registry = $c->get(SegmentProcessRegistry::class);
                    $registry->setWaiterGuard(
                        static fn (string $key): bool => $manager->hasOtherWaiter($key)
                    );
                    // SV-4.2-disconnect F1: on a genuine (non-deferred) reap the
                    // registry must invalidate the manager's dedup reservation for
                    // the reaped segment, so the next requester re-launches instead
                    // of deduping onto the killed encode (a transient 404 otherwise).
                    // Same singleton, so this covers BOTH the relay killGroup path
                    // and the direct-LAN onClose path with one wiring; both video and
                    // audio segments flow through the same kill().
                    $registry->setReapCallback(
                        static function (string $key) use ($manager): void {
                            $manager->invalidateReservation($key);
                        }
                    );

                    return $manager;
                }
            ),
        ]);
    }
}
