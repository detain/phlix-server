<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Music;

/**
 * MusicAlbumWithTracks represents an album with its associated tracks.
 */
final readonly class MusicAlbumWithTracks
{
    /**
     * @param MusicAlbum $album The album data
     * @param MusicArtist|null $artist The album's artist (null if not found)
     * @param MusicTrack[] $tracks Tracks on the album
     */
    public function __construct(
        public MusicAlbum $album,
        public ?MusicArtist $artist,
        public array $tracks
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
            'album' => $this->album->toArray(),
            'artist' => $this->artist?->toArray(),
            'tracks' => array_map(fn(MusicTrack $t) => $t->toArray(), $this->tracks),
        ];
    }
}
