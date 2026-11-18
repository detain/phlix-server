<?php

/**
 * Phlix media server component: Providers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\DegradedBuild;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\ProbeCache;
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

        // C6 (playback-lag): the creation-time head-start window. Resolved the
        // same shape as the sibling knobs — absent/non-int → null, letting the
        // TranscodeManager apply its shipped default (3). IMPORTANT: 0 is a
        // REAL value here (disable), not "absent", hence is_int and not ?: .
        $prewarmSegments = is_int($hlsConfig['prewarm_segments'] ?? null)
            ? $hlsConfig['prewarm_segments'] : null;

        // On-demand segment concurrency ceiling + cache budget. Null lets the
        // TranscodeManager apply its own safe defaults.
        $maxConcurrentSegments = is_int($hlsConfig['max_concurrent_segments'] ?? null)
            ? $hlsConfig['max_concurrent_segments'] : null;
        $cacheMaxBytes = is_int($hlsConfig['cache_max_bytes'] ?? null)
            ? $hlsConfig['cache_max_bytes'] : null;
        $cacheMaxAge = is_int($hlsConfig['cache_max_age'] ?? null)
            ? $hlsConfig['cache_max_age'] : null;

        // SV-1.9: ENOSPC guard threshold — minimum free bytes on the segment-cache
        // filesystem before an on-demand encode is attempted. Null lets the
        // TranscodeManager apply its own 500 MiB default, so an absent config key
        // changes nothing (the wiring below still passes it explicitly because the
        // constructor args are positional).
        $minDiskSpaceBytes = is_int($hlsConfig['min_disk_space_bytes'] ?? null)
            ? $hlsConfig['min_disk_space_bytes'] : null;

        // SV-3.3(1A): loudness-normalization target params. `config/ffmpeg.php`'s
        // `loudness` block is read HERE (this is the plumbing sub-step) and threaded
        // to the TranscodeManager only when explicitly enabled with a numeric
        // integrated-loudness (`I`) target; `LRA`/`TP` are folded in when numeric.
        // Disabled (the shipped default) or malformed config → null → zero behavior
        // change: nothing consumes the param until sub-step 1B seeds
        // $params['loudnorm'] at the encode param-assembly sites.
        // Ceiling on simultaneously-running transcode JOBS. `config/ffmpeg.php`'s
        // `max_concurrent_transcodes` was previously read by nobody: TranscodeManager's
        // ctor assigned a bare literal `4`, so both the config key and its admin-UI
        // setting were inert. The boot default is read here; the DB override is
        // layered on inside the factory below (getEffective() needs the container).
        $maxConcurrentTranscodes = is_int($ffmpegConfig['max_concurrent_transcodes'] ?? null)
            ? $ffmpegConfig['max_concurrent_transcodes']
            : null;

        // playback-lag wave C: the software libx264 encode thread cap. Pass the
        // config value through when it is a real int (env-aware at the config
        // layer; 0 = "disable the flag" is a REAL value, hence is_int and not ?:
        // — the same M-SERVER-34 rule the C6 knob above honors). Absent/malformed
        // → null → the runner's own env → default chain applies identically.
        $segmentEncodeThreads = is_int($ffmpegConfig['segment_encode_threads'] ?? null)
            ? $ffmpegConfig['segment_encode_threads']
            : null;

        // perf #5: the software decoder's input-side thread cap, same rule as the
        // encode cap above (is_int, so 0 — "auto" — is a real value).
        $segmentDecodeThreads = is_int($ffmpegConfig['segment_decode_threads'] ?? null)
            ? $ffmpegConfig['segment_decode_threads']
            : null;

        // perf #11: persisted, cross-worker ffprobe cache directory. OPT-IN: the config default is
        // empty, so the disk cache is off and the runner is memo-only (no disk writes, no directory
        // creation, no prune timer). A non-empty PHLIX_PROBE_CACHE_DIR enables it. A relative value is
        // refused by ProbeCache::createIfSecure() with one warning, and the runner stays memo-only.
        $probeCacheDir = is_string($ffmpegConfig['probe_cache_dir'] ?? null)
            && trim((string) $ffmpegConfig['probe_cache_dir']) !== ''
            ? (string) $ffmpegConfig['probe_cache_dir']
            : null;

        $loudnormParams = null;
        $loudnessConfig = is_array($ffmpegConfig['loudness'] ?? null) ? $ffmpegConfig['loudness'] : [];
        if (($loudnessConfig['enabled'] ?? false) === true && is_numeric($loudnessConfig['I'] ?? null)) {
            $loudnormParams = ['I' => (float) $loudnessConfig['I']];
            if (is_numeric($loudnessConfig['LRA'] ?? null)) {
                $loudnormParams['LRA'] = (float) $loudnessConfig['LRA'];
            }
            if (is_numeric($loudnessConfig['TP'] ?? null)) {
                $loudnormParams['TP'] = (float) $loudnessConfig['TP'];
            }
        }

        $builder->addDefinitions([
            // SV-4.2: shared per-worker registry of detached segment-encode PIDs so
            // abandoned/timed-out on-demand encodes can be killed (see FfmpegRunner
            // + TranscodeManager). A singleton within each worker process.
            SegmentProcessRegistry::class => factory(
                static function (ContainerInterface $c): SegmentProcessRegistry {
                    /** @var \Psr\Log\LoggerInterface $logger */
                    $logger = $c->get('logger.media');
                    $registry = new SegmentProcessRegistry($logger);
                    // Stall and wall-clock watchdog bounds (config/ffmpeg.php). A missing
                    // or non-numeric value passes 0.0, and the registry keeps its default.
                    $ffmpegCfg = \Phlix\Config\EffectiveConfig::file('ffmpeg');
                    $stallSeconds = $ffmpegCfg['segment_stall_seconds'] ?? null;
                    $maxWallSeconds = $ffmpegCfg['segment_max_wall_seconds'] ?? null;
                    $registry->configureWatchdog(
                        is_numeric($stallSeconds) ? (float) $stallSeconds : 0.0,
                        is_numeric($maxWallSeconds) ? (float) $maxWallSeconds : 0.0,
                    );
                    // Bound on holding an unverifiable encode's temp and cap slot after TERM
                    // (see SegmentProcessRegistry::sweep()). The wrapper timeout is the bound.
                    $transcodeTimeout = $ffmpegCfg['transcode_timeout'] ?? null;
                    $registry->configureUnverifiedHold(
                        is_numeric($transcodeTimeout) ? (float) $transcodeTimeout : 0.0,
                    );

                    return $registry;
                }
            ),

            FfmpegRunner::class => factory(
                static function (ContainerInterface $c) use (
                    $ffmpegPath,
                    $ffprobePath,
                    $transcodeDir,
                    $segmentEncodeThreads,
                    $segmentDecodeThreads,
                    $probeCacheDir
                ): FfmpegRunner {
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
                    // playback-lag wave C: software-encode thread cap (setter per
                    // this constructor's documented "signature unchanged" law).
                    $runner->setSegmentEncodeThreads($segmentEncodeThreads);
                    $runner->setSegmentDecodeThreads($segmentDecodeThreads);
                    // The directory trust check (owner, no symlink, no group/other write)
                    // runs once here, at build, not per probe. A refused directory yields
                    // null, which leaves the runner memo-only, and logs one warning.
                    $probeCache = $probeCacheDir !== null
                        ? ProbeCache::createIfSecure($probeCacheDir, ProbeCache::MAX_ENTRIES, $logger)
                        : null;
                    // Pruning runs on a Workerman timer, never on a probe request.
                    $probeCache?->startPruneTimer();
                    $runner->setProbeCache($probeCache);

                    // SV-4.2: wire the segment-process registry so on-demand encode
                    // PIDs are tracked for cancellation / wait-timeout kill.
                    /** @var SegmentProcessRegistry $segmentRegistry */
                    $segmentRegistry = $c->get(SegmentProcessRegistry::class);
                    $runner->setSegmentProcessRegistry($segmentRegistry);

                    // Probe hardware acceleration once at container build time and cache
                    // the result on the runner. This avoids per-request probing (which
                    // uses shell_exec/Coroutine\System::exec).
                    //
                    // NB: this does NOT respect vendor_priority from the merged config,
                    // despite what this comment used to claim. probeHardwareAcceleration()
                    // resolves the registry via FfmpegRunner:1354
                    // (`$registry ?? HwaccelRegistry::getInstance()`), and getInstance()
                    // (HwaccelRegistry.php:49-52) calls a PRIVATE constructor with no
                    // arguments — so the registry always carries the ctor defaults at
                    // HwaccelRegistry.php:78-92, never config/hwaccel_base.php. The same
                    // is true of probe_timeout, test_clip_path and fallback_to_software.
                    // Only `enabled` and `prefer_hardware` reach a consumer, via the
                    // setConfig() above. See docs/developers/hardware-acceleration.md.
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
                    $prewarmSegments,
                    $maxConcurrentSegments,
                    $cacheMaxBytes,
                    $cacheMaxAge,
                    $minDiskSpaceBytes,
                    $loudnormParams,
                    $maxConcurrentTranscodes
                ): TranscodeManager {
                    /** @var \Psr\Log\LoggerInterface $logger */
                    $logger = $c->get('logger.media');
                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    /** @var FfmpegRunner $ffmpeg */
                    $ffmpeg = $c->get(FfmpegRunner::class);

                    // No settings-store override is read here. The job-count ceiling is
                    // INERT (see TranscodeManager::$maxConcurrentTranscodes): the value
                    // only reaches the `configured_job_ceiling_inert` log line, so an
                    // admin-saved `ffmpeg.max_concurrent_transcodes` changes nothing and
                    // the read that fed it was dead. The real ceiling is
                    // `server.hls.max_concurrent_segments`.
                    //
                    // The constructor args are POSITIONAL, so threading the SV-1.9
                    // ENOSPC threshold (position 13) requires passing position 12
                    // ($segmentMaxWaitMs) too. It has never been surfaced in config,
                    // so pass null explicitly to preserve its existing default
                    // (self::SEGMENT_MAX_WAIT_MS) verbatim — no behavior change.
                    // SV-3.3(1A): $loudnormParams (position 14) is appended last;
                    // null by default (loudness disabled) → inert.
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
                        $cacheMaxAge,
                        null,
                        $minDiskSpaceBytes,
                        $loudnormParams,
                        $maxConcurrentTranscodes,
                        // Position 16: the software-encode tunables
                        // (`transcoding.preset` / `crf_h264` / `audio_bitrate`).
                        // Unlike the inert job-count value above, this is NOT
                        // resolved to a value here — EncodeSettings holds the
                        // store and reads it per param-assembly, so a change
                        // applies to the next transcode with no restart
                        // (read-path class (a) LIVE). Resolving it here instead
                        // would freeze the value into this once-per-worker
                        // factory result and make the keys restart-only.
                        new EncodeSettings(self::optionalSettings($c)),
                        // Position 17 (C6): the head-start window from
                        // `config['hls']['prewarm_segments']` / env
                        // PHLIX_SEGMENT_PREWARM_SEGMENTS; null → the manager's
                        // own shipped default, 0 → disabled (see resolution above).
                        $prewarmSegments
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
                    // The stall / wall-clock watchdog sweeps the same registry, so the
                    // manager needs it to free a stalled encode's slot after its poll gives up.
                    $manager->setSegmentProcessRegistry($registry);
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

    /**
     * Resolve {@see SettingsRepository} from the container, or NULL when it is
     * unavailable.
     *
     * Wrapped because {@see EncodeSettings} must never be the reason a
     * transcode fails: a container without a settings binding (or one whose
     * database is down) degrades to the shipped encode defaults rather than
     * throwing out of the TranscodeManager factory.
     *
     * The two failures are NOT the same and are treated differently:
     *
     *   - **Not defined at all** (`NotFoundExceptionInterface`) — a normal,
     *     expected shape; several unit containers register no settings store.
     *     Stays quiet.
     *   - **Defined but unbuildable** — a genuine degradation: encoding then
     *     silently runs on shipped defaults for the worker's whole lifetime.
     *     Logged.
     *
     * `$c->has()` cannot make this distinction: with autowiring enabled it
     * returns TRUE for any instantiable class. The exception type is the only
     * reliable signal.
     */
    private static function optionalSettings(ContainerInterface $c): ?SettingsRepository
    {
        try {
            $settings = $c->get(SettingsRepository::class);

            return $settings instanceof SettingsRepository ? $settings : null;
        } catch (\Throwable $e) {
            DegradedBuild::warnUnlessAbsent(
                $c,
                LogChannels::MEDIA,
                'The settings store is bound but could not be built; encode settings fall back '
                . 'to their shipped defaults. Admin-saved encode values stay ignored by this '
                . 'worker until it is recycled.',
                $e
            );

            return null;
        }
    }
}
