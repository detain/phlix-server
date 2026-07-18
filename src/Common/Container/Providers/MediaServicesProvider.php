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
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Media\Library\BookProgressStore;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\LibraryScanWorker;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\ChapterSearchService;
use Phlix\Media\CollectionService;
use Phlix\Media\Markers\Detection\BackgroundDetectorWorker;
use Phlix\Media\Markers\Detection\IntroDetectionJob;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateStore;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintFactory;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintInterface;
use Phlix\Media\Markers\Fingerprinting\FingerprintRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Phlix\Media\Metadata\FuzzyMatcher;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\Rating;
use Phlix\Media\Metadata\RatingService;
use Phlix\Media\Metadata\Resolution\LibraryPriorityResolver;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\ThemeMusic\StreamThemeMusicFetcher;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicFetcherInterface;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicResolver;
use Phlix\Media\Metadata\TitleSuffixStripper;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\MediaAsset\MediaAssetWorker;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\SimilarityJobStore;
use Phlix\Media\SimilarityWorker;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Theming\ThemeMediaFinder;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Playlists\SmartPlaylistController;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRefreshHandler;
use Phlix\Playlists\SmartPlaylistRepository;
use Phlix\Stats\StatsCollector;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Registers the media subsystem: library scanning, repositories, the
 * metadata manager and the HLS streamer.
 *
 * The HlsStreamer needs a segments directory and a base URL that are
 * not class-level concerns, so they come from $appConfig['hls'] with
 * sensible defaults aligned to public/index.php's current behaviour.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.10.0
 */
final class MediaServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the media bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $hlsConfig = is_array($appConfig['hls'] ?? null) ? $appConfig['hls'] : [];
        $segmentDirRaw = $hlsConfig['segment_dir'] ?? null;
        $segmentDir = is_string($segmentDirRaw) ? $segmentDirRaw : sys_get_temp_dir() . '/phlix_hls';
        $baseUrlRaw = $hlsConfig['base_url'] ?? null;
        $baseUrl = is_string($baseUrlRaw) ? $baseUrlRaw : 'http://localhost:8096';

        // S8: bounded fan-out cap for MediaScanner::scanFlat()'s concurrent
        // ffprobe pool. Null lets MediaScanner apply its own safe default
        // (mirrors TranscodeServicesProvider's max_concurrent_segments style).
        $ffmpegConfigForScan = is_array($appConfig['ffmpeg'] ?? null) ? $appConfig['ffmpeg'] : [];
        $maxConcurrentScanProbesRaw = $ffmpegConfigForScan['max_concurrent_scan_probes'] ?? null;
        $maxConcurrentScanProbes = is_int($maxConcurrentScanProbesRaw) ? $maxConcurrentScanProbesRaw : null;

        // TMDB API key — prefer $appConfig['tmdb']['api_key'] (loaded by the
        // bootstrap from config/tmdb.php when available), otherwise fall back
        // to the TMDB_API_KEY environment variable. An empty key is harmless:
        // TrailerResolver consults the local extras cache before any HTTP
        // call, so the trailers endpoints stay live even without a key.
        $tmdbConfig = is_array($appConfig['tmdb'] ?? null) ? $appConfig['tmdb'] : [];
        $tmdbApiKeyRaw = $tmdbConfig['api_key'] ?? null;
        $tmdbApiKey = is_string($tmdbApiKeyRaw) && $tmdbApiKeyRaw !== ''
            ? $tmdbApiKeyRaw
            : ((string)(getenv('TMDB_API_KEY') ?: ''));

        // Theme-music (M3) config. Prefer $appConfig['theme_music'] (config/server.php
        // requires config/theme_music.php into it); fall back to a direct include so
        // an entry point that doesn't compose the full server.php still gets the real
        // config. Coerced/defaulted by ThemeMusicConfig::fromArray().
        $themeMusicRaw = $appConfig['theme_music'] ?? null;
        if (!is_array($themeMusicRaw)) {
            /** @var mixed $included */
            $included = @include __DIR__ . '/../../../../config/theme_music.php';
            $themeMusicRaw = is_array($included) ? $included : [];
        }
        /** @var array<string, mixed> $themeMusicConfigArray */
        $themeMusicConfigArray = [];
        /** @var mixed $tmValue */
        foreach ($themeMusicRaw as $tmKey => $tmValue) {
            if (is_string($tmKey)) {
                $themeMusicConfigArray[$tmKey] = $tmValue;
            }
        }

        $builder->addDefinitions([
            // Effective trailing-edition noise-suffix list, resolved ONCE when
            // first built (per worker cycle, not per request): the admin-managed
            // `matching.noise_suffixes` override (server_settings) is read via
            // SettingsRepository::getEffective(), which already merges the
            // override OVER the config/matching.php default. An empty/blank
            // result (no override AND no readable config, or an admin who cleared
            // the field) falls back to the built-in
            // TitleSuffixStripper::NOISE_SUFFIXES const — it never blanks the
            // list. Injected into the matching services below so the same list
            // drives both scan-time and re-match-time title cleaning. No mutable
            // static/global state — the value is computed once at construction.
            'matching.noise_suffixes' => factory(
                static function (ContainerInterface $c): array {
                    try {
                        $settings = $c->get(SettingsRepository::class);
                        if ($settings instanceof SettingsRepository) {
                            $effective = self::stringList($settings->getEffective('matching.noise_suffixes'));
                            if ($effective !== []) {
                                return $effective;
                            }
                        }
                    } catch (\Throwable) {
                        // Settings store unavailable — use the in-code default.
                    }

                    // Defensive fallback: config/matching.php unreadable AND no
                    // override. Mirror the canonical in-code default so matching
                    // still peels the standard noise phrases. NOISE_SUFFIXES is
                    // already a list<string>.
                    return TitleSuffixStripper::NOISE_SUFFIXES;
                }
            ),

            // Effective metadata source-priority config (Feature 3), resolved
            // ONCE when first built (per worker cycle, not per request). The
            // admin-managed `metadata.provider_priority` override (an object of
            // media-type => ordered source list) and `metadata.genres_mode`
            // string are read via SettingsRepository::getEffective(), which
            // already returns the override when present, else the
            // config/metadata.php default. The provider_priority override is
            // merged per-type OVER the config default (REPLACE-not-deep-merge,
            // mirroring noise_suffixes): a type the override names replaces that
            // type's default list; a type absent from the override keeps its
            // default; an empty/absent override leaves all defaults intact. The
            // constructed PriorityConfig is shared (immutable accessor, no
            // mutable static/global state). Step 3.4 consumes it in the live
            // resolvers; 3.3b only constructs + wires it.
            PriorityConfig::class => factory(
                static function (ContainerInterface $c): PriorityConfig {
                    // Config-file defaults (config/metadata.php) AND the admin
                    // override are read through SettingsRepository: getDefault()
                    // loads the config default for the per-type base map, and
                    // getOverride() returns the stored override map (if any) so
                    // it can be merged per-type OVER the default. genres_mode is
                    // read via getEffective() (override-or-default wholesale —
                    // it is a scalar, no per-type merge needed).
                    $merged = [];
                    $genresMode = PriorityConfig::DEFAULT_GENRES_MODE;

                    try {
                        $settings = $c->get(SettingsRepository::class);
                        if ($settings instanceof SettingsRepository) {
                            $merged = self::priorityMap($settings->getDefault('metadata.provider_priority'));

                            $overrideRow = $settings->getOverride('metadata.provider_priority');
                            if (is_array($overrideRow)) {
                                $override = self::priorityMap($overrideRow['value'] ?? null);
                                // REPLACE-not-deep-merge per type: a type the
                                // override names replaces that type's default
                                // list outright; a type absent from the override
                                // keeps its default; an empty override leaves all
                                // defaults intact.
                                foreach ($override as $type => $order) {
                                    $merged[$type] = $order;
                                }
                            }

                            $modeOverride = $settings->getEffective('metadata.genres_mode');
                            if (is_string($modeOverride) && $modeOverride !== '') {
                                $genresMode = $modeOverride;
                            }
                        }
                    } catch (\Throwable) {
                        // Settings store unavailable — use the in-code defaults
                        // (PriorityConfig::orderFor() still falls back to the
                        // canonical [tmdb, imdb] baseline for any type).
                    }

                    return new PriorityConfig($merged, $genresMode);
                }
            ),

            // Per-library provider-priority resolver (item 5): layers a library's
            // `options.metadata_priority` override OVER the shared global
            // PriorityConfig (the fallback base above stays intact) with the SAME
            // per-type REPLACE-merge. Its only dep is that global PriorityConfig,
            // so a plain autowire binds the single shared instance.
            LibraryPriorityResolver::class => autowire()
                ->constructorParameter('globalPriority', get(PriorityConfig::class)),

            // `statsCollector` is named explicitly because PHP-DI skips optional
            // ctor params with defaults during autowiring; without it item
            // add/remove changes never reach stats_library_changes (the admin
            // dashboard activity feed).
            ItemRepository::class => autowire()
                ->constructorParameter('statsCollector', get(StatsCollector::class)),

            // Per-user favorites + ratings (E10). The repository takes only a
            // Workerman MySQL Connection; the controller takes ItemRepository +
            // the repository — both autowirable. Referenced by WebPortalRouter
            // (the single dispatch point for /api/v1/media/* on both entry points).
            \Phlix\Media\UserItemDataRepository::class => autowire(),
            \Phlix\Server\Http\Controllers\MediaUserDataController::class => autowire(),

            // Book reading progress tracking (SV-3.2). Autowires with
            // Workerman MySQL Connection (globally registered in CoreServicesProvider).
            BookProgressStore::class => autowire(),

            TmdbProvider::class => factory(static function (ContainerInterface $c) use ($tmdbApiKey): TmdbProvider {
                // Prefer the admin-managed server setting (set via the admin
                // UI's Settings → Metadata page, persisted in server_settings)
                // and fall back to config/tmdb.php / the TMDB_API_KEY env var.
                // getEffective() already returns the override when present,
                // else the config/env default, so an admin-saved key wins.
                // Resolved when the provider is first built, so a saved key
                // applies on the next worker cycle without a redeploy.
                $key = $tmdbApiKey;
                try {
                    $settings = $c->get(SettingsRepository::class);
                    if ($settings instanceof SettingsRepository) {
                        $stored = $settings->getEffective('tmdb.api_key');
                        if (is_string($stored) && $stored !== '') {
                            $key = $stored;
                        }
                    }
                } catch (\Throwable) {
                    // Settings store not available — keep the config/env key.
                }
                return new TmdbProvider($key);
            }),

            FolderWatcher::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class)),

            MediaScanner::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class))
                // Probe each time-based file's total duration during the scan so
                // the player's scrubber knows the full length immediately. The
                // FfmpegRunner is registered in TranscodeServicesProvider and is
                // resolvable from the shared container.
                ->constructorParameter('ffmpeg', get(FfmpegRunner::class))
                // Effective (settings-merged) noise-suffix list; named because
                // PHP-DI skips defaulted optional ctor params during autowiring.
                ->constructorParameter('noiseSuffixes', get('matching.noise_suffixes'))
                // S8: bounded concurrent-ffprobe cap for scanFlat(); named for
                // the same reason (PHP-DI skips defaulted optional params).
                ->constructorParameter('maxConcurrentScanProbes', $maxConcurrentScanProbes)
                // P4-S3: TMDB box-set collection sync
                ->constructorParameter('collectionService', get(CollectionService::class))
                // SV-1.3: chapter-thumbnail + trickplay generation job store. Named
                // because PHP-DI skips defaulted optional ctor params during
                // autowiring — without it the store stays null, the enqueue guard
                // (MediaScanner::indexFile) is never true, and chapter thumbnails +
                // trickplay are NEVER generated in prod (inline generation was
                // removed). The MediaAssetWorker (also wired here) drains the queue.
                ->constructorParameter(
                    'mediaAssetJobStore',
                    get(\Phlix\Media\MediaAsset\MediaAssetJobStore::class)
                )
                // SV-2.9: similarity computation job store. Named for the same
                // PHP-DI reason — without it the scan falls back to the inline
                // O(N²) similarity path (or nothing). With it wired the per-item
                // similarity computation is deferred to a background job.
                ->constructorParameter(
                    'similarityJobStore',
                    get(\Phlix\Media\SimilarityJobStore::class)
                ),

            LibraryManager::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                // Named because PHP-DI skips defaulted optional ctor params
                // during autowiring — WITHOUT these the fine-grained maintenance
                // ops (clear_metadata / clear_artwork / delete_all) would have a
                // null ItemRepository/ArtworkStorage and throw at runtime.
                ->constructorParameter('itemRepository', get(ItemRepository::class))
                ->constructorParameter('artworkStorage', get(ArtworkStorage::class)),

            // Scan-job data layer (Step 1.1a). Its only ctor dependency is the
            // Workerman MySQL Connection, already resolvable in this provider.
            ScanJobRepository::class => autowire(),

            // Offline IMDb dataset lookup — ctor dep is the Workerman MySQL
            // Connection (already resolvable here); the optional logger defaults
            // to the MEDIA channel.
            ImdbLookup::class => autowire(),

            // Cross-source movie metadata resolver (TMDB + IMDb). TmdbProvider is
            // built by the factory above (admin-managed key, env/config
            // fallback); ImdbLookup is autowired; the optional logger defaults to
            // the MEDIA channel. The effective PriorityConfig (Step 3.3) drives
            // per-field source precedence (Step 3.4) — named because PHP-DI skips
            // defaulted optional ctor params during autowiring; the
            // PriorityFieldResolver is a pure default-constructed instance.
            MovieMetadataResolver::class => autowire()
                ->constructorParameter('tmdb', get(TmdbProvider::class))
                ->constructorParameter('imdb', get(ImdbLookup::class))
                ->constructorParameter('priorityConfig', get(PriorityConfig::class)),

            // Theme-music (M3) producer. The validated config is built once from
            // the coerced config array; the default fetcher uses the same
            // verified-TLS stream approach as MetadataHttpClient; ThemeMediaFinder
            // is shared with the library-level theme routes.
            ThemeMusicConfig::class => factory(
                static fn(): ThemeMusicConfig => ThemeMusicConfig::fromArray($themeMusicConfigArray)
            ),
            ThemeMusicFetcherInterface::class => autowire(StreamThemeMusicFetcher::class),
            ThemeMusicResolver::class => autowire()
                ->constructorParameter('config', get(ThemeMusicConfig::class))
                ->constructorParameter('finder', get(ThemeMediaFinder::class))
                ->constructorParameter('fetcher', get(ThemeMusicFetcherInterface::class)),

            // TV series resolver (TMDB TV). Shares the admin-keyed TmdbProvider so
            // series/season/episode matching uses the same API key as movies. The
            // effective PriorityConfig is injected (named) for the genres mode; the
            // series path stays TMDB-only (Step 3.4), so no other source can
            // contribute a field regardless of the configured order.
            SeriesMetadataResolver::class => autowire()
                ->constructorParameter('tmdb', get(TmdbProvider::class))
                ->constructorParameter('priorityConfig', get(PriorityConfig::class)),

            // Background per-library metadata matcher run for `metadata`-type
            // scan jobs. Its ItemRepository + resolver deps are resolvable above;
            // the optional logger defaults to the MEDIA channel.
            LibraryMetadataMatcher::class => autowire()
                ->constructorParameter('items', get(ItemRepository::class))
                ->constructorParameter('resolver', get(MovieMetadataResolver::class))
                ->constructorParameter('seriesResolver', get(SeriesMetadataResolver::class))
                // Direct TMDB provider powers the interactive per-item match
                // API (search/apply). Named because PHP-DI skips defaulted
                // optional ctor params during autowiring; shares the same
                // admin-keyed TmdbProvider as the resolvers.
                ->constructorParameter('tmdb', get(TmdbProvider::class))
                // Effective (settings-merged) noise-suffix list so re-match-time
                // title cleaning uses the same list as the scanner.
                ->constructorParameter('noiseSuffixes', get('matching.noise_suffixes'))
                // Per-library provider-priority (item 5): the LibraryManager loads
                // the library's `options.metadata_priority` override and the
                // LibraryPriorityResolver layers it over the global default so a
                // library's effective source order drives its metadata match.
                // Named because PHP-DI skips defaulted optional ctor params.
                ->constructorParameter('libraries', get(LibraryManager::class))
                ->constructorParameter('priorityResolver', get(LibraryPriorityResolver::class))
                // Theme-music (M3) producer — populates metadata_json.theme_audio_url
                // at match time (local theme file, else Plex archive by TVDB id).
                // Named because PHP-DI skips defaulted optional ctor params.
                ->constructorParameter('themeMusic', get(ThemeMusicResolver::class))
                // Fuzzy matching + manual override registry (P1-S5). Named because
                // PHP-DI skips defaulted optional ctor params.
                ->constructorParameter('fuzzyMatcher', get(FuzzyMatcher::class))
                // Local artwork cache (SV-3.4). Named because PHP-DI skips
                // defaulted optional ctor params — WITHOUT this the field stayed
                // null and cacheArtworkLocally() was a no-op, so TMDB posters were
                // never downloaded/resized locally (poster_srcset never emitted).
                // Safe to wire now that ArtworkStorage::downloadToTemp() is
                // non-blocking under the event loop (SV-3.4 sub-step 1).
                ->constructorParameter('artworkStorage', get(ArtworkStorage::class)),

            // Async scan worker (Step 1.1b). Its ctor deps — ScanJobRepository,
            // LibraryManager and the LibraryMetadataMatcher (for `metadata`
            // jobs) — are all autowired above; the optional StructuredLogger
            // defaults to the MEDIA channel.
            LibraryScanWorker::class => autowire()
                ->constructorParameter('metadataMatcher', get(LibraryMetadataMatcher::class)),

            // Per-provider metadata coordinator. The LibraryManager is injected
            // (named — PHP-DI skips defaulted optional ctor params) so getImages
            // results are filtered to the library's enabled `options.image_types`
            // (M5) before being stored in metadata_json.images.{provider}.
            // RatingService is injected so TMDB vote data can be captured (P1-S1).
            // providerPriority (S-F48/SV-4.10) is named for the same PHP-DI
            // reason — without it MetadataManager would fall back to its own
            // ctor default (which ALSO now reads config/metadata.php, so this
            // binding is not strictly required for correctness, but naming it
            // here makes the single config source explicit at the wiring site
            // and keeps this provider the one place that resolves it).
            MetadataManager::class => autowire()
                ->constructorParameter('libraries', get(LibraryManager::class))
                ->constructorParameter('ratingService', get(RatingService::class))
                ->constructorParameter(
                    'providerPriority',
                    factory(static function (): array {
                        return MetadataManager::defaultProviderPriority();
                    })
                ),

            // Rating persistence: stores TMDB/IMDb/user scores and aggregates them.
            // The service takes only the Workerman MySQL Connection (autowirable).
            RatingService::class => autowire(),

            // Fuzzy matching (P1-S5): Levenshtein-distance similarity search across
            // TMDB/IMDb results, plus manual match-override persistence. Autowires:
            // Workerman MySQL Connection (global binding from CoreServicesProvider),
            // TmdbProvider (admin-keyed factory above), and an optional logger.
            FuzzyMatcher::class => autowire(),

            // Process-scoped registry of PLUGIN metadata sources
            // (MetadataSourceInterface). Single container-scoped instance —
            // PluginLoader registers a source on plugin-enable and deregisters
            // it on plugin-disable (no leak). No ctor deps; a plain autowire
            // yields the singleton PHP-DI binds by default.
            SourceRegistry::class => autowire(),

            QualitySelector::class => factory(static function (): QualitySelector {
                return new QualitySelector();
            }),

            HlsStreamer::class => factory(static function ($container) use ($segmentDir, $baseUrl): HlsStreamer {
                return new HlsStreamer(
                    $segmentDir,
                    $baseUrl,
                    $container->get(QualitySelector::class)
                );
            }),

            // Smart playlist services
            SmartPlaylistRepository::class => autowire()
                ->constructorParameter('db', get(Connection::class)),

            SmartPlaylistEngine::class => autowire()
                ->constructorParameter('itemRepository', get(ItemRepository::class)),

            SmartPlaylistRefreshHandler::class => autowire(),

            SmartPlaylistController::class => factory(static function ($container): SmartPlaylistController {
                return new SmartPlaylistController(
                    $container->get(Connection::class),
                    $container->get(ItemRepository::class)
                );
            }),

            // Marker services
            MarkerCandidateRepository::class => autowire()
                ->constructorParameter('itemRepo', get(ItemRepository::class)),

            MarkerService::class => autowire()
                ->constructorParameter('item_repo', get(ItemRepository::class))
                ->constructorParameter('candidate_repo', get(MarkerCandidateRepository::class)),

            PlaybackMarkerService::class => autowire()
                ->constructorParameter('marker_service', get(MarkerService::class)),

            // SV-0.7: marker/intro-detection worker (BackgroundDetectorWorker)
            // ChromaPrint: try FFI first, fall back to fpcalc shelled binary.
            // The fpcalc path ('fpcalc') matches scripts/run-marker-detection-worker.php.
            ChromaPrintInterface::class => factory(
                static fn (): ChromaPrintInterface => ChromaPrintFactory::build('fpcalc')
            ),

            // Fingerprint repository: autowires with ItemRepository (already registered).
            FingerprintRepository::class => autowire(),

            // Marker candidate store: reads job_queue_dir from marker_detection config.
            MarkerCandidateStore::class => factory(static function (ContainerInterface $c): MarkerCandidateStore {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $markerCfg = $appConfig['marker_detection'] ?? null;
                if (!is_array($markerCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/marker_detection.php';
                    $markerCfg = is_array($inc) ? $inc : [];
                }
                $queueDir = is_string(($markerCfg['job_queue_dir'] ?? null))
                    ? $markerCfg['job_queue_dir']
                    : '/tmp/phlix_marker_jobs';
                return new MarkerCandidateStore($queueDir);
            }),

            // Intro detection job: reads min_episodes_for_detection from marker_detection config.
            IntroDetectionJob::class => factory(static function (ContainerInterface $c): IntroDetectionJob {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $markerCfg = $appConfig['marker_detection'] ?? null;
                if (!is_array($markerCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/marker_detection.php';
                    $markerCfg = is_array($inc) ? $inc : [];
                }
                $minEpisodes = is_int(($markerCfg['min_episodes_for_detection'] ?? null))
                    ? $markerCfg['min_episodes_for_detection']
                    : 3;
                /** @var FingerprintRepository */
                $fpRepo = $c->get(FingerprintRepository::class);
                /** @var ItemRepository */
                $itemRepo = $c->get(ItemRepository::class);
                /** @var ChromaPrintInterface */
                $chromaPrint = $c->get(ChromaPrintInterface::class);
                return new IntroDetectionJob($fpRepo, $itemRepo, $chromaPrint, null, $minEpisodes);
            }),

            // Background detector worker: autowires with IntroDetectionJob, MarkerCandidateStore,
            // MarkerCandidateRepository + optional LoggerInterface (defaults to NullLogger).
            BackgroundDetectorWorker::class => autowire(),

            // SV-1.3: media-asset (chapter-thumbnail + trickplay) job store and worker.
            // Reads job_queue_dir and max_concurrent from media_asset_jobs config.

            // Job store: file-based queue keyed by media item ID.
            MediaAssetJobStore::class => factory(static function (ContainerInterface $c): MediaAssetJobStore {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $assetCfg = $appConfig['media_asset_jobs'] ?? null;
                if (!is_array($assetCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/media_asset_jobs.php';
                    $assetCfg = is_array($inc) ? $inc : [];
                }
                $queueDir = is_string(($assetCfg['job_queue_dir'] ?? null))
                    ? $assetCfg['job_queue_dir']
                    : '/tmp/phlix_media_asset_jobs';
                return new \Phlix\Media\MediaAsset\MediaAssetJobStore($queueDir);
            }),

            // Generation job processor: wires FfmpegRunner, ItemRepository, and Connection.
            \Phlix\Media\MediaAsset\MediaAssetGenerationJob::class => autowire()
                ->constructorParameter('ffmpeg', get(FfmpegRunner::class))
                ->constructorParameter('itemRepo', get(ItemRepository::class))
                ->constructorParameter('db', get(Connection::class)),

            // Media asset worker: autowires with MediaAssetJobStore, MediaAssetGenerationJob,
            // optional LoggerInterface (defaults to NullLogger), and max_concurrent config.
            MediaAssetWorker::class => factory(static function (ContainerInterface $c): MediaAssetWorker {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $assetCfg = $appConfig['media_asset_jobs'] ?? null;
                if (!is_array($assetCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/media_asset_jobs.php';
                    $assetCfg = is_array($inc) ? $inc : [];
                }
                $maxConcurrent = is_int(($assetCfg['max_concurrent'] ?? null))
                    ? $assetCfg['max_concurrent']
                    : 2;
                /** @var \Phlix\Media\MediaAsset\MediaAssetJobStore */
                $store = $c->get(\Phlix\Media\MediaAsset\MediaAssetJobStore::class);
                /** @var \Phlix\Media\MediaAsset\MediaAssetGenerationJob */
                $processor = $c->get(\Phlix\Media\MediaAsset\MediaAssetGenerationJob::class);
                return new \Phlix\Media\MediaAsset\MediaAssetWorker($store, $processor, null, $maxConcurrent);
            }),

            // P3B-S8: marker-based media search
            ChapterSearchService::class => autowire()
                ->constructorParameter('itemRepository', get(ItemRepository::class)),

            // P4-S1: similar items engine
            \Phlix\Media\SimilarityService::class => autowire()
                ->constructorParameter('itemRepository', get(ItemRepository::class)),

            // SV-2.9: file-based similarity job queue (keyed by media item ID).
            // Config-driven (similarity_jobs.job_queue_dir) via a factory that
            // mirrors the MediaAssetJobStore idiom, so the scanner (producer) and
            // the SimilarityWorker (consumer) resolve the SAME queue directory
            // even when an operator overrides it. Registered explicitly so the
            // MediaScanner wiring above resolves a shared instance.
            SimilarityJobStore::class => factory(static function (ContainerInterface $c): SimilarityJobStore {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $simCfg = $appConfig['similarity_jobs'] ?? null;
                if (!is_array($simCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/similarity_jobs.php';
                    $simCfg = is_array($inc) ? $inc : [];
                }
                $queueDir = is_string(($simCfg['job_queue_dir'] ?? null))
                    ? $simCfg['job_queue_dir']
                    : '/tmp/phlix_similarity_jobs';
                return new \Phlix\Media\SimilarityJobStore($queueDir);
            }),

            // SV-2.9: similarity worker — the CONSUMER that drains the queue the
            // scanner enqueues into. Without it the enqueued jobs accumulate
            // undrained on disk (leak). Autowires the SimilarityJobStore + the
            // SimilarityService above; max_concurrent is read from config. Spawned
            // as a managed worker by start.php (config/managed_workers.php).
            SimilarityWorker::class => factory(static function (ContainerInterface $c): SimilarityWorker {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $simCfg = $appConfig['similarity_jobs'] ?? null;
                if (!is_array($simCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/similarity_jobs.php';
                    $simCfg = is_array($inc) ? $inc : [];
                }
                $maxConcurrent = is_int(($simCfg['max_concurrent'] ?? null))
                    ? $simCfg['max_concurrent']
                    : 2;
                /** @var \Phlix\Media\SimilarityJobStore */
                $store = $c->get(\Phlix\Media\SimilarityJobStore::class);
                /** @var \Phlix\Media\SimilarityService */
                $service = $c->get(\Phlix\Media\SimilarityService::class);
                return new \Phlix\Media\SimilarityWorker($store, $service, null, $maxConcurrent);
            }),

            // P4-S2: because-you-watched recommendations engine
            \Phlix\Media\RecommendationService::class => autowire()
                ->constructorParameter('similarityService', get(\Phlix\Media\SimilarityService::class)),

            // P7: gapless playback and crossfade manager
            GaplessPlaybackManager::class => autowire()
                ->constructorParameter('userRepository', get(\Phlix\Auth\UserRepository::class))
                ->constructorParameter('ffmpegRunner', get(FfmpegRunner::class)),

            // P4-S3: TMDB box-set collection sync
            CollectionService::class => autowire()
                ->constructorParameter('itemRepository', get(ItemRepository::class))
                ->constructorParameter('tmdbProvider', get(TmdbProvider::class)),

            // SV-3.4: effective local-artwork storage directory. Read ONCE when
            // first built from the `artwork` app-config sub-array (sourced in
            // config/server.php, operator-overridable via ARTWORK_STORAGE_PATH),
            // with a defensive @include fallback + the historic /var/artwork
            // default so an unreadable config never blanks the path. Mirrors the
            // MarkerCandidateStore/MediaAssetJobStore config-read idiom.
            'artwork.storage_path' => factory(static function (ContainerInterface $c): string {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $artworkCfg = $appConfig['artwork'] ?? null;
                if (!is_array($artworkCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/artwork.php';
                    $artworkCfg = is_array($inc) ? $inc : [];
                }
                $path = $artworkCfg['storage_path'] ?? null;
                return is_string($path) && $path !== '' ? $path : '/var/artwork';
            }),

            // SV-3.4: Local artwork cache with sized variants for offline/LAN
            // installs. `storageDir` is config-driven (named because PHP-DI skips
            // defaulted optional ctor params) — defaults to /var/artwork when the
            // config value is unset.
            ArtworkStorage::class => autowire()
                ->constructorParameter('storageDir', get('artwork.storage_path')),
        ]);
    }

    /**
     * Coerce a raw config/setting value into a clean `list<string>` of
     * non-empty, trimmed suffix phrases. Anything that is not an array yields
     * an empty list (so the caller falls back to its default); non-string and
     * blank entries are dropped, and the keys are re-indexed to a list.
     *
     * @param mixed $value Raw value from config or the settings store.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        /** @var mixed $entry */
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $trimmed = trim($entry);
            if ($trimmed === '') {
                continue;
            }
            $out[] = $trimmed;
        }

        return $out;
    }

    /**
     * Coerce a raw config/setting value into a clean per-media-type source-order
     * map (`array<string, list<string>>`). The value must be an array keyed by
     * media-type string; each value is sanitised via {@see self::stringList()}.
     * A type whose cleaned order is empty is dropped (so it falls back to the
     * default / baseline rather than being recorded as an empty override).
     * Anything that is not an array yields an empty map.
     *
     * @param mixed $value Raw value from config or the settings store.
     *
     * @return array<string, list<string>>
     */
    private static function priorityMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        /** @var mixed $order */
        foreach ($value as $type => $order) {
            if (!is_string($type) || $type === '') {
                continue;
            }
            $clean = self::stringList($order);
            if ($clean === []) {
                continue;
            }
            $out[$type] = $clean;
        }

        return $out;
    }
}
