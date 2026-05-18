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
        $musicLibraries = array_filter($libraries, fn($lib) => $lib['type'] === 'music');

        $allArtists = [];
        foreach ($musicLibraries as $library) {
            $artists = $this->musicManager->getArtists($library['id']);
            foreach ($artists as $artist) {
                $key = $artist['name'];
                if (!isset($allArtists[$key])) {
                    $allArtists[$key] = $artist;
                } else {
                    $allArtists[$key]['track_count'] += $artist['track_count'];
                    foreach ($artist['albums'] as $album) {
                        if (!in_array($album, $allArtists[$key]['albums'], true)) {
                            $allArtists[$key]['albums'][] = $album;
                            $allArtists[$key]['album_count']++;
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
        $musicLibraries = array_filter($libraries, fn($lib) => $lib['type'] === 'music');

        foreach ($musicLibraries as $library) {
            $artists = $this->musicManager->getArtists($library['id']);
            foreach ($artists as $artist) {
                if (strcasecmp($artist['name'], $artistName) === 0) {
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
        $musicLibraries = array_filter($libraries, fn($lib) => $lib['type'] === 'music');

        $allAlbums = [];
        foreach ($musicLibraries as $library) {
            $albums = $this->musicManager->getAlbums($library['id']);
            foreach ($albums as $album) {
                $key = $album['name'] . ' - ' . $album['artist'];
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
        $musicLibraries = array_filter($libraries, fn($lib) => $lib['type'] === 'music');

        foreach ($musicLibraries as $library) {
            $albums = $this->musicManager->getAlbums($library['id']);
            foreach ($albums as $album) {
                if (strcasecmp($album['name'], $albumName) === 0) {
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
        $limit = (int)($request->query['limit'] ?? 100);
        $offset = (int)($request->query['offset'] ?? 0);

        $libraries = $this->libraryManager->getAllLibraries();
        $musicLibraries = array_filter($libraries, fn($lib) => $lib['type'] === 'music');

        $allTracks = [];
        $totalCount = 0;

        foreach ($musicLibraries as $library) {
            $tracks = $this->musicManager->getTracks($library['id'], $limit, $offset);
            foreach ($tracks as $track) {
                $allTracks[] = $this->formatTrack($track);
            }
            $totalCount += $this->libraryManager->getLibrary($library['id'])['item_count'] ?? 0;
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
        $session = $sessions[0] ?? null;
        if (!$session) {
            return (new Response())->json([
                'now_playing' => null,
            ]);
        }

        // Get currently playing item from session
        $currentItemId = $session['current_media_id'] ?? null;
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
                'position' => $session['position_ticks'] ?? 0,
                'state' => $session['playback_state'] ?? 'stopped',
                'session_id' => $session['id'] ?? null,
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
        $musicLibraries = array_filter($libraries, fn($lib) => $lib['type'] === 'music');

        foreach ($musicLibraries as $library) {
            $tracks = $this->musicManager->getTracks($library['id'], 1000, 0);
            foreach ($tracks as $track) {
                if ($track['id'] === $trackId) {
                    return $track;
                }
            }
        }

        return null;
    }

    /**
     * Formats a track array for API response.
     *
     * @param array<string, mixed> $track Raw track data
     * @return array<string, mixed> Formatted track for response
     */
    private function formatTrack(array $track): array
    {
        $metadata = $track['metadata'] ?? [];

        return [
            'id' => $track['id'],
            'name' => $metadata['title'] ?? $track['name'],
            'artist' => $metadata['artist'] ?? null,
            'album' => $metadata['album'] ?? null,
            'album_artist' => $metadata['album_artist'] ?? null,
            'year' => $metadata['year'] ?? null,
            'genre' => $metadata['genre'] ?? null,
            'track_number' => $metadata['track_number'] ?? null,
            'disc_number' => $metadata['disc_number'] ?? null,
            'duration_secs' => $metadata['duration_secs'] ?? null,
            'composer' => $metadata['composer'] ?? null,
            'path' => $track['path'],
        ];
    }
}
