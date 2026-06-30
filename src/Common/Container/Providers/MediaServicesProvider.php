<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\LibraryScanWorker;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\TitleSuffixStripper;
use Phlix\Media\Metadata\TmdbProvider;
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
                ->constructorParameter('noiseSuffixes', get('matching.noise_suffixes')),

            LibraryManager::class => autowire()
                ->constructorParameter('logger', get('logger.media')),

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
            // the MEDIA channel.
            MovieMetadataResolver::class => autowire()
                ->constructorParameter('tmdb', get(TmdbProvider::class))
                ->constructorParameter('imdb', get(ImdbLookup::class)),

            // TV series resolver (TMDB TV). Shares the admin-keyed TmdbProvider so
            // series/season/episode matching uses the same API key as movies.
            SeriesMetadataResolver::class => autowire()
                ->constructorParameter('tmdb', get(TmdbProvider::class)),

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
                ->constructorParameter('noiseSuffixes', get('matching.noise_suffixes')),

            // Async scan worker (Step 1.1b). Its ctor deps — ScanJobRepository,
            // LibraryManager and the LibraryMetadataMatcher (for `metadata`
            // jobs) — are all autowired above; the optional StructuredLogger
            // defaults to the MEDIA channel.
            LibraryScanWorker::class => autowire()
                ->constructorParameter('metadataMatcher', get(LibraryMetadataMatcher::class)),

            MetadataManager::class => autowire(),

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
