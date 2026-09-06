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
use Phlix\Auth\UserRepository;
use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionRepository;
use Phlix\Common\Container\DegradedBuild;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Media\Library\BookProgressStore;
use Phlix\Media\Library\FolderWatchScheduler;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\LibraryScanWorker;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\ChapterSearchService;
use Phlix\Media\CollectionJobStore;
use Phlix\Media\CollectionService;
use Phlix\Media\CollectionWorker;
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
use Phlix\Media\Library\ScanIgnorePatterns;
use Phlix\Media\Metadata\Enrichment\BackgroundEnrichmentSubscriber;
use Phlix\Media\Metadata\Enrichment\PluginEnrichmentQueue;
use Phlix\Media\Metadata\Enrichment\PluginMetadataEnricher;
use Phlix\Media\Metadata\Enrichment\SourceRateLimiter;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MetadataOverwritePolicy;
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
use Phlix\Media\Storage\ArtworkDownloadPolicy;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Subtitles\Quota\SubtitleProviderQuotaRepository;
use Phlix\Media\Subtitles\SubtitleFetchService;
use Phlix\Media\Subtitles\SubtitleSourceRegistry;
use Phlix\Media\Subtitles\SubtitleStorage;
use Phlix\Theming\ThemeMediaFinder;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Playlists\SmartPlaylistController;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRefreshHandler;
use Phlix\Playlists\SmartPlaylistRefreshSubscriber;
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

        // Folder-watch (config/folder_watch.php, composed into config/server.php).
        // Absent or malformed config leaves the feature OFF — the same value the
        // config file ships — so a partial entry point that never composes
        // `folder_watch` cannot accidentally turn on filesystem polling.
        $folderWatchConfig = is_array($appConfig['folder_watch'] ?? null) ? $appConfig['folder_watch'] : [];
        $folderWatchEnabled = ($folderWatchConfig['enabled'] ?? false) === true;
        $folderWatchIntervalRaw = $folderWatchConfig['interval_seconds'] ?? null;
        $folderWatchInterval = is_int($folderWatchIntervalRaw) && $folderWatchIntervalRaw > 0
            ? $folderWatchIntervalRaw
            : 300;

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
                    } catch (\Throwable $e) {
                        // Settings store unavailable — use the in-code default.
                        // Logged because this decision is frozen per worker: an
                        // admin's saved list stays ignored until the workers are
                        // recycled, and the default list is indistinguishable from
                        // "the admin never set one".
                        DegradedBuild::warnUnlessAbsent(
                            $c,
                            LogChannels::MEDIA,
                            'matching.noise_suffixes: the settings store was unreachable; using the '
                            . 'in-code default noise-suffix list. An admin-saved list stays ignored '
                            . 'by this worker until it is recycled.',
                            $e
                        );
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
                    } catch (\Throwable $e) {
                        // Settings store unavailable — use the in-code defaults
                        // (PriorityConfig::orderFor() still falls back to the
                        // canonical [tmdb, imdb] baseline for any type).
                        DegradedBuild::warnUnlessAbsent(
                            $c,
                            LogChannels::MEDIA,
                            'metadata.provider_priority / metadata.genres_mode: the settings store '
                            . 'was unreachable; using the in-code provider order and genres mode. '
                            . 'Admin-saved values stay ignored by this worker until it is recycled.',
                            $e
                        );
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

            // Shared parental-control ACCESS gate (effective-rating + cap check),
            // used by every user-facing read/stream path.
            //
            // `users` is named EXPLICITLY. PHP-DI skips optional ctor params that
            // carry a default during autowiring, so the previous bare
            // `autowire()` left {@see RatingGate::$users} NULL — which silently
            // disabled the account-owner/admin bypass in
            // {@see RatingGate::resolveFilterForUser()} (that shortcut is guarded
            // by `if ($this->users !== null)`). The container-built gate then
            // applied the active profile's cap to the OWNER too, and because
            // `profile_settings.content_rating` defaults to 'R', every NC-17 item
            // 404'd out of `GET /api/v1/media/{id}/playback-info` and
            // `POST /api/v1/media/{id}/transcode` for the owner — with a valid
            // Bearer token, while an ANONYMOUS request (empty user id → early
            // null return) still got a 200. Browse/detail kept working only
            // because {@see \Phlix\Server\WebPortal\WebPortalRouter::gate()}
            // builds its OWN RatingGate WITH the repository. Same PHP-DI pitfall
            // (and same fix shape) as `statsCollector` on ItemRepository above.
            RatingGate::class => autowire()
                ->constructorParameter('users', get(UserRepository::class)),

            // Per-user favorites + ratings (E10). The repository takes only a
            // Workerman MySQL Connection; the controller takes ItemRepository +
            // the repository — both autowirable. Consumed by MediaUserDataController,
            // which is dispatched from TWO routers, not one: WebPortalRouter's auth
            // group (favorite/rating/like/watched) and {@see
            // \Phlix\Server\Core\Application::loadApiRoutes()}:723-730, which
            // registers `POST /api/v1/media/{id}/watched` + `/unwatched` on the
            // Application router as well.
            \Phlix\Media\UserItemDataRepository::class => autowire(),

            // `ratingGate` is named explicitly for the SAME PHP-DI reason as
            // RatingGate::$users above: it is an optional ctor param with a
            // default, so a bare `autowire()` left it null and the controller's
            // parental checks (`$this->ratingGate?->resolveFilterForUser(...)`
            // plus the `$this->ratingGate !== null && !isAllowed(...)` guard)
            // were skipped ENTIRELY — a rating-capped profile could favorite,
            // rate, like and mark-watched items above its cap.
            // `playbackController` (S438 ruling: markWatched drives the finalize
            // path so a marked-watched item leaves Continue Watching) is named
            // for the identical reason — left implicit it would arrive null
            // and the finalize would silently never run, the exact silent-null
            // landmine this provider's comments keep recording.
            \Phlix\Server\Http\Controllers\MediaUserDataController::class => autowire()
                ->constructorParameter('ratingGate', get(RatingGate::class))
                ->constructorParameter('playbackController', get(\Phlix\Session\PlaybackController::class)),

            // Book reading progress tracking (SV-3.2). Autowires with
            // Workerman MySQL Connection (globally registered in CoreServicesProvider).
            BookProgressStore::class => autowire(),

            TmdbProvider::class => factory(static function (ContainerInterface $c) use ($tmdbApiKey): TmdbProvider {
                // Prefer the admin-managed server setting (set via the admin
                // UI's Settings → Metadata page, persisted in server_settings)
                // and fall back to config/tmdb.php / the TMDB_API_KEY env var.
                // getEffective() already returns the override when present,
                // else the config/env default, so an admin-saved key wins.
                //
                // This factory result is a PER-WORKER SINGLETON (PHP-DI caches
                // it in $resolvedEntries), so whatever is decided here used to
                // be final for the worker's whole lifetime. On a deployment
                // where server_settings is the ONLY source of the key — the
                // normal case once it is managed from the admin UI, since
                // config/tmdb.php resolves `api_key` to `getenv('TMDB_API_KEY')
                // ?: ''` — ANY transient failure to reach the database at fork
                // time (connection refused, auth blip, pool exhaustion) baked an
                // EMPTY key in permanently. Every TMDB lookup on that worker
                // then returned [] with nothing logged, which is
                // indistinguishable from "no match": exactly the failure mode
                // that makes unmatched-episode counts mysteriously stall.
                //
                // Two things prevent that now: the catch below LOGS instead of
                // swallowing, and $resolve is handed to the provider so it can
                // re-resolve later if what we captured here is empty.
                $resolve = static function () use ($c): mixed {
                    $settings = $c->get(SettingsRepository::class);

                    return $settings instanceof SettingsRepository
                        ? $settings->getEffective('tmdb.api_key')
                        : null;
                };

                $key = $tmdbApiKey;
                try {
                    $stored = $resolve();
                    if (is_string($stored) && $stored !== '') {
                        $key = $stored;
                    }
                } catch (\Throwable $e) {
                    DegradedBuild::warnUnlessAbsent(
                        $c,
                        LogChannels::MEDIA,
                        'TMDB API key: the settings store was unreachable while building '
                        . 'TmdbProvider; falling back to the config/env key.',
                        $e,
                        [
                            // Whether the fallback is usable at all. False here means
                            // this worker has NO key until the store answers again.
                            'fallback_key_present' => $key !== '',
                        ]
                    );
                }

                return new TmdbProvider($key, null, null, $resolve);
            }),

            FolderWatcher::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class)),

            // The driver that makes the watcher live. `FolderWatcher::watch()`
            // is called only by LibraryManager::createLibrary(), and
            // `checkForChanges()` had NO caller at all, so LibraryUpdated was
            // never dispatched in production. This scheduler registers the
            // libraries from the DB and polls them on a timer.
            //
            // Registered ONLY in the library-scan managed worker (start.php):
            // PSR-14 dispatch is per-process and SmartPlaylistRefreshSubscriber
            // — the only LibraryUpdated listener — lives there, so dispatching
            // from anywhere else would reach nobody.
            //
            // `logger`, `enabled` and `intervalSeconds` are named explicitly
            // because PHP-DI skips optional ctor params during autowiring; the
            // `enabled` default is false, so an unbound param would silently
            // pin the feature off regardless of config.
            FolderWatchScheduler::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                ->constructorParameter('enabled', $folderWatchEnabled)
                ->constructorParameter('intervalSeconds', $folderWatchInterval),

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
                // P4-S3/S215: TMDB box-set collection sync job store. Named for
                // the same PHP-DI reason as every entry above. S215 replaced the
                // former `collectionService` parameter here with the QUEUE: the
                // sync makes blocking-HTTPS TMDB calls, which must never run
                // inside the scan loop; the CollectionWorker (factory below)
                // drains this queue after the scan.
                ->constructorParameter('collectionJobStore', get(CollectionJobStore::class))
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
                )
                // Effective `scanner.ignore_patterns` list for shouldSkipFile().
                // Named for the same PHP-DI reason as every entry above — an
                // unnamed optional param is SKIPPED during autowiring, which
                // would pin the scanner to the shipped defaults and make the
                // admin control inert while still rendering and accepting a PUT.
                ->constructorParameter('ignorePatterns', get(ScanIgnorePatterns::class)),

            // Native (getID3) music scanner. Registered explicitly — NOT left to
            // bare autowiring — because PHP-DI SKIPS defaulted optional ctor
            // params, which would leave the scanner with a null EventDispatcher
            // (music enrichment via the musicbrainz plugin's MediaItemAdded
            // subscription would silently never fire) and the shipped
            // ScanIgnorePatterns defaults instead of the live admin setting. The
            // same shared EventDispatcher the plugins subscribe to (and the video
            // MediaScanner publishes on) is injected, so a track added by the
            // library-scan worker reaches the plugin listener.
            //
            // ⚠ S96(a) — `logger` IS THE ONE THAT COST FOUR WRONG DIAGNOSES. Omitting
            // it here is exactly what sent every music-scan log line into
            // `sys_get_temp_dir()/phlix_music_scanner_<uniqid>/music_scanner.log`
            // inside the systemd unit's `PrivateTmp` (unreadable without nsenter,
            // destroyed on restart, and one leaked directory per instantiation — 66 on
            // production). The scanner no longer has that fallback at all, but this
            // parameter must still be NAMED: PHP-DI skips defaulted optional ctor
            // params, so without it the scanner would build its own MEDIA logger via
            // LoggerFactory instead of sharing this container's instance.
            MusicLibraryScanner::class => autowire()
                ->constructorParameter('logger', get('logger.media'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class))
                ->constructorParameter('ignorePatterns', get(ScanIgnorePatterns::class)),

            // The ONE music read path (S99), and — since S97 — the only place the
            // Artist→Album→Track hierarchy exists at all: `media_items.parent_id`
            // is never written for music, so `findByParent()` cannot reach it.
            // Registered here (it used to be `new`-ed inline in
            // WebPortalServicesProvider) because the DLNA LibraryBridge and
            // MediaItemController's shuffle both need it now. Both ctor
            // dependencies — the Workerman MySQL Connection and the scanner
            // configured just above — are already resolvable in this container.
            MusicLibraryService::class => autowire(),

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
                ->constructorParameter('priorityConfig', get(PriorityConfig::class))
                // F2: the container-scoped registry of enabled plugin metadata
                // sources (omdb/anidb/myanimelist). Named because PHP-DI skips
                // defaulted optional ctor params during autowiring. Consulted only
                // when resolve() is called with includePluginSources=true (the
                // quota-safe on-demand path); the bulk scan leaves it dormant.
                ->constructorParameter('sourceRegistry', get(SourceRegistry::class)),

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
                ->constructorParameter('priorityConfig', get(PriorityConfig::class))
                // F2: same container-scoped plugin-source registry as the movie
                // resolver. Named for the same PHP-DI reason; consulted only on the
                // opt-in (includePluginSources=true) path, never on the bulk scan.
                ->constructorParameter('sourceRegistry', get(SourceRegistry::class)),

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
                ->constructorParameter('artworkStorage', get(ArtworkStorage::class))
                // Gate for `artwork.download_enabled`. Named for the same
                // PHP-DI reason as every entry above — an unnamed optional
                // param is SKIPPED during autowiring, which would leave the
                // matcher on a store-less policy and make the admin toggle
                // inert while still rendering and accepting a PUT.
                ->constructorParameter(
                    'artworkDownloadPolicy',
                    get(ArtworkDownloadPolicy::class)
                )
                // Gate for `metadata.overwrite_existing`. Named for the same
                // PHP-DI reason as every entry above — an unnamed optional param
                // is SKIPPED during autowiring, which would leave the matcher on
                // a store-less policy (overwrite always on) and make the admin
                // toggle inert while still rendering and accepting a PUT.
                ->constructorParameter(
                    'overwritePolicy',
                    get(MetadataOverwritePolicy::class)
                )
                // F2: persists ratings surfaced by plugin sources on the opt-in
                // (includePluginSources=true) resolve path — the matcher owns the
                // media_item_id the resolver lacks. Named because PHP-DI skips
                // defaulted optional ctor params; a no-op on the bulk scan (which
                // never sets plugin_ratings), so it never fires there.
                ->constructorParameter('ratingService', get(RatingService::class)),

            // Gate for `artwork.download_enabled`, read live per metadata
            // persist. A factory (not autowire()) because the SettingsRepository
            // must be OPTIONAL: an unavailable settings store degrades to the
            // shipped default (downloads enabled) instead of throwing.
            ArtworkDownloadPolicy::class => factory(
                static fn(ContainerInterface $c): ArtworkDownloadPolicy
                    => new ArtworkDownloadPolicy(self::optionalSettings($c))
            ),

            // Gate for `metadata.overwrite_existing`, read live at the matcher's
            // (re)resolve decision point. Same optional-store rationale as
            // ArtworkDownloadPolicy above: an unavailable settings store degrades
            // to the shipped default (overwrite enabled) instead of throwing.
            MetadataOverwritePolicy::class => factory(
                static fn(ContainerInterface $c): MetadataOverwritePolicy
                    => new MetadataOverwritePolicy(self::optionalSettings($c))
            ),

            // Effective scanner skip-pattern list behind `scanner.ignore_patterns`.
            // Same optional-store rationale as ArtworkDownloadPolicy above.
            ScanIgnorePatterns::class => factory(
                static fn(ContainerInterface $c): ScanIgnorePatterns
                    => new ScanIgnorePatterns(self::optionalSettings($c))
            ),

            // Async scan worker (Step 1.1b). Its ctor deps — ScanJobRepository,
            // LibraryManager and the LibraryMetadataMatcher (for `metadata`
            // jobs) — are all autowired above; the optional StructuredLogger
            // defaults to the MEDIA channel.
            // `mediaAssetBackfill` (S284) is NAMED for the same reason
            // `metadataMatcher` is: PHP-DI's autowire() SKIPS ctor params that
            // carry a default, so an optional dependency left implicit here is
            // silently null in production and every `media_assets` job fails.
            LibraryScanWorker::class => autowire()
                ->constructorParameter('metadataMatcher', get(LibraryMetadataMatcher::class))
                ->constructorParameter(
                    'mediaAssetBackfill',
                    get(\Phlix\Media\MediaAsset\MediaAssetBackfill::class)
                ),

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
            // Workerman MySQL Connection (global binding from CoreServicesProvider)
            // and TmdbProvider (admin-keyed factory above).
            //
            // `logger` is named explicitly for the same PHP-DI reason as every
            // other optional param in this provider (skipped when defaulted):
            // without it the matcher built its OWN MEDIA logger via
            // LoggerFactory instead of sharing this container's initialised
            // instance. The `logger.media` alias is used so the channel matches
            // the class's own fallback exactly (no channel change).
            FuzzyMatcher::class => autowire()
                ->constructorParameter('logger', get('logger.media')),

            // Process-scoped registry of PLUGIN metadata sources
            // (MetadataSourceInterface). Single container-scoped instance —
            // PluginLoader registers a source on plugin-enable and deregisters
            // it on plugin-disable (no leak). No ctor deps; a plain autowire
            // yields the singleton PHP-DI binds by default.
            SourceRegistry::class => autowire(),

            // F2b — per-source quota-safety limiter for background enrichment.
            // Reads the per-source min-spacing knobs from config/metadata.php's
            // `background_enrichment.source_intervals` (a direct @include: this
            // file is not composed into config/server.php). SourceRateLimiter
            // clamps every interval up to a 1s floor, so a bad config can only
            // slow a source down, never remove the quota guard.
            SourceRateLimiter::class => factory(
                static function (): SourceRateLimiter {
                    $cfg = self::backgroundEnrichmentConfig();
                    /** @var array<string, float|int> $intervals */
                    $intervals = is_array($cfg['source_intervals'] ?? null) ? $cfg['source_intervals'] : [];
                    $default = isset($cfg['default_interval']) && is_numeric($cfg['default_interval'])
                        ? (float) $cfg['default_interval']
                        : null;
                    return new SourceRateLimiter($intervals, $default);
                }
            ),

            // F2b — bounded, de-duped FIFO of item-ids awaiting enrichment.
            // Instance-scoped (resident-memory bound); one per worker.
            PluginEnrichmentQueue::class => factory(
                static function (): PluginEnrichmentQueue {
                    $cfg = self::backgroundEnrichmentConfig();
                    $maxSize = isset($cfg['queue_max_size']) && is_numeric($cfg['queue_max_size'])
                        ? (int) $cfg['queue_max_size']
                        : 10000;
                    $minInterval = isset($cfg['queue_min_interval']) && is_numeric($cfg['queue_min_interval'])
                        ? (float) $cfg['queue_min_interval']
                        : 1.0;
                    return new PluginEnrichmentQueue($minInterval, $maxSize);
                }
            ),

            // F2b — drains ONE item and gap-fills it from the DUE plugin sources
            // (never clobbering scan-resolved TMDB/IMDb values, never re-hitting
            // TMDB). Required deps (SourceRegistry, ItemRepository, RatingService,
            // PriorityConfig, SourceRateLimiter) are all bound above; the optional
            // logger + PriorityFieldResolver default internally (PHP-DI skips
            // defaulted optional ctor params during autowiring).
            PluginMetadataEnricher::class => autowire()
                ->constructorParameter('registry', get(SourceRegistry::class))
                ->constructorParameter('items', get(ItemRepository::class))
                ->constructorParameter('ratingService', get(RatingService::class))
                ->constructorParameter('priorityConfig', get(PriorityConfig::class))
                ->constructorParameter('rateLimiter', get(SourceRateLimiter::class)),

            // F2b — the host-side subscriber wired into the library-scan worker's
            // ListenerRegistry at onWorkerStart (start.php). It enqueues on
            // MediaItemAdded (no HTTP) and drains off a re-arming Workerman timer.
            BackgroundEnrichmentSubscriber::class => autowire()
                ->constructorParameter('queue', get(PluginEnrichmentQueue::class))
                ->constructorParameter('enricher', get(PluginMetadataEnricher::class))
                ->constructorParameter('registry', get(SourceRegistry::class))
                ->constructorParameter('items', get(ItemRepository::class)),

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

            // `collectionManager` + `collectionRepo` are named explicitly: both are
            // optional ctor params with defaults, so PHP-DI skipped them and the
            // handler's own guard —
            // `if ($this->collectionManager === null || $this->collectionRepo === null) { return; }`
            // ({@see \Phlix\Playlists\SmartPlaylistRefreshHandler::refreshCollectionsForPlaylist()})
            // — would have made smart-COLLECTION refresh a silent no-op. Since the
            // collection refresh is the ONLY thing this handler does, skipping
            // them would leave it with no effect at all. Both deps are plainly
            // autowirable (CollectionRepository/CollectionItemRepository take only
            // the Workerman MySQL Connection; CollectionManager's other deps —
            // SmartPlaylistEngine, SmartPlaylistRepository, ItemRepository — are
            // all bound in this provider), and neither depends back on the handler,
            // so there is no cycle.
            //
            // The handler is now actually REACHED: SmartPlaylistRefreshSubscriber
            // (below) is resolved and registered by the `library-scan` managed
            // worker in start.php. Before that hookup existed, nothing resolved
            // this handler and `SmartPlaylistRefreshHandler::register()` had no
            // caller, so smart-COLLECTION membership never refreshed after a scan.
            SmartPlaylistRefreshHandler::class => autowire()
                ->constructorParameter('collectionManager', get(CollectionManager::class))
                ->constructorParameter('collectionRepo', get(CollectionRepository::class)),

            // The subscriber that makes the handler live. Registered ONLY in the
            // library-scan managed worker (start.php) — the process that
            // dispatches LibraryScanCompleted. It must never be registered in an
            // HTTP worker: a refresh is O(library size × linked collections) +
            // O(playlists) blocking DB round-trips — ONE linked collection
            // already walks the whole library in 500-row batches and then writes
            // the membership diff, and even with NO link there is one cheap
            // `collections` lookup per playlist (floor `1 + N`, not 1) — which
            // would stall every concurrent connection on that worker. It enqueues
            // on the event and drains one library per timer tick.
            SmartPlaylistRefreshSubscriber::class => autowire()
                ->constructorParameter('handler', get(SmartPlaylistRefreshHandler::class)),

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

            // Background detector worker: autowires with IntroDetectionJob,
            // MarkerCandidateStore and MarkerCandidateRepository.
            //
            // `logger` is named explicitly because PHP-DI skips optional ctor
            // params with defaults — without it the worker fell back to its own
            // `new NullLogger()` and every intro/outro detection run was
            // completely SILENT (no progress, no failures). Bound to the MEDIA
            // channel like the other media workers in this provider; the alias
            // resolves to a StructuredLogger, which implements the ctor's
            // Psr\Log\LoggerInterface.
            //
            // This is the one binding in this batch that changes behaviour in a
            // LIVE process: the worker is spawned by config/managed_workers.php and
            // polls every 30s. A real logger therefore also turned its per-tick
            // "queue empty" debug line into ~2,880 lines/day/box, so that line was
            // demoted to a state-change log at the same time — see
            // {@see \Phlix\Media\Markers\Detection\BackgroundDetectorWorker::$idleLogged}.
            // Genuine work/error logging is untouched.
            BackgroundDetectorWorker::class => autowire()
                ->constructorParameter('logger', get('logger.media')),

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

            // S284: re-enqueue pass for the media-asset queue. Named parameters
            // throughout — autowire() would resolve ItemRepository/FfmpegRunner by
            // type anyway, but the store MUST come from the factory above so the
            // backfill (producer) and the MediaAssetWorker (consumer) resolve the
            // SAME queue directory when an operator overrides it.
            \Phlix\Media\MediaAsset\MediaAssetBackfill::class => autowire()
                ->constructorParameter('items', get(ItemRepository::class))
                ->constructorParameter('store', get(MediaAssetJobStore::class))
                ->constructorParameter('ffmpeg', get(FfmpegRunner::class))
                ->constructorParameter('logger', get('logger.media')),

            // Generation job processor: wires FfmpegRunner, ItemRepository, and Connection.
            \Phlix\Media\MediaAsset\MediaAssetGenerationJob::class => autowire()
                ->constructorParameter('ffmpeg', get(FfmpegRunner::class))
                ->constructorParameter('itemRepo', get(ItemRepository::class))
                ->constructorParameter('db', get(Connection::class))
                // Gates sprite generation on the `trickplay.enabled` setting.
                ->constructorParameter('settings', get(\Phlix\Admin\SettingsRepository::class)),

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

            // S215: file-based collection-sync job queue (keyed by media item ID).
            // Config-driven (collection_jobs.job_queue_dir) via a factory mirroring
            // the SimilarityJobStore idiom, so the scanner (producer) and the
            // CollectionWorker (consumer) resolve the SAME queue directory even
            // when an operator overrides it. Unlike SimilarityJobStore the queue
            // directory is minted lazily on first enqueue, never at construction,
            // so resolving this factory leaves zero /tmp residue (S439 census).
            CollectionJobStore::class => factory(static function (ContainerInterface $c): CollectionJobStore {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $colCfg = $appConfig['collection_jobs'] ?? null;
                if (!is_array($colCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/collection_jobs.php';
                    $colCfg = is_array($inc) ? $inc : [];
                }
                $queueDir = is_string(($colCfg['job_queue_dir'] ?? null))
                    ? $colCfg['job_queue_dir']
                    : '/tmp/phlix_collection_jobs';
                return new \Phlix\Media\CollectionJobStore($queueDir);
            }),

            // S215: collection worker — the CONSUMER that drains the collection
            // queue the scanner enqueues into. Without it the enqueued jobs
            // accumulate undrained on disk (leak). Resolves the CollectionJobStore
            // + the CollectionService above; max_concurrent is read from config.
            // Spawned as a managed worker by start.php (config/managed_workers.php).
            CollectionWorker::class => factory(static function (ContainerInterface $c): CollectionWorker {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $colCfg = $appConfig['collection_jobs'] ?? null;
                if (!is_array($colCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/collection_jobs.php';
                    $colCfg = is_array($inc) ? $inc : [];
                }
                $maxConcurrent = is_int(($colCfg['max_concurrent'] ?? null))
                    ? $colCfg['max_concurrent']
                    : 1;
                /** @var \Phlix\Media\CollectionJobStore */
                $store = $c->get(\Phlix\Media\CollectionJobStore::class);
                /** @var \Phlix\Media\CollectionService */
                $service = $c->get(\Phlix\Media\CollectionService::class);
                return new \Phlix\Media\CollectionWorker($store, $service, null, $maxConcurrent);
            }),

            // P4-S2: because-you-watched recommendations engine
            \Phlix\Media\RecommendationService::class => autowire()
                ->constructorParameter('similarityService', get(\Phlix\Media\SimilarityService::class)),

            // P7: resolves per-user gapless/crossfade playback preferences
            GaplessPlaybackManager::class => autowire()
                ->constructorParameter('userRepository', get(\Phlix\Auth\UserRepository::class)),

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

            // S301: the transport-neutral pre-router byte stage. `container` is
            // an OPTIONAL ctor param (PHP-DI skips those during autowiring),
            // and the stream branch NEEDS it — without the explicit binding the
            // relay fork's container-built instance would resolve with
            // container=null and the fail-loud guard would refuse every
            // relayed direct-play request (measured live in the S301 proof:
            // HTTP_REQUEST dispatch failed with "no container was provided").
            \Phlix\Server\Http\FastPath\PreRouterFastPaths::class => autowire()
                ->constructorParameter('container', get(ContainerInterface::class)),

            // F3: root directory for DOWNLOADED external subtitles
            // (config/subtitles.php `storage_path`, operator-overridable via
            // SUBTITLE_STORAGE_PATH), with a defensive @include fallback + the
            // historic /var/subtitles default. Mirrors the artwork.storage_path
            // config-read idiom.
            'subtitles.storage_path' => factory(static function (ContainerInterface $c): string {
                $appConfig = $c->get('app.config');
                if (!is_array($appConfig)) {
                    $appConfig = [];
                }
                $subCfg = $appConfig['subtitles'] ?? null;
                if (!is_array($subCfg)) {
                    /** @var mixed $inc */
                    $inc = @include __DIR__ . '/../../../../config/subtitles.php';
                    $subCfg = is_array($inc) ? $inc : [];
                }
                $path = $subCfg['storage_path'] ?? null;
                return is_string($path) && $path !== '' ? $path : '/var/subtitles';
            }),

            // F3: process-scoped registry of PLUGIN subtitle sources
            // (SubtitleSourceInterface). Single container-scoped instance —
            // PluginLoader registers a source on plugin-enable and deregisters it
            // on plugin-disable (no leak). Mirrors SourceRegistry.
            SubtitleSourceRegistry::class => autowire(),

            // F3: downloaded-subtitle storage under the configured root
            // (named because PHP-DI skips defaulted optional ctor params).
            SubtitleStorage::class => autowire()
                ->constructorParameter('baseDir', get('subtitles.storage_path')),

            // F3: per-provider download-quota persistence (Workerman MySQL
            // Connection is globally bound by CoreServicesProvider).
            SubtitleProviderQuotaRepository::class => autowire(),

            // F3: on-demand subtitle fetch orchestrator. All ctor deps are
            // resolvable above; `settings` (for subtitles.provider_priority) is
            // NAMED because PHP-DI skips defaulted optional ctor params during
            // autowiring — without this it would stay null and the priority
            // override would never be read. The optional logger defaults to the
            // MEDIA channel.
            SubtitleFetchService::class => autowire()
                ->constructorParameter('settings', get(SettingsRepository::class)),
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
     * Load the `background_enrichment` sub-array from config/metadata.php (F2b).
     *
     * A direct `@include` mirroring the theme-music pattern above: config/metadata.php
     * is NOT composed into config/server.php, so a boot `$appConfig` lookup would
     * miss it. Returns an empty array when the file or key is unreadable, so the
     * consuming factories fall back to their in-code defaults.
     *
     * @return array<string, mixed>
     */
    private static function backgroundEnrichmentConfig(): array
    {
        /** @var mixed $included */
        $included = @include __DIR__ . '/../../../../config/metadata.php';
        if (!is_array($included)) {
            return [];
        }
        $cfg = $included['background_enrichment'] ?? null;
        if (!is_array($cfg)) {
            return [];
        }
        /** @var array<string, mixed> $out */
        $out = [];
        /** @var mixed $v */
        foreach ($cfg as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
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

    /**
     * Resolve {@see SettingsRepository} from the container, or NULL when it is
     * unavailable.
     *
     * Wrapped because neither {@see ArtworkDownloadPolicy} nor
     * {@see ScanIgnorePatterns} may be the reason a scan or a metadata match
     * fails: a container without a settings binding (or one whose database is
     * down) must degrade to the shipped defaults rather than throw out of the
     * factory. Mirrors `TranscodeServicesProvider::optionalSettings()`.
     *
     * The two failures are NOT the same and are treated differently:
     *
     *   - **Not defined at all** (`NotFoundExceptionInterface`) — a normal,
     *     expected shape; several unit containers register no settings store.
     *     Stays quiet.
     *   - **Defined but unbuildable** — a genuine degradation: the policies then
     *     silently run on shipped defaults for the worker's whole lifetime.
     *     Logged.
     *
     * `$c->has()` cannot make this distinction: with autowiring enabled it
     * returns TRUE for any instantiable class, so a container with no settings
     * store at all still answers `has() === true`. The exception type is the
     * only reliable signal.
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
                'The settings store is bound but could not be built; artwork-download and '
                . 'scan-ignore policies fall back to their shipped defaults. Admin-saved '
                . 'values stay ignored by this worker until it is recycled.',
                $e
            );

            return null;
        }
    }
}
