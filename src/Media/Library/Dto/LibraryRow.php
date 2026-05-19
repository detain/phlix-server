<?php

declare(strict_types=1);

namespace Phlex\Media\Library\Dto;

/**
 * Typed value object representing a hydrated row from the `libraries` table.
 *
 * Encapsulates the result of `LibraryManager::getLibrary()` /
 * `MusicLibraryManager::getLibrary()` with `paths` and `options` already
 * JSON-decoded so callers receive typed values instead of mixed.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Strongly-typed library row from DB hydration
 */
final class LibraryRow
{
    /**
     * @param string $id Library UUID
     * @param string $name Display name
     * @param string $type Library type discriminator (e.g. 'video', 'music', 'book')
     * @param list<string> $paths Filesystem paths to scan
     * @param array<string, mixed> $options Type-specific options blob (decoded)
     * @param array<string, mixed> $raw Original row, with decoded paths/options re-applied
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly array $paths,
        public readonly array $options,
        public readonly array $raw,
    ) {
    }

    /**
     * Hydrate a LibraryRow from a raw DB row.
     *
     * @param array<string, mixed> $row Raw DB row from `libraries` table.
     */
    public static function fromRow(array $row): self
    {
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';

        $pathsRaw = $row['paths'] ?? null;
        $paths = self::decodePaths($pathsRaw);

        $optionsRaw = $row['options'] ?? null;
        $options = self::decodeOptions($optionsRaw);

        $row['paths'] = $paths;
        $row['options'] = $options;

        return new self($id, $name, $type, $paths, $options, $row);
    }

    /**
     * Returns the underlying row with decoded paths/options arrays substituted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * Decodes the `paths` column into a list of strings.
     *
     * @param mixed $value Raw column value (string JSON or array).
     * @return list<string>
     */
    private static function decodePaths(mixed $value): array
    {
        $decoded = $value;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
        }
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $entry) {
            if (is_string($entry)) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * Decodes the `options` column into a string-keyed array.
     *
     * @param mixed $value Raw column value (string JSON or array).
     * @return array<string, mixed>
     */
    private static function decodeOptions(mixed $value): array
    {
        $decoded = $value;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
        }
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $key => $entry) {
            if (is_string($key)) {
                $result[$key] = $entry;
            }
        }
        return $result;
    }
}
