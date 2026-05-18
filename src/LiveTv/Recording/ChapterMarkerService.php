<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Recording;

use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Markers\ChapterMarker;

/**
 * Converts EDL commercial segments into HLS chapter markers.
 *
 * Provides functionality to:
 * - Convert EDL segments to HLS EXTINF chapter format
 * - Persist chapter markers to media_items.metadata_json
 * - Retrieve chapter markers for a media item
 *
 * @since 0.12.0
 */
class ChapterMarkerService
{
    /** @var ItemRepository Media item repository for database access */
    private ItemRepository $itemRepo;

    /**
     * Create a new ChapterMarkerService.
     *
     * @param ItemRepository $itemRepo Media item repository
     *
     * @since 0.12.0
     */
    public function __construct(ItemRepository $itemRepo)
    {
        $this->itemRepo = $itemRepo;
    }

    /**
     * Convert EDL segments to HLS EXTINF chapter markers.
     *
     * Produces an array of chapter entries suitable for HLS playlist
     * embedding. Each entry contains start time, end time, and title.
     *
     * EDL format: [start_seconds, end_seconds, type]
     * HLS chapter format: {start, end, title}
     *
     * @param array<ChapterMarker|array{start_seconds?: int, end_seconds?: int, title?: string|null}> $edlSegments
     *        Array of EDL segments (either ChapterMarker DTOs or arrays)
     *
     * @return array<int, array{start: int, end: int, title: string|null}> HLS chapter markers
     *
     * @since 0.12.0
     */
    public function toHlsChapters(array $edlSegments): array
    {
        $chapters = [];

        foreach ($edlSegments as $segment) {
            // Handle both ChapterMarker objects and arrays
            if ($segment instanceof ChapterMarker) {
                $startSeconds = $segment->start_seconds;
                $endSeconds = $segment->end_seconds;
                $title = $segment->title;
            } elseif (is_array($segment)) {
                $startSeconds = $segment['start_seconds'] ?? $segment['start'] ?? 0;
                $endSeconds = $segment['end_seconds'] ?? $segment['end'] ?? 0;
                $title = $segment['title'] ?? null;
            } else {
                // Skip invalid segments
                continue;
            }

            // Skip invalid ranges
            if ($startSeconds >= $endSeconds) {
                continue;
            }

            $chapters[] = [
                'start' => (int) $startSeconds,
                'end' => (int) $endSeconds,
                'title' => $title,
            ];
        }

        // Sort by start time
        usort($chapters, fn($a, $b) => $a['start'] <=> $b['start']);

        return $chapters;
    }

    /**
     * Persist chapter markers to media_items metadata_json.
     *
     * Stores the commercial chapter markers under the
     * `commercial_chapters` key in the media item's metadata_json.
     *
     * @param string $mediaItemId The media item identifier
     * @param array<ChapterMarker|array{start_seconds?: int, end_seconds?: int, title?: string|null}> $edlSegments
     *        Array of EDL segments to persist
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function persistChapters(string $mediaItemId, array $edlSegments): void
    {
        $hlsChapters = $this->toHlsChapters($edlSegments);

        // Get current metadata
        $item = $this->itemRepo->findById($mediaItemId);

        if ($item === null) {
            return;
        }

        $metadata = [];

        if (isset($item['metadata_json']) && is_string($item['metadata_json'])) {
            $decoded = json_decode($item['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } elseif (isset($item['metadata_json']) && is_array($item['metadata_json'])) {
            $metadata = $item['metadata_json'];
        }

        // Update commercial_chapters
        $metadata['commercial_chapters'] = $hlsChapters;

        // Save back to database
        $this->itemRepo->update($mediaItemId, [
            'metadata_json' => json_encode($metadata),
        ]);
    }

    /**
     * Get chapter markers for a media item.
     *
     * Retrieves commercial chapter markers stored in the media
     * item's metadata_json under the `commercial_chapters` key.
     *
     * @param string $mediaItemId The media item identifier
     *
     * @return array<int, array{start: int, end: int, title: string|null}> Chapter markers
     *
     * @since 0.12.0
     */
    public function getChapters(string $mediaItemId): array
    {
        $item = $this->itemRepo->findById($mediaItemId);

        if ($item === null) {
            return [];
        }

        $metadata = [];

        if (isset($item['metadata_json']) && is_string($item['metadata_json'])) {
            $decoded = json_decode($item['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } elseif (isset($item['metadata_json']) && is_array($item['metadata_json'])) {
            $metadata = $item['metadata_json'];
        }

        /** @var mixed $chapters */
        $chapters = $metadata['commercial_chapters'] ?? [];

        if (!is_array($chapters)) {
            return [];
        }

        /** @var array<int, array{start: int, end: int, title: string|null}> $chapters */
        return $chapters;
    }

    /**
     * Generate HLS chapter segment content.
     *
     * Produces the HLS EXTM3U-compatible chapter content that can be
     * embedded in an HLS playlist.
     *
     * @param array<int, array{start: int, end: int, title: string|null}> $chapters
     *        Chapter markers from toHlsChapters()
     *
     * @return string HLS chapter content
     *
     * @since 0.12.0
     */
    public function generateHlsChapterContent(array $chapters): string
    {
        $output = "#EXTM3U\n";

        foreach ($chapters as $chapter) {
            $start = $chapter['start'] ?? 0;
            $end = $chapter['end'] ?? 0;
            $title = $chapter['title'] ?? 'Commercial';

            // Format as HH:MM:SS
            $startFormatted = $this->formatTimestamp($start);
            $endFormatted = $this->formatTimestamp($end);

            $output .= "#EXTINF:{$startFormatted},{$title}\n";
            $output .= "#EXTX-CUE-PRICE:{$startFormatted} - {$endFormatted}\n";
        }

        return $output;
    }

    /**
     * Format a timestamp as HH:MM:SS.
     *
     * @param int $seconds Total seconds
     *
     * @return string Formatted timestamp
     *
     * @since 0.12.0
     */
    private function formatTimestamp(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
