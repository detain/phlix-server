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
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\PriorityFieldResolver;
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
     * @param TmdbProvider               $tmdb           Online TMDB provider.
     * @param ImdbLookup                 $imdb           Offline IMDb dataset lookup.
     * @param StructuredLogger|null      $logger         Optional logger; defaults to the MEDIA channel.
     * @param PriorityConfig|null        $priorityConfig Effective per-type source priority. When null,
     *     defaults to the canonical `['tmdb','imdb']` order — i.e. today's hard-coded precedence — so
     *     behavior is unchanged for callers that do not inject it.
     * @param PriorityFieldResolver|null $fieldResolver  The merge engine; a fresh pure instance by default.
     *
     * @since 0.21.0
     */
    public function __construct(
        TmdbProvider $tmdb,
        ImdbLookup $imdb,
        ?StructuredLogger $logger = null,
        ?PriorityConfig $priorityConfig = null,
        ?PriorityFieldResolver $fieldResolver = null
    ) {
        $this->tmdb = $tmdb;
        $this->imdb = $imdb;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
        // Default config reproduces today's hard-coded `[tmdb, imdb]` precedence,
        // keeping the merge behavior-identical when no PriorityConfig is injected.
        $this->priorityConfig = $priorityConfig ?? new PriorityConfig(['movie' => ['tmdb', 'imdb']]);
        $this->fieldResolver = $fieldResolver ?? new PriorityFieldResolver();
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
        ?PriorityConfig $priorityOverride = null
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
            if ($tmdbDetails !== null && $imdbId === null) {
                $fromTmdb = MetadataValue::asNullableString($tmdbDetails['imdb_id'] ?? null);
                if ($fromTmdb !== null) {
                    $imdbId = $fromTmdb;
                }
            }
        }

        // 4. Fetch offline IMDb data for the (possibly cross-populated) id.
        $imdbData = $imdbLookupData;
        if ($imdbId !== null && ($imdbData === null || ($imdbData['imdb_id'] ?? null) !== $imdbId)) {
            $byId = $this->safeImdbGetById($imdbId);
            if ($byId !== null) {
                $imdbData = $byId;
            }
        }

        // 5. Merge — bail out only when NEITHER source produced anything.
        if ($tmdbDetails === null && $imdbData === null) {
            return null;
        }

        return $this->merge(
            $existingExternalIds,
            $tmdbId,
            $imdbId,
            $tmdbDetails,
            $imdbData,
            $priorityOverride,
        );
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
     *
     * @return array<string, mixed> Merged details.
     */
    private function merge(
        array $existingExternalIds,
        ?string $tmdbId,
        ?string $imdbId,
        ?array $tmdbDetails,
        ?array $imdbData,
        ?PriorityConfig $priorityOverride = null
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
        $resolved = $this->fieldResolver->resolve(
            $records,
            $priority->orderFor('movie'),
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
        $result['sources'] = $sources;

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
     * @param string   $title Movie title.
     * @param int|null $year  Optional year.
     *
     * @return array<string, mixed>|null IMDb row, or null when no match/error.
     */
    private function safeImdbLookup(string $title, ?int $year): ?array
    {
        try {
            return $this->imdb->lookup($title, $year);
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
