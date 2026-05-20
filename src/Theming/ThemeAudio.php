<?php

declare(strict_types=1);

namespace Phlix\Theming;

/**
 * ThemeAudio represents audio theme media for a library.
 *
 * Contains path, URL, duration, and format information for
 * theme audio files like theme.mp3.
 *
 * @since 0.14.0
 */
final readonly class ThemeAudio
{
    /**
     * @param string $path Absolute filesystem path to the audio file
     * @param string $url Internal streaming URL for the audio file
     * @param int $duration Duration in seconds
     * @param string $format Audio format (mp3|ogg|aac)
     */
    public function __construct(
        public string $path,
        public string $url,
        public int $duration,
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
            'format' => $this->format,
        ];
    }
}
