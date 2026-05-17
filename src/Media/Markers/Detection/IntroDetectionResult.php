<?php

declare(strict_types=1);

namespace Phlex\Media\Markers\Detection;

/**
 * Result of the intro/outro detection for a show.
 *
 * @since 0.12.0
 */
final class IntroDetectionResult
{
    /**
     * @param string                    $show_id              The show/series media item ID
     * @param int                        $episodes_fingerprinted Number of episodes that had fingerprints
     * @param IntroMarkerCandidate|null   $intro_candidate       Detected intro candidate or null
     * @param OutroMarkerCandidate|null  $outro_candidate      Detected outro candidate or null
     * @param array<string>              $episodes_processed    Array of media_item_id strings
     *
     * @since 0.12.0
     */
    public function __construct(
        public readonly string $show_id,
        public readonly int $episodes_fingerprinted,
        public readonly ?IntroMarkerCandidate $intro_candidate,
        public readonly ?OutroMarkerCandidate $outro_candidate,
        public readonly array $episodes_processed,
    ) {
    }

    /**
     * Check if any markers were detected.
     *
     * @return bool True if at least one of intro or outro was detected
     *
     * @since 0.12.0
     */
    public function hasMarkers(): bool
    {
        return $this->intro_candidate !== null || $this->outro_candidate !== null;
    }
}
