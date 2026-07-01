<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\Resolution;

/**
 * Effective, per-media-type metadata source-priority configuration.
 *
 * Holds the merged-effective `metadata.provider_priority` map (config default
 * <- admin override) and the effective `metadata.genres_mode`, and exposes the
 * ordered source list that {@see PriorityFieldResolver::resolve()} consumes as
 * its `sourceOrder` for a given media type (Step 3.4 wires the consumption).
 *
 * The class is a PURE accessor over injected state: it performs NO database or
 * I/O access and holds NO mutable static/global state — the effective config is
 * computed ONCE (in {@see \Phlix\Common\Container\Providers\MediaServicesProvider})
 * and handed to the constructor, so it is safe to share under Workerman's
 * resident-memory model.
 *
 * ## Override merge semantics (REPLACE-not-deep-merge, per type)
 *
 * The provider mirrors the `matching.noise_suffixes` wiring: the admin override
 * REPLACES the default list for any media type it names, and a media type absent
 * from the override keeps its config default. An empty/absent override leaves
 * the defaults entirely intact (it never blanks the map). The merge is performed
 * by the provider; this class simply reads the already-merged map.
 *
 * ## `orderFor()` fallback rule
 *
 *  1. If the (merged) map has a non-empty list for `$type`, return it.
 *  2. Otherwise fall back to the `movie` order when present and non-empty
 *     (the conventional `[tmdb, imdb]` baseline).
 *  3. Otherwise return the hard baseline {@see self::DEFAULT_ORDER}
 *     (`['tmdb','imdb']`), so an unknown media type or a pathologically empty
 *     map still yields a usable source order.
 *
 * @package Phlix\Media\Metadata\Resolution
 * @since   Feature 3 (metadata source priority)
 */
final class PriorityConfig
{
    /**
     * Hard baseline source order for an unknown media type when neither the
     * type's own list nor a `movie` list is available. Mirrors the canonical
     * movie default `['tmdb','imdb']`.
     *
     * @var list<string>
     */
    public const DEFAULT_ORDER = ['tmdb', 'imdb'];

    /** Default genres-combine mode when none is configured. */
    public const DEFAULT_GENRES_MODE = PriorityFieldResolver::GENRES_FIRST;

    /**
     * Effective per-media-type source order (config default <- admin override),
     * already merged. Each value is a non-empty `list<string>` of source names.
     *
     * @var array<string, list<string>>
     */
    private array $priority;

    /** Effective genres mode ({@see PriorityFieldResolver::GENRES_FIRST}|GENRES_UNION). */
    private string $genresMode;

    /**
     * @param array<string, list<string>> $priority   Merged-effective per-type
     *     source order. Empty/missing types are tolerated — {@see orderFor()}
     *     falls back. Callers should pass already-sanitised lists.
     * @param string                      $genresMode Effective genres mode;
     *     anything other than `'first'`/`'union'` is coerced to the default.
     */
    public function __construct(array $priority, string $genresMode = self::DEFAULT_GENRES_MODE)
    {
        $this->priority = $priority;
        $this->genresMode = in_array(
            $genresMode,
            [PriorityFieldResolver::GENRES_FIRST, PriorityFieldResolver::GENRES_UNION],
            true,
        ) ? $genresMode : self::DEFAULT_GENRES_MODE;
    }

    /**
     * Effective ordered source list for a media type, applying the fallback
     * rule documented on the class.
     *
     * @param string $type Media type (e.g. `movie`, `series`, `anime`).
     *
     * @return list<string> Ordered source names, highest priority first;
     *     never empty.
     */
    public function orderFor(string $type): array
    {
        $order = $this->priority[$type] ?? null;
        if (is_array($order) && $order !== []) {
            return array_values($order);
        }

        $movie = $this->priority['movie'] ?? null;
        if (is_array($movie) && $movie !== []) {
            return array_values($movie);
        }

        return self::DEFAULT_ORDER;
    }

    /**
     * Effective genres-combine mode for {@see PriorityFieldResolver::resolve()}.
     *
     * @return string `'first'` (default) or `'union'`.
     */
    public function genresMode(): string
    {
        return $this->genresMode;
    }

    /**
     * The full effective per-media-type source-order map, as merged at
     * construction. Exposed so a per-library override can be layered OVER this
     * global map with the SAME per-type REPLACE-merge semantics (see
     * {@see \Phlix\Media\Metadata\Resolution\LibraryPriorityResolver}). The
     * class stays immutable — the returned array is a copy of internal state.
     *
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->priority;
    }
}
