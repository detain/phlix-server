<?php

/**
 * Phlix media server component: Resolution.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Metadata\Resolution;

/**
 * Configurable, per-field "first non-empty value" merge across several
 * {@see SourceRecord}s.
 *
 * Step 3.1 normalized every provider's raw payload onto one canonical field
 * vocabulary ({@see SourceRecord::CANONICAL_FIELDS}). This resolver consumes a
 * collection of those records plus an ORDERED list of source names and produces
 * a single merged metadata array: for each canonical field it walks the source
 * order and takes the FIRST source that supplied a non-empty value, exactly the
 * way the live {@see \Phlix\Media\Metadata\MovieMetadataResolver::merge()} and
 * {@see \Phlix\Media\Metadata\SeriesMetadataResolver} pick fields today — but
 * driven by a configurable order instead of hard-coded per-field choices.
 *
 * ## Why this reproduces today's behavior under `['tmdb','imdb']`
 *
 * The live movie merge is precisely a first-non-empty walk of `[tmdb, imdb]`:
 *  - `title`            tmdb `name` else imdb `title`           → first-non-empty
 *  - `overview`/images  tmdb only                               → only tmdb has the key
 *  - `genres`           tmdb if non-empty else imdb             → first-non-empty list
 *  - `year`/`runtime`   tmdb else imdb                          → first-non-empty
 *  - cast/crew/etc.     tmdb only                               → only tmdb has the key
 *  - `imdb_rating`/`imdb_votes` imdb only                       → only imdb has the key
 *  - `external_ids`     merged                                  → union (earlier source wins)
 *  - `sources`          contributing providers                 → union list
 *
 * The 3.1 mappers already mirror the "only X has this key" facts (e.g. the tmdb
 * record carries no `imdb_rating` key, the imdb record carries no `poster_url`
 * key), so a plain first-non-empty walk of `[tmdb, imdb]` lands on the identical
 * source for every field. Step 3.4 can therefore swap the hand-rolled merges for
 * this resolver without changing output under the default order.
 *
 * ## "Empty" — defined to match the live resolvers exactly
 *
 *  - **String fields** (`title`, `overview`, `poster_url`, `backdrop_url`,
 *    `official_rating`, `director`, `studio`): empty when `null` or `''`. This
 *    mirrors {@see \Phlix\Media\Metadata\Dto\MetadataValue::asNullableString()},
 *    which rejects only the empty string (it does NOT trim whitespace), so the
 *    resolver deliberately does NOT trim — a `'  '` value is non-empty, exactly
 *    as today.
 *  - **Numeric fields** (`year`, `runtime`, `imdb_votes` ints; `imdb_rating`
 *    float): empty ONLY when absent/`null`. A genuine `0`/`0.0` is NON-empty and
 *    is taken. (The mappers already drop a `runtime` of `<= 0`, so a present 0
 *    never reaches here for runtime; for the others a real 0 is a legitimate
 *    value the live code would also keep once present.)
 *  - **List fields** (`genres`, `actors`, `cast`, `crew`,
 *    `production_companies`): empty when `null` or `[]`, mirroring the live
 *    `if ($genres !== [])` / `if ($actors !== [])` guards.
 *  - **`external_ids`**: never first-non-empty — always UNIONed (see below).
 *
 * A field that a record does not carry at all ({@see SourceRecord::has()} false)
 * is skipped; the resolver relies on the present-vs-absent contract, not on
 * `=== null` probing of typed accessors.
 *
 * ## `external_ids` (union)
 *
 * Unlike scalars, ids from every record in `$sourceOrder` are UNIONed into one
 * map. On a key collision the EARLIER source in `$sourceOrder` wins (same
 * precedence direction as the scalar walk; matches the live merge, which layers
 * discovered ids over caller-supplied ones and never lets a later source clobber
 * an id an earlier source already provided).
 *
 * ## `sources` (provenance)
 *
 * The result carries a `sources` list naming every record (in `$sourceOrder`
 * order) that contributed AT LEAST ONE present canonical field — the same
 * provenance the live resolvers emit (`['tmdb','imdb']`). A source present in
 * the order but with an empty/field-less record is omitted.
 *
 * ## `genres` modes
 *
 *  - `'first'` (DEFAULT): genres behave like every other list field — the first
 *    source with a non-empty list wins. This is behavior-preserving for 3.4.
 *  - `'union'`: genres from every contributing record are concatenated and
 *    de-duplicated (earlier source first), supporting the optional
 *    `metadata.genres_mode` setting added in Step 3.3.
 *
 * ## Sources not in `$sourceOrder`
 *
 * Records whose source name is absent from `$sourceOrder` are IGNORED entirely
 * (they contribute no fields, no ids and no `sources` entry). This keeps the
 * default `['tmdb','imdb']` output identical to today even if a caller hands in
 * extra records, and makes the order the single source of truth for precedence.
 *
 * Pure: performs NO I/O and holds NO mutable static/global state — safe to
 * construct per request under Workerman's resident-memory model.
 *
 * @phpstan-type CanonicalResult array<string, mixed>
 *
 * @package Phlix\Media\Metadata\Resolution
 * @since   Feature 3 (metadata source priority)
 */
final class PriorityFieldResolver
{
    /** Genres take the first non-empty list (default; behavior-preserving). */
    public const GENRES_FIRST = 'first';

    /** Genres union+dedupe across all contributing records. */
    public const GENRES_UNION = 'union';

    /**
     * Canonical fields whose value is a `list<string>` or `list<array>` and
     * that are therefore "empty" when `null` or `[]`.
     *
     * @var list<string>
     */
    private const LIST_FIELDS = [
        'genres',
        'actors',
        'cast',
        'crew',
        'production_companies',
    ];

    /**
     * Canonical fields whose value is numeric (int|float); "empty" ONLY when
     * absent — a genuine `0` is kept.
     *
     * @var list<string>
     */
    private const NUMERIC_FIELDS = [
        'year',
        'runtime',
        'imdb_rating',
        'imdb_votes',
    ];

    /**
     * Merge a collection of canonical {@see SourceRecord}s into one metadata
     * array using a configurable per-field priority.
     *
     * @param iterable<SourceRecord>  $records     The per-provider records. Each
     *     record carries its own source name ({@see SourceRecord::$source}); the
     *     iterable may be keyed however the caller likes — keys are ignored, the
     *     record's own `source` is authoritative.
     * @param list<string>            $sourceOrder Ordered source names, highest
     *     priority first (e.g. `['tmdb','imdb']`). Records whose source is not in
     *     this list are ignored.
     * @param string                  $genresMode  {@see self::GENRES_FIRST} (default)
     *     or {@see self::GENRES_UNION}.
     *
     * @return array<string, mixed> Canonical result keyed by the present field
     *     names, plus a `sources` list. Absent everywhere → key omitted. The
     *     shape matches what the live movie/series resolvers return today.
     */
    public function resolve(iterable $records, array $sourceOrder, string $genresMode = self::GENRES_FIRST): array
    {
        // Index the supplied records by source name (last record for a given
        // source name wins, but in practice there is one record per source).
        $bySource = [];
        foreach ($records as $record) {
            $bySource[$record->source] = $record;
        }

        // Ordered list of the records that actually exist, in priority order,
        // restricted to sources named in $sourceOrder. A source named in the
        // order with no record is skipped gracefully here.
        $ordered = [];
        foreach ($sourceOrder as $sourceName) {
            if (isset($bySource[$sourceName])) {
                $ordered[] = $bySource[$sourceName];
            }
        }

        $result = [];

        foreach (SourceRecord::CANONICAL_FIELDS as $field) {
            if ($field === 'external_ids') {
                continue; // handled by the union pass below
            }

            if ($field === 'genres' && $genresMode === self::GENRES_UNION) {
                $genres = $this->unionGenres($ordered);
                if ($genres !== []) {
                    $result['genres'] = $genres;
                }
                continue;
            }

            $value = $this->firstNonEmpty($ordered, $field);
            if ($value !== null) {
                $result[$field] = $value;
            }
        }

        $externalIds = $this->unionExternalIds($ordered);
        if ($externalIds !== []) {
            $result['external_ids'] = $externalIds;
        }

        $result['sources'] = $this->contributingSources($ordered);

        return $result;
    }

    /**
     * Walk the ordered records and return the first non-empty value for $field,
     * or null when no record supplies a non-empty value.
     *
     * @param list<SourceRecord> $ordered
     * @return mixed
     */
    private function firstNonEmpty(array $ordered, string $field): mixed
    {
        foreach ($ordered as $record) {
            if (!$record->has($field)) {
                continue;
            }
            $value = $record->get($field);
            if (!$this->isEmpty($field, $value)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Per-field emptiness test, matching the live resolvers' guards:
     *  - list fields: empty when not an array or `[]`;
     *  - numeric fields: empty only when null (a genuine 0 is kept);
     *  - string fields: empty when null or `''` (no trimming, like asNullableString).
     */
    private function isEmpty(string $field, mixed $value): bool
    {
        if (in_array($field, self::LIST_FIELDS, true)) {
            return !is_array($value) || $value === [];
        }

        if (in_array($field, self::NUMERIC_FIELDS, true)) {
            return $value === null;
        }

        // String fields (title, overview, poster_url, backdrop_url,
        // official_rating, director, studio).
        return $value === null || $value === '';
    }

    /**
     * Union + de-duplicate genres across every contributing record, preserving
     * order (earlier source first, first-seen wins).
     *
     * @param list<SourceRecord> $ordered
     * @return list<string>
     */
    private function unionGenres(array $ordered): array
    {
        $out = [];
        foreach ($ordered as $record) {
            $genres = $record->genres();
            if ($genres === null) {
                continue;
            }
            foreach ($genres as $genre) {
                if ($genre !== '' && !in_array($genre, $out, true)) {
                    $out[] = $genre;
                }
            }
        }
        return $out;
    }

    /**
     * Union every record's external_ids into one map. On a key collision the
     * EARLIER source in the order wins (matching the scalar precedence
     * direction).
     *
     * @param list<SourceRecord> $ordered
     * @return array<string, string>
     */
    private function unionExternalIds(array $ordered): array
    {
        $out = [];
        foreach ($ordered as $record) {
            $ids = $record->externalIds();
            if ($ids === null) {
                continue;
            }
            foreach ($ids as $key => $value) {
                if ($value === '') {
                    continue;
                }
                // Earlier source wins: only fill a key not yet present.
                if (!array_key_exists($key, $out)) {
                    $out[$key] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * The source names (in priority order) that contributed at least one present
     * canonical field — the provenance list the live resolvers emit.
     *
     * @param list<SourceRecord> $ordered
     * @return list<string>
     */
    private function contributingSources(array $ordered): array
    {
        $out = [];
        foreach ($ordered as $record) {
            if ($record->toArray() !== []) {
                $out[] = $record->source;
            }
        }
        return $out;
    }
}
