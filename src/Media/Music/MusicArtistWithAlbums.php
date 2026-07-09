<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

/**
 * MusicArtistWithAlbums represents an artist with their albums.
 */
final readonly class MusicArtistWithAlbums
{
    /**
     * @param MusicArtist $artist The artist data
     * @param int $albumCount Number of albums by this artist
     * @param int $trackCount Number of tracks by this artist
     * @param MusicAlbum[] $albums Albums by this artist (empty when from getAllArtists)
     */
    public function __construct(
        public MusicArtist $artist,
        public int $albumCount = 0,
        public int $trackCount = 0,
        public array $albums = []
    ) {
    }

    /**
     * Converts to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artist' => $this->artist->toArray(),
            'album_count' => $this->albumCount,
            'track_count' => $this->trackCount,
            'albums' => array_map(fn(MusicAlbum $a) => $a->toArray(), $this->albums),
        ];
    }
}
