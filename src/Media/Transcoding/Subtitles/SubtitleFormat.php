<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Subtitles;

/**
 * Supported subtitle formats for burn-in and extraction.
 *
 * Each format maps to an FFmpeg codec argument (-c:s) and has different
 * styling capabilities. ASS/SSA support advanced styling while SRT
 * and VTT are more limited but universally compatible.
 *
 * @since 0.11.0
 */
enum SubtitleFormat: string
{
    case SRT = 'srt';
    case ASS = 'ass';
    case SSA = 'ssa';
    case VTT = 'vtt';
    case HDMV = 'hdmv'; // Blu-ray PGS

    /**
     * Returns the FFmpeg format/codec argument for this subtitle format.
     *
     * Used with -c:s flag to specify subtitle codec when muxing.
     *
     * @return string FFmpeg format name (e.g., 'srt', 'ass', 'copy')
     *
     * @since 0.11.0
     */
    public function getFfmpegFormat(): string
    {
        return match ($this) {
            self::SRT => 'srt',
            self::ASS => 'ass',
            self::SSA => 'ass', // SSA is muxed as ASS
            self::VTT => 'webvtt',
            self::HDMV => 'copy', // PGS subtitles are copied, not transcoded
        };
    }

    /**
     * Returns whether this format supports advanced font styling.
     *
     * ASS and SSA formats support custom fonts, colors, positioning,
     * and other advanced styling. SRT and VTT support only basic
     * text formatting without font styles.
     *
     * @return bool True if format supports font styles
     *
     * @since 0.11.0
     */
    public function supportsFontstyle(): bool
    {
        return match ($this) {
            self::ASS, self::SSA => true,
            self::SRT, self::VTT, self::HDMV => false,
        };
    }
}
