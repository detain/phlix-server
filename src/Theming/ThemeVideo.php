<?php

declare(strict_types=1);

namespace Phlex\Theming;

/**
 * ThemeVideo represents video theme media for a library.
 *
 * Contains path, URL, duration, dimensions, and format information
 * for backdrop video files like backdrop.mp4.
 *
 * @since 0.14.0
 */
final readonly class ThemeVideo
{
    /**
     * @param string $path Absolute filesystem path to the video file
     * @param string $url Internal streaming URL for the video file
     * @param int $duration Duration in seconds
     * @param int $width Video width in pixels
     * @param int $height Video height in pixels
     * @param string $format Video format (mp4|webm)
     */
    public function __construct(
        public string $path,
        public string $url,
        public int $duration,
        public int $width,
        public int $height,
        public string $format,
    ) {
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     *
     * @since 0.14.0
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'url' => $this->url,
            'duration' => $this->duration,
            'width' => $this->width,
            'height' => $this->height,
            'format' => $this->format,
        ];
    }
}
