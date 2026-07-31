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
use Phlix\Media\Metadata\Resolution\FieldMappers;
use Phlix\Media\Metadata\Resolution\PluginSourceConsultation;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\PriorityFieldResolver;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Throwable;

/**
 * TV series metadata resolver — the series-side counterpart to
 * {@see MovieMetadataResolver}.
 *
 * Given a series title (and optional first-air year) it searches TMDB's TV
 * endpoints, fetches the series details, and returns a metadata array shaped
 * with the SAME keys the media-item shaper consumes (`poster_url`, `overview`,
 * `genres`, `year`, …) so a matched series renders a cover + synopsis exactly
 * like a movie. It also exposes {@see self::resolveSeasonEpisodes()} so the
 * matcher can enrich each episode (title/still/overview/air date) from one
 * `/tv/{id}/season/{n}` call per season.
 *
 * Performs NO persistence — it is a pure matching/format unit. TMDB being
 * unavailable (no API key, network error) degrades gracefully to `null`/empty.
 *
 * ## Provider call budget — HARD bound per {@see resolve()} call
 *
 * | | searches (`/search/tv`) | details (`/tv/{id}`) |
 * |---|---:|---:|
 * | common path (exact-title year winner, coverage satisfied) | 1 | 1 |
 * | **worst case** | **3** | **5** |
 *
 * The three searches are the year-scoped one, the year-less one, and the
 * year-less one the coverage guard falls back to when it was never fetched. The
 * five details are the chosen entity, the one winner probe guard 1 pays for
 * corroboration ({@see SeriesCandidateSelector::knowsTitle()}), and at most
 * {@see SeriesCandidateSelector::MAX_ALTERNATIVES} same-title alternatives. No
 * loop here is driven by provider data, so the bound cannot be widened by a
 * response; `/search/tv` is deliberately NOT paginated (see
 * {@see SeriesCandidateSelector::spuriousYearMatchReplacement()} — the live
 * corpus contains 500-page result sets).
 *
 * @package Phlix\Media\Metadata
 * @since   0.24.0
 */
class SeriesMetadataResolver
{
    /** @var string Base URL for the TMDB image CDN. */
    private string $imageBaseUrl = 'https://image.tmdb.org/t/p';

    /** @var PriorityConfig Effective per-media-type source priority. */
    private PriorityConfig $priorityConfig;

    /** @var PriorityFieldResolver Configurable per-field first-non-empty merge engine. */
    private PriorityFieldResolver $fieldResolver;

    /** @var SeriesCandidateSelector Pure series-identification guards for the TMDB TV search. */
    private SeriesCandidateSelector $selector;

    /**
     * @param TmdbProvider               $tmdb           Online TMDB provider (TV endpoints).
     * @param StructuredLogger|null      $loggerOverride Optional logger; defaults to the MEDIA channel.
     * @param PriorityConfig|null        $priorityConfig Effective per-type source priority. When null,
     *     defaults to a series order of `['tmdb']` — i.e. today's TMDB-only behavior — so output is
     *     unchanged for callers that do not inject it. Note the series path only ever builds a TMDB
     *     record, so it stays free of any TVDB-sourced field (e.g. a TVDB site rating mapped into the
     *     imdb_rating slot) regardless of the configured order.
     * @param PriorityFieldResolver|null $fieldResolver  The merge engine; a fresh pure instance by default.
     * @param SourceRegistry|null        $sourceRegistry Enabled plugin metadata sources. Only consulted when
     *     {@see resolve()} is called with `$includePluginSources = true`; null (unit tests / legacy) makes
     *     plugin consultation a no-op, so behaviour is byte-for-byte identical to today's TMDB-only result.
     */
    public function __construct(
        private readonly TmdbProvider $tmdb,
        private readonly ?StructuredLogger $loggerOverride = null,
        ?PriorityConfig $priorityConfig = null,
        ?PriorityFieldResolver $fieldResolver = null,
        private readonly ?SourceRegistry $sourceRegistry = null,
    ) {
        // Default config reproduces today's TMDB-only series behavior. Even if an
        // admin order names other sources, the series path below only constructs a
        // TMDB record, so no other source can contribute a field.
        $this->priorityConfig = $priorityConfig ?? new PriorityConfig(['series' => ['tmdb']]);
        $this->fieldResolver = $fieldResolver ?? new PriorityFieldResolver();
        $this->selector = new SeriesCandidateSelector();
    }

    private function logger(): StructuredLogger
    {
        return $this->loggerOverride ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Resolve series-level metadata for a title.
     *
     * @param string              $title            Series name (e.g. "24").
     * @param int|null            $year             Optional first-air year to disambiguate.
     * @param PriorityConfig|null $priorityOverride Optional per-library effective
     *     priority config (library override layered over the global default). When
     *     provided it drives the genres mode for THIS call instead of the injected
     *     global `$this->priorityConfig`; null (the default) preserves the existing
     *     global behaviour, so all existing callers are unaffected. The series path
     *     stays TMDB-only for the built-ins, so only the genres mode is affected by the override.
     * @param bool                $includePluginSources When true (AND a SourceRegistry is injected),
     *     the enabled plugin metadata sources for `series` are consulted AFTER TMDB and merged UNDER
     *     it (pure gap-fill; TMDB wins every shared field), and any surfaced ratings are returned under
     *     a `plugin_ratings` key for the caller to persist. **DEFAULT false** — the bulk library-scan
     *     path leaves this off so a scan makes ZERO plugin-source network calls. When false, output is
     *     byte-for-byte identical to today (TMDB only).
     * @param int|null            $localHighestSeason Highest NON-SPECIAL season number present in the
     *     caller's local tree for this series (season 0 excluded; null when unknown or when the tree
     *     has no numbered season yet). Used ONLY by the season-coverage guard
     *     {@see seasonCoverageSwap()} to detect a same-titled entity that cannot possibly hold the
     *     local tree (miniseries vs series, remake vs original). **DEFAULT null = the guard is off**,
     *     so every caller that does not supply it keeps today's behaviour byte-for-byte.
     *
     * @return array<string, mixed>|null Metadata to merge (with `external_ids.tmdb`
     *     + `tmdb_id` so the caller can fetch seasons), or null on no match.
     */
    public function resolve(
        string $title,
        ?int $year,
        ?PriorityConfig $priorityOverride = null,
        bool $includePluginSources = false,
        ?int $localHighestSeason = null
    ): ?array {
        if (trim($title) === '') {
            return null;
        }

        try {
            $this->logger()->info('SeriesMetadataResolver: searching', [
                'title' => $title,
                'year' => $year,
            ]);

            $search = $this->searchSeries($title, $year);
            $tmdbId = $search['id'];
            if ($tmdbId === null) {
                $this->logger()->info('SeriesMetadataResolver: search returned no id', [
                    'title' => $title,
                    'year' => $year,
                ]);
                return null;
            }

            $this->logger()->info('SeriesMetadataResolver: fetching details', [
                'title' => $title,
                'year' => $year,
                'tmdb_id' => $tmdbId,
            ]);

            // Guard 1 may already have paid for these details while corroborating
            // the winner it decided to KEEP; reusing them makes the decline path
            // cost nothing extra.
            $details = $search['details'] ?? $this->tmdb->getTvDetails($tmdbId);
            if ($details === []) {
                $this->logger()->info('SeriesMetadataResolver: details returned empty', [
                    'title' => $title,
                    'tmdb_id' => $tmdbId,
                ]);
                return null;
            }

            $swap = $this->seasonCoverageSwap(
                $title,
                $tmdbId,
                $details,
                $localHighestSeason,
                $search['yearLess'],
            );
            if ($swap !== null) {
                $this->logger()->info('SeriesMetadataResolver: season-coverage guard swapped entity', [
                    'title' => $title,
                    'year' => $year,
                    'local_highest_season' => $localHighestSeason,
                    'rejected_tmdb_id' => $tmdbId,
                    'rejected_seasons' => MetadataValue::asInt($details['number_of_seasons'] ?? null),
                    'tmdb_id' => $swap['id'],
                    'seasons' => MetadataValue::asInt($swap['details']['number_of_seasons'] ?? null),
                ]);
                $tmdbId = $swap['id'];
                $details = $swap['details'];
            }

            $result = $this->format($tmdbId, $details, $priorityOverride, $includePluginSources, $title, $year);

            $resultExternalIds = is_array($result['external_ids'] ?? null) ? $result['external_ids'] : [];
            $this->logger()->info('SeriesMetadataResolver: resolved', [
                'title' => $title,
                'year' => $year,
                'tmdb_id' => $tmdbId,
                'imdb_id' => $resultExternalIds['imdb'] ?? null,
                'tvdb_id' => $resultExternalIds['tvdb'] ?? null,
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->logger()->warning('SeriesMetadataResolver: resolve failed', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Resolve a season's poster/overview + a per-episode-number metadata map.
     *
     * @param string $tmdbId       TMDB series id.
     * @param int    $seasonNumber Season number (0 = Specials).
     *
     * @return array{
     *     poster_url: string|null,
     *     overview: string,
     *     episodes: array<int, array{
     *         episode_title: string|null,
     *         overview: string|null,
     *         poster_url: string|null,
     *         still_url: string|null,
     *         air_date: string|null,
     *         runtime: int|null,
     *         vote_average: float|null,
     *         cast: list<array{name: string, role: string, profile_url: string|null}>,
     *         crew: list<array{name: string, job: string, profile_url: string|null}>
     *     }>
     * } Empty `episodes` when the season is unknown.
     */
    public function resolveSeasonEpisodes(string $tmdbId, int $seasonNumber): array
    {
        $empty = ['poster_url' => null, 'overview' => '', 'episodes' => []];
        if ($tmdbId === '') {
            return $empty;
        }

        try {
            $season = $this->tmdb->getTvSeason($tmdbId, $seasonNumber);
        } catch (Throwable $e) {
            $this->logger()->warning('SeriesMetadataResolver: season fetch failed', [
                'tmdb_id' => $tmdbId,
                'season' => $seasonNumber,
                'error' => $e->getMessage(),
            ]);
            return $empty;
        }

        $episodes = [];
        foreach ($season['episodes'] ?? [] as $ep) {
            $number = MetadataValue::asInt($ep['episode_number'] ?? null);
            $vote = MetadataValue::asFloat($ep['vote_average'] ?? null);
            // Each episode's own still (a landscape frame). Used as the episode
            // image; when TMDB has no still this is null and enrichEpisode()
            // falls through to the season/series poster. Distinct stills have
            // distinct paths, so the artwork dedup downloads each exactly once.
            $stillUrl = $this->imageUrl(MetadataValue::asNullableString($ep['still_path'] ?? null));
            $episodes[$number] = [
                'episode_title' => MetadataValue::asNullableString($ep['name'] ?? null),
                'overview' => MetadataValue::asNullableString($ep['overview'] ?? null),
                'poster_url' => $stillUrl,
                'still_url' => $stillUrl,
                'air_date' => MetadataValue::asNullableString($ep['air_date'] ?? null),
                'runtime' => (($r = MetadataValue::asInt($ep['runtime'] ?? null)) > 0) ? $r : null,
                'vote_average' => $vote > 0.0 ? $vote : null,
                'cast' => $this->castList($ep['cast'] ?? null),
                'crew' => $this->crewList($ep['crew'] ?? null),
            ];
        }

        return [
            'poster_url' => $this->imageUrl($season['poster_path'] ?? null),
            'overview' => MetadataValue::asString($season['overview'] ?? null),
            'episodes' => $episodes,
        ];
    }

    /**
     * Find the TMDB series id by title (+ optional year).
     *
     * Falls back to a year-less search when a year-scoped search finds nothing,
     * and applies the spurious-year-match guard
     * ({@see SeriesCandidateSelector::spuriousYearMatchReplacement()}) when the
     * year-scoped winner's title is not an exact match for the query. The extra
     * year-less request is issued ONLY in that inexact case — measured against
     * the live library that is 52 of 434 series, so the common path costs exactly
     * the one search it costs today.
     *
     * A proposed replacement is never acted on unsupported: the winner is probed
     * once with `getTvDetails()` and kept whenever TMDB itself knows it under the
     * queried title ({@see SeriesCandidateSelector::knowsTitle()}). Those details
     * are handed back so the keep path costs no extra request, and a failed probe
     * keeps the winner too — no corroboration, no swap.
     *
     * @param string   $title Series title.
     * @param int|null $year  Optional first-air-year hint.
     *
     * @return array{
     *     id: string|null,
     *     yearLess: array<int, array<string, mixed>>|null,
     *     details: array<string, mixed>|null
     * } The chosen TMDB id, the year-less candidate list when one was fetched
     *   (null when it was not, so the caller can fetch it lazily), and the chosen
     *   entity's already-fetched details when the guard paid for them.
     */
    private function searchSeries(string $title, ?int $year): array
    {
        if ($year === null) {
            $results = $this->tmdb->searchTv($title);
            return ['id' => $this->firstId($title, $year, $results), 'yearLess' => $results, 'details' => null];
        }

        $scoped = $this->tmdb->searchTv($title, ['first_air_date_year' => $year]);
        if ($scoped === []) {
            $this->logger()->debug('SeriesMetadataResolver: year-scoped search empty, retrying without year', [
                'title' => $title,
                'year' => $year,
            ]);
            $results = $this->tmdb->searchTv($title); // retry without the year filter
            return ['id' => $this->firstId($title, $year, $results), 'yearLess' => $results, 'details' => null];
        }

        $winner = $scoped[0];
        if ($this->selector->isExactTitleMatch($title, $winner)) {
            // Fast path: the year filter produced a title-identical hit. No extra
            // request, and the year-less list is left unfetched for the caller.
            return ['id' => $this->firstId($title, $year, $scoped), 'yearLess' => null, 'details' => null];
        }

        $yearLess = $this->tmdb->searchTv($title);
        $replacement = $this->selector->spuriousYearMatchReplacement($title, $winner, $yearLess);
        if ($replacement === null) {
            return ['id' => $this->firstId($title, $year, $scoped), 'yearLess' => $yearLess, 'details' => null];
        }

        $winnerId = MetadataValue::asString($winner['id'] ?? null);
        $winnerDetails = $winnerId !== '' ? $this->tmdb->getTvDetails($winnerId) : [];
        if ($winnerDetails === [] || $this->selector->knowsTitle($title, $winnerDetails)) {
            // Either the corroborating fact could not be established (a failed
            // probe) or it came back POSITIVE — TMDB does know this entity under
            // the queried title, so the year filter did not fabricate it. Keep it.
            $this->logger()->info('SeriesMetadataResolver: kept an inexact year-scoped match', [
                'title' => $title,
                'year' => $year,
                'tmdb_id' => $winnerId,
                'name' => MetadataValue::asNullableString($winner['name'] ?? null),
                'reason' => $winnerDetails === [] ? 'details_unavailable' : 'provider_knows_this_title',
            ]);
            return [
                'id' => $this->firstId($title, $year, $scoped),
                'yearLess' => $yearLess,
                'details' => $winnerDetails === [] ? null : $winnerDetails,
            ];
        }

        $this->logger()->info('SeriesMetadataResolver: discarded a spurious year-scoped match', [
            'title' => $title,
            'year' => $year,
            'rejected_tmdb_id' => $winnerId,
            'rejected_name' => MetadataValue::asNullableString($winner['name'] ?? null),
            'tmdb_id' => MetadataValue::asNullableString($replacement['id'] ?? null),
            'name' => MetadataValue::asNullableString($replacement['name'] ?? null),
        ]);

        return ['id' => $this->firstId($title, $year, [$replacement]), 'yearLess' => $yearLess, 'details' => null];
    }

    /**
     * Narrow a candidate list's first entry to a non-empty TMDB id, logging the
     * outcome exactly as the pre-guard code path did.
     *
     * @param array<int, array<string, mixed>> $results Candidate rows.
     *
     * @return string|null Non-empty TMDB id, or null when there is none.
     */
    private function firstId(string $title, ?int $year, array $results): ?string
    {
        if ($results === []) {
            $this->logger()->info('SeriesMetadataResolver: searchTv returned no results', [
                'title' => $title,
                'year' => $year,
            ]);
            return null;
        }

        $id = MetadataValue::asNullableString($results[0]['id'] ?? null);
        $this->logger()->info('SeriesMetadataResolver: searchTv result', [
            'title' => $title,
            'year' => $year,
            'first_result_tmdb_id' => $id,
            'total_results' => count($results),
        ]);
        return ($id !== null && $id !== '') ? $id : null;
    }

    /**
     * Season-coverage guard — reject an entity that cannot hold the local tree.
     *
     * A same-titled TMDB entity with FEWER seasons than the caller's tree has is
     * the measured signature of a wrong incarnation: the 2-episode
     * `Battlestar Galactica` 2003 miniseries standing in for the 4-season 2004
     * series, or the 2024 live-action `Avatar: The Last Airbender` standing in for
     * the 3-season 2005 animated series (TMDB's own relevance ranking prefers the
     * remake, so no title/year heuristic can catch that one).
     *
     * Fail-closed by construction:
     *  - off entirely unless the caller supplied `$localHighestSeason >= 2`;
     *  - only STRICT-EXACT title alternatives are eligible;
     *  - the alternative must share a production origin with the entity it would
     *    replace ({@see SeriesCandidateSelector::sharesProductionOrigin()}) —
     *    corroboration that does NOT depend on the local season count, so a
     *    sibling step that re-parses the local tree cannot silently remove the
     *    only thing keeping an unrelated show out;
     *  - the alternative must itself cover `$localHighestSeason`, otherwise the
     *    original stands.
     * ⚠ **Known limit:** a local tree whose seasons are really absolute episode
     * ordinals is out of scope here — the alternative has to cover the local
     * season span exactly, so those simply find no alternative and are left alone
     * (measured: 16 of the 18 live series that trip the coverage check).
     *
     * @param string                          $title              Series title searched for.
     * @param string                          $tmdbId             Currently chosen TMDB id.
     * @param array<string, mixed>            $details            Its `getTvDetails()` payload.
     * @param int|null                        $localHighestSeason Highest local non-special season.
     * @param array<int, array<string, mixed>>|null $yearLess     Year-less candidates when already
     *     fetched; null makes this method fetch them (only in the rare coverage-miss case).
     *
     * @return array{id: string, details: array<string, mixed>}|null The better entity, or null to keep
     *     the current one.
     */
    private function seasonCoverageSwap(
        string $title,
        string $tmdbId,
        array $details,
        ?int $localHighestSeason,
        ?array $yearLess
    ): ?array {
        if ($localHighestSeason === null) {
            return null;
        }
        // A single-season tree needs no explicit guard: any entity TMDB reports
        // at all has >= 1 season, so the coverage test below is already false.
        $providerSeasons = MetadataValue::asInt($details['number_of_seasons'] ?? null);
        if ($providerSeasons <= 0 || $providerSeasons >= $localHighestSeason) {
            return null;
        }

        $candidates = $yearLess ?? $this->tmdb->searchTv($title);
        foreach ($this->selector->exactTitleAlternatives($title, $candidates, $tmdbId) as $alternative) {
            $altId = MetadataValue::asString($alternative['id'] ?? null);
            if ($altId === '') {
                continue;
            }
            $altDetails = $this->tmdb->getTvDetails($altId);
            if ($altDetails === []) {
                continue;
            }
            if (!$this->selector->sharesProductionOrigin($details, $altDetails)) {
                // A same-titled show from another country/language is another
                // show, not another incarnation of this one. Live case: the
                // `Blood+` folder is offered TMDB 84768 `Blood` (en/IE) against
                // 19849 `Blood+` (ja/JP).
                $this->logger()->info('SeriesMetadataResolver: alternative rejected, production origin differs', [
                    'title' => $title,
                    'tmdb_id' => $tmdbId,
                    'rejected_alternative' => $altId,
                ]);
                continue;
            }
            if (MetadataValue::asInt($altDetails['number_of_seasons'] ?? null) >= $localHighestSeason) {
                return ['id' => $altId, 'details' => $altDetails];
            }
        }

        return null;
    }

    /**
     * Shape raw TMDB series details into a mergeable metadata array.
     *
     * @param array<string, mixed> $details
     * @param PriorityConfig|null  $priorityOverride Per-library override; when null the
     *     injected global `$this->priorityConfig` drives the genres mode.
     * @param bool                 $includePluginSources When true and a SourceRegistry is injected,
     *     plugin `series` sources are consulted and merged UNDER TMDB; their ratings surface under
     *     `plugin_ratings`. Default false = today's TMDB-only behaviour.
     * @param string               $title            Title fed to plugin `search()` (opt-in only).
     * @param int|null             $year             Optional year hint for plugin search.
     * @return array<string, mixed>
     */
    private function format(
        string $tmdbId,
        array $details,
        ?PriorityConfig $priorityOverride = null,
        bool $includePluginSources = false,
        string $title = '',
        ?int $year = null
    ): array {
        // Per-field selection is delegated to PriorityFieldResolver. The series
        // path builds ONLY a TMDB record, so it stays TMDB-only — no TVDB/IMDb
        // source can contribute a field (in particular no TVDB site rating can
        // surface in the imdb_rating slot), preserving today's output exactly.
        // FieldMappers::fromTmdb reproduces the live per-field shaping: `name`→
        // title, `*_path`→`*_url` (/w500), genres→cleaned string list, `year`,
        // `official_rating`, flat actor names, verbatim cast/crew/companies, studio.
        // A fixed `['tmdb']` order is used (not the configured series order) so the
        // series resolver remains robustly TMDB-driven regardless of admin config
        // until real series sources are registered (Step 3.5).
        $priority = $priorityOverride ?? $this->priorityConfig;

        // The built-in series record is TMDB-only; the built-in merge order stays
        // `['tmdb']` regardless of the configured order (no TVDB/IMDb record is
        // built here). F2: when the caller opts in AND a registry is wired, plugin
        // `series` sources are appended to the END of the order so TMDB wins every
        // shared field (pure gap-fill). Off by default = today's output, verbatim.
        $records = [FieldMappers::fromTmdb($details)];
        $order = ['tmdb'];
        $pluginSourceNames = [];
        $pluginRatings = [];
        if ($includePluginSources && $this->sourceRegistry !== null) {
            $consult = (new PluginSourceConsultation($this->sourceRegistry, $this->logger()))
                ->consult('series', $title, $year, $priority->orderFor('series'));
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
        // Drop the resolver's provenance/id keys — rebuilt below to match the live
        // shape exactly (sources=['tmdb', …plugins], an explicit tmdb_id, and the
        // tmdb+imdb external_ids derived from the resolved id and the details).
        unset($resolved['external_ids'], $resolved['sources']);

        $result = [
            'external_ids' => array_filter([
                'tmdb' => $tmdbId,
                'imdb' => MetadataValue::asNullableString($details['imdb_id'] ?? null),
                // TheTVDB id (from TmdbProvider::formatTvDetails `external_ids`).
                // Threaded here so the theme-music resolver (M3) can build the
                // Plex-archive fallback URL keyed on the TVDB id.
                'tvdb' => MetadataValue::asNullableString($details['tvdb_id'] ?? null),
            ], static fn(?string $v): bool => $v !== null && $v !== ''),
            'tmdb_id' => $tmdbId,
            'sources' => array_merge(['tmdb'], $pluginSourceNames),
        ];
        if ($pluginRatings !== []) {
            $result['plugin_ratings'] = $pluginRatings;
        }

        foreach ($resolved as $key => $value) {
            $result[$key] = $value;
        }

        // Tags/keywords are not part of the canonical merge vocabulary
        // (SourceRecord::CANONICAL_FIELDS), so carry them straight from the raw
        // TMDB details. Series-level; episodes inherit them via the matcher.
        $tags = $this->stringList($details['tags'] ?? null);
        if ($tags !== []) {
            $result['tags'] = $tags;
        }

        return $result;
    }

    private function imageUrl(mixed $path): ?string
    {
        $clean = MetadataValue::asNullableString($path);
        if ($clean === null) {
            return null;
        }
        return $this->imageBaseUrl . '/w500' . $clean;
    }

    /**
     * Narrow a mixed value to a de-duplicated list of non-empty strings.
     *
     * @param mixed $value
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
     * Narrow a raw episode cast value to the canonical shape the media-item shaper
     * renders — `{name, role, profile_url}`. Entries without a name are dropped;
     * profile URLs are already full TMDB URLs from {@see \Phlix\Media\Metadata\TmdbProvider}.
     *
     * @param mixed $value Raw cast list from `getTvSeason()`.
     * @return list<array{name: string, role: string, profile_url: string|null}>
     */
    private function castList(mixed $value): array
    {
        $out = [];
        foreach ($this->namedEntries($value) as $entry) {
            $out[] = [
                'name' => MetadataValue::asString($entry['name'] ?? null),
                'role' => MetadataValue::asString($entry['role'] ?? null),
                'profile_url' => MetadataValue::asNullableString($entry['profile_url'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Narrow a raw episode crew value to the canonical shape the media-item shaper
     * renders — `{name, job, profile_url}`. Entries without a name are dropped.
     *
     * @param mixed $value Raw crew list from `getTvSeason()`.
     * @return list<array{name: string, job: string, profile_url: string|null}>
     */
    private function crewList(mixed $value): array
    {
        $out = [];
        foreach ($this->namedEntries($value) as $entry) {
            $out[] = [
                'name' => MetadataValue::asString($entry['name'] ?? null),
                'job' => MetadataValue::asString($entry['job'] ?? null),
                'profile_url' => MetadataValue::asNullableString($entry['profile_url'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Yield the array entries of a raw people list that carry a non-empty `name`.
     *
     * @param mixed $value Raw cast/crew list.
     * @return list<array<string, mixed>>
     */
    private function namedEntries(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (MetadataValue::asString($entry['name'] ?? null) === '') {
                continue;
            }
            $out[] = $entry;
        }
        return $out;
    }
}
