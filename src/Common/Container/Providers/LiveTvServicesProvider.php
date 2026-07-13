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
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\ChannelManager;
use Phlix\LiveTv\ComskipEdlParser;
use Phlix\LiveTv\ComskipRunner;
use Phlix\LiveTv\GuideManager;
use Phlix\LiveTv\LiveTvManager;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\Recording\ComskipIntegration;
use Phlix\LiveTv\Recording\ComskipLifecycleManager;
use Phlix\LiveTv\Recording\RecordingMediaRegistrar;
use Phlix\LiveTv\Recording\RecordingScheduler;
use Phlix\Media\Library\ItemRepository;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunApiClient;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunDiscovery;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunTunerDriver;
use Phlix\LiveTv\Tuners\TunerDriverInterface;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;
use function DI\get;

/**
 * Registers the Live-TV / DVR service stack as per-worker singletons.
 *
 * Before SV-3.1b0 no production code constructed a wired DVR stack: the
 * only {@see Recorder} built at runtime ({@see \Phlix\Server\Core\Application::getLiveTvStreamController()})
 * was created without an ffmpeg path, comskip manager or {@see LiveTvManager},
 * so its {@see Recorder::resolveTunerStreamUrl()} always returned null and the
 * capture pipeline was dead. This provider wires the whole stack once per
 * worker (PHP-DI caches every entry as a singleton):
 *
 *  - {@see ChannelManager}, {@see GuideManager} — DB-backed, LiveTV log channel.
 *  - {@see TunerDriverInterface} — the primary tuner driver (HDHomeRun, the
 *    default-enabled driver and the one {@see LiveTvManager} treats as primary
 *    in device discovery).
 *  - {@see ComskipLifecycleManager} — commercial-detection lifecycle, built from
 *    the `livetv.comskip` config and registered as a {@see Recorder} onComplete
 *    hook by the Recorder constructor.
 *  - {@see Recorder} — FULLY wired ($db, storage_path, max_storage_bytes, logger,
 *    comskip lifecycle manager, ffmpeg path). Built WITHOUT a LiveTvManager to
 *    break the Recorder↔LiveTvManager cycle; the LiveTvManager factory injects
 *    itself back via {@see Recorder::setLiveTvManager()} so
 *    {@see Recorder::resolveTunerStreamUrl()} becomes reachable.
 *  - {@see LiveTvManager} — the primary DVR/tuner facade; resolving it links the
 *    shared Recorder singleton to itself.
 *  - {@see RecordingScheduler} — start/stop-at-boundary scheduler (its Timers are
 *    a separate sub-step, SV-3.1c; this only wires the object).
 *
 * Dual-entrypoint: this provider lives in the shared container
 * ({@see \Phlix\Common\Container\ContainerFactory}) so both `public/index.php`
 * (CGI) and `start.php` (Workerman daemon) resolve the same stack. Boot-time
 * recovery ({@see LiveTvManager::bootstrap()}) is triggered ONLY from the
 * long-running `start.php` master (SV-3.1e), never from the single-shot CGI path.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since SV-3.1b0
 */
final class LiveTvServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the Live-TV / DVR bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since SV-3.1b0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $builder->addDefinitions([
            ChannelManager::class => factory(static function (ContainerInterface $c): ChannelManager {
                return new ChannelManager(
                    self::db($c),
                    self::livetvLogger($c),
                );
            }),

            GuideManager::class => factory(static function (ContainerInterface $c): GuideManager {
                return new GuideManager(
                    self::db($c),
                    self::livetvLogger($c),
                );
            }),

            // Primary tuner driver. HDHomeRun is the default-enabled driver and
            // the one LiveTvManager special-cases as the primary during device
            // discovery. The shared HdHomeRunApiClient starts with an empty base
            // URL; the driver rebinds a per-device URL when a device is
            // discovered / streamed. (Additional drivers — IPTV/DVB-T — are wired
            // in a follow-up sub-step.)
            TunerDriverInterface::class => factory(static function (ContainerInterface $c): TunerDriverInterface {
                $livetv = self::livetvConfig($c);
                /** @var array<string, mixed> $hdhr */
                $hdhr = is_array($livetv['hdhomerun'] ?? null) ? $livetv['hdhomerun'] : [];
                $ssdpTimeoutRaw = $hdhr['ssdp_timeout_secs'] ?? 5;
                $ssdpTimeout = is_int($ssdpTimeoutRaw) ? $ssdpTimeoutRaw
                    : (is_numeric($ssdpTimeoutRaw) ? (int) $ssdpTimeoutRaw : 5);

                $logger = self::livetvLogger($c);
                $discovery = new HdHomeRunDiscovery($logger, $ssdpTimeout);
                $apiClient = new HdHomeRunApiClient('', $logger);

                return new HdHomeRunTunerDriver($discovery, $apiClient, $logger);
            }),

            ComskipLifecycleManager::class => factory(static function (ContainerInterface $c): ComskipLifecycleManager {
                $livetv = self::livetvConfig($c);
                /** @var array<string, mixed> $comskip */
                $comskip = is_array($livetv['comskip'] ?? null) ? $livetv['comskip'] : [];

                $binaryPathRaw = $comskip['binary_path'] ?? '/usr/bin/comskip';
                $binaryPath = is_string($binaryPathRaw) ? $binaryPathRaw : '/usr/bin/comskip';
                $queueRaw = $comskip['queue_processing'] ?? true;
                $queueEnabled = is_bool($queueRaw) ? $queueRaw : (bool) $queueRaw;
                $maxConcurrentRaw = $comskip['max_concurrent'] ?? 2;
                $maxConcurrent = is_int($maxConcurrentRaw) ? $maxConcurrentRaw
                    : (is_numeric($maxConcurrentRaw) ? (int) $maxConcurrentRaw : 2);

                $logger = self::livetvLogger($c);
                $db = self::db($c);

                $integration = new ComskipIntegration(
                    new ComskipRunner($binaryPath, $logger),
                    new ComskipEdlParser(),
                    $db,
                    $logger,
                );

                return new ComskipLifecycleManager(
                    $integration,
                    $db,
                    $logger,
                    $queueEnabled,
                    $maxConcurrent > 0 ? $maxConcurrent : 2,
                );
            }),

            // SV-3.1d: registers a completed recording's .ts as a playable
            // media_items row + persists the media_item_id linkage. Wired below
            // as a Recorder onComplete hook. (Comskip chapter-marker attachment
            // to the real media item is the SEPARATE, deferred SV-3.1d-comskip
            // sub-step, gated on SV-4.3 — NOT wired here.)
            RecordingMediaRegistrar::class => factory(static function (ContainerInterface $c): RecordingMediaRegistrar {
                $livetv = self::livetvConfig($c);
                /** @var array<string, mixed> $dvr */
                $dvr = is_array($livetv['dvr'] ?? null) ? $livetv['dvr'] : [];
                $nameRaw = $dvr['library_name'] ?? 'DVR Recordings';
                $libraryName = is_string($nameRaw) && $nameRaw !== '' ? $nameRaw : 'DVR Recordings';

                /** @var ItemRepository $items */
                $items = $c->get(ItemRepository::class);

                return new RecordingMediaRegistrar(
                    self::db($c),
                    $items,
                    $libraryName,
                    self::livetvLogger($c),
                );
            }),

            // Fully-wired Recorder. Built WITHOUT a LiveTvManager here to break
            // the Recorder↔LiveTvManager cycle — the LiveTvManager factory below
            // calls Recorder::setLiveTvManager() on this same singleton so
            // resolveTunerStreamUrl() becomes reachable in every real usage path
            // (all of which resolve LiveTvManager first).
            Recorder::class => factory(static function (ContainerInterface $c): Recorder {
                $livetv = self::livetvConfig($c);
                [$storagePath, $maxStorageBytes] = self::dvrStorage($livetv);

                /** @var ComskipLifecycleManager $comskip */
                $comskip = $c->get(ComskipLifecycleManager::class);

                $recorder = new Recorder(
                    self::db($c),
                    $storagePath,
                    $maxStorageBytes,
                    self::livetvLogger($c),
                    $comskip,
                    self::ffmpegPath($c, $livetv),
                    null,
                );

                // SV-3.1d: register the completed .ts as a media_items row on the
                // once-only onComplete path (Recorder fires onComplete exactly
                // once per completion via its atomic CAS, SV-3.1c). The registrar
                // itself guards status/file so a FAILED or empty capture is never
                // registered.
                /** @var RecordingMediaRegistrar $registrar */
                $registrar = $c->get(RecordingMediaRegistrar::class);
                $recorder->onComplete([$registrar, 'register']);

                return $recorder;
            }),

            LiveTvManager::class => factory(static function (ContainerInterface $c): LiveTvManager {
                /** @var Recorder $recorder */
                $recorder = $c->get(Recorder::class);
                /** @var ChannelManager $channelManager */
                $channelManager = $c->get(ChannelManager::class);
                /** @var GuideManager $guideManager */
                $guideManager = $c->get(GuideManager::class);
                /** @var TunerDriverInterface $tunerDriver */
                $tunerDriver = $c->get(TunerDriverInterface::class);

                $manager = new LiveTvManager(
                    self::db($c),
                    $channelManager,
                    $guideManager,
                    $recorder,
                    $tunerDriver,
                    self::livetvLogger($c),
                );

                // Close the cycle: the shared Recorder singleton can now resolve
                // tuner stream URLs through this manager.
                $recorder->setLiveTvManager($manager);

                return $manager;
            }),

            RecordingScheduler::class => factory(static function (ContainerInterface $c): RecordingScheduler {
                // Resolve the manager first so the shared Recorder is linked
                // before the scheduler receives it.
                /** @var LiveTvManager $manager */
                $manager = $c->get(LiveTvManager::class);
                /** @var Recorder $recorder */
                $recorder = $c->get(Recorder::class);

                return new RecordingScheduler(
                    self::db($c),
                    $recorder,
                    $manager,
                    self::livetvLogger($c),
                );
            }),
        ]);
    }

    /**
     * The Live-TV log-channel logger (shared alias registered by CoreServicesProvider).
     *
     * @param ContainerInterface $c
     *
     * @return StructuredLogger
     *
     * @since SV-3.1b0
     */
    private static function livetvLogger(ContainerInterface $c): StructuredLogger
    {
        /** @var StructuredLogger $logger */
        $logger = $c->get('logger.livetv');
        return $logger;
    }

    /**
     * The shared MySQL connection for this worker.
     *
     * @param ContainerInterface $c
     *
     * @return Connection
     *
     * @since SV-3.1b0
     */
    private static function db(ContainerInterface $c): Connection
    {
        /** @var Connection $db */
        $db = $c->get(Connection::class);
        return $db;
    }

    /**
     * Resolve the `livetv` config sub-array from the shared app config.
     *
     * @param ContainerInterface $c
     *
     * @return array<string, mixed>
     *
     * @since SV-3.1b0
     */
    private static function livetvConfig(ContainerInterface $c): array
    {
        /** @var mixed $appConfig */
        $appConfig = $c->get('app.config');
        if (!is_array($appConfig)) {
            return [];
        }
        /** @var mixed $livetv */
        $livetv = $appConfig['livetv'] ?? null;
        return is_array($livetv) ? $livetv : [];
    }

    /**
     * Resolve the DVR storage path + max-storage-bytes from livetv config.
     *
     * Prefers the `dvr.*` block, falling back to the top-level keys, then the
     * historic Recorder defaults.
     *
     * @param array<string, mixed> $livetv
     *
     * @return array{0:string,1:int} [$storagePath, $maxStorageBytes]
     *
     * @since SV-3.1b0
     */
    private static function dvrStorage(array $livetv): array
    {
        /** @var array<string, mixed> $dvr */
        $dvr = is_array($livetv['dvr'] ?? null) ? $livetv['dvr'] : [];

        $storageRaw = $dvr['storage_path'] ?? ($livetv['storage_path'] ?? '/var/recordings');
        $storagePath = is_string($storageRaw) && $storageRaw !== '' ? $storageRaw : '/var/recordings';

        $maxRaw = $dvr['max_storage_bytes'] ?? ($livetv['max_storage_bytes'] ?? 0);
        $maxStorageBytes = is_int($maxRaw) ? $maxRaw : (is_numeric($maxRaw) ? (int) $maxRaw : 0);

        return [$storagePath, $maxStorageBytes];
    }

    /**
     * Resolve the ffmpeg binary path used for recording spawns.
     *
     * Prefers the global `ffmpeg.ffmpeg_path` (the same binary the transcoder
     * uses), then the DVB-T tuner's ffmpeg path, then the historic default.
     *
     * @param ContainerInterface   $c
     * @param array<string, mixed> $livetv
     *
     * @return string
     *
     * @since SV-3.1b0
     */
    private static function ffmpegPath(ContainerInterface $c, array $livetv): string
    {
        /** @var mixed $appConfig */
        $appConfig = $c->get('app.config');
        if (is_array($appConfig) && is_array($appConfig['ffmpeg'] ?? null)) {
            $path = $appConfig['ffmpeg']['ffmpeg_path'] ?? null;
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        /** @var array<string, mixed> $dvbt */
        $dvbt = is_array($livetv['dvbt'] ?? null) ? $livetv['dvbt'] : [];
        $dvbtPath = $dvbt['ffmpeg_path'] ?? null;
        if (is_string($dvbtPath) && $dvbtPath !== '') {
            return $dvbtPath;
        }

        return '/usr/bin/ffmpeg';
    }
}
