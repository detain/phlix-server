<?php

/**
 * Phlix media server component: Markers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Markers;

use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Extracts chapter markers from MKV/MP4/WebM container files using FFprobe.
 *
 * FFmpeg can read chapter metadata from all three formats using the same
 * {@see extractFromFile()} command, which invokes:
 *
 *   ffprobe -show_chapters -print_format json <file>
 *
 * The extracted chapters are returned as {@see ChapterMarker} DTOs and can
 * be stored via {@see MarkerService::storeChapters()}.
 *
 * @since 0.13.0
 */
class ChapterMarkerService
{
    /** @var FfmpegRunner FFprobe runner for executing commands */
    private FfmpegRunner $ffmpeg;

    /**
     * Create a new ChapterMarkerService.
     *
     * @param FfmpegRunner $ffmpeg FFmpeg/FFprobe runner (injected for testability)
     *
     * @since 0.13.0
     */
    public function __construct(FfmpegRunner $ffmpeg)
    {
        $this->ffmpeg = $ffmpeg;
    }

    /**
     * Extract chapter markers from a media file using FFprobe.
     *
     * Runs `ffprobe -show_chapters -print_format json <filePath>` and parses
     * the JSON output into {@see ChapterMarker} DTOs. Supports MKV, MP4, and WebM
     * containers, as FFmpeg reads chapter metadata from all three with the same
     * command.
     *
     * Each returned ChapterMarker carries:
     *   - start_seconds: chapter start time in seconds (integer)
     *   - end_seconds:   chapter end time in seconds (integer)
     *   - title:         chapter title string (null when no title is set)
     *
     * When ffprobe returns no chapters or the file cannot be probed, an empty
     * array is returned. This method never throws.
     *
     * @param string $filePath Absolute filesystem path to the media file.
     *
     * @return ChapterMarker[] Array of chapter markers (may be empty).
     *
     * @since 0.13.0
     */
    public function extractFromFile(string $filePath): array
    {
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_chapters %s 2>/dev/null',
            escapeshellarg($this->ffmpeg->getFfprobePath()),
            escapeshellarg($filePath)
        );

        $output = $this->runCommand($cmd);

        if ($output === null || $output === '') {
            return [];
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return [];
        }

        $rawChapters = $data['chapters'] ?? null;
        if (!is_array($rawChapters)) {
            return [];
        }

        /** @var ChapterMarker[] $chapters */
        $chapters = [];

        foreach ($rawChapters as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            // FFprobe START/END are in nanoseconds; convert to seconds.
            $startNs = $raw['start_time'] ?? null;
            $endNs = $raw['end_time'] ?? null;

            if (!is_numeric($startNs) || !is_numeric($endNs)) {
                continue;
            }

            $startSeconds = (int) floor((float) $startNs / 1_000_000_000);
            $endSeconds = (int) floor((float) $endNs / 1_000_000_000);

            // Skip malformed ranges
            if ($startSeconds >= $endSeconds) {
                $endSeconds = $startSeconds + 1;
            }

            $title = null;
            $tags = $raw['tags'] ?? null;
            if (is_array($tags) && array_key_exists('title', $tags)) {
                $rawTitle = $tags['title'];
                if (is_string($rawTitle) && $rawTitle !== '') {
                    $title = $rawTitle;
                }
            }

            $chapters[] = new ChapterMarker(
                start_seconds: $startSeconds,
                end_seconds: $endSeconds,
                title: $title,
            );
        }

        return $chapters;
    }

    /**
     * Run a shell command, coroutine-aware under Swoole.
     *
     * Mirrors the pattern in {@see FfmpegRunner::runProbeCommand()}: uses
     * {@see \Swoole\Coroutine\System::exec()} inside a coroutine and falls
     * back to {@see shell_exec()} in CLI/non-coroutine contexts.
     *
     * @param string $cmd Fully shell-escaped command to execute.
     *
     * @return string|null Captured stdout, or null on failure.
     */
    private function runCommand(string $cmd): ?string
    {
        if (
            extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0
        ) {
            /** @var array{code?: int, signal?: int, output?: string}|false $result */
            $result = \Swoole\Coroutine\System::exec($cmd);
            if (is_array($result) && isset($result['output']) && is_string($result['output'])) {
                return $result['output'];
            }

            return null;
        }

        $output = shell_exec($cmd);

        return is_string($output) ? $output : null;
    }
}
