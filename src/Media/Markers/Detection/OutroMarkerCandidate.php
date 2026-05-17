<?php

declare(strict_types=1);

namespace Phlex\Media\Markers\Detection;

/**
 * Represents a detected outro segment candidate.
 *
 * @since 0.12.0
 */
final class OutroMarkerCandidate
{
    /**
     * @param int    $start_seconds Start time in seconds (e.g., 2340)
     * @param int    $end_seconds   End time in seconds (e.g., 2520)
     * @param string $fingerprint   Representative fingerprint for the outro
     * @param int    $confidence    Confidence score 0–100
     *
     * @since 0.12.0
     */
    public function __construct(
        public readonly int $start_seconds,
        public readonly int $end_seconds,
        public readonly string $fingerprint,
        public readonly int $confidence,
    ) {
    }

    /**
     * Get the duration of the outro in seconds.
     *
     * @return int Duration in seconds
     *
     * @since 0.12.0
     */
    public function duration(): int
    {
        return $this->end_seconds - $this->start_seconds;
    }
}
