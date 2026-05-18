<?php

declare(strict_types=1);

namespace Phlex\Theming;

/**
 * ThemeMedia represents theme media (audio and/or video) for a library.
 *
 * This readonly DTO holds the theme audio (e.g., theme.mp3) and theme video
 * (e.g., backdrop.mp4) metadata for a media library, including the scan
 * timestamp indicating when the theme media was last discovered.
 *
 * @since 0.14.0
 */
final readonly class ThemeMedia
{
    /**
     * @param string $libraryId The library this theme media belongs to
     * @param ThemeAudio|null $audio Theme audio details, or null if no audio theme found
     * @param ThemeVideo|null $video Theme video details, or null if no video theme found
     * @param \DateTimeImmutable $scannedAt When the theme media was scanned
     */
    public function __construct(
        public string $libraryId,
        public ?ThemeAudio $audio,
        public ?ThemeVideo $video,
        public \DateTimeImmutable $scannedAt,
    ) {
    }

    /**
     * Check if this theme media has audio.
     *
     * @return bool True if audio theme is present
     *
     * @since 0.14.0
     */
    public function hasAudio(): bool
    {
        return $this->audio !== null;
    }

    /**
     * Check if this theme media has video.
     *
     * @return bool True if video theme is present
     *
     * @since 0.14.0
     */
    public function hasVideo(): bool
    {
        return $this->video !== null;
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
            'library_id' => $this->libraryId,
            'audio' => $this->audio?->toArray(),
            'video' => $this->video?->toArray(),
            'scanned_at' => $this->scannedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
