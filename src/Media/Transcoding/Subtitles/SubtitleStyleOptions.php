<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Subtitles;

/**
 * Styling options for subtitle burn-in rendering.
 *
 * Provides configurable appearance settings including font family,
 * size, colors, outline/border, position, and margin. Supports both
 * ASS-style advanced styling and limited SRT styling.
 *
 * @since 0.11.0
 */
final class SubtitleStyleOptions
{
    /**
     * Creates a new SubtitleStyleOptions with default values.
     *
     * Default styling produces white Arial 24pt subtitles with
     * a 2px black outline, positioned at the bottom with 10px margin.
     *
     * @since 0.11.0
     */
    public function __construct(
        public readonly string $font_name = 'Arial',
        public readonly int $font_size = 24,
        public readonly string $primary_color = '&H00FFFFFF', // ARGB hex (white)
        public readonly string $outline_color = '&H00000000', // ARGB hex (black outline)
        public readonly int $outline_thickness = 2,
        public readonly string $position = 'bottom', // 'top' | 'bottom' | 'absolute'
        public readonly int $margin = 10,
    ) {
    }

    /**
     * Converts options to an ASS style string for use with force_style.
     *
     * Produces a comma-separated list of ASS style overrides that
     * can be passed to FFmpeg's subtitles filter via force_style parameter.
     *
     * @return string ASS-style formatted string
     *
     * @since 0.11.0
     */
    public function toAssStyle(): string
    {
        $parts = [
            sprintf('FontName=%s', $this->font_name),
            sprintf('FontSize=%d', $this->font_size),
            sprintf('PrimaryColour=%s', $this->primary_color),
            sprintf('OutlineColour=%s', $this->outline_color),
            sprintf('Outline=%d', $this->outline_thickness),
            sprintf('MarginV=%d', $this->margin),
        ];

        if ($this->position === 'top') {
            $parts[] = 'Alignment=5'; // Top center
        } elseif ($this->position === 'bottom') {
            $parts[] = 'Alignment=2'; // Bottom center
        } else {
            $parts[] = 'Alignment=2'; // Default to bottom
        }

        return implode(',', $parts);
    }

    /**
     * Converts options to a limited SRT-style string.
     *
     * SRT format has very limited styling capabilities - only basic
     * text formatting is supported. This returns an empty string as SRT
     * does not support font styling via FFmpeg. Use ASS for advanced styling.
     *
     * @return string Empty string (SRT has no style support)
     *
     * @since 0.11.0
     */
    public function toSrtStyle(): string
    {
        // SRT format in FFmpeg doesn't support force_style for fonts
        // Return empty - callers should use ASS for styled subtitles
        return '';
    }
}
