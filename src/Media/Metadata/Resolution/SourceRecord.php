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
 * Immutable, normalized contribution from a SINGLE metadata provider.
 *
 * One {@see SourceRecord} captures everything one source (tmdb, imdb, tvdb,
 * fanart, a local NFO, or a plugin) was able to say about an item, expressed in
 * a single CANONICAL field vocabulary so that {@see PriorityFieldResolver}
 * (Step 3.2) can merge several records field-by-field without caring which
 * provider produced each value.
 *
 * ## Present vs. empty (the central contract)
 *
 * The resolver picks the "first NON-EMPTY value per field" walking a configured
 * source order, so it must be able to tell a field the provider NEVER SUPPLIED
 * apart from one it supplied as an explicitly-empty value. Therefore a field is
 * stored ONLY when the provider actually contributed it: the backing map holds
 * a key exclusively for present fields, and {@see self::has()} reports presence
 * without conflating it with emptiness. A mapper must NEVER null-fill a missing
 * raw key — it simply leaves it out (the {@see FieldMappers} factories do this).
 *
 * The canonical field set is fixed (see {@see self::CANONICAL_FIELDS}):
 *   title, overview, poster_url, backdrop_url, genres (string[]), year (int),
 *   runtime (minutes, int), official_rating, imdb_rating (float),
 *   imdb_votes (int), actors (string[]), director, cast[], crew[],
 *   production_companies[], studio, external_ids ({} map).
 *
 * This class performs NO I/O and holds NO mutable state — it is a pure value
 * object safe to construct per request under Workerman's resident memory model.
 *
 * @psalm-immutable
 *
 * Each canonical field carries a single, fixed type (string / int / float /
 * `list<string>` / `list<array<string,mixed>>` / `array<string,string>`); the
 * backing map holds the loose union of those and the typed accessors below
 * narrow each one back to its declared type.
 *
 * @phpstan-type FieldValue string|int|float|list<string>|list<array<string, mixed>>|array<string, string>
 * @phpstan-type FieldMap array<string, FieldValue>
 *
 * @package Phlix\Media\Metadata\Resolution
 * @since   Feature 3 (metadata source priority)
 */
final class SourceRecord
{
    /**
     * The complete, ordered canonical field vocabulary. A {@see SourceRecord}
     * may carry any subset of these; nothing outside this list is storable.
     *
     * @var list<string>
     */
    public const CANONICAL_FIELDS = [
        'title',
        'overview',
        'poster_url',
        'backdrop_url',
        'genres',
        'year',
        'runtime',
        'official_rating',
        'imdb_rating',
        'imdb_votes',
        'actors',
        'director',
        'cast',
        'crew',
        'production_companies',
        'studio',
        'external_ids',
    ];

    /**
     * The provider name this record came from, e.g. `tmdb`, `imdb`, `tvdb`,
     * `fanart`, `local`, or a plugin source name. Always present.
     */
    public readonly string $source;

    /**
     * Present canonical fields ONLY. A key is absent when the provider did not
     * supply that field; this is what lets the resolver distinguish "missing"
     * from "present-but-empty".
     *
     * @var FieldMap
     */
    private readonly array $fields;

    /**
     * @param string   $source The provider/source name (e.g. `tmdb`). Trimmed; never empty in practice.
     * @param FieldMap $fields Present canonical fields only — absent keys mean "not supplied".
     *                         A field that the provider did not supply must be ABSENT (never null-filled).
     */
    public function __construct(string $source, array $fields)
    {
        $this->source = $source;
        $this->fields = $fields;
    }

    /**
     * Whether the provider supplied a value for the given canonical field.
     *
     * Reports PRESENCE, not non-emptiness: a field supplied as `''`, `[]` or
     * `0` still counts as present. The resolver applies its own non-empty test
     * on top of this.
     *
     * @param string $field Canonical field name (see {@see self::CANONICAL_FIELDS}).
     */
    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    /**
     * Raw value for a present field, or null when the field is absent.
     *
     * Prefer the typed accessors below; this is the generic escape hatch the
     * resolver uses to walk fields uniformly.
     *
     * @return FieldValue|null
     */
    public function get(string $field): string|int|float|array|null
    {
        return $this->fields[$field] ?? null;
    }

    /**
     * All present canonical fields as an associative array (absent keys omitted).
     *
     * @return FieldMap
     */
    public function toArray(): array
    {
        return $this->fields;
    }

    public function title(): ?string
    {
        $value = $this->fields['title'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function overview(): ?string
    {
        $value = $this->fields['overview'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function posterUrl(): ?string
    {
        $value = $this->fields['poster_url'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function backdropUrl(): ?string
    {
        $value = $this->fields['backdrop_url'] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>|null
     */
    public function genres(): ?array
    {
        $value = $this->fields['genres'] ?? null;
        return $this->stringListOrNull($value);
    }

    public function year(): ?int
    {
        $value = $this->fields['year'] ?? null;
        return is_int($value) ? $value : null;
    }

    /** Runtime in MINUTES. */
    public function runtime(): ?int
    {
        $value = $this->fields['runtime'] ?? null;
        return is_int($value) ? $value : null;
    }

    public function officialRating(): ?string
    {
        $value = $this->fields['official_rating'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function imdbRating(): ?float
    {
        $value = $this->fields['imdb_rating'] ?? null;
        return is_float($value) ? $value : null;
    }

    public function imdbVotes(): ?int
    {
        $value = $this->fields['imdb_votes'] ?? null;
        return is_int($value) ? $value : null;
    }

    /**
     * @return list<string>|null
     */
    public function actors(): ?array
    {
        $value = $this->fields['actors'] ?? null;
        return $this->stringListOrNull($value);
    }

    public function director(): ?string
    {
        $value = $this->fields['director'] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function cast(): ?array
    {
        return $this->objectListOrNull($this->fields['cast'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function crew(): ?array
    {
        return $this->objectListOrNull($this->fields['crew'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function productionCompanies(): ?array
    {
        return $this->objectListOrNull($this->fields['production_companies'] ?? null);
    }

    public function studio(): ?string
    {
        $value = $this->fields['studio'] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, string>|null
     */
    public function externalIds(): ?array
    {
        $value = $this->fields['external_ids'] ?? null;
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $entry) {
            if (is_string($key) && is_string($entry)) {
                $out[$key] = $entry;
            }
        }
        return $out;
    }

    /**
     * Narrow a stored field value to a `list<string>`, or null when not such a list.
     *
     * @param FieldValue|null $value
     * @return list<string>|null
     */
    private function stringListOrNull(string|int|float|array|null $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Narrow a stored field value to a `list<array<string, mixed>>`, or null.
     *
     * @param FieldValue|null $value
     * @return list<array<string, mixed>>|null
     */
    private function objectListOrNull(string|int|float|array|null $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $row = [];
                foreach ($entry as $k => $v) {
                    if (is_string($k)) {
                        $row[$k] = $v;
                    }
                }
                $out[] = $row;
            }
        }
        return $out;
    }
}
