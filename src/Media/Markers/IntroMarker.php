<?php

declare(strict_types=1);

namespace Phlex\Media\Markers;

/**
 * Intro marker DTO representing a detected or stored intro segment.
 *
 * @since 0.12.0
 */
final class IntroMarker
{
    /**
     * @param int $start_seconds Intro start time in seconds
     * @param int $end_seconds   Intro end time in seconds
     * @param int $confidence     Confidence score 0-100
     *
     * @since 0.12.0
     */
    public function __construct(
        public readonly int $start_seconds,
        public readonly int $end_seconds,
        public readonly int $confidence,
    ) {
    }

    /**
     * Convert to array representation.
     *
     * @return array{start: int, end: int, confidence: int}
     *
     * @since 0.12.0
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start_seconds,
            'end' => $this->end_seconds,
            'confidence' => $this->confidence,
        ];
    }
}
