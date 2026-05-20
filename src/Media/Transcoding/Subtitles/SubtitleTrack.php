<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Subtitles;

/**
 * Immutable metadata for a subtitle track detected in a media file.
 *
 * Contains stream index, language code, display label, format, and
 * file path for the subtitle source. Used by SubtitleBurner to
 * identify which track to burn into the video.
 *
 * @since 0.11.0
 */
final class SubtitleTrack
{
    /**
     * Creates a new SubtitleTrack.
     *
     * @param string $index Stream index in source media file (e.g., '0', '1', '2')
     * @param string $language ISO 639-1 or ISO 639-2 language code (e.g., 'eng', 'fra')
     * @param string $label Human-readable display label (e.g., 'English (CC)', 'Spanish Subtitles')
     * @param SubtitleFormat $format Subtitle format (SRT, ASS, VTT, etc.)
     * @param string $path Absolute path to subtitle file on disk
     *
     * @since 0.11.0
     */
    public function __construct(
        public readonly string $index,
        public readonly string $language,
        public readonly string $label,
        public readonly SubtitleFormat $format,
        public readonly string $path,
    ) {
    }
}
