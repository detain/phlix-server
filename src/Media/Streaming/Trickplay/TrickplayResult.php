<?php

declare(strict_types=1);

namespace Phlix\Media\Streaming\Trickplay;

/**
 * Trickplay Result Container.
 *
 * Holds the result of trickplay generation including the job ID,
 * configuration used, generated image files with their byte offsets,
 * and the path to the BIF index XML file.
 *
 * @since 0.11.0
 */
final class TrickplayResult
{
    /**
     * Creates a new TrickplayResult instance.
     *
     * @param string $job_id Transcode job identifier
     * @param int $interval_seconds Interval between thumbnails in seconds
     * @param int $grid_columns Number of grid columns
     * @param int $grid_rows Number of grid rows
     * @param array<string, array{offset: int, size: int}> $image_files Map of image filename to metadata
     * @param string $index_xml Path to the BIF index XML file
     */
    public function __construct(
        public readonly string $job_id,
        public readonly int $interval_seconds,
        public readonly int $grid_columns,
        public readonly int $grid_rows,
        public readonly array $image_files,
        public readonly string $index_xml,
    ) {
    }

    /**
     * Gets the total number of thumbnails generated.
     *
     * @return int Total thumbnail count
     */
    public function getThumbnailCount(): int
    {
        return count($this->image_files);
    }

    /**
     * Gets the total number of grid images.
     *
     * @return int Number of grid images
     */
    public function getGridCount(): int
    {
        return count($this->image_files);
    }

    /**
     * Gets image files sorted by index.
     *
     * @return array<string, array{offset: int, size: int}> Sorted image files
     */
    public function getSortedImageFiles(): array
    {
        $sorted = $this->image_files;
        ksort($sorted);
        return $sorted;
    }

    /**
     * Gets the time for a given thumbnail index.
     *
     * @param int $index Thumbnail index
     * @return int Time in seconds
     */
    public function getTimeForIndex(int $index): int
    {
        return $index * $this->interval_seconds;
    }

    /**
     * Gets the index for a given time.
     *
     * @param int $time_seconds Time in seconds
     * @return int Closest thumbnail index
     */
    public function getIndexForTime(int $time_seconds): int
    {
        return (int) floor($time_seconds / $this->interval_seconds);
    }
}
