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
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Phlix\Media\Metadata\Resolution\FieldMappers;
use Phlix\Media\Metadata\Resolution\PluginSourceConsultation;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\PriorityFieldResolver;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Throwable;

/**
 * Cross-source movie metadata resolver — the "matching brain".
 *
 * Given a title (and optional year / known external ids), this service gathers
 * metadata from BOTH the online TMDB provider and the offline IMDb dataset,
 * cross-populates ids between the two sources, and merges everything into a
 * single details array. Either provider being unavailable (no API key, network
 * failure, no local IMDb table) degrades gracefully: whatever the other source
 * produced is still returned, and only a total miss yields null.
 *
 * This class deliberately performs NO persistence and is NOT yet wired into
 * {@see MetadataManager}; it is a pure matching/merge unit.
 *
 * @package Phlix\Media\Metadata
 * @since   0.21.0
 */
class MovieMetadataResolver
{
    /** @var TmdbProvider Online TMDB provider (search / find / details). */
    private TmdbProvider $tmdb;

    /** @var ImdbLookup Offline IMDb dataset lookup (ratings / genres / runtime). */
    private ImdbLookup $imdb;

    /** @var StructuredLogger Structured logger instance. */
    private StructuredLogger $logger;

    /** @var PriorityConfig Effective per-media-type source priority. */
    private PriorityConfig $priorityConfig;

    /** @var PriorityFieldResolver Configurable per-field first-non-empty merge engine. */
    private PriorityFieldResolver $fieldResolver;

    /**
     * @var SourceRegistry|null Registry of enabled plugin metadata sources (omdb,
     *     anidb, myanimelist, …). Null in legacy construction / unit tests, in
     *     which case plugin-source consultation is skipped entirely and output is
     *     exactly today's TMDB+IMDb result.
     */
    private ?SourceRegistry $sourceRegistry;

    /**
     * @param TmdbProvider               $tmdb           Online TMDB provider.
     * @param ImdbLookup                 $imdb           Offline IMDb dataset lookup.
     * @param StructuredLogger|null      $logger         Optional logger; defaults to the MEDIA channel.
     * @param PriorityConfig|null        $priorityConfig Effective per-type source priority. When null,
     *     defaults to the canonical `['tmdb','imdb']` order — i.e. today's hard-coded precedence — so
     *     behavior is unchanged for callers that do not inject it.
     * @param PriorityFieldResolver|null $fieldResolver  The merge engine; a fresh pure instance by default.
     * @param SourceRegistry|null        $sourceRegistry Enabled plugin metadata sources. Only consulted when
     *     {@see resolve()} is called with `$includePluginSources = true`; null (unit tests / legacy) makes
     *     plugin consultation a no-op, so behaviour is byte-for-byte identical to today.
     *
     * @since 0.21.0
     */
    public function __construct(
        TmdbProvider $tmdb,
        ImdbLookup $imdb,
        ?StructuredLogger $logger = null,
        ?PriorityConfig $priorityConfig = null,
        ?PriorityFieldResolver $fieldResolver = null,
        ?SourceRegistry $sourceRegistry = null
    ) {
        $this->tmdb = $tmdb;
        $this->imdb = $imdb;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
        // Default config reproduces today's hard-coded `[tmdb, imdb]` precedence,
        // keeping the merge behavior-identical when no PriorityConfig is injected.
        $this->priorityConfig = $priorityConfig ?? new PriorityConfig(['movie' => ['tmdb', 'imdb']]);
        $this->fieldResolver = $fieldResolver ?? new PriorityFieldResolver();
        $this->sourceRegistry = $sourceRegistry;
    }

    /**
     * Resolve and merge movie metadata across TMDB and IMDb.
     *
     * @param string                $title              Raw movie title.
     * @param int|null              $year               Optional release year.
     * @param array<string, string> $existingExternalIds Already-known external ids, e.g. `['imdb' => 'tt…']`.
     * @param PriorityConfig|null   $priorityOverride   Optional per-library effective
     *     priority config (library override layered over the global default). When
     *     provided it drives the source order + genres mode for THIS call instead of
     *     the injected global `$this->priorityConfig`; null (the default) preserves
     *     the existing global behaviour, so all existing callers are unaffected.
     * @param bool                  $includePluginSources When true (AND a SourceRegistry is
     *     injected), the enabled plugin metadata sources for `movie` are consulted AFTER
     *     TMDB/IMDb and merged UNDER them (pure gap-fill; TMDB wins every shared field), and
     *     any surfaced ratings are returned under a `plugin_ratings` key for the caller to
     *     persist. **DEFAULT false** — the keystone safety property: the bulk library-scan
     *     path leaves this off so a 1000-item scan makes ZERO plugin-source network calls
     *     (omdb 1000/day quota, anidb ban risk). When false, output is byte-for-byte identical
     *     to today (TMDB+IMDb only).
     *
     * @return array<string, mixed>|null Merged details, or null when neither source matched.
     *     Shape (keys present only when data is available):
     *     ```
     *     [
     *         'external_ids' => array<string, string>, // ['tmdb' => '603', 'imdb' => 'tt0133093']
     *         'title'        => string,
     *         'overview'     => string,
     *         'poster_url'   => string,
     *         'backdrop_url' => string,
     *         'genres'       => list<string>,
     *         'year'         => int,
     *         'runtime'      => int,   // minutes
     *         'imdb_rating'  => float,
     *         'imdb_votes'   => int,
     *         'sources'      => list<string>, // which providers contributed, e.g. ['tmdb','imdb']
     *     ]
     *     ```
     *
     * @since 0.21.0
     */
    public function resolve(
        string $title,
        ?int $year,
        array $existingExternalIds = [],
        ?PriorityConfig $priorityOverride = null,
        bool $includePluginSources = false
    ): ?array {
        // 1. Seed the IMDb id from caller-provided ids, else attempt an offline
        //    title lookup to discover one.
        $imdbId = $this->extractImdbId($existingExternalIds);

        $imdbLookupData = null;
        if ($imdbId === null) {
            $imdbLookupData = $this->safeImdbLookup($title, $year);
            if ($imdbLookupData !== null) {
                $candidate = MetadataValue::asNullableString($imdbLookupData['imdb_id'] ?? null);
                if ($candidate !== null) {
                    $imdbId = $candidate;
                }
            }
        }

        // 2. Resolve the TMDB id: by IMDb id when known (surer), else title search.
        $tmdbId = $this->resolveTmdbId($title, $year, $imdbId);

        // 3. Fetch TMDB details; cross-populate the IMDb id from them if needed.
        $tmdbDetails = null;
        if ($tmdbId !== null) {
            $tmdbDetails = $this->safeTmdbDetails($tmdbId);
            $this->logger->debug('MovieMetadataResolver: TMDB details fetched', [
                'title' => $title,
                'tmdb_id' => $tmdbId,
                'returned' => $tmdbDetails !== null,
            ]);
            if ($tmdbDetails !== null && $imdbId === null) {
                $fromTmdb = MetadataValue::asNullableString($tmdbDetails['imdb_id'] ?? null);
                if ($fromTmdb !== null) {
                    $imdbId = $fromTmdb;
                }
            }
        } else {
            $this->logger->debug('MovieMetadataResolver: TMDB id not resolved', [
                'title' => $title,
                'year' => $year,
                'imdb_id_used' => $imdbId,
            ]);
        }

        // 4. Fetch offline IMDb data for the (possibly cross-populated) id.
        $imdbData = $imdbLookupData;
        $imdbSource = $imdbLookupData !== null ? 'lookup' : null;
        if ($imdbId !== null && ($imdbData === null || ($imdbData['imdb_id'] ?? null) !== $imdbId)) {
            $byId = $this->safeImdbGetById($imdbId);
            if ($byId !== null) {
                $imdbData = $byId;
                $imdbSource = 'getById';
            }
        }

        $this->logger->debug('MovieMetadataResolver: IMDb data fetched', [
            'title' => $title,
            'imdb_id' => $imdbId,
            'returned' => $imdbData !== null,
            'source' => $imdbSource, // null, 'lookup', or 'getById'
        ]);

        // 5. Merge — bail out only when NEITHER source produced anything.
        if ($tmdbDetails === null && $imdbData === null) {
            $this->logger->info('MovieMetadataResolver: no match', [
                'title' => $title,
                'year' => $year,
                'providers_tried' => ['tmdb', 'imdb'],
                'tmdb_returned' => false,
                'imdb_returned' => false,
            ]);
            return null;
        }

        $result = $this->merge(
            $existingExternalIds,
            $tmdbId,
            $imdbId,
            $tmdbDetails,
            $imdbData,
            $priorityOverride,
            $includePluginSources,
            $title,
            $year,
        );

        $this->logger->info('MovieMetadataResolver: resolved', [
            'title' => $title,
            'year' => $year,
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId,
            'sources' => $result['sources'] ?? [],
            'providers_tried' => ['tmdb', 'imdb'],
            'tmdb_returned' => $tmdbDetails !== null,
            'imdb_returned' => $imdbData !== null,
        ]);

        return $result;
    }

    /**
     * Merge TMDB details and IMDb data into one details array.
     *
     * @param array<string, string>     $existingExternalIds Caller-supplied ids (lowest priority for ids).
     * @param string|null               $tmdbId              Resolved TMDB id.
     * @param string|null               $imdbId              Resolved IMDb id.
     * @param array<string, mixed>|null $tmdbDetails         Formatted TMDB details.
     * @param array<string, mixed>|null $imdbData            Offline IMDb row.
     * @param PriorityConfig|null       $priorityOverride    Per-library override; when
     *     null the injected global `$this->priorityConfig` drives the order/genres mode.
     * @param bool                      $includePluginSources When true and a SourceRegistry is
     *     injected, plugin `movie` sources are consulted and merged UNDER TMDB/IMDb; their
     *     ratings are surfaced under `plugin_ratings`. Default false = today's behaviour.
     * @param string                    $title               Title fed to plugin `search()` (only
     *     used when `$includePluginSources` is true).
     * @param int|null                  $year                Optional year hint for plugin search.
     *
     * @return array<string, mixed> Merged details.
     */
    private function merge(
        array $existingExternalIds,
        ?string $tmdbId,
        ?string $imdbId,
        ?array $tmdbDetails,
        ?array $imdbData,
        ?PriorityConfig $priorityOverride = null,
        bool $includePluginSources = false,
        string $title = '',
        ?int $year = null
    ): array {
        // Per-field selection is delegated to PriorityFieldResolver, driven by the
        // configurable source order (PriorityConfig). The 3.1 FieldMappers normalize
        // each provider's already-formatted payload onto the canonical field set, so
        // under the default `['tmdb','imdb']` order the resolver makes the SAME
        // per-field choice the old hand-rolled merge did:
        //  - title          tmdb `name` else imdb `title`        (first-non-empty)
        //  - overview/images/cast/crew/companies/actors/director/studio  tmdb only
        //  - genres         tmdb if non-empty else imdb          (first-non-empty list)
        //  - year/runtime   tmdb else imdb                       (first-non-empty)
        //  - imdb_rating/imdb_votes  imdb only
        // `external_ids` and `sources` are NOT taken from the resolver: they retain
        // the live construction below to preserve their exact idiosyncrasies (caller-
        // supplied-under-discovered id layering; provenance keyed on a non-null
        // payload, not on "contributed a field").
        $records = [];
        if ($tmdbDetails !== null) {
            $records[] = FieldMappers::fromTmdb($tmdbDetails);
        }
        if ($imdbData !== null) {
            $records[] = FieldMappers::fromImdb($imdbData);
        }

        $priority = $priorityOverride ?? $this->priorityConfig;
        $order = $priority->orderFor('movie');

        // F2: gap-fill under the built-ins from enabled plugin `movie` sources —
        // ONLY when the caller opted in AND a registry is wired. The plugin source
        // names are appended to the END of the merge order so TMDB/IMDb win every
        // shared field (plugin data is pure gap-fill); PriorityFieldResolver is
        // first-non-empty by order. Off by default = today's behaviour, byte-for-byte.
        $pluginSourceNames = [];
        $pluginRatings = [];
        if ($includePluginSources && $this->sourceRegistry !== null) {
            $consult = (new PluginSourceConsultation($this->sourceRegistry, $this->logger))
                ->consult('movie', $title, $year, $order);
            foreach ($consult['records'] as $record) {
                $records[] = $record;
            }
            foreach ($consult['sources'] as $sourceName) {
                if (!in_array($sourceName, $order, true)) {
                    $order[] = $sourceName;
                }
            }
            $pluginSourceNames = $consult['sources'];
            $pluginRatings = $consult['ratings'];
        }

        $resolved = $this->fieldResolver->resolve(
            $records,
            $order,
            $priority->genresMode(),
        );
        // Drop the resolver's own provenance/id keys — rebuilt below to match live.
        unset($resolved['external_ids'], $resolved['sources']);

        // external_ids: discovered ids merged OVER caller-supplied ones (unchanged).
        $discovered = array_filter([
            'tmdb' => $tmdbId,
            'imdb' => $imdbId,
        ], static fn(?string $v): bool => $v !== null && $v !== '');
        /** @var array<string, string> $externalIds */
        $externalIds = array_merge($existingExternalIds, $discovered);

        $result = [];
        $result['external_ids'] = $externalIds;

        foreach ($resolved as $key => $value) {
            $result[$key] = $value;
        }

        // Which providers contributed (unchanged: keyed on a non-null payload).
        $sources = [];
        if ($tmdbDetails !== null) {
            $sources[] = 'tmdb';
        }
        if ($imdbData !== null) {
            $sources[] = 'imdb';
        }
        // Append any plugin sources that contributed (empty unless the caller
        // opted into plugin consultation), preserving their consult order.
        foreach ($pluginSourceNames as $sourceName) {
            if (!in_array($sourceName, $sources, true)) {
                $sources[] = $sourceName;
            }
        }
        $result['sources'] = $sources;

        // Ratings surfaced by plugin sources (e.g. omdb's imdb/rt scores). The
        // resolver has no media_item_id, so it never writes metadata_ratings —
        // it only carries them here for the caller (which owns the id) to persist
        // via RatingService. Absent unless a plugin source supplied ratings.
        if ($pluginRatings !== []) {
            $result['plugin_ratings'] = $pluginRatings;
        }

        return $result;
    }

    /**
     * Resolve the TMDB movie id: by IMDb id when known, else by title search.
     *
     * @param string      $title  Movie title.
     * @param int|null    $year   Optional year.
     * @param string|null $imdbId Known IMDb id, if any.
     *
     * @return string|null TMDB id, or null when no TMDB match.
     */
    private function resolveTmdbId(string $title, ?int $year, ?string $imdbId): ?string
    {
        try {
            if ($imdbId !== null) {
                $found = $this->tmdb->findByImdbId($imdbId);
                $id = MetadataValue::asNullableString($found['id'] ?? null);
                if ($id !== null) {
                    return $id;
                }
                return null;
            }

            $results = $this->tmdb->search($title, ['year' => $year]);
            $first = $results[0] ?? null;
            if (is_array($first)) {
                return MetadataValue::asNullableString($first['id'] ?? null);
            }
            return null;
        } catch (Throwable $e) {
            $this->logger->warning('TMDB id resolution failed', [
                'title' => $title,
                'imdb_id' => $imdbId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch TMDB details, tolerating provider errors.
     *
     * @param string $tmdbId TMDB id.
     *
     * @return array<string, mixed>|null Formatted details, or null on error/empty.
     */
    private function safeTmdbDetails(string $tmdbId): ?array
    {
        try {
            $details = $this->tmdb->getDetails($tmdbId);
            return $details === [] ? null : $details;
        } catch (Throwable $e) {
            $this->logger->warning('TMDB getDetails failed', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Offline IMDb title lookup, tolerating errors (e.g. missing local table).
     *
     * Happy path is unchanged: a primary-title match wins. Only when the primary
     * lookup MISSES does this fall back to a conservative exact alternate-title
     * (aka) match, so files whose on-disk title differs from the canonical
     * primaryTitle (foreign titles, transliterations, alternate spellings) can
     * still resolve. The aka fallback is exact-normalized (year-constrained when
     * known) to avoid false positives.
     *
     * @param string   $title Movie title.
     * @param int|null $year  Optional year.
     *
     * @return array<string, mixed>|null IMDb row, or null when no match/error.
     */
    private function safeImdbLookup(string $title, ?int $year): ?array
    {
        try {
            $match = $this->imdb->lookup($title, $year);
            if ($match !== null) {
                return $match;
            }

            // Fallback: match via an alternate/localized aka title.
            $aka = $this->imdb->lookupByAka($title, $year);
            if ($aka !== null) {
                $this->logger->debug('MovieMetadataResolver: resolved via IMDb aka fallback', [
                    'title' => $title,
                    'year' => $year,
                    'imdb_id' => $aka['imdb_id'] ?? null,
                ]);
            }
            return $aka;
        } catch (Throwable $e) {
            $this->logger->warning('IMDb lookup failed', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Offline IMDb fetch by id, tolerating errors.
     *
     * @param string $imdbId IMDb id.
     *
     * @return array<string, mixed>|null IMDb row, or null when no match/error.
     */
    private function safeImdbGetById(string $imdbId): ?array
    {
        try {
            return $this->imdb->getByImdbId($imdbId);
        } catch (Throwable $e) {
            $this->logger->warning('IMDb getByImdbId failed', [
                'imdb_id' => $imdbId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract a non-empty IMDb id from caller-supplied external ids.
     *
     * @param array<string, string> $existingExternalIds External ids.
     *
     * @return string|null IMDb id, or null when absent/empty.
     */
    private function extractImdbId(array $existingExternalIds): ?string
    {
        return MetadataValue::asNullableString($existingExternalIds['imdb'] ?? null);
    }
}
