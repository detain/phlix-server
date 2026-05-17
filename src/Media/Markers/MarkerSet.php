<?php

declare(strict_types=1);

namespace Phlex\Media\Markers;

/**
 * Aggregate marker set DTO containing all markers for a media item.
 *
 * @since 0.12.0
 */
final class MarkerSet
{
    /**
     * @param IntroMarker|null    $intro     Intro marker or null if none
     * @param OutroMarker|null    $outro    Outro marker or null if none
     * @param ChapterMarker[]     $chapters Array of chapter markers
     *
     * @since 0.12.0
     */
    public function __construct(
        public readonly ?IntroMarker $intro,
        public readonly ?OutroMarker $outro,
        public readonly array $chapters = [],
    ) {
    }

    /**
     * Check if this marker set has any markers.
     *
     * @return bool True if at least one marker exists
     *
     * @since 0.12.0
     */
    public function hasMarkers(): bool
    {
        return $this->intro !== null
            || $this->outro !== null
            || !empty($this->chapters);
    }

    /**
     * Convert to array representation for JSON serialization.
     *
     * @return array{
     *     intro: array{start: int, end: int, confidence: int}|null,
     *     outro: array{start: int, end: int, confidence: int}|null,
     *     chapters: array<array{start: int, end: int, title?: string|null}>
     * }
     *
     * @since 0.12.0
     */
    public function toArray(): array
    {
        return [
            'intro' => $this->intro?->toArray(),
            'outro' => $this->outro?->toArray(),
            'chapters' => array_map(fn(ChapterMarker $c) => $c->toArray(), $this->chapters),
        ];
    }

    /**
     * Create an empty marker set.
     *
     * @return self
     *
     * @since 0.12.0
     */
    public static function empty(): self
    {
        return new self(null, null, []);
    }
}
