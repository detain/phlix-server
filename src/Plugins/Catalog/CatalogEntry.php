<?php

declare(strict_types=1);

namespace Phlix\Plugins\Catalog;

/**
 * One plugin listed in a catalog's `plugins.json`.
 *
 * A catalog repository (e.g. https://github.com/detain/phlix-plugins)
 * publishes a `plugins.json` of the shape:
 *
 * ```json
 * {
 *   "schemaVersion": 1,
 *   "name": "Phlix Official Plugins",
 *   "plugins": [
 *     {
 *       "name": "phlix-plugin-anidb",
 *       "title": "AniDB",
 *       "type": "metadata-provider",
 *       "summary": "Anime metadata from AniDB.",
 *       "description": "…",
 *       "repo": "https://github.com/detain/phlix-plugin-anidb",
 *       "author": "detain",
 *       "tags": ["anime", "metadata"]
 *     }
 *   ]
 * }
 * ```
 *
 * Each `plugins[]` element is hydrated into one immutable {@see CatalogEntry}.
 * Only `name` and `repo` are required; everything else degrades to a sensible
 * empty default so a sparse catalog still renders.
 *
 * @package Phlix\Plugins\Catalog
 * @since 0.33.0
 */
final class CatalogEntry
{
    /**
     * @param string       $name        Manifest name (`phlix-plugin-*`); the
     *                                   identity used to cross-reference
     *                                   installed plugins.
     * @param string       $title       Human display name (falls back to name).
     * @param string       $type         Category, e.g. `metadata-provider`,
     *                                   `scrobbler` (empty when unspecified).
     * @param string       $summary     One-line description.
     * @param string       $description Long description.
     * @param string       $repo        Git repository URL the plugin installs
     *                                   from (passed verbatim to the installer).
     * @param string       $author      Catalog-declared author/owner.
     * @param list<string> $tags        Free-form tags.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $type,
        public readonly string $summary,
        public readonly string $description,
        public readonly string $repo,
        public readonly string $author,
        public readonly array $tags,
    ) {
    }

    /**
     * Hydrate one `plugins[]` element, or return `null` when the element is
     * not an array or is missing the required `name`/`repo` fields.
     *
     * @param mixed $raw A decoded JSON element.
     *
     * @since 0.33.0
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $name = self::str($raw, 'name');
        $repo = self::str($raw, 'repo');
        if ($name === '' || $repo === '') {
            return null;
        }

        $title = self::str($raw, 'title');

        return new self(
            name: $name,
            title: $title !== '' ? $title : $name,
            type: self::str($raw, 'type'),
            summary: self::str($raw, 'summary'),
            description: self::str($raw, 'description'),
            repo: $repo,
            author: self::str($raw, 'author'),
            tags: self::tags($raw['tags'] ?? null),
        );
    }

    /**
     * Serialise to a JSON-ready array (the wire shape the admin UI consumes).
     *
     * @return array{
     *     name: string, title: string, type: string, summary: string,
     *     description: string, repo: string, author: string, tags: list<string>
     * }
     *
     * @since 0.33.0
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'title'       => $this->title,
            'type'        => $this->type,
            'summary'     => $this->summary,
            'description' => $this->description,
            'repo'        => $this->repo,
            'author'      => $this->author,
            'tags'        => $this->tags,
        ];
    }

    /**
     * Read a string field, coercing absent/non-string values to `''`.
     *
     * @param array<array-key, mixed> $raw
     */
    private static function str(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Coerce a raw `tags` value into a clean list of non-empty strings.
     *
     * @return list<string>
     */
    private static function tags(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $out[] = trim($tag);
            }
        }
        return $out;
    }
}
