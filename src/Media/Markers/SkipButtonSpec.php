<?php

declare(strict_types=1);

namespace Phlex\Media\Markers;

/**
 * Value object representing skip-button boundaries for client UI.
 *
 * The server provides start/end timestamps; the client decides when to
 * show the button and what to do when clicked (seek to `end` position).
 *
 * @since 0.12.0
 */
final class SkipButtonSpec
{
    /**
     * @param int|null $skip_intro_start Intro skip start in seconds, null if no intro detected
     * @param int|null $skip_intro_end   Intro skip end in seconds, null if no intro detected
     * @param int|null $skip_outro_start Outro skip start in seconds, null if no outro detected
     * @param int|null $skip_outro_end   Outro skip end in seconds, null if no outro detected
     *
     * @since 0.12.0
     */
    public function __construct(
        public readonly ?int $skip_intro_start,
        public readonly ?int $skip_intro_end,
        public readonly ?int $skip_outro_start,
        public readonly ?int $skip_outro_end,
    ) {
    }

    /**
     * Serialize to array for JSON response.
     *
     * @return array{
     *     skip_intro_start: int|null,
     *     skip_intro_end: int|null,
     *     skip_outro_start: int|null,
     *     skip_outro_end: int|null
     * }
     *
     * @since 0.12.0
     */
    public function toArray(): array
    {
        return [
            'skip_intro_start' => $this->skip_intro_start,
            'skip_intro_end' => $this->skip_intro_end,
            'skip_outro_start' => $this->skip_outro_start,
            'skip_outro_end' => $this->skip_outro_end,
        ];
    }

    /**
     * Create a SkipButtonSpec from a MarkerSet.
     *
     * @param MarkerSet $set The marker set to convert
     *
     * @return self
     *
     * @since 0.12.0
     */
    public static function fromMarkerSet(MarkerSet $set): self
    {
        return new self(
            $set->intro?->start_seconds,
            $set->intro?->end_seconds,
            $set->outro?->start_seconds,
            $set->outro?->end_seconds,
        );
    }
}
