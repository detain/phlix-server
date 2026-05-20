<?php

declare(strict_types=1);

namespace Phlix\Media\Extras;

/**
 * Trailer represents a trailer for a media item.
 *
 * A trailer can be either local (file on disk) or from TMDB.
 *
 * @since 0.14.0
 */
final readonly class Trailer
{
    /**
     * @param string $id Unique identifier for the trailer
     * @param string $mediaItemId Associated media item ID
     * @param string $title Display title (e.g., "Official Trailer", "Teaser")
     * @param string $source Source of the trailer: 'local' or 'tmdb'
     * @param string $url Absolute URL (local file path or TMDB URL)
     * @param int $duration Duration in seconds; 0 if unknown
     * @param int $quality Video quality (480/720/1080/2160); 0 if unknown
     * @param bool $isLocal True for local -trailer.mkv files
     * @param string $filePath Empty string for TMDB sources
     */
    public function __construct(
        public string $id,
        public string $mediaItemId,
        public string $title,
        public string $source,
        public string $url,
        public int $duration,
        public int $quality,
        public bool $isLocal,
        public string $filePath,
    ) {
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'media_item_id' => $this->mediaItemId,
            'title' => $this->title,
            'source' => $this->source,
            'url' => $this->url,
            'duration' => $this->duration,
            'quality' => $this->quality,
            'is_local' => $this->isLocal,
            'file_path' => $this->filePath,
        ];
    }
}
