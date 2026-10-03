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
     * @var MatchConfidencePolicy Threshold gate for the title-search path (F3).
     *     A store-less instance (legacy construction / unit tests) yields the
     *     shipped default 0.0, which keeps the blind-first-result behaviour
     *     byte-for-byte identical to before the setting existed.
     */
    private MatchConfidencePolicy $confidencePolicy;

    /**
     * Title weight of the bounded title/year similarity heuristic (see
     * {@see matchConfidence()}). The two weights sum to 1.0 so the score stays
     * inside its advertised 0..1 domain by construction.
     */
    public const TITLE_WEIGHT = 0.7;

    /**
     * Year-exactness weight of the heuristic; see {@see self::TITLE_WEIGHT}.
     */
    public const YEAR_WEIGHT = 0.3;

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
     * @param MatchConfidencePolicy|null $confidencePolicy F3 gate for `metadata.min_match_confidence`.
     *     Null (unit tests / legacy) builds a store-less policy that returns the shipped default 0.0 =
     *     gate off = today's blind `$results[0]` behaviour preserved byte-for-byte.
     *
     * @since 0.21.0
     */
    public function __construct(
        TmdbProvider $tmdb,
        ImdbLookup $imdb,
        ?StructuredLogger $logger = null,
        ?PriorityConfig $priorityConfig = null,
        ?PriorityFieldResolver $fieldResolver = null,
        ?SourceRegistry $sourceRegistry = null,
        ?MatchConfidencePolicy $confidencePolicy = null
    ) {
        $this->tmdb = $tmdb;
        $this->imdb = $imdb;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
        // Default config reproduces today's hard-coded `[tmdb, imdb]` precedence,
        // keeping the merge behavior-identical when no PriorityConfig is injected.
        $this->priorityConfig = $priorityConfig ?? new PriorityConfig(['movie' => ['tmdb', 'imdb']]);
        $this->fieldResolver = $fieldResolver ?? new PriorityFieldResolver();
        $this->sourceRegistry = $sourceRegistry;
        $this->confidencePolicy = $confidencePolicy ?? new MatchConfidencePolicy();
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
     * F3 (`metadata.min_match_confidence`): the title-search path used to accept
     * `$results[0]` BLIND — TMDB orders results by its own popularity ranking,
     * so a wrong-but-popular first hit silently became the match. When the
     * effective threshold is above 0, the first result is now scored against
     * the requested title/year via {@see matchConfidence()} and a score below
     * the threshold routes to the SAME no-match path an empty result set takes
     * (null → merge proceeds IMDb-only or bails). The gate does not re-rank the
     * result list — it only accepts/rejects the candidate the old code would
     * have taken unconditionally. At the shipped default 0.0 the scorer is not
     * even invoked, so today's behaviour is byte-preserved by construction.
     *
     * The IMDb-id path is deliberately UNGATED: an `tt…` id match is an exact
     * external-identity lookup, not a fuzzy title guess — there is nothing for
     * a similarity heuristic to score.
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
                $threshold = $this->confidencePolicy->minConfidence();
                if ($threshold > 0.0) {
                    $confidence = self::matchConfidence($first, $title, $year);
                    if ($confidence < $threshold) {
                        $this->logger->info(
                            'MovieMetadataResolver: first TMDB search result rejected below confidence',
                            [
                                'title' => $title,
                                'year' => $year,
                                'candidate_id' => MetadataValue::asNullableString($first['id'] ?? null),
                                'candidate_title' => MetadataValue::asNullableString($first['title'] ?? null),
                                'confidence' => $confidence,
                                'threshold' => $threshold,
                            ]
                        );
                        return null;
                    }
                }
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
     * Bounded title/year similarity heuristic (0..1) for one TMDB search result.
     *
     * ## What this IS
     *
     * A cheap, deterministic, in-resolver guess that the first search result
     * plausibly IS the requested film. Pure (no I/O, no clock) so the whole
     * table is unit-testable. Deliberately NOT provider-side TMDB relevance
     * scoring (popularity/vote-weighted) — that is a follow-up ticket; this
     * function's name and bounds must never be described as an accuracy or
     * probability claim.
     *
     * ## Score model
     *
     *  - `titleSim`: 1.0 when the normalized titles are equal (case, edges and
     *    interior whitespace normalized; no diacritic folding, no article
     *    handling — `Matrix, The` ≠ `The Matrix`); else the `similar_text`
     *    common-character fraction (a byte-overlap measure; ASCII-titles only
     *    are precisely modelled, non-ASCII degrades conservatively).
     *  - Requested year KNOWN:  `0.7*titleSim + 0.3*(candidate year exactly equal ? 1 : 0)`.
     *    A candidate WITHOUT a usable release year earns no year credit (0.7
     *    ceiling) — absence of corroboration is not corroboration.
     *  - Requested year UNKNOWN: year is not a comparable signal at all, so the
     *    score is titleSim alone.
     *
     * Table anchors: exact title + exact year = 1.0; exact title + year
     * mismatch (or missing candidate year) = 0.7; empty candidate title = the
     * bare year credit (≤0.3).
     *
     * @param array<array-key, mixed> $candidate One formatted `TmdbProvider::search()`
     *     result row (`id`/`title`/`original_title`/`release_date`).
     * @param string                  $wantedTitle  Title the file is being matched under.
     * @param int|null                $wantedYear   Year parsed from the filename, if any.
     *
     * @return float Confidence in 0..1.
     *
     * @since 1.8.0
     */
    public static function matchConfidence(array $candidate, string $wantedTitle, ?int $wantedYear): float
    {
        $candidateTitle = self::candidateTitle($candidate);
        $titleSim = self::titleSimilarity($wantedTitle, $candidateTitle);

        if ($wantedYear === null) {
            return $titleSim;
        }

        $candidateYear = self::candidateYear($candidate);
        $yearCredit = ($candidateYear !== null && $candidateYear === $wantedYear) ? 1.0 : 0.0;

        return max(0.0, min(1.0, (self::TITLE_WEIGHT * $titleSim) + (self::YEAR_WEIGHT * $yearCredit)));
    }

    /**
     * Best available display title on a search-result row (title, else original).
     *
     * @param array<array-key, mixed> $candidate Search-result row.
     */
    private static function candidateTitle(array $candidate): string
    {
        $title = MetadataValue::asString($candidate['title'] ?? null);
        if ($title !== '') {
            return $title;
        }

        return MetadataValue::asString($candidate['original_title'] ?? null);
    }

    /**
     * Release YEAR parsed from a TMDB `release_date` ('Y-m-d') string, if any.
     *
     * Only an unambiguous year counts: `YYYY` and `YYYY-MM-DD` (TMDB's two
     * real shapes) parse; empty, month-partial (`'2024-08'`) or otherwise
     * malformed dates yield null — the heuristic never guesses a year.
     *
     * @param array<array-key, mixed> $candidate Search-result row.
     */
    private static function candidateYear(array $candidate): ?int
    {
        $releaseDate = MetadataValue::asString($candidate['release_date'] ?? null);
        if (preg_match('/^(\d{4})(?:-\d{2}-\d{2})?$/', $releaseDate, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Normalized-title equality (1.0) or `similar_text` character-overlap
     * fraction (0..1) between the wanted and candidate titles.
     *
     * Normalization is intentionally shallow — trim, lowercase, collapse
     * whitespace — because every extra fold (articles, punctuation, diacritics)
     * is a precision CLAIM the byte-overlap model cannot back. Either side
     * empty means there is no title to compare at all: 0.0.
     */
    private static function titleSimilarity(string $wanted, string $candidate): float
    {
        $a = self::normalizeTitle($wanted);
        $b = self::normalizeTitle($candidate);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $percent = 0.0;
        similar_text($a, $b, $percent);

        return max(0.0, min(1.0, $percent / 100));
    }

    /**
     * Lowercase, trim, and collapse interior whitespace of a title.
     *
     * `mb_strtolower` for correct Unicode casing; the whitespace fold uses the
     * `/u` modifier with a plain-space fallback so an invalid-UTF-8 byte
     * sequence (from a corrupt filename upstream) cannot turn comparison into a
     * preg-null silent "unequal" against everything.
     */
    private static function normalizeTitle(string $title): string
    {
        $lower = mb_strtolower(trim($title), 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $lower);

        return $collapsed ?? $lower;
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
