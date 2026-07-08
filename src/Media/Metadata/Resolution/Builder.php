<?php

/**
 * Phlix media server component: Resolution.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Metadata\Resolution;

use Phlix\Media\Metadata\Dto\MetadataValue;

/**
 * Mutable accumulator that the {@see FieldMappers} factories use to assemble a
 * {@see SourceRecord}, recording a canonical field ONLY when a usable value is
 * derived from the raw payload.
 *
 * Every setter narrows a `mixed` raw value via {@see MetadataValue}; a value
 * that narrows to "nothing usable" (null string, empty list, etc.) leaves the
 * field ABSENT, preserving the "missing != present-but-empty" contract. This is
 * an internal helper (no I/O, no shared state) discarded after {@see self::build()}.
 *
 * @internal Used only by {@see FieldMappers}.
 * @package  Phlix\Media\Metadata\Resolution
 * @since    Feature 3 (metadata source priority)
 */
final class Builder
{
    /**
     * Accumulated present fields, keyed by canonical field name. Stored loosely
     * here (each setter has already narrowed its own value to the canonical
     * type); {@see self::build()} hands it to {@see SourceRecord} which exposes
     * the precise per-field types.
     *
     * @var array<string, string|int|float|list<string>|list<array<string, mixed>>|array<string, string>>
     */
    private array $fields = [];

    public function __construct(private readonly string $source)
    {
    }

    /**
     * Record a string field when the raw value narrows to a non-empty string.
     */
    public function string(string $field, mixed $raw): void
    {
        $value = MetadataValue::asNullableString($raw);
        if ($value !== null) {
            $this->set($field, $value);
        }
    }

    /**
     * Record an int field when the raw value narrows to an int.
     */
    public function int(string $field, mixed $raw): void
    {
        $this->setInt($field, MetadataValue::asNullableInt($raw));
    }

    /**
     * Record an already-narrowed int when non-null.
     */
    public function setInt(string $field, ?int $value): void
    {
        if ($value !== null) {
            $this->set($field, $value);
        }
    }

    /**
     * Record an already-narrowed float when non-null.
     */
    public function setFloat(string $field, ?float $value): void
    {
        if ($value !== null) {
            $this->set($field, $value);
        }
    }

    /**
     * Record a list of non-empty, de-duplicated strings (e.g. genres) when the
     * raw value yields at least one entry.
     */
    public function stringList(string $field, mixed $raw): void
    {
        if (!is_array($raw)) {
            return;
        }
        $out = [];
        foreach ($raw as $entry) {
            $name = MetadataValue::asNullableString($entry);
            if ($name !== null && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        if ($out !== []) {
            $this->set($field, $out);
        }
    }

    /**
     * Record a flattened list of actor NAME strings (tolerates the object form
     * `[{name,role,…}]` and the already-flat `["Name", …]` form) when non-empty.
     */
    public function actorNames(string $field, mixed $raw): void
    {
        $names = MetadataValue::actorNames($raw);
        if ($names !== []) {
            $this->set($field, $names);
        }
    }

    /**
     * Record the first non-empty string of a raw list (e.g. `studios[0]`,
     * `directors[0]`) when present.
     */
    public function firstOf(string $field, mixed $raw): void
    {
        if (!is_array($raw)) {
            return;
        }
        foreach ($raw as $entry) {
            $value = MetadataValue::asNullableString($entry);
            if ($value !== null) {
                $this->set($field, $value);
                return;
            }
        }
    }

    /**
     * Record a list of associative objects (cast/crew/companies) verbatim when
     * the raw value yields at least one entry.
     */
    public function objectList(string $field, mixed $raw): void
    {
        $list = MetadataValue::asAssocList($raw);
        if ($list !== []) {
            $this->set($field, $list);
        }
    }

    /**
     * Record a poster/backdrop URL: pass through an already-built `*_url`, else
     * convert a TMDB `*_path` fragment to a full w500 URL. Absent when neither.
     */
    public function tmdbImage(string $field, mixed $url, mixed $path): void
    {
        $clean = MetadataValue::asNullableString($url);
        if ($clean !== null) {
            $this->set($field, $clean);
            return;
        }
        $fragment = MetadataValue::asNullableString($path);
        if ($fragment !== null) {
            $this->set($field, FieldMappers::tmdbImageBase() . '/w500' . $fragment);
        }
    }

    /**
     * Record the external-ids map when it carries at least one id.
     *
     * @param array<string, string> $ids
     */
    public function externalIds(array $ids): void
    {
        if ($ids !== []) {
            $this->fields['external_ids'] = $ids;
        }
    }

    public function build(): SourceRecord
    {
        return new SourceRecord($this->source, $this->fields);
    }

    /**
     * Store an already-narrowed value under a canonical field key.
     *
     * @param string                                                   $field
     * @param string|int|float|list<string>|list<array<string, mixed>> $value
     */
    private function set(string $field, string|int|float|array $value): void
    {
        $this->fields[$field] = $value;
    }
}
