<?php

declare(strict_types=1);

namespace Phlex\Media\Markers;

/**
 * Service for providing skip-button specs enriched with playback position context.
 *
 * @since 0.12.0
 */
class PlaybackMarkerService
{
    /**
     * @param MarkerService $marker_service Marker service for fetching marker data
     *
     * @since 0.12.0
     */
    public function __construct(
        private readonly MarkerService $marker_service,
    ) {
    }

    /**
     * Return skip-button spec for the current playback position.
     *
     * Markers that fall outside the current position range are nulled out,
     * so the client only shows buttons relevant to the viewer's position.
     * This is useful for live streams where the viewer may have started mid-episode.
     *
     * @param string $media_item_id  The media item ID
     * @param int    $position_ticks Current playback position in ticks
     *
     * @return SkipButtonSpec
     *
     * @since 0.12.0
     */
    public function getSkipSpec(string $media_item_id, int $position_ticks): SkipButtonSpec
    {
        $fullSpec = $this->getFullSpec($media_item_id);

        // Convert ticks to seconds for comparison
        // 1 tick = 100 nanoseconds, so 1 second = 10,000,000 ticks
        $position_seconds = (int) ($position_ticks / 10_000_000);

        $intro_active = $this->isMarkerActive(
            $position_seconds,
            $fullSpec->skip_intro_start,
            $fullSpec->skip_intro_end,
        );

        $outro_active = $this->isMarkerActive(
            $position_seconds,
            $fullSpec->skip_outro_start,
            $fullSpec->skip_outro_end,
        );

        return new SkipButtonSpec(
            $intro_active ? $fullSpec->skip_intro_start : null,
            $intro_active ? $fullSpec->skip_intro_end : null,
            $outro_active ? $fullSpec->skip_outro_start : null,
            $outro_active ? $fullSpec->skip_outro_end : null,
        );
    }

    /**
     * Convenience: return full spec regardless of playback position.
     *
     * @param string $media_item_id The media item ID
     *
     * @return SkipButtonSpec
     *
     * @since 0.12.0
     */
    public function getFullSpec(string $media_item_id): SkipButtonSpec
    {
        $markerSet = $this->marker_service->getMarkers($media_item_id);

        return SkipButtonSpec::fromMarkerSet($markerSet);
    }

    /**
     * Check if a marker is currently active at the given position.
     *
     * @param int      $position_seconds Current position in seconds
     * @param int|null $marker_start    Marker start in seconds (null = not set)
     * @param int|null $marker_end      Marker end in seconds (null = not set)
     *
     * @return bool True if marker is active at position
     */
    private function isMarkerActive(int $position_seconds, ?int $marker_start, ?int $marker_end): bool
    {
        if ($marker_start === null || $marker_end === null) {
            return false;
        }

        return $position_seconds >= $marker_start && $position_seconds <= $marker_end;
    }
}
