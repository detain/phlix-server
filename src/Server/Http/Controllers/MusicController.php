<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Media\Library\MusicLibraryManager;
use Phlex\Media\Library\LibraryManager;
use Phlex\Session\SessionManager;

/**
 * MusicController handles music library API endpoints.
 *
 * Provides REST endpoints for browsing and playing music including
 * artists, albums, tracks, and now-playing information.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description HTTP API controller for music library browsing and playback
 * @see MusicLibraryManager For music library operations
 * @see SessionManager For current session/playback state
 */
class MusicController
{
    /** @var MusicLibraryManager Manages music library operations */
    private MusicLibraryManager $musicManager;

    /** @var LibraryManager Manages media libraries */
    private LibraryManager $libraryManager;

    /** @var SessionManager Manages user sessions and playback state */
    private SessionManager $sessionManager;

    /**
     * Constructor for MusicController.
     *
     * @param MusicLibraryManager $musicManager Manages music library operations
     * @param LibraryManager $libraryManager Manages media libraries
     * @param SessionManager $sessionManager Manages user sessions and playback
     */
    public function __construct(
        MusicLibraryManager $musicManager,
        LibraryManager $libraryManager,
        SessionManager $sessionManager
    ) {
        $this->musicManager = $musicManager;
        $this->libraryManager = $libraryManager;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Lists all artists in the music libraries.
     *
     * GET /music/artists
     *
     * Returns a JSON array of artists with album and track counts.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response with artists array
     *
     * @example Response structure:
     * ```json
     * {
     *   "artists": [
     *     {
     *       "name": "Artist Name",
     *       "album_count": 5,
     *       "track_count": 42,
     *       "albums": ["Album 1", "Album 2"]
     *     }
     *   ]
     * }
     * ```
     */
    public function listArtists(Request $request, array $params): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();

        $allArtists = [];
        foreach ($libraries as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'music') {
                continue;
            }
            $libraryId = is_string($library['id'] ?? null) ? $library['id'] : null;
            if ($libraryId === null) {
                continue;
            }
            $artists = $this->musicManager->getArtists($libraryId);
            foreach ($artists as $artist) {
                if (!is_array($artist)) {
                    continue;
                }
                $artistName = $artist['name'] ?? null;
                $key = is_string($artistName) ? $artistName : '';
                if (!isset($allArtists[$key])) {
                    $allArtists[$key] = $artist;
                } else {
                    $existingTrackCount = $this->toInt($allArtists[$key]['track_count'] ?? 0);
                    $artistTrackCount = $this->toInt($artist['track_count'] ?? 0);
                    $allArtists[$key]['track_count'] = $existingTrackCount + $artistTrackCount;
                    $existingAlbums = is_array($allArtists[$key]['albums'] ?? null)
                        ? $allArtists[$key]['albums']
                        : [];
                    $newAlbums = is_array($artist['albums'] ?? null) ? $artist['albums'] : [];
                    foreach ($newAlbums as $album) {
                        if (!in_array($album, $existingAlbums, true)) {
                            $existingAlbums[] = $album;
                            $allArtists[$key]['albums'] = $existingAlbums;
                            $existingAlbumCount = $this->toInt($allArtists[$key]['album_count'] ?? 0);
                            $allArtists[$key]['album_count'] = $existingAlbumCount + 1;
                        }
                    }
                }
            }
        }

        return (new Response())->json([
            'artists' => array_values($allArtists),
        ]);
    }

    /**
     * Gets details for a specific artist.
     *
     * GET /music/artists/{mbid}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'mbid' (artist name or MusicBrainz ID)
     * @return Response JSON response with artist details and albums, or 404 if not found
     *
     * @example Response structure:
     * ```json
     * {
     *   "artist": {
     *     "name": "Artist Name",
     *     "album_count": 5,
     *     "track_count": 42,
     *     "albums": [...]
     *   }
     * }
     * ```
     */
    public function getArtist(Request $request, array $params): Response
    {
        $artistName = $params['mbid'] ?? '';

        if (empty($artistName)) {
            return (new Response())->status(400)->json(['error' => 'Artist name is required']);
        }

        $libraries = $this->libraryManager->getAllLibraries();

        foreach ($libraries as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'music') {
                continue;
            }
            $libraryId = is_string($library['id'] ?? null) ? $library['id'] : null;
            if ($libraryId === null) {
                continue;
            }
            $artists = $this->musicManager->getArtists($libraryId);
            foreach ($artists as $artist) {
                if (is_array($artist) && strcasecmp($this->toString($artist['name'] ?? ''), $artistName) === 0) {
                    return (new Response())->json(['artist' => $artist]);
                }
            }
        }

        return (new Response())->status(404)->json(['error' => 'Artist not found']);
    }

    /**
     * Lists all albums in the music libraries.
     *
     * GET /music/albums
     *
     * Returns a JSON array of albums with track counts and artist info.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response with albums array
     *
     * @example Response structure:
     * ```json
     * {
     *   "albums": [
     *     {
     *       "name": "Album Name",
     *       "artist": "Artist Name",
     *       "year": 2020,
     *       "track_count": 12,
     *       "tracks": [...]
     *     }
     *   ]
     * }
     * ```
     */
    public function listAlbums(Request $request, array $params): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();

        $allAlbums = [];
        foreach ($libraries as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'music') {
                continue;
            }
            $libraryId = is_string($library['id'] ?? null) ? $library['id'] : null;
            if ($libraryId === null) {
                continue;
            }
            $albums = $this->musicManager->getAlbums($libraryId);
            foreach ($albums as $album) {
                if (!is_array($album)) {
                    continue;
                }
                $albumName = is_string($album['name'] ?? null) ? $album['name'] : '';
                $albumArtist = is_string($album['artist'] ?? null) ? $album['artist'] : '';
                $key = $albumName . ' - ' . $albumArtist;
                $allAlbums[$key] = $album;
            }
        }

        return (new Response())->json([
            'albums' => array_values($allAlbums),
        ]);
    }

    /**
     * Gets details for a specific album.
     *
     * GET /music/albums/{mbid}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'mbid' (album name or MusicBrainz ID)
     * @return Response JSON response with album details and track listing, or 404 if not found
     *
     * @example Response structure:
     * ```json
     * {
     *   "album": {
     *     "name": "Album Name",
     *     "artist": "Artist Name",
     *     "year": 2020,
     *     "track_count": 12,
     *     "tracks": [
     *       {"id": "...", "name": "Track 1", "track_number": 1, ...}
     *     ]
     *   }
     * }
     * ```
     */
    public function getAlbum(Request $request, array $params): Response
    {
        $albumName = $params['mbid'] ?? '';

        if (empty($albumName)) {
            return (new Response())->status(400)->json(['error' => 'Album name is required']);
        }

        $libraries = $this->libraryManager->getAllLibraries();

        foreach ($libraries as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'music') {
                continue;
            }
            $libraryId = is_string($library['id'] ?? null) ? $library['id'] : null;
            if ($libraryId === null) {
                continue;
            }
            $albums = $this->musicManager->getAlbums($libraryId);
            foreach ($albums as $album) {
                if (is_array($album) && strcasecmp($this->toString($album['name'] ?? ''), $albumName) === 0) {
                    return (new Response())->json(['album' => $album]);
                }
            }
        }

        return (new Response())->status(404)->json(['error' => 'Album not found']);
    }

    /**
     * Lists all tracks in the music libraries.
     *
     * GET /music/tracks
     *
     * Returns a JSON array of tracks with pagination support.
     *
     * @param Request $request The HTTP request with optional query params:
     *   - limit: Maximum tracks to return (default: 100)
     *   - offset: Pagination offset (default: 0)
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response with tracks array and pagination info
     *
     * @example Response structure:
     * ```json
     * {
     *   "tracks": [
     *     {
     *       "id": "...",
     *       "name": "Track Name",
     *       "artist": "Artist Name",
     *       "album": "Album Name",
     *       "duration_secs": 245,
     *       "track_number": 1
     *     }
     *   ],
     *   "limit": 100,
     *   "offset": 0
     * }
     * ```
     */
    public function listTracks(Request $request, array $params): Response
    {
        $limit = $this->toInt($request->query['limit'] ?? 100);
        $offset = $this->toInt($request->query['offset'] ?? 0);
        if ($limit <= 0) {
            $limit = 100;
        }

        $libraries = $this->libraryManager->getAllLibraries();

        $allTracks = [];
        $totalCount = 0;

        foreach ($libraries as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'music') {
                continue;
            }
            $libraryId = is_string($library['id'] ?? null) ? $library['id'] : null;
            if ($libraryId === null) {
                continue;
            }
            $tracks = $this->musicManager->getTracks($libraryId, $limit, $offset);
            foreach ($tracks as $track) {
                if (is_array($track)) {
                    $allTracks[] = $this->formatTrack($track);
                }
            }
            $libraryInfo = $this->libraryManager->getLibrary($libraryId);
            $totalCount += is_array($libraryInfo) ? $this->toInt($libraryInfo['item_count'] ?? 0) : 0;
        }

        return (new Response())->json([
            'tracks' => array_slice($allTracks, $offset, $limit),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $totalCount,
        ]);
    }

    /**
     * Gets details for a specific track.
     *
     * GET /music/tracks/{id}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id' (track UUID)
     * @return Response JSON response with track details, or 404 if not found
     */
    public function getTrack(Request $request, array $params): Response
    {
        $trackId = $params['id'] ?? '';

        if (empty($trackId)) {
            return (new Response())->status(400)->json(['error' => 'Track ID is required']);
        }

        // Get track from ItemRepository
        $track = $this->getTrackById($trackId);

        if (!$track) {
            return (new Response())->status(404)->json(['error' => 'Track not found']);
        }

        return (new Response())->json(['track' => $this->formatTrack($track)]);
    }

    /**
     * Gets the currently playing track for the session.
     *
     * GET /music/now-playing
     *
     * Returns information about the current playback state including
     * the playing track, position, and playback state.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response with current playback state
     *
     * @example Response structure:
     * ```json
     * {
     *   "now_playing": {
     *     "track": {...},
     *     "position": 45000,
     *     "state": "playing",
     *     "session_id": "..."
     *   }
     * }
     * ```
     */
    public function nowPlaying(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';

        if (empty($userId)) {
            // Return empty if no user context
            return (new Response())->json([
                'now_playing' => null,
            ]);
        }

        // Get sessions for user and pick the most recent one
        $sessions = $this->sessionManager->getUserSessions($userId);
        $session = is_array($sessions[0] ?? null) ? $sessions[0] : null;
        if (!$session) {
            return (new Response())->json([
                'now_playing' => null,
            ]);
        }

        // Get currently playing item from session
        $currentItemId = is_string($session['current_media_id'] ?? null) ? $session['current_media_id'] : null;
        if (!$currentItemId) {
            return (new Response())->json([
                'now_playing' => null,
            ]);
        }

        $track = $this->getTrackById($currentItemId);
        if (!$track) {
            return (new Response())->json([
                'now_playing' => null,
            ]);
        }

        return (new Response())->json([
            'now_playing' => [
                'track' => $this->formatTrack($track),
                'position' => is_int($session['position_ticks'] ?? null) ? $session['position_ticks'] : 0,
                'state' => is_string($session['playback_state'] ?? null) ? $session['playback_state'] : 'stopped',
                'session_id' => is_string($session['id'] ?? null) ? $session['id'] : null,
            ],
        ]);
    }

    /**
     * Gets a track by its ID from any music library.
     *
     * @param string $trackId Track UUID
     * @return array<string, mixed>|null Track data or null if not found
     */
    private function getTrackById(string $trackId): ?array
    {
        $libraries = $this->libraryManager->getAllLibraries();

        foreach ($libraries as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'music') {
                continue;
            }
            $libraryId = is_string($library['id'] ?? null) ? $library['id'] : null;
            if ($libraryId === null) {
                continue;
            }
            $tracks = $this->musicManager->getTracks($libraryId, 1000, 0);
            foreach ($tracks as $track) {
                if (is_array($track) && ($track['id'] ?? null) === $trackId) {
                    return $track;
                }
            }
        }

        return null;
    }

    /**
     * Narrows a mixed value to int, falling back to 0 for non-numeric input.
     *
     * @param mixed $value Untrusted scalar value (often from JSON / DB rows).
     */
    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return 0;
    }

    /**
     * Narrows a mixed value to string, falling back to '' for non-stringable input.
     *
     * @param mixed $value Untrusted scalar value.
     */
    private function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Formats a track array for API response.
     *
     * @param array<string, mixed> $track Raw track data
     * @return array<string, mixed> Formatted track for response
     */
    private function formatTrack(array $track): array
    {
        $metadata = is_array($track['metadata'] ?? null) ? $track['metadata'] : [];

        return [
            'id' => $track['id'] ?? null,
            'name' => $metadata['title'] ?? ($track['name'] ?? null),
            'artist' => $metadata['artist'] ?? null,
            'album' => $metadata['album'] ?? null,
            'album_artist' => $metadata['album_artist'] ?? null,
            'year' => $metadata['year'] ?? null,
            'genre' => $metadata['genre'] ?? null,
            'track_number' => $metadata['track_number'] ?? null,
            'disc_number' => $metadata['disc_number'] ?? null,
            'duration_secs' => $metadata['duration_secs'] ?? null,
            'composer' => $metadata['composer'] ?? null,
            'path' => $track['path'] ?? null,
        ];
    }
}
