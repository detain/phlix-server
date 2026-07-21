<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Metadata\Exception\TmdbUnconfiguredException;
use Phlix\Media\Metadata\Resolution\LibraryPriorityResolver;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicResolver;
use Phlix\Media\Storage\ArtworkDownloadPolicy;
use Phlix\Media\Storage\ArtworkStorage;
use Throwable;
use Phlix\Media\Metadata\SceneFilenameNormalizer;

/**
 * Background metadata matcher for a whole library.
 *
 * Pages through a library's media items and, for each MOVIE-type item, asks the
 * {@see MovieMetadataResolver} (the cross-source TMDB + IMDb "matching brain")
 * to resolve fresh details from the item's stored name + year + any known
 * external ids. When the resolver returns a match, the result is MERGED into the
 * item's existing `metadata_json` (preserving unrelated keys) and persisted via
 * {@see ItemRepository::update()}, stamping `metadata_refreshed_at = NOW()`.
 *
 * This service is the unit the async {@see \Phlix\Media\Library\LibraryScanWorker}
 * runs for `metadata`-type jobs — reusing the existing `library_scan_jobs`
 * queue + status infrastructure so the admin UI's scan-status badge/polling
 * shows progress for a metadata match exactly as it does for a scan.
 *
 * **Resilience.** A single item failing (resolver error, malformed metadata,
 * persistence error) must NOT abort the whole library — each item is processed
 * in its own try/catch, the failure is logged on the MEDIA channel, and the run
 * continues. The method returns `{matched, processed}` counts.
 *
 * **Resident-memory (Workerman) safety.** The matcher holds no unbounded
 * `static`/`global` state — its only instance state is the injected
 * dependencies. It pages the library in fixed-size batches so a huge library
 * never loads every row into memory at once.
 *
 * @package Phlix\Media\Metadata
 * @since   0.21.0
 */
class LibraryMetadataMatcher
{
    /**
     * Media-item `type` values that represent a movie. The scanner stores
     * concrete `movie` for video-library films (see {@see \Phlix\Media\Library\MediaScanner}),
     * and `video` is accepted as the raw/library-level fallback some callers
     * persist before a metadata pass has refined the type.
     *
     * @var list<string>
     */
    private const MOVIE_TYPES = ['movie', 'video'];

    /** @var int Page size used to drain the library without loading it all at once. */
    private const PAGE_SIZE = 100;

    /**
     * SV-3.5: Maximum concurrent metadata-match operations when inside a Swoole
     * coroutine. Bounded fan-out prevents overwhelming the TMDB/TVDB API with
     * hundreds of simultaneous requests during a large-library match pass.
     * Coroutine-based I/O (HTTP calls) overlaps the per-item wait time so this
     * does not slow down individual matches — it just prevents thundering-herd
     * on the provider's rate-limiter. Outside a coroutine (PHPUnit CLI, plain
     * CLI scan scripts) this degrades to exact sequential behaviour so nothing
     * regresses for non-coroutine callers.
     *
     * @var int
     */
    private const MAX_CONCURRENT_MATCHES = 8;

    /** @var ItemRepository Media-item data access (paging + persistence). */
    private ItemRepository $items;

    /** @var MovieMetadataResolver Cross-source resolver (TMDB + IMDb). */
    private MovieMetadataResolver $resolver;

    /** @var SeriesMetadataResolver|null TV series resolver (TMDB TV); null disables TV matching. */
    private ?SeriesMetadataResolver $seriesResolver;

    /** @var TmdbProvider|null Direct TMDB provider for interactive single-item search/apply. */
    private ?TmdbProvider $tmdb;

    /** @var StructuredLogger Logger for the MEDIA channel. */
    private StructuredLogger $logger;

    /** @var string Base URL for the TMDB image CDN (poster/backdrop URL building). */
    private string $imageBaseUrl = 'https://image.tmdb.org/t/p';

    /**
     * Effective trailing-edition noise-suffix list applied to a parsed title
     * before metadata matching (admin-extensible via the
     * `matching.noise_suffixes` server setting, merged over the
     * `config/matching.php` default by the DI provider). Resolved once at
     * construction and forwarded to {@see SceneFilenameNormalizer::normalize()};
     * never mutated afterwards. Null when not injected — the normalizer then
     * falls back to the built-in
     * {@see \Phlix\Media\Metadata\TitleSuffixStripper::NOISE_SUFFIXES}.
     *
     * @var list<string>|null
     */
    private ?array $noiseSuffixes;

    /**
     * Library data access used to load a library's `options.metadata_priority`
     * override so {@see matchLibrary()} can build the effective per-library
     * {@see PriorityConfig}. Nullable for back-compat: when null (legacy
     * construction / unit tests), no override is loaded and matching uses the
     * resolvers' injected global priority config exactly as before.
     *
     * @var LibraryManager|null
     */
    private ?LibraryManager $libraries;

    /**
     * Builds the effective per-library {@see PriorityConfig} (library override
     * layered over the global default). Nullable for back-compat alongside
     * {@see self::$libraries}; both must be present for a per-library override to
     * take effect.
     *
     * @var LibraryPriorityResolver|null
     */
    private ?LibraryPriorityResolver $priorityResolver;

    /**
     * Theme-music (M3) producer. When present, series/movie matches call it after
     * resolving to populate `metadata_json.theme_audio_url` (local theme file,
     * else Plex archive by TVDB id). Null in legacy construction / unit tests that
     * do not exercise theme music — the theme_audio_url slot then stays unset,
     * exactly as before.
     *
     * @var ThemeMusicResolver|null
     */
    private ?ThemeMusicResolver $themeMusic;

    /**
     * Fuzzy matching (P1-S5): Levenshtein-distance search + manual override
     * registry. When present, resolved TMDB/IMDb ids are checked against the
     * manual override table before persisting — a user-specified override short-
     * circuits automatic matching so the user's explicit choice is preserved.
     * Null in legacy construction / unit tests that do not exercise fuzzy
     * matching; all other behaviour is then unchanged.
     *
     * @var FuzzyMatcher|null
     */
    private ?FuzzyMatcher $fuzzyMatcher;

    /**
     * Local artwork cache (SV-3.4): downloads TMDB posters and generates
     * sized variants for offline/LAN installs. Null when not wired (legacy
     * construction / unit tests) — artwork is then served directly from
     * TMDB with no local caching.
     *
     * @var ArtworkStorage|null
     */
    private ?ArtworkStorage $artworkStorage;

    /**
     * Gate for `artwork.download_enabled`, consulted at BOTH artwork download
     * choke points ({@see cacheArtworkLocally()} and {@see cacheLogoLocally()}).
     *
     * Never null: legacy construction substitutes a store-less policy, which
     * reports the shipped default (downloads enabled).
     */
    private ArtworkDownloadPolicy $artworkDownloadPolicy;

    /**
     * Gate for `metadata.overwrite_existing`, consulted by
     * {@see shouldSkipOverwrite()} — the SINGLE decision point that governs
     * every `array_merge($existing, $resolved)` metadata-overwrite site in this
     * class (movie, series, season, episode and all four interactive-apply
     * branches), reached through the three (re)resolve entry points
     * ({@see matchItem()}, {@see matchSeries()}, {@see applyMatchResolved()}).
     *
     * Never null: legacy construction / unit tests substitute a store-less
     * policy, which reports the shipped default (overwrite ENABLED) — i.e.
     * exactly the unconditional `array_merge` behaviour before this gate
     * existed, so nothing changes at the default.
     */
    private MetadataOverwritePolicy $overwritePolicy;

    /**
     * Scan-scoped map of TMDB poster path => the local artwork override fields
     * ({@see cacheArtworkLocally()} produces `poster_url` / `poster_srcset` /
     * `poster_path`) generated the FIRST time that path was downloaded this run.
     *
     * Seasons and episodes inherit the series poster, so the same TMDB image
     * recurs across every child of a series. Without this map each child
     * re-downloads and re-resizes the identical bytes into its own per-item
     * directory — measured at ~15x redundant work on a real TV library and the
     * dominant scan cost. Later items that inherit a path reuse the first item's
     * local URLs (the bytes are identical), so the image is fetched + resized
     * once and no duplicate storage is written. Reset per {@see matchLibrary()}
     * run so URLs never point across libraries and memory stays bounded.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $artworkPathCache = [];

    /**
     * Scan-scoped map of TMDB logo path => the local logo override field
     * ({@see cacheLogoLocally()} produces a signed local `logo_url`) generated the
     * FIRST time that path was downloaded this run. Series children inherit the
     * series title logo, so the same TMDB path recurs across a series — this
     * mirrors {@see $artworkPathCache} to avoid re-downloading identical bytes.
     * Reset per {@see matchLibrary()} run so URLs never leak across libraries.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $logoPathCache = [];

    /**
     * The image types (M5) enabled for the CURRENT match run/item, used to gate
     * the flat `poster_url` / `backdrop_url` metadata keys in
     * {@see persistMetadata()}. `null` means "do not filter" (back-compat: no
     * LibraryManager wired, or the enabled set could not be loaded) — behaviour
     * is then exactly as before. Set once per {@see matchLibrary()} run and
     * per-item in {@see applyMatch()}; reset to null when the run finishes so a
     * later call without a computed set never inherits a stale filter.
     *
     * @var list<string>|null
     */
    private ?array $activeImageTypes = null;

    /**
     * When true, re-process items that already have metadata
     * (i.e., items with `metadata_refreshed_at IS NOT NULL`).
     * When false (default), skip already-processed items.
     *
     * @var bool
     */
    private bool $forceRefresh = false;

    /**
     * Map of flat metadata image KEY => the canonical {@see ImageType} const it
     * carries. Only keys in this map are gated by the per-library selection; any
     * image key NOT listed here passes through unfiltered (we only filter the
     * types we know — see the M5 spec's "pass through unmapped" rule).
     *
     * @var array<string, string>
     */
    private const FLAT_IMAGE_KEY_TYPES = [
        'poster_url' => ImageType::POSTER,
        'backdrop_url' => ImageType::BACKDROP,
    ];

    /**
     * @param ItemRepository             $items          Media-item data access.
     * @param MovieMetadataResolver      $resolver       Cross-source movie resolver.
     * @param SeriesMetadataResolver|null $seriesResolver TV series resolver; when
     *                                                   null, series/episode items
     *                                                   are skipped (movie-only).
     * @param StructuredLogger|null      $logger         Optional logger; defaults
     *                                                   to the MEDIA channel.
     * @param TmdbProvider|null          $tmdb           Direct TMDB provider used by
     *                                                   the interactive per-item
     *                                                   search/apply API; when null,
     *                                                   {@see self::searchCandidates()}
     *                                                   / {@see self::applyMatch()}
     *                                                   report TMDB as unconfigured.
     * @param list<string>|null          $noiseSuffixes  Effective trailing-edition
     *                                                   noise list (admin-extensible
     *                                                   via `matching.noise_suffixes`,
     *                                                   merged over `config/matching.php`
     *                                                   by the DI provider). A
     *                                                   null/empty value falls back to
     *                                                   the built-in const.
     * @param LibraryManager|null        $libraries      Library data access used to
     *                                                   load a library's
     *                                                   `options.metadata_priority`
     *                                                   override. Nullable for
     *                                                   back-compat — when null (with
     *                                                   or without $priorityResolver)
     *                                                   matching uses the resolvers'
     *                                                   injected global priority config.
     * @param LibraryPriorityResolver|null $priorityResolver Builds the effective
     *                                                   per-library priority config
     *                                                   (override over global default).
     *                                                   Nullable for back-compat; both
     *                                                   this and $libraries must be
     *                                                   present for an override to apply.
     * @param ThemeMusicResolver|null      $themeMusic      Theme-music (M3) producer.
     *                                                   Null in legacy construction.
     * @param FuzzyMatcher|null            $fuzzyMatcher     Fuzzy matching + manual
     *                                                   override registry (P1-S5).
     *                                                   Null in legacy construction;
     *                                                   all other behaviour unchanged.
     * @param ArtworkStorage|null          $artworkStorage  Local artwork cache for
     *                                                   offline/LAN installs (SV-3.4).
     *                                                   Null in legacy construction.
     * @param bool                        $forceRefresh   When true, re-process items
     *                                                   that already have metadata
     *                                                   (metadata_refreshed_at IS NOT NULL).
     *                                                   When false (default), skip them.
     * @param ArtworkDownloadPolicy|null  $artworkDownloadPolicy Gate for
     *                                                   `artwork.download_enabled`.
     *                                                   Null (legacy construction)
     *                                                   substitutes a store-less
     *                                                   policy = downloads enabled,
     *                                                   the pre-setting behaviour.
     * @param MetadataOverwritePolicy|null $overwritePolicy Gate for
     *                                                   `metadata.overwrite_existing`.
     *                                                   Null (legacy construction /
     *                                                   unit tests) substitutes a
     *                                                   store-less policy = overwrite
     *                                                   ENABLED, the pre-setting
     *                                                   behaviour (unconditional
     *                                                   array_merge).
     *
     * @since 0.21.0
     */
    public function __construct(
        ItemRepository $items,
        MovieMetadataResolver $resolver,
        ?SeriesMetadataResolver $seriesResolver = null,
        ?StructuredLogger $logger = null,
        ?TmdbProvider $tmdb = null,
        ?array $noiseSuffixes = null,
        ?LibraryManager $libraries = null,
        ?LibraryPriorityResolver $priorityResolver = null,
        ?ThemeMusicResolver $themeMusic = null,
        ?FuzzyMatcher $fuzzyMatcher = null,
        ?ArtworkStorage $artworkStorage = null,
        bool $forceRefresh = false,
        ?ArtworkDownloadPolicy $artworkDownloadPolicy = null,
        ?MetadataOverwritePolicy $overwritePolicy = null
    ) {
        $this->items = $items;
        $this->resolver = $resolver;
        $this->seriesResolver = $seriesResolver;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
        $this->tmdb = $tmdb;
        // Drop a null/empty injected list so the normalizer falls back to the
        // built-in const (an empty admin override must never blank the list).
        $this->noiseSuffixes = ($noiseSuffixes === null || $noiseSuffixes === [])
            ? null
            : array_values($noiseSuffixes);
        $this->libraries = $libraries;
        $this->priorityResolver = $priorityResolver;
        $this->themeMusic = $themeMusic;
        $this->fuzzyMatcher = $fuzzyMatcher;
        $this->artworkStorage = $artworkStorage;
        $this->forceRefresh = $forceRefresh;
        // Never null internally: a legacy construction that omits the policy
        // gets a store-less one, which yields the shipped default (downloads
        // ENABLED) — i.e. exactly the behaviour before the gate existed.
        $this->artworkDownloadPolicy = $artworkDownloadPolicy ?? new ArtworkDownloadPolicy();
        // Never null internally: a legacy construction / unit test that omits
        // the policy gets a store-less one, which yields the shipped default
        // (overwrite ENABLED) — i.e. exactly the unconditional array_merge
        // behaviour before the gate existed.
        $this->overwritePolicy = $overwritePolicy ?? new MetadataOverwritePolicy();
    }

    /**
     * The SINGLE decision point behind `metadata.overwrite_existing`.
     *
     * Returns TRUE when a (re)match must SKIP an item WHOLESALE because the
     * operator has turned overwrite OFF and the item ALREADY carries resolved
     * metadata (a `metadata_refreshed_at` stamp is present) — mirroring the
     * manual-override short-circuit in {@see matchItem()}. There is no per-field
     * provenance in the pipeline, so "don't overwrite" can only mean a
     * whole-item skip; a never-resolved item still returns false here and is
     * enriched normally (nothing to protect).
     *
     * At the shipped default (overwrite ON) this ALWAYS returns false, so
     * nothing is skipped and behaviour is byte-for-byte identical to before this
     * setting existed (plan_settings.md §4 rule 7).
     *
     * Consulted at the THREE (re)resolve entry points — {@see matchItem()},
     * {@see matchSeries()} and {@see applyMatchResolved()} — which between them
     * gate EVERY `array_merge($existing, $resolved)` metadata-overwrite site in
     * this class (movie item, series root, season patch on both the interactive
     * and batch paths, episode patch, and all four interactive-apply branches),
     * so no single site can drift from the others.
     *
     * @param array<string, mixed> $item Hydrated media-item row (must carry the
     *        `metadata_refreshed_at` column, as every row from
     *        {@see ItemRepository::getByLibrary()} / {@see ItemRepository::findById()}
     *        does). An absent/empty stamp is treated as "never resolved", which
     *        fails safe toward the historical overwrite behaviour.
     *
     * @return bool True to skip the (re)match for this item.
     */
    private function shouldSkipOverwrite(array $item): bool
    {
        if ($this->overwritePolicy->overwriteExisting()) {
            return false;
        }

        // Mirror the batch pre-skip predicate in matchBatchConcurrently(): an
        // item that has been through persistMetadata() carries a
        // metadata_refreshed_at stamp. Absent/empty => never resolved => still
        // enrich it (there is nothing to preserve).
        $refreshedAt = $item['metadata_refreshed_at'] ?? null;
        if (is_string($refreshedAt)) {
            $refreshedAt = trim($refreshedAt);
            return $refreshedAt !== '' && $refreshedAt !== '0000-00-00 00:00:00';
        }

        return $refreshedAt !== null;
    }

    /**
     * Toggle force-refresh mode for the next {@see self::matchLibrary()} run.
     *
     * When true, {@see self::matchLibrary()} re-processes items that already have
     * metadata (`metadata_refreshed_at IS NOT NULL`) instead of skipping them, and
     * {@see self::countMatchable()} counts ALL top-level items (not just unmatched)
     * so the reported progress percentage stays correct. Used by the
     * `metadata_refresh` scan-job type (see {@see \Phlix\Media\Library\LibraryScanWorker})
     * to expose a force re-match through the same async job pipeline as `metadata`.
     *
     * @param bool $forceRefresh Whether to re-process already-matched items.
     */
    public function setForceRefresh(bool $forceRefresh): void
    {
        $this->forceRefresh = $forceRefresh;
    }

    /**
     * Match metadata for every movie in a library.
     *
     * Pages through {@see ItemRepository::getByLibrary()} in fixed batches. For
     * each MOVIE-type item it resolves details from the stored name/year/external
     * ids and, on a hit, merges the result into the item's `metadata_json` and
     * persists it (stamping `metadata_refreshed_at`). Non-movie items are
     * skipped. A per-item exception is logged and swallowed so one bad item
     * cannot abort the run.
     *
     * @param string $libraryId Target library UUID.
     *
     * @return array{matched: int, processed: int} `processed` = movie items
     *         examined; `matched` = movie items whose metadata was updated.
     *
     * @since 0.21.0
     */
    public function matchLibrary(string $libraryId, ?callable $onProgress = null): array
    {
        $matched = 0;
        $processed = 0;
        $offset = 0;

        // Effective per-library priority config (item 5): the library's
        // `options.metadata_priority` override layered over the global default.
        // Computed ONCE here (not per item/page) and passed into every
        // resolve() call in this run. Stays null when the deps are absent
        // (legacy construction / unit tests) OR the library has no override, in
        // which case the resolvers fall back to their injected global config —
        // behaviour is then exactly as before.
        // Load the library row ONCE per run (best-effort) and derive both the
        // per-library metadata-priority override AND the image-type selection
        // from it, so a single getLibrary() call serves both features.
        $libraryRow = $this->loadLibraryRow($libraryId);
        $effective = $this->effectivePriorityFor($libraryRow);

        // Effective per-library image-type selection (M5): the library's
        // `options.image_types` (defaults when absent). Computed ONCE here and
        // consulted by persistMetadata() to drop disabled flat image keys
        // (poster_url/backdrop_url) for every item persisted in this run. Null
        // when the LibraryManager dep is absent (legacy) or the library cannot
        // be loaded — persistMetadata() then filters nothing, exactly as before.
        $this->activeImageTypes = $this->enabledImageTypesFor($libraryRow);

        // Fresh per-run artwork dedup map (see property docblock): inherited
        // series posters recur across every season/episode, so cache the first
        // download's local URLs and reuse them instead of re-fetching.
        $this->artworkPathCache = [];
        $this->logoPathCache = [];

        // Progress denominator: the count of top-level items (movies + series)
        // the flat pass visits. Reported via $onProgress so the worker can stamp
        // it onto the job row and the UI can render a percentage.
        $total = $this->countMatchable($libraryId);

        // A start marker so the log shows the run has begun, not just its end.
        $this->logger->info('LibraryMetadataMatcher: library match started', [
            'library_id' => $libraryId,
            'total' => $total,
        ]);

        while (true) {
            $batch = $this->items->getByLibrary($libraryId, self::PAGE_SIZE, $offset);
            if ($batch === []) {
                break;
            }

            // SV-3.5: bounded-concurrency per-batch fan-out using the
            // Channel-as-semaphore idiom. Handles both serial (non-coroutine)
            // and concurrent (coroutine) paths internally, preserving exact
            // sequential behaviour outside a Swoole context.
            $batchResult = $this->matchBatchConcurrently($batch, $effective, $libraryId);
            $matched += $batchResult['matched'];
            $processed += $batchResult['processed'];

            // Per-batch summary at INFO so the run is visibly advancing even when
            // the per-item DEBUG lines are filtered out.
            $this->logger->info('LibraryMetadataMatcher: library match progress', [
                'library_id' => $libraryId,
                'processed' => $processed,
                'matched' => $matched,
            ]);

            if ($onProgress !== null) {
                $onProgress($processed, max($total, $processed), $matched);
            }

            // The driver may return a short final page; stop once it does.
            if (count($batch) < self::PAGE_SIZE) {
                break;
            }

            $offset += self::PAGE_SIZE;
        }

        $this->logger->info('LibraryMetadataMatcher: library match complete', [
            'library_id' => $libraryId,
            'processed' => $processed,
            'matched' => $matched,
        ]);

        // Clear the per-run image-type filter so a later call (e.g. an
        // interactive applyMatch on an item from a different library) never
        // inherits this run's selection.
        $this->activeImageTypes = null;

        // Release the per-run artwork dedup map so its URLs never leak into a
        // later run for a different library and its memory is reclaimed.
        $this->artworkPathCache = [];
        $this->logoPathCache = [];

        return ['matched' => $matched, 'processed' => $processed];
    }

    /**
     * Load a library row (best-effort) for the per-library override + image-type
     * selection. Returns null when the {@see LibraryManager} dep is absent
     * (legacy construction / unit tests) or the load fails — both derivations
     * then fall back to their global/no-filter defaults. Called ONCE per run so
     * a single getLibrary() serves both features.
     *
     * @param string $libraryId Target library UUID.
     *
     * @return array<string, mixed>|null The hydrated library row, or null.
     */
    private function loadLibraryRow(string $libraryId): ?array
    {
        if ($this->libraries === null) {
            return null;
        }
        try {
            $row = $this->libraries->getLibrary($libraryId);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            $this->logger->warning('LibraryMetadataMatcher: library load failed; using global/no-filter defaults', [
                'library_id' => $libraryId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build the effective per-library {@see PriorityConfig} for a match run
     * (item 5): the library's `options.metadata_priority` override layered over
     * the global default, from an already-loaded library row.
     *
     * Returns null when the priority-resolver dep is absent (legacy construction
     * / unit tests) OR no row was loaded — the resolvers then use their injected
     * global config, so behaviour is exactly as before.
     *
     * @param array<string, mixed>|null $libraryRow Pre-loaded library row (or null).
     *
     * @return PriorityConfig|null Effective config, or null to use the global.
     */
    private function effectivePriorityFor(?array $libraryRow): ?PriorityConfig
    {
        if ($this->priorityResolver === null) {
            return null;
        }

        $override = null;
        if ($libraryRow !== null) {
            $candidate = $libraryRow['metadata_priority'] ?? null;
            if (is_array($candidate) && $candidate !== []) {
                /** @var array<string, list<string>> $candidate */
                $override = $candidate;
            }
        }

        return $this->priorityResolver->effectiveFor($override);
    }

    /**
     * The image types (M5) enabled for a library, from an already-loaded library
     * row's `options.image_types` selection (defaults when absent). Returns null
     * when no row was loaded (LibraryManager dep absent / load failed) —
     * {@see persistMetadata()} then does NOT filter image keys, so behaviour is
     * exactly as before.
     *
     * @param array<string, mixed>|null $libraryRow Pre-loaded library row (or null).
     *
     * @return list<string>|null Enabled image types, or null to skip filtering.
     */
    private function enabledImageTypesFor(?array $libraryRow): ?array
    {
        if ($libraryRow === null) {
            return null;
        }
        $options = $libraryRow['options'] ?? null;
        $options = is_array($options) ? $this->stringKeyed($options) : [];
        return ImageType::enabledForOptions($options);
    }

    /**
     * Count the top-level (movie + series) items a library match will visit —
     * the denominator for progress reporting. Best-effort: returns 0 on any
     * query error so a failed count never aborts the match run.
     */
    private function countMatchable(string $libraryId): int
    {
        try {
            // Mirror the in-batch skip in matchBatchConcurrently(): when NOT
            // forcing a refresh, already-matched items (metadata_refreshed_at IS
            // NOT NULL) are skipped, so they must be excluded from the progress
            // denominator too — otherwise the percentage never reaches 100%. When
            // forcing, EVERY top-level item is re-processed, so count them all.
            $params = ['topLevel' => true, 'limit' => 1];
            if (!$this->forceRefresh) {
                $params['match'] = 'unmatched';
            }
            $result = $this->items->query($params, $libraryId);
            $total = $result['total'] ?? 0;
            if (is_int($total)) {
                return $total;
            }
            return is_numeric($total) ? (int) $total : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Map a media-item `type` to the TMDB search/apply mode (`tv` or `movie`).
     *
     * series/season/episode all resolve against TMDB TV; everything else (movie,
     * video, …) resolves against TMDB movie.
     *
     * @param mixed $type The item's stored `type` column value.
     *
     * @return string `'tv'` or `'movie'`.
     *
     * @since 0.25.0
     */
    public static function modeForType(mixed $type): string
    {
        return (is_string($type) && in_array($type, ['series', 'season', 'episode'], true)) ? 'tv' : 'movie';
    }

    /**
     * Search TMDB for candidate matches for the interactive per-item match UI.
     *
     * Stateless — performs NO persistence. Maps raw TMDB movie/tv search results
     * into a stable candidate shape the UI renders as a pick-list.
     *
     * @param string $query Search query (e.g. the item's title).
     * @param string $type  `'tv'` or `'movie'`; anything else is treated as movie.
     * @param int|null $year Optional release / first-air year to bias the search.
     * @param int    $limit Maximum candidates to return (clamped to [1, 50]).
     *
     * @return list<array{
     *     tmdb_id: string,
     *     type: string,
     *     title: string,
     *     year: int|null,
     *     overview: string,
     *     poster_url: string|null,
     *     backdrop_url: string|null,
     *     vote_average: float
     * }> Candidate matches (possibly empty).
     *
     * @throws TmdbUnconfiguredException When no TMDB provider is wired.
     *
     * @since 0.25.0
     */
    public function searchCandidates(string $query, string $type, ?int $year = null, int $limit = 20): array
    {
        $tmdb = $this->requireTmdb();
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $mode = $type === 'tv' ? 'tv' : 'movie';

        $candidates = [];
        if ($mode === 'tv') {
            $options = $year !== null ? ['first_air_date_year' => $year] : [];
            foreach ($tmdb->searchTv($query, $options) as $row) {
                $candidates[] = [
                    'tmdb_id' => MetadataValue::asString($row['id'] ?? null),
                    'type' => 'tv',
                    'title' => MetadataValue::asString($row['name'] ?? null),
                    'year' => $this->yearFromDate($row['first_air_date'] ?? null),
                    'overview' => MetadataValue::asString($row['overview'] ?? null),
                    'poster_url' => $this->imageUrl($row['poster_path'] ?? null),
                    'backdrop_url' => $this->imageUrl($row['backdrop_path'] ?? null),
                    'vote_average' => MetadataValue::asFloat($row['vote_average'] ?? null),
                ];
            }
        } else {
            $options = $year !== null ? ['year' => $year] : [];
            foreach ($tmdb->search($query, $options) as $row) {
                $candidates[] = [
                    'tmdb_id' => MetadataValue::asString($row['id'] ?? null),
                    'type' => 'movie',
                    'title' => MetadataValue::asString($row['title'] ?? null),
                    'year' => $this->yearFromDate($row['release_date'] ?? null),
                    'overview' => MetadataValue::asString($row['overview'] ?? null),
                    'poster_url' => $this->imageUrl($row['poster_path'] ?? null),
                    'backdrop_url' => $this->imageUrl($row['backdrop_path'] ?? null),
                    'vote_average' => MetadataValue::asFloat($row['vote_average'] ?? null),
                ];
            }
        }

        // Drop entries with no usable id, then cap.
        $candidates = array_values(array_filter(
            $candidates,
            static fn(array $c): bool => $c['tmdb_id'] !== '',
        ));

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Apply a chosen TMDB match to a single media item, persisting its metadata.
     *
     * Mirrors the whole-library matcher's persistence (`persistMetadata`,
     * `enrichSeriesChildren`) so the DRY persistence path is reused:
     *  - `movie` mode → fetch `/movie/{id}` details and merge+persist onto the item.
     *  - `tv` mode on a `series` item → fetch `/tv/{id}` details, persist, then
     *    enrich every season/episode child (same as a whole-library series match).
     *  - `tv` mode on a `season` item → persist the season's poster/overview from
     *    `/tv/{id}/season/{n}` (season number from the item's metadata) and enrich
     *    that season's episode children.
     *  - `tv` mode on an `episode` item → persist that episode's title/still/
     *    overview/air-date from the right season/episode of `/tv/{id}`.
     *
     * @param string $itemId Target media-item id.
     * @param string $tmdbId Chosen TMDB id (movie or series).
     * @param string $type   `'tv'` or `'movie'`; anything else is treated as movie.
     *
     * @return array{
     *     item_id: string,
     *     mode: string,
     *     tmdb_id: string,
     *     matched: bool,
     *     children_enriched: int
     * } What was applied. `matched` is false when TMDB returned no usable details.
     *
     * @throws TmdbUnconfiguredException When no TMDB provider is wired.
     *
     * @since 0.25.0
     */
    public function applyMatch(string $itemId, string $tmdbId, string $type): array
    {
        $tmdb = $this->requireTmdb();
        $tmdbId = trim($tmdbId);
        if ($itemId === '' || $tmdbId === '') {
            return [
                'item_id' => $itemId,
                'mode' => $type,
                'tmdb_id' => $tmdbId,
                'matched' => false,
                'children_enriched' => 0,
            ];
        }

        $item = $this->items->findById($itemId);
        if ($item === null) {
            return [
                'item_id' => $itemId,
                'mode' => $type,
                'tmdb_id' => $tmdbId,
                'matched' => false,
                'children_enriched' => 0,
            ];
        }

        // Apply the item's library's image-type selection (M5) for the duration
        // of this interactive apply so persistMetadata() drops disabled flat
        // image keys (poster_url/backdrop_url) here too — not just in the batch
        // matchLibrary() run. Reset in the finally so it never leaks to a later
        // call. Null (no LibraryManager / unloadable library) → no filtering.
        $libraryId = is_string($item['library_id'] ?? null) ? $item['library_id'] : '';
        $this->activeImageTypes = $libraryId !== ''
            ? $this->enabledImageTypesFor($this->loadLibraryRow($libraryId))
            : null;

        try {
            return $this->applyMatchResolved($item, $itemId, $tmdbId, $tmdb, $type);
        } finally {
            $this->activeImageTypes = null;
        }
    }

    /**
     * Inner body of {@see applyMatch()} — resolves + persists the chosen TMDB
     * match. Split out so {@see applyMatch()} can wrap it in a try/finally that
     * always clears the per-run image-type filter ({@see $activeImageTypes}).
     *
     * @param array<string, mixed> $item   Hydrated media-item row (already loaded).
     * @param string               $itemId Target media-item id.
     * @param string               $tmdbId Chosen TMDB id (already trimmed, non-empty).
     * @param TmdbProvider         $tmdb   The wired TMDB provider.
     * @param string               $type   `'tv'` or `'movie'`.
     *
     * @return array{
     *     item_id: string,
     *     mode: string,
     *     tmdb_id: string,
     *     matched: bool,
     *     children_enriched: int
     * }
     */
    private function applyMatchResolved(
        array $item,
        string $itemId,
        string $tmdbId,
        TmdbProvider $tmdb,
        string $type
    ): array {
        $itemType = $item['type'] ?? null;
        $mode = $type === 'tv' ? 'tv' : 'movie';

        // `metadata.overwrite_existing` gate: when overwrite is off and this
        // item already has resolved metadata, skip the interactive apply
        // WHOLESALE — none of this method's four array_merge($existing,$resolved)
        // branches, nor applyToSeason()/applyToEpisode()/enrichSeriesChildren()
        // downstream, run. No-op at the shipped default (overwrite on).
        if ($this->shouldSkipOverwrite($item)) {
            $this->logger->info('LibraryMetadataMatcher: interactive apply skipped — overwrite disabled, item already resolved', [
                'item_id' => $itemId,
                'tmdb_id' => $tmdbId,
                'mode' => $mode,
            ]);
            return [
                'item_id' => $itemId,
                'mode' => $mode,
                'tmdb_id' => $tmdbId,
                'matched' => false,
                'children_enriched' => 0,
            ];
        }

        $existing = $this->extractMetadata($item);
        $childrenEnriched = 0;
        $matched = false;

        if ($mode === 'tv') {
            $details = $tmdb->getTvDetails($tmdbId);
            if ($details !== []) {
                $resolved = $this->formatSeriesDetails($tmdbId, $details);

                $inheritance = $this->seriesInheritance($resolved);
                if ($itemType === 'series') {
                    // Resolve + persist the series-root theme (local theme file, else
                    // the Plex archive via the TVDB id in external_ids), then thread
                    // the resolved theme url into $inheritance so seasons/episodes
                    // inherit it — mirroring the batch matchSeries() path exactly.
                    $themed = $this->applyThemeAudio($item, array_merge($existing, $resolved));
                    $this->persistMetadata($itemId, $themed);
                    $seriesTheme = $this->stringOrNull($themed['theme_audio_url'] ?? null);
                    if ($seriesTheme !== null) {
                        $inheritance['theme_audio_url'] = $seriesTheme;
                    }
                    $childrenEnriched = $this->enrichSeriesChildren(
                        $itemId,
                        $tmdbId,
                        $this->stringOrNull($resolved['poster_url'] ?? null),
                        $this->stringOrNull($resolved['backdrop_url'] ?? null),
                        $this->stringOrNull($resolved['overview'] ?? null),
                        $inheritance,
                    );
                } elseif ($itemType === 'season') {
                    // Resolve the season's theme (local theme file next to the season
                    // folder, else the Plex archive via the series' TVDB id) and thread
                    // it into $inheritance so the season's episodes inherit it on
                    // interactive re-match too.
                    $themed = $this->applyThemeAudio($item, array_merge($existing, $resolved));
                    $seasonTheme = $this->stringOrNull($themed['theme_audio_url'] ?? null);
                    if ($seasonTheme !== null) {
                        $inheritance['theme_audio_url'] = $seasonTheme;
                    }
                    $childrenEnriched = $this->applyToSeason(
                        $itemId,
                        $tmdbId,
                        $existing,
                        $this->stringOrNull($resolved['poster_url'] ?? null),
                        $this->stringOrNull($resolved['backdrop_url'] ?? null),
                        $this->stringOrNull($resolved['overview'] ?? null),
                        $inheritance,
                    );
                } elseif ($itemType === 'episode') {
                    $this->applyToEpisode(
                        $itemId,
                        $item,
                        $tmdbId,
                        $existing,
                        $this->stringOrNull($resolved['poster_url'] ?? null),
                        $this->stringOrNull($resolved['overview'] ?? null),
                        $inheritance,
                    );
                } else {
                    // A non-hierarchy item matched against TV: persist series-level
                    // metadata (with theme_audio_url — it is series-typed for the
                    // resolver's Plex-fallback gate only when $itemType==='series',
                    // so this path stays local-theme-only unless it is a series).
                    $this->persistMetadata(
                        $itemId,
                        $this->applyThemeAudio($item, array_merge($existing, $resolved))
                    );
                }
                $matched = true;
            }
        } else {
            $details = $tmdb->getDetails($tmdbId);
            if ($details !== []) {
                $resolved = $this->formatMovieDetails($tmdbId, $details, $existing);
                // Movie interactive apply: local theme only (non-series type).
                $this->persistMetadata(
                    $itemId,
                    $this->applyThemeAudio($item, array_merge($existing, $resolved))
                );
                $matched = true;
            }
        }

        $this->logger->info('LibraryMetadataMatcher: interactive apply', [
            'item_id' => $itemId,
            'tmdb_id' => $tmdbId,
            'mode' => $mode,
            'matched' => $matched,
            'children_enriched' => $childrenEnriched,
        ]);

        return [
            'item_id' => $itemId,
            'mode' => $mode,
            'tmdb_id' => $tmdbId,
            'matched' => $matched,
            'children_enriched' => $childrenEnriched,
        ];
    }

    /**
     * Apply a chosen series id to a single `season` item: persist the season's
     * poster/overview + enrich its episode children.
     *
     * @param array<string, mixed> $existing          Decoded existing season metadata.
     * @param array{
     *     genres?: list<string>,
     *     tags?: list<string>,
     *     backdrop_url?: string,
     *     theme_audio_url?: string
     * } $seriesInheritance
     *     Series-level fields (genres/tags/backdrop) episodes inherit.
     *
     * @return int Episodes enriched.
     */
    private function applyToSeason(
        string $seasonId,
        string $tmdbId,
        array $existing,
        ?string $seriesPoster,
        ?string $seriesBackdrop,
        ?string $seriesOverview,
        array $seriesInheritance = []
    ): int {
        $seasonNumber = $this->intMeta($existing, 'season');
        $seasonData = ($seasonNumber !== null && $this->seriesResolver !== null)
            ? $this->seriesResolver->resolveSeasonEpisodes($tmdbId, $seasonNumber)
            : null;

        $this->persistMetadata(
            $seasonId,
            array_merge($existing, $this->seasonPatch($seasonData, $seriesPoster, $seriesBackdrop, $seriesOverview))
        );

        $enriched = 0;
        foreach ($this->items->findByParent($seasonId) as $episode) {
            $this->enrichEpisode($episode, $seasonData, $seriesPoster, $seriesOverview, $seriesInheritance);
            $enriched++;
        }
        return $enriched;
    }

    /**
     * Apply a chosen series id to a single `episode` item: persist that episode's
     * title/still/overview/air-date from the right season/episode.
     *
     * @param array<string, mixed> $episode  Hydrated episode row.
     * @param array<string, mixed> $existing Decoded existing episode metadata.
     * @param array{
     *     genres?: list<string>,
     *     tags?: list<string>,
     *     backdrop_url?: string,
     *     theme_audio_url?: string
     * } $seriesInheritance
     *     Series-level fields (genres/tags/backdrop) the episode inherits.
     */
    private function applyToEpisode(
        string $episodeId,
        array $episode,
        string $tmdbId,
        array $existing,
        ?string $seriesPoster,
        ?string $seriesOverview,
        array $seriesInheritance = []
    ): void {
        $seasonNumber = $this->intMeta($existing, 'season');
        $seasonData = ($seasonNumber !== null && $this->seriesResolver !== null)
            ? $this->seriesResolver->resolveSeasonEpisodes($tmdbId, $seasonNumber)
            : null;
        $this->enrichEpisode($episode, $seasonData, $seriesPoster, $seriesOverview, $seriesInheritance);
    }

    /**
     * Format raw TMDB `/tv/{id}` details into a mergeable series-metadata array
     * (same shape {@see SeriesMetadataResolver::resolve()} produces).
     *
     * @param array<string, mixed> $details Formatted TMDB TV details (from getTvDetails).
     *
     * @return array<string, mixed>
     */
    private function formatSeriesDetails(string $tmdbId, array $details): array
    {
        $result = [
            'external_ids' => array_filter([
                'tmdb' => $tmdbId,
                'imdb' => MetadataValue::asNullableString($details['imdb_id'] ?? null),
                // TheTVDB id (from getTvDetails `external_ids`) — powers the
                // theme-music (M3) Plex-archive fallback in the interactive apply
                // path, matching the whole-library SeriesMetadataResolver output.
                'tvdb' => MetadataValue::asNullableString($details['tvdb_id'] ?? null),
            ], static fn(?string $v): bool => $v !== null && $v !== ''),
            'tmdb_id' => $tmdbId,
            'sources' => ['tmdb'],
        ];

        $name = MetadataValue::asNullableString($details['name'] ?? null);
        if ($name !== null) {
            $result['title'] = $name;
        }
        $overview = MetadataValue::asNullableString($details['overview'] ?? null);
        if ($overview !== null) {
            $result['overview'] = $overview;
        }
        $poster = $this->imageUrl($details['poster_path'] ?? null);
        if ($poster !== null) {
            $result['poster_url'] = $poster;
        }
        $backdrop = $this->imageUrl($details['backdrop_path'] ?? null);
        if ($backdrop !== null) {
            $result['backdrop_url'] = $backdrop;
        }
        $genres = MetadataValue::asList($details['genres'] ?? null);
        $genreNames = array_values(array_filter(
            array_map(static fn(mixed $g): string => MetadataValue::asString($g), $genres),
            static fn(string $g): bool => $g !== '',
        ));
        if ($genreNames !== []) {
            $result['genres'] = $genreNames;
        }
        $tags = $this->stringList($details['tags'] ?? null);
        if ($tags !== []) {
            $result['tags'] = $tags;
        }
        $year = MetadataValue::asNullableInt($details['year'] ?? null);
        if ($year !== null) {
            $result['year'] = $year;
        }
        $rating = MetadataValue::asNullableString($details['official_rating'] ?? null);
        if ($rating !== null) {
            $result['official_rating'] = $rating;
        }
        $actors = MetadataValue::actorNames($details['actors'] ?? null);
        if ($actors !== []) {
            $result['actors'] = $actors;
        }
        // Primary trailer (already validated in TmdbProvider). Mirror the
        // batch-scan path so an item matched via the interactive apply flow
        // gets the same trailer_* fields.
        $this->copyTrailerFields($details, $result);

        return $result;
    }

    /**
     * Format raw TMDB `/movie/{id}` details into a mergeable movie-metadata array
     * (same descriptive keys {@see MovieMetadataResolver::resolve()} produces).
     *
     * @param array<string, mixed> $details  Formatted TMDB movie details (from getDetails).
     * @param array<string, mixed> $existing Existing metadata (its external_ids are preserved/merged under).
     *
     * @return array<string, mixed>
     */
    private function formatMovieDetails(string $tmdbId, array $details, array $existing): array
    {
        $imdbId = MetadataValue::asNullableString($details['imdb_id'] ?? null);

        $existingExternal = $this->extractExternalIds($existing);
        $discovered = array_filter([
            'tmdb' => $tmdbId,
            'imdb' => $imdbId,
        ], static fn(?string $v): bool => $v !== null && $v !== '');

        $result = [
            'external_ids' => array_merge($existingExternal, $discovered),
            'tmdb_id' => $tmdbId,
            'sources' => ['tmdb'],
        ];

        $title = MetadataValue::asNullableString($details['name'] ?? null);
        if ($title !== null) {
            $result['title'] = $title;
        }
        $overview = MetadataValue::asNullableString($details['overview'] ?? null);
        if ($overview !== null) {
            $result['overview'] = $overview;
        }
        $poster = $this->imageUrl($details['poster_path'] ?? null);
        if ($poster !== null) {
            $result['poster_url'] = $poster;
        }
        $backdrop = $this->imageUrl($details['backdrop_path'] ?? null);
        if ($backdrop !== null) {
            $result['backdrop_url'] = $backdrop;
        }
        $genres = $this->stringList($details['genres'] ?? null);
        if ($genres !== []) {
            $result['genres'] = $genres;
        }
        $year = MetadataValue::asNullableInt($details['year'] ?? null);
        if ($year !== null) {
            $result['year'] = $year;
        }
        $ticks = MetadataValue::asNullableInt($details['runtime_ticks'] ?? null);
        if ($ticks !== null && $ticks > 0) {
            $result['runtime'] = (int) ($ticks / 600000000);
        }
        $director = MetadataValue::asNullableString($details['director'] ?? null);
        if ($director !== null) {
            $result['director'] = $director;
        }
        // Content certification parsed from TMDB movie `release_dates`
        // (Phase C). Mirrors the series path above so a refresh persists the
        // movie cert into `metadata_json.official_rating`, where the shaper and
        // the materialized `content_rating` column both read it.
        $rating = MetadataValue::asNullableString($details['official_rating'] ?? null);
        if ($rating !== null) {
            $result['official_rating'] = $rating;
        }
        $actors = MetadataValue::actorNames($details['actors'] ?? null);
        if ($actors !== []) {
            $result['actors'] = $actors;
        }
        // Primary trailer (already validated in TmdbProvider). Mirror the
        // batch-scan path so an item matched via the interactive apply flow
        // gets the same trailer_* fields.
        $this->copyTrailerFields($details, $result);

        return $result;
    }

    /**
     * Copy the primary-trailer AND title-logo passthrough fields from raw TMDB
     * `$details` (as produced by getDetails/getTvDetails) into a formatted
     * metadata `$result`, only when present and non-empty. Shared by the movie and
     * series apply-match formatters so both mirror the batch-scan output (the
     * local logo caching then happens in persistMetadata → cacheLogoLocally).
     *
     * @param array<string, mixed> $details Raw TMDB details.
     * @param array<string, mixed> $result  Formatted result to augment (by reference).
     */
    private function copyTrailerFields(array $details, array &$result): void
    {
        foreach (['trailer_url', 'trailer_key', 'trailer_site', 'logo_url'] as $field) {
            $value = MetadataValue::asNullableString($details[$field] ?? null);
            if ($value !== null) {
                $result[$field] = $value;
            }
        }
    }

    /**
     * Narrow a mixed value to a de-duplicated list of non-empty strings (genres).
     *
     * @param mixed $value
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            $name = MetadataValue::asNullableString($entry);
            if ($name !== null && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Build a full TMDB image URL from a `/path.jpg` fragment, or null when absent.
     */
    private function imageUrl(mixed $path): ?string
    {
        $clean = MetadataValue::asNullableString($path);
        if ($clean === null) {
            return null;
        }
        return $this->imageBaseUrl . '/w500' . $clean;
    }

    /**
     * Extract a 4-digit year from a TMDB date string (`YYYY-MM-DD`), or null.
     */
    private function yearFromDate(mixed $date): ?int
    {
        $clean = MetadataValue::asNullableString($date);
        if ($clean === null) {
            return null;
        }
        if (preg_match('/^(\d{4})/', $clean, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Return the wired TMDB provider, or throw when interactive search/apply is
     * unavailable because no provider is wired OR no API key is configured
     * (an empty key would otherwise fail TMDB auth and surface as an empty
     * result / no-match instead of the clear "configure TMDB" signal).
     *
     * @throws TmdbUnconfiguredException
     */
    private function requireTmdb(): TmdbProvider
    {
        if ($this->tmdb === null || !$this->tmdb->hasApiKey()) {
            throw new TmdbUnconfiguredException();
        }
        return $this->tmdb;
    }

    /**
     * Resolve + persist metadata for a single (already movie-typed) item.
     *
     * @param array<string, mixed> $item             Hydrated media-item row.
     * @param PriorityConfig|null  $priorityOverride Effective per-library priority
     *     config (item 5); when null the resolver's injected global config drives
     *     the source order.
     *
     * @return bool `true` when the resolver matched and the item was persisted,
     *              `false` when there was no usable id/name or no match.
     */
    private function matchItem(array $item, ?PriorityConfig $priorityOverride = null): bool
    {
        $id = $item['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return false;
        }

        // `metadata.overwrite_existing` gate: when overwrite is off and this
        // item already has resolved metadata, skip re-resolving/re-merging it
        // WHOLESALE (mirrors the manual-override short-circuit below). No-op at
        // the shipped default (overwrite on).
        if ($this->shouldSkipOverwrite($item)) {
            $this->logger->debug('LibraryMetadataMatcher: skip re-match — overwrite disabled, item already resolved', [
                'item_id' => $id,
            ]);
            return false;
        }

        $existingMetadata = $this->extractMetadata($item);

        $name = $this->extractName($item, $existingMetadata);
        if ($name === null) {
            return false;
        }

        $normalized = SceneFilenameNormalizer::normalize($name, $this->noiseSuffixes);
        if ($normalized['title'] !== '') {
            $name = $normalized['title'];
        }

        $year = $this->extractYear($existingMetadata);
        if ($year === null && $normalized['year'] !== null) {
            $year = $normalized['year'];
        }

        $externalIds = $this->extractExternalIds($existingMetadata);

        $this->logger->info('LibraryMetadataMatcher: resolving movie', [
            'title' => $name,
            'year' => $year,
            'external_ids' => $externalIds,
        ]);

        $resolved = $this->resolver->resolve($name, $year, $externalIds, $priorityOverride);
        if ($resolved === null) {
            $this->logger->info('LibraryMetadataMatcher: movie not matched', [
                'title' => $name,
                'year' => $year,
            ]);
            return false;
        }

        $resolvedExternalIds = is_array($resolved['external_ids'] ?? null) ? $resolved['external_ids'] : [];
        $this->logger->info('LibraryMetadataMatcher: movie matched', [
            'title' => $name,
            'year' => $year,
            'tmdb_id' => $resolved['tmdb_id'] ?? null,
            'imdb_id' => $resolvedExternalIds['imdb'] ?? null,
        ]);

        // P1-S5: If the resolved TMDB/IMDb id has a manual override, skip the
        // automatic match — the user's explicit override takes precedence and
        // prevents the automatic resolver from overwriting it.
        if ($this->fuzzyMatcher !== null) {
            $resolvedExternalIds = is_array($resolved['external_ids'] ?? null) ? $resolved['external_ids'] : [];
            foreach ($resolvedExternalIds as $provider => $providerId) {
                $override = $this->fuzzyMatcher->getManualOverride(
                    is_string($provider) ? $provider : '',
                    is_string($providerId) ? $providerId : ''
                );
                if ($override !== null) {
                    $this->logger->debug('LibraryMetadataMatcher: skipping due to manual override', [
                        'item_id' => $id,
                        'provider' => $provider,
                        'provider_id' => $providerId,
                        'overridden_to' => $override['media_item_id'],
                    ]);
                    return false;
                }
            }
        }

        $merged = array_merge($existingMetadata, $resolved);

        // Movies: local theme file only (the resolver skips the Plex fallback for
        // non-series types), populating theme_audio_url (M3) when a theme.* sits
        // next to the film.
        $this->persistMetadata($id, $this->applyThemeAudio($item, $merged));

        return true;
    }

    /**
     * Resolve + persist a TV series and enrich its whole season/episode subtree.
     *
     * Matches the series against TMDB TV, persists its poster/overview/genres,
     * then walks its children: each season gets the series (or season) poster +
     * overview, and each episode gets its TMDB title/still/overview/air-date —
     * falling back to the series poster so nothing in the tree renders blank.
     *
     * @param array<string, mixed> $seriesItem       Hydrated `series`-type row.
     * @param PriorityConfig|null  $priorityOverride Effective per-library priority
     *     config (item 5); when null the series resolver's injected global config
     *     drives the genres mode.
     *
     * @return bool True when the series matched (and its subtree was enriched).
     */
    private function matchSeries(array $seriesItem, ?PriorityConfig $priorityOverride = null): bool
    {
        $resolver = $this->seriesResolver;
        if ($resolver === null) {
            return false;
        }

        $id = is_string($seriesItem['id'] ?? null) ? $seriesItem['id'] : '';
        if ($id === '') {
            return false;
        }

        // `metadata.overwrite_existing` gate: when overwrite is off and this
        // series already has resolved metadata, skip the WHOLE subtree — the
        // series root is not re-persisted and enrichSeriesChildren() (the batch
        // season/episode overwrite sites) never runs. No-op at the shipped
        // default (overwrite on).
        if ($this->shouldSkipOverwrite($seriesItem)) {
            $this->logger->debug('LibraryMetadataMatcher: skip series re-match — overwrite disabled, series already resolved', [
                'item_id' => $id,
            ]);
            return false;
        }

        $existing = $this->extractMetadata($seriesItem);

        // Prefer the folder-derived series title/year hint that the scanner
        // persists in series-per-directory mode (`series_title`/`year` on the
        // container's metadata). The folder name is far cleaner than the noisy
        // filename-derived title, so it drives the TMDB TV search directly with
        // no further scene-normalisation. Fall back to the legacy
        // name-+-normalise path when no hint is present.
        $hintTitle = $this->stringOrNull($existing['series_title'] ?? null);
        if ($hintTitle !== null) {
            $name = $hintTitle;
            $year = $this->extractYear($existing);
        } else {
            $name = $this->extractName($seriesItem, $existing);
            if ($name === null) {
                return false;
            }

            $normalized = SceneFilenameNormalizer::normalize($name, $this->noiseSuffixes);
            if ($normalized['title'] !== '') {
                $name = $normalized['title'];
            }
            $year = $this->extractYear($existing) ?? $normalized['year'];
        }

        $resolved = $resolver->resolve($name, $year, $priorityOverride);
        if ($resolved === null) {
            return false;
        }

        // Populate theme_audio_url (M3) on the series root before persisting —
        // local theme file next to the series folder, else the Plex archive keyed
        // on the TVDB id the series resolver threaded into external_ids. The themed
        // metadata is also the source of the theme url episodes/seasons inherit.
        $themed = $this->applyThemeAudio($seriesItem, array_merge($existing, $resolved));
        $this->persistMetadata($id, $themed);

        $tmdbId = $this->resolvedTmdbId($resolved);
        if ($tmdbId !== '') {
            $inheritance = $this->seriesInheritance($resolved);
            $seriesTheme = $this->stringOrNull($themed['theme_audio_url'] ?? null);
            if ($seriesTheme !== null) {
                $inheritance['theme_audio_url'] = $seriesTheme;
            }
            $this->enrichSeriesChildren(
                $id,
                $tmdbId,
                $this->stringOrNull($resolved['poster_url'] ?? null),
                $this->stringOrNull($resolved['backdrop_url'] ?? null),
                $this->stringOrNull($resolved['overview'] ?? null),
                $inheritance,
            );
        }

        return true;
    }

    /**
     * Enrich a series' seasons + episodes from TMDB, caching one season fetch per
     * season number.
     *
     * @param string      $seriesId          The series item id.
     * @param string      $tmdbId            Resolved TMDB series id.
     * @param string|null $seriesPoster      Series poster URL (episode/season fallback).
     * @param string|null $seriesBackdrop    Series backdrop URL.
     * @param string|null $seriesOverview    Series overview (episode/season fallback).
     * @param array{
     *     genres?: list<string>,
     *     tags?: list<string>,
     *     backdrop_url?: string,
     *     theme_audio_url?: string
     * } $seriesInheritance
     *     Series-level fields (genres, tags, backdrop) inherited by every episode.
     *
     * @return int Number of child nodes (seasons + episodes) enriched.
     */
    private function enrichSeriesChildren(
        string $seriesId,
        string $tmdbId,
        ?string $seriesPoster,
        ?string $seriesBackdrop,
        ?string $seriesOverview,
        array $seriesInheritance = []
    ): int {
        /** @var array<int, array<string, mixed>> $seasonCache */
        $seasonCache = [];
        $enriched = 0;

        foreach ($this->items->findByParent($seriesId) as $child) {
            $childType = $child['type'] ?? null;
            $childId = is_string($child['id'] ?? null) ? $child['id'] : '';
            if ($childId === '') {
                continue;
            }
            $childMeta = $this->extractMetadata($child);

            if ($childType === 'season') {
                $seasonData = $this->cachedSeason($tmdbId, $this->intMeta($childMeta, 'season'), $seasonCache);
                $this->persistMetadata(
                    $childId,
                    array_merge(
                        $childMeta,
                        $this->seasonPatch($seasonData, $seriesPoster, $seriesBackdrop, $seriesOverview)
                    )
                );
                $enriched++;
                foreach ($this->items->findByParent($childId) as $episode) {
                    $this->enrichEpisode($episode, $seasonData, $seriesPoster, $seriesOverview, $seriesInheritance);
                    $enriched++;
                }
            } elseif ($childType === 'episode') {
                $seasonData = $this->cachedSeason($tmdbId, $this->intMeta($childMeta, 'season'), $seasonCache);
                $this->enrichEpisode($child, $seasonData, $seriesPoster, $seriesOverview, $seriesInheritance);
                $enriched++;
            }
        }

        return $enriched;
    }

    /**
     * Persist an episode's TMDB title/still/overview/air-date + cast/crew/rating,
     * inheriting the series' genres/tags/backdrop, falling back to the
     * season/series poster + series overview so it never renders blank.
     *
     * Episode cast is (season regulars ∪ that episode's guest stars) and crew is
     * the episode's own key crew — both in the SAME canonical `{name, role/job,
     * profile_url}` shape movies/series use, so {@see \Phlix\Media\Library\MediaItemShaper}
     * renders them with no shaper change. `genres`, `tags` and `backdrop_url` are
     * inherited from the parent series record (episodes carry no TMDB genres/tags
     * of their own).
     *
     * @param array<string, mixed>      $episode        Hydrated episode row.
     * @param array<string, mixed>|null $seasonData     Resolved season data (or null).
     * @param string|null               $seriesPoster   Series poster fallback.
     * @param string|null               $seriesOverview Series overview fallback.
     * @param array{
     *     genres?: list<string>,
     *     tags?: list<string>,
     *     backdrop_url?: string,
     *     theme_audio_url?: string
     * } $seriesInheritance
     *     Series-level fields inherited by the episode.
     */
    private function enrichEpisode(
        array $episode,
        ?array $seasonData,
        ?string $seriesPoster,
        ?string $seriesOverview,
        array $seriesInheritance = []
    ): void {
        $id = is_string($episode['id'] ?? null) ? $episode['id'] : '';
        if ($id === '') {
            return;
        }
        $meta = $this->extractMetadata($episode);
        $episodeNumber = $this->intMeta($meta, 'episode');

        /** @var array<string, mixed> $info */
        $info = [];
        if ($seasonData !== null && $episodeNumber !== null) {
            $episodes = $seasonData['episodes'] ?? [];
            if (is_array($episodes) && isset($episodes[$episodeNumber]) && is_array($episodes[$episodeNumber])) {
                $info = $episodes[$episodeNumber];
            }
        }

        $patch = [];
        $title = $this->stringOrNull($info['episode_title'] ?? null);
        if ($title !== null) {
            $patch['episode_title'] = $title;
        }
        $overview = $this->stringOrNull($info['overview'] ?? null) ?? $seriesOverview;
        if ($overview !== null) {
            $patch['overview'] = $overview;
        }
        $airDate = $this->stringOrNull($info['air_date'] ?? null);
        if ($airDate !== null) {
            $patch['air_date'] = $airDate;
        }
        if (is_int($info['runtime'] ?? null) && $info['runtime'] > 0) {
            $patch['runtime'] = $info['runtime'];
        }
        // Poster: episode still → season poster → series poster.
        $poster = $this->stringOrNull($info['poster_url'] ?? null)
            ?? ($seasonData !== null ? $this->stringOrNull($seasonData['poster_url'] ?? null) : null)
            ?? $seriesPoster;
        if ($poster !== null) {
            $patch['poster_url'] = $poster;
        }

        // Preserve the episode's own still separately (a landscape frame) so a
        // client can show it distinctly from the portrait poster. Present only
        // when TMDB supplied a still for this episode.
        $still = $this->stringOrNull($info['still_url'] ?? null);
        if ($still !== null) {
            $patch['still_url'] = $still;
        }

        // Episode-level cast/crew (season regulars + guest stars / episode crew).
        $cast = $this->peopleList($info['cast'] ?? null);
        if ($cast !== []) {
            $patch['cast'] = $cast;
        }
        $crew = $this->peopleList($info['crew'] ?? null);
        if ($crew !== []) {
            $patch['crew'] = $crew;
        }
        // Episode rating (TMDB per-episode vote average).
        if (isset($info['vote_average']) && is_float($info['vote_average']) && $info['vote_average'] > 0.0) {
            $patch['vote_average'] = $info['vote_average'];
        }

        // Inherit series-level genres/tags/backdrop (episodes carry none of their
        // own). Backdrop fallback: season backdrop (if the season resolver ever
        // supplies one) → series backdrop.
        $genres = $this->stringList($seriesInheritance['genres'] ?? null);
        if ($genres !== []) {
            $patch['genres'] = $genres;
        }
        $tags = $this->stringList($seriesInheritance['tags'] ?? null);
        if ($tags !== []) {
            $patch['tags'] = $tags;
        }
        $backdrop = ($seasonData !== null ? $this->stringOrNull($seasonData['backdrop_url'] ?? null) : null)
            ?? $this->stringOrNull($seriesInheritance['backdrop_url'] ?? null);
        if ($backdrop !== null) {
            $patch['backdrop_url'] = $backdrop;
        }

        // Inherit the series theme-audio url (M3) — episodes carry no theme of
        // their own, so they play the series theme on their detail page.
        $themeAudio = $this->stringOrNull($seriesInheritance['theme_audio_url'] ?? null);
        if ($themeAudio !== null) {
            $patch['theme_audio_url'] = $themeAudio;
        }

        if ($patch !== []) {
            $this->persistMetadata($id, array_merge($meta, $patch));
        }
    }

    /**
     * Build the series-inheritance context (genres, tags, backdrop) an episode
     * inherits, from the resolved series metadata array. Absent fields are
     * omitted so a missing series field never overwrites episode data.
     *
     * @param array<string, mixed> $resolved Resolved series metadata.
     * @return array{
     *     genres?: list<string>,
     *     tags?: list<string>,
     *     backdrop_url?: string,
     *     theme_audio_url?: string
     * }
     */
    private function seriesInheritance(array $resolved): array
    {
        $context = [];
        $genres = $this->stringList($resolved['genres'] ?? null);
        if ($genres !== []) {
            $context['genres'] = $genres;
        }
        $tags = $this->stringList($resolved['tags'] ?? null);
        if ($tags !== []) {
            $context['tags'] = $tags;
        }
        $backdrop = $this->stringOrNull($resolved['backdrop_url'] ?? null);
        if ($backdrop !== null) {
            $context['backdrop_url'] = $backdrop;
        }
        return $context;
    }

    /**
     * Narrow a raw cast/crew value to the canonical people shape the media-item
     * shaper renders. The resolver already produces `{name, role|job,
     * profile_url}` objects; this defensively drops non-array/nameless entries.
     *
     * @param mixed $value Raw cast/crew list from the season resolver.
     * @return list<array<string, mixed>>
     */
    private function peopleList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = MetadataValue::asString($entry['name'] ?? null);
            if ($name === '') {
                continue;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Build the season-item metadata patch (poster + overview, series fallbacks).
     *
     * @param array<string, mixed>|null $seasonData
     * @return array<string, mixed>
     */
    private function seasonPatch(
        ?array $seasonData,
        ?string $seriesPoster,
        ?string $seriesBackdrop,
        ?string $seriesOverview
    ): array {
        $patch = [];
        $poster = ($seasonData !== null
            ? $this->stringOrNull($seasonData['poster_url'] ?? null)
            : null) ?? $seriesPoster;
        if ($poster !== null) {
            $patch['poster_url'] = $poster;
        }
        if ($seriesBackdrop !== null) {
            $patch['backdrop_url'] = $seriesBackdrop;
        }
        $overview = ($seasonData !== null
            ? $this->stringOrNull($seasonData['overview'] ?? null)
            : null) ?? $seriesOverview;
        if ($overview !== null) {
            $patch['overview'] = $overview;
        }
        return $patch;
    }

    /**
     * Return the cached season data for a season number, fetching once per number.
     *
     * @param array<int, array<string, mixed>> $cache Season cache (by number, mutated).
     *
     * @return array<string, mixed>|null Season data, or null when no number.
     */
    private function cachedSeason(string $tmdbId, ?int $seasonNumber, array &$cache): ?array
    {
        if ($seasonNumber === null || $this->seriesResolver === null) {
            return null;
        }
        if (!array_key_exists($seasonNumber, $cache)) {
            $cache[$seasonNumber] = $this->seriesResolver->resolveSeasonEpisodes($tmdbId, $seasonNumber);
        }
        return $cache[$seasonNumber];
    }

    /**
     * Pull the TMDB series id out of a resolved metadata array.
     *
     * @param array<string, mixed> $resolved
     */
    private function resolvedTmdbId(array $resolved): string
    {
        $ext = $resolved['external_ids'] ?? null;
        if (is_array($ext) && is_string($ext['tmdb'] ?? null) && $ext['tmdb'] !== '') {
            return $ext['tmdb'];
        }
        return $this->stringOrNull($resolved['tmdb_id'] ?? null) ?? '';
    }

    /**
     * Resolve + write `theme_audio_url` (M3) into a metadata array for an item.
     *
     * No-op (returns $merged unchanged) when the theme-music producer is not wired
     * or it produces no url. The TVDB id used for the Plex-archive fallback is
     * read from the merged metadata's `external_ids.tvdb` (threaded there by the
     * series resolver). Movies pass a non-series `type`, so the resolver only ever
     * checks the local theme file for them. Never throws — the resolver already
     * swallows its own failures, and this method adds a defensive guard.
     *
     * @param array<string, mixed> $item   Hydrated media-item row (for `path`/`type`).
     * @param array<string, mixed> $merged Metadata about to be persisted.
     *
     * @return array<string, mixed> $merged, with `theme_audio_url` set when found.
     */
    private function applyThemeAudio(array $item, array $merged): array
    {
        if ($this->themeMusic === null) {
            return $merged;
        }

        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        if ($itemId === '') {
            return $merged;
        }

        $type = is_string($item['type'] ?? null) ? $item['type'] : '';
        $path = is_string($item['path'] ?? null) ? $item['path'] : null;

        $tvdbId = null;
        $external = $merged['external_ids'] ?? null;
        if (is_array($external)) {
            $tvdbRaw = $external['tvdb'] ?? null;
            if (is_string($tvdbRaw) || is_int($tvdbRaw)) {
                $tvdbId = $tvdbRaw;
            }
        }

        try {
            $url = $this->themeMusic->resolveForItem([
                'item_id' => $itemId,
                'type' => $type,
                'path' => $path,
                'tvdb_id' => $tvdbId,
            ]);
        } catch (Throwable $e) {
            $this->logger->debug('LibraryMetadataMatcher: theme-music resolve failed; skipping', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return $merged;
        }

        if ($url !== null) {
            $merged['theme_audio_url'] = $url;
        }
        return $merged;
    }

    /**
     * Persist a merged metadata array onto an item, stamping the refresh time.
     *
     * Before persisting, the flat image keys (`poster_url` / `backdrop_url`) are
     * dropped when their canonical {@see ImageType} is DISABLED for the current
     * library (M5). Only mapped keys are gated; every other key (including any
     * unmapped image key) passes through untouched. When no image-type selection
     * is active ({@see $activeImageTypes} is null — legacy construction /
     * unloadable library), nothing is filtered — behaviour is exactly as before.
     *
     * When ArtworkStorage is wired (SV-3.4), this method also downloads the
     * TMDB poster and generates sized variants locally, then updates the
     * item with local URLs so offline/LAN installs can serve artwork.
     *
     * @param array<string, mixed> $merged
     */
    private function persistMetadata(string $id, array $merged): void
    {
        $merged = $this->filterDisabledImageKeys($merged);

        // SV-3.4: Download and cache TMDB poster locally for offline/LAN installs
        $merged = $this->cacheArtworkLocally($id, $merged);

        // Phase C: cache the TMDB title logo as a transparency-safe local PNG and
        // rewrite `logo_url` to the local served URL (independent of the poster).
        $merged = $this->cacheLogoLocally($id, $merged);

        $this->items->update($id, [
            'metadata_json' => $merged,
            'metadata_refreshed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Download and cache TMDB artwork locally, updating metadata with local URLs.
     *
     * When ArtworkStorage is wired and the item has a TMDB poster URL, this
     * method downloads the poster and generates sized variants. The metadata
     * is updated to use local URLs so the UI can serve artwork without TMDB.
     *
     * @param string              $id     Media item UUID
     * @param array<string, mixed> $merged Current merged metadata
     *
     * @return array<string, mixed> Updated metadata with local URLs (or unchanged if no artwork)
     */
    private function cacheArtworkLocally(string $id, array $merged): array
    {
        // Skip if ArtworkStorage is not wired
        if ($this->artworkStorage === null) {
            return $merged;
        }

        // Skip if the operator has switched artwork downloads off
        // (`artwork.download_enabled`). Returning $merged untouched leaves the
        // REMOTE poster_url in place and writes nothing new to disk; artwork
        // already cached is unaffected. See ArtworkDownloadPolicy.
        if (!$this->artworkDownloadPolicy->downloadsEnabled()) {
            return $merged;
        }

        // Extract poster_path from poster_url if it's a TMDB URL
        $posterUrl = $merged['poster_url'] ?? null;
        if (!is_string($posterUrl) || $posterUrl === '') {
            return $merged;
        }

        // Check if this is a TMDB URL we can download
        $posterPath = $this->extractTmdbPosterPath($posterUrl);
        if ($posterPath === null) {
            return $merged;
        }

        // Store the raw TMDB path for future reference (reconstruct URL if needed)
        $merged['poster_path'] = $posterPath;

        // Dedup: seasons/episodes inherit the series poster, so the SAME TMDB
        // path recurs across every child of a series. If it was already
        // downloaded this run, splice the first item's local URLs onto this one
        // instead of re-fetching + re-resizing the identical bytes (the dominant
        // scan cost). The reused item points at the first item's cached variants
        // — correct because the bytes are identical, and it writes no duplicate.
        $cached = $this->artworkPathCache[$posterPath] ?? null;
        if ($cached !== null) {
            return array_merge($merged, $cached);
        }

        // Download and cache the artwork (idempotent - skips if already cached)
        try {
            $variants = $this->artworkStorage->downloadAndStore($id, $posterPath);
            if ($variants === []) {
                // Download failed or returned no variants
                return $merged;
            }

            // Collect the local URL overrides so we can both apply them here and
            // remember them for every later item that inherits this poster path.
            $overrides = ['poster_path' => $posterPath];

            // Build local srcset from the cached variants
            $localSrcset = $this->artworkStorage->srcset($id);
            if ($localSrcset !== null) {
                $overrides['poster_srcset'] = $localSrcset;
            }

            // Update poster_url to point to local server (use w500 size as default)
            $localPath = $this->artworkStorage->relativePath($id, 'w500');
            if ($localPath !== null) {
                // Sign the URL for access
                $signedUrl = $this->artworkStorage->url($id, 'w500', $localPath);
                if ($signedUrl !== null) {
                    $overrides['poster_url'] = $signedUrl;
                }
            }

            // Remember this path's local URLs for the rest of the scan.
            $this->artworkPathCache[$posterPath] = $overrides;

            $merged = array_merge($merged, $overrides);
        } catch (\Throwable $e) {
            // Artwork caching is best-effort - log and continue
            $this->logger->warning('Failed to cache artwork locally', [
                'item_id'   => $id,
                'poster_path' => $posterPath,
                'error'    => $e->getMessage(),
            ]);
        }

        return $merged;
    }

    /**
     * Extract the TMDB poster path from a full poster URL.
     *
     * TMDB poster URLs look like: https://image.tmdb.org/t/p/w500/abc.jpg
     * We want to extract just the path part: /abc.jpg
     *
     * @param string $posterUrl Full TMDB poster URL
     *
     * @return string|null The poster path or null if not a TMDB URL
     */
    private function extractTmdbPosterPath(string $posterUrl): ?string
    {
        // TMDB URL pattern: https://image.tmdb.org/t/p/{size}/{filename}
        // We want to extract just the filename portion (e.g., "/abc.jpg")
        if (preg_match('#^https?://image\.tmdb\.org/t/p/[^/]+(/[^/]+)$#', $posterUrl, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Download and cache the TMDB title logo locally as a transparency-safe PNG,
     * rewriting `logo_url` to the local served URL.
     *
     * Only TMDB **PNG** logos are localized: an SVG (or non-TMDB) `logo_url` is
     * left pointing at its original remote URL — the JPEG variant pipeline is
     * never involved and SVGs are never rasterized. When the download exists but
     * is not a usable PNG, {@see ArtworkStorage::downloadAndStoreLogo()} returns
     * null and the original `logo_url` is kept. Best-effort: any failure is logged
     * and the metadata is returned unchanged.
     *
     * @param string               $id     Media item UUID.
     * @param array<string, mixed> $merged Current merged metadata.
     * @return array<string, mixed> Metadata with a localized `logo_url` (or unchanged).
     */
    private function cacheLogoLocally(string $id, array $merged): array
    {
        if ($this->artworkStorage === null) {
            return $merged;
        }

        // Same gate as cacheArtworkLocally(): `artwork.download_enabled` off
        // means no new logo is fetched and `logo_url` keeps pointing at the
        // remote provider URL. Already-cached logos are untouched.
        if (!$this->artworkDownloadPolicy->downloadsEnabled()) {
            return $merged;
        }

        $logoUrl = $merged['logo_url'] ?? null;
        if (!is_string($logoUrl) || $logoUrl === '') {
            return $merged;
        }

        // Only a TMDB PNG logo is cached; an SVG/non-TMDB URL is left as-is.
        $logoPath = $this->extractTmdbLogoPath($logoUrl);
        if ($logoPath === null) {
            return $merged;
        }

        // Dedup across the scan run (series children inherit the series logo).
        $cached = $this->logoPathCache[$logoPath] ?? null;
        if ($cached !== null) {
            return array_merge($merged, $cached);
        }

        try {
            $stored = $this->artworkStorage->downloadAndStoreLogo($id, $logoPath);
            if ($stored === null) {
                // Not a usable PNG — keep the original remote logo_url.
                return $merged;
            }

            $overrides = [];
            $relative = $this->artworkStorage->relativePath($id, ArtworkStorage::LOGO_SIZE);
            if ($relative !== null) {
                $signedUrl = $this->artworkStorage->url($id, ArtworkStorage::LOGO_SIZE, $relative);
                if ($signedUrl !== null) {
                    $overrides['logo_url'] = $signedUrl;
                }
            }

            if ($overrides !== []) {
                $this->logoPathCache[$logoPath] = $overrides;
                $merged = array_merge($merged, $overrides);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to cache logo locally', [
                'item_id'   => $id,
                'logo_path' => $logoPath,
                'error'     => $e->getMessage(),
            ]);
        }

        return $merged;
    }

    /**
     * Extract the TMDB logo path from a full logo URL, but ONLY for PNG logos.
     *
     * TMDB logo URLs look like: https://image.tmdb.org/t/p/original/abc.png
     * A non-PNG (e.g. `.svg`) or non-TMDB URL returns null so it is never routed
     * through the local raster cache.
     *
     * @param string $logoUrl Full TMDB logo URL.
     * @return string|null The `/abc.png` path, or null when not a TMDB PNG URL.
     */
    private function extractTmdbLogoPath(string $logoUrl): ?string
    {
        if (preg_match('#^https?://image\.tmdb\.org/t/p/[^/]+(/[^/]+\.png)$#i', $logoUrl, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Drop the flat image keys whose canonical {@see ImageType} is disabled for
     * the current library's {@see $activeImageTypes} selection (M5).
     *
     * A null selection (no LibraryManager wired, or the library could not be
     * loaded) is a no-op — the array is returned unchanged. Only keys listed in
     * {@see FLAT_IMAGE_KEY_TYPES} are considered; unmapped image keys pass
     * through so an unknown/new key is never silently dropped.
     *
     * @param array<string, mixed> $merged
     *
     * @return array<string, mixed>
     */
    private function filterDisabledImageKeys(array $merged): array
    {
        if ($this->activeImageTypes === null) {
            return $merged;
        }
        foreach (self::FLAT_IMAGE_KEY_TYPES as $key => $imageType) {
            if (array_key_exists($key, $merged) && !in_array($imageType, $this->activeImageTypes, true)) {
                unset($merged[$key]);
            }
        }
        return $merged;
    }

    /** A non-empty string value, or null. */
    private function stringOrNull(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * Read an int from a metadata field (int or numeric string), else null.
     *
     * @param array<string, mixed> $meta
     */
    private function intMeta(array $meta, string $key): ?int
    {
        $value = $meta[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Extract the item's existing decoded metadata as a string-keyed array.
     *
     * Prefers the hydrated `metadata` key {@see ItemRepository} adds; falls back
     * to decoding `metadata_json` defensively when only the raw column is
     * present (e.g. a mocked row).
     *
     * @param array<string, mixed> $item Hydrated media-item row.
     *
     * @return array<string, mixed> Existing metadata (empty when none/invalid).
     */
    private function extractMetadata(array $item): array
    {
        $metadata = $item['metadata'] ?? null;
        if (is_array($metadata)) {
            return $this->stringKeyed($metadata);
        }

        $raw = $item['metadata_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->stringKeyed($decoded);
            }
        }
        if (is_array($raw)) {
            return $this->stringKeyed($raw);
        }

        return [];
    }

    /**
     * Resolve the movie title: the item's `name` column, else a `title`/`name`
     * already in its metadata.
     *
     * @param array<string, mixed> $item             Hydrated media-item row.
     * @param array<string, mixed> $existingMetadata Decoded metadata.
     *
     * @return string|null Non-empty title, or null when none is available.
     */
    private function extractName(array $item, array $existingMetadata): ?string
    {
        $candidates = [
            $item['name'] ?? null,
            $existingMetadata['title'] ?? null,
            $existingMetadata['name'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
        return null;
    }

    /**
     * Resolve the release year from the item's metadata, if present and sane.
     *
     * @param array<string, mixed> $existingMetadata Decoded metadata.
     *
     * @return int|null Year, or null when absent/non-numeric.
     */
    private function extractYear(array $existingMetadata): ?int
    {
        $year = $existingMetadata['year'] ?? null;
        if (is_int($year)) {
            return $year;
        }
        if (is_string($year) && is_numeric($year)) {
            return (int) $year;
        }
        return null;
    }

    /**
     * Extract already-known external ids (e.g. `['imdb' => 'tt…']`) from the
     * item's metadata, narrowing to a `array<string, string>` for the resolver.
     *
     * @param array<string, mixed> $existingMetadata Decoded metadata.
     *
     * @return array<string, string> Known external ids (possibly empty).
     */
    private function extractExternalIds(array $existingMetadata): array
    {
        $raw = $existingMetadata['external_ids'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $stringValue = (string) $value;
                if ($stringValue !== '') {
                    $out[$key] = $stringValue;
                }
            }
        }
        return $out;
    }

    /**
     * Narrow a mixed array to only its string-keyed entries.
     *
     * @param array<array-key, mixed> $value Raw array.
     *
     * @return array<string, mixed> String-keyed subset.
     */
    private function stringKeyed(array $value): array
    {
        $out = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $out[$key] = $entry;
            }
        }
        return $out;
    }

    /**
     * SV-3.5: Detect whether we are executing inside a Swoole coroutine context.
     *
     * Mirrors the guard in {@see MediaScanner::coroutineFanOutAvailable()}: both
     * must activate/deactivate together so the bounded fan-out and the HTTP
     * client's own non-blocking async branch ({@see MetadataHttpClient::get()})
     * are in sync.
     *
     * @return bool True when Swoole is loaded, the Coroutine class exists,
     *              and getCid() > 0 (we are inside a coroutine, not the main
     *              thread/process).
     */
    private function coroutineFanOutAvailable(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * SV-3.5: Process a batch of items for metadata matching with bounded
     * concurrency using the Channel-as-semaphore idiom from
     * {@see MediaScanner::probeManyConcurrently()}.
     *
     * When not inside a coroutine, falls back to sequential per-item processing
     * so behaviour is identical to the pre-SV-3.5 path in all non-coroutine
     * contexts (PHPUnit CLI, plain CLI scan scripts).
     *
     * @param array<int, array<string, mixed>> $batch Page of items from getByLibrary().
     * @param PriorityConfig|null $effective Effective per-library priority config.
     * @param string $libraryId For logging.
     *
     * @return array{matched: int, processed: int} Counts from this batch.
     */
    private function matchBatchConcurrently(
        array $batch,
        ?PriorityConfig $effective,
        string $libraryId,
    ): array {
        if ($batch === []) {
            return ['matched' => 0, 'processed' => 0];
        }

        // Filter to matchable items and compute their names once.
        $matchable = [];
        foreach ($batch as $item) {
            $type = $item['type'] ?? null;
            $isMovie = is_string($type) && in_array($type, self::MOVIE_TYPES, true);
            $isSeries = $type === 'series' && $this->seriesResolver !== null;
            if (!$isMovie && !$isSeries) {
                continue;
            }

            // Skip already-processed items unless force refresh is enabled
            if (!$this->forceRefresh) {
                $refreshedAt = $item['metadata_refreshed_at'] ?? null;
                if ($refreshedAt !== null) {
                    continue;
                }
            }

            $id = is_string($item['id'] ?? null) ? $item['id'] : '';
            $name = is_string($item['name'] ?? null) ? $item['name'] : '';
            $matchable[] = [
                'item' => $item,
                'is_series' => $isSeries,
                'id' => $id,
                'name' => $name,
            ];
        }

        if ($matchable === []) {
            return ['matched' => 0, 'processed' => 0];
        }

        // Outside a coroutine: sequential processing (exact same behaviour as pre-SV-3.5).
        if (!$this->coroutineFanOutAvailable()) {
            $matched = 0;
            $processed = 0;
            foreach ($matchable as $entry) {
                $processed++;
                // Per-item try/catch: a single item's failure (resolver error,
                // malformed metadata, persistence error) is logged on the MEDIA
                // channel and the run CONTINUES to the next item — one bad item
                // must never abort the whole library (see class docblock).
                try {
                    $hit = $this->executeMatchForItem($entry['item'], $entry['is_series'], $effective);
                } catch (Throwable $e) {
                    $this->logger->warning('LibraryMetadataMatcher: item match failed; skipping', [
                        'library_id' => $libraryId,
                        'item_id' => $entry['id'],
                        'name' => $entry['name'],
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
                if ($hit) {
                    $matched++;
                    $this->logger->debug('LibraryMetadataMatcher: item matched', [
                        'library_id' => $libraryId,
                        'item_id' => $entry['id'],
                        'name' => $entry['name'],
                        'processed' => $processed,
                        'matched' => $matched,
                    ]);
                } else {
                    // A processed-but-unmatched item still emits a per-item DEBUG
                    // line so the log shows every item being visited, not just hits.
                    $this->logger->debug('LibraryMetadataMatcher: item not matched', [
                        'library_id' => $libraryId,
                        'item_id' => $entry['id'],
                        'name' => $entry['name'],
                        'processed' => $processed,
                        'matched' => $matched,
                    ]);
                }
            }
            return ['matched' => $matched, 'processed' => $processed];
        }

        // Inside a coroutine: bounded-concurrency fan-out.
        $matched = 0;
        $processed = 0;
        $semaphore = new \Swoole\Coroutine\Channel(max(1, self::MAX_CONCURRENT_MATCHES));
        $done = new \Swoole\Coroutine\Channel(count($matchable));

        foreach ($matchable as $entry) {
            // Blocking push when semaphore is full — bounds concurrency.
            $semaphore->push(true);
            \Swoole\Coroutine::create(function () use (
                $entry,
                $effective,
                $libraryId,
                &$matched,
                &$processed,
                $semaphore,
                $done
            ): void {
                try {
                    $hit = $this->executeMatchForItem($entry['item'], $entry['is_series'], $effective);
                    if ($hit) {
                        $matched++;
                        $this->logger->debug('LibraryMetadataMatcher: item matched', [
                            'library_id' => $libraryId,
                            'item_id' => $entry['id'],
                            'name' => $entry['name'],
                        ]);
                    } else {
                        $this->logger->debug('LibraryMetadataMatcher: item not matched', [
                            'library_id' => $libraryId,
                            'item_id' => $entry['id'],
                            'name' => $entry['name'],
                        ]);
                    }
                } catch (Throwable $e) {
                    $this->logger->warning('LibraryMetadataMatcher: item match failed; skipping', [
                        'library_id' => $libraryId,
                        'item_id' => $entry['id'],
                        'name' => $entry['name'],
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    $processed++;
                    $semaphore->pop();
                    $done->push(true);
                }
            });
        }

        // Wait for all items in this batch to complete before moving to next batch.
        for ($i = 0; $i < count($matchable); $i++) {
            $done->pop();
        }

        return ['matched' => $matched, 'processed' => $processed];
    }

    /**
     * Execute the actual per-item match and return whether it succeeded.
     *
     * @param array<string, mixed> $item The media item.
     * @param bool $isSeries Whether this is a series (calls matchSeries) or movie (calls matchItem).
     * @param PriorityConfig|null $effective Effective per-library priority config.
     *
     * @return bool True when a match was found and persisted.
     */
    private function executeMatchForItem(array $item, bool $isSeries, ?PriorityConfig $effective): bool
    {
        return $isSeries
            ? $this->matchSeries($item, $effective)
            : $this->matchItem($item, $effective);
    }
}
