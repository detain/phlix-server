<?php

declare(strict_types=1);

namespace Phlix\LiveTv;

use Phlix\Media\Markers\ChapterMarker;

/**
 * Parser for Comskip EDL (Edit Decision List) files.
 *
 * Comskip outputs EDL files with 3 tab-separated columns:
 *   start_seconds  end_seconds  scene_description
 *
 * The scene_description field contains a type indicator:
 *   0 = cut (commercial)
 *   1 = mute
 *   2 = scene change
 *   3 = commercial (main detection type)
 *
 * This parser converts EDL entries into ChapterMarker DTOs,
 * filtering out segments shorter than the configured minimum length.
 *
 * @since 0.12.0
 */
class ComskipEdlParser
{
    /** @var int Minimum commercial length in seconds to consider */
    private int $minCommercialLength;

    /**
     * Create a new ComskipEdlParser.
     *
     * @param int $minCommercialLength Minimum length in seconds for a segment
     *                                to be considered a commercial (default: 30)
     *
     * @since 0.12.0
     */
    public function __construct(int $minCommercialLength = 30)
    {
        $this->minCommercialLength = $minCommercialLength;
    }

    /**
     * Parse a Comskip EDL file and return chapter markers.
     *
     * @param string $edlPath Absolute path to the .edl file
     *
     * @return ChapterMarker[] Array of chapter markers derived from commercial segments
     *
     * @throws \RuntimeException If the EDL file cannot be read
     *
     * @since 0.12.0
     */
    public function parse(string $edlPath): array
    {
        if (!file_exists($edlPath)) {
            throw new \RuntimeException("EDL file not found: {$edlPath}");
        }

        $content = file_get_contents($edlPath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read EDL file: {$edlPath}");
        }

        return $this->parseString($content);
    }

    /**
     * Parse EDL content from a string.
     *
     * This method is useful for testing with raw EDL data.
     *
     * @param string $edlContent Raw EDL file content
     *
     * @return ChapterMarker[] Array of chapter markers
     *
     * @since 0.12.0
     */
    public function parseString(string $edlContent): array
    {
        $chapters = [];
        $lines = explode("\n", trim($edlContent));

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line === "\r") {
                continue;
            }

            $parts = preg_split('/\t+/', $line);

            if (!is_array($parts) || count($parts) < 3) {
                continue;
            }

            [$startStr, $endStr, $typeStr] = $parts;

            $startSeconds = $this->parseSeconds($startStr);
            $endSeconds = $this->parseSeconds($endStr);

            // Skip invalid ranges
            if ($startSeconds === null || $endSeconds === null) {
                continue;
            }

            if ($startSeconds >= $endSeconds) {
                continue;
            }

            // Skip segments that are too short (likely not a commercial)
            $length = $endSeconds - $startSeconds;
            if ($length < $this->minCommercialLength) {
                continue;
            }

            // Type 3 indicates a detected commercial
            // Types 0, 1, 2 are cut, mute, scene change respectively
            // We include all types as potential chapters
            $type = is_numeric($typeStr) ? (int) $typeStr : 0;

            $chapters[] = new ChapterMarker(
                start_seconds: (int) $startSeconds,
                end_seconds: (int) $endSeconds,
                title: $this->buildChapterTitle($type, $startSeconds, $endSeconds),
            );
        }

        return $chapters;
    }

    /**
     * Parse a timestamp string to seconds.
     *
     * Handles formats: integer seconds, or HH:MM:SS.mmm
     *
     * @param string $value The timestamp value from EDL
     *
     * @return int|null Seconds as integer, or null if invalid
     *
     * @since 0.12.0
     */
    private function parseSeconds(string $value): ?int
    {
        $value = trim($value);

        // Already in seconds (integer or float as string)
        if (is_numeric($value)) {
            return (int) floor((float) $value);
        }

        // HH:MM:SS or HH:MM:SS.mmm format
        if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $value, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (float) $matches[3];

            return (int) floor($hours * 3600 + $minutes * 60 + $seconds);
        }

        return null;
    }

    /**
     * Build a human-readable chapter title.
     *
     * @param int $type Commercial type (0=cut, 1=mute, 2=scene, 3=commercial)
     * @param int $startSeconds Start time in seconds
     * @param int $endSeconds End time in seconds
     *
     * @return string Chapter title
     *
     * @since 0.12.0
     */
    private function buildChapterTitle(int $type, int $startSeconds, int $endSeconds): string
    {
        $typeLabels = [
            0 => 'Cut',
            1 => 'Mute',
            2 => 'Scene',
            3 => 'Commercial',
        ];

        $typeLabel = $typeLabels[$type] ?? 'Segment';
        $length = $endSeconds - $startSeconds;
        $startFormatted = $this->formatTimestamp($startSeconds);

        return "{$typeLabel} @ {$startFormatted} ({$length}s)";
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
