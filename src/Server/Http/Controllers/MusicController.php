<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\SignedUrl;
use Phlix\Common\Http\PageLimit;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\SessionManager;

/**
 * MusicController handles music library API endpoints.
 *
 * Provides REST endpoints for browsing and playing music including
 * artists, albums, tracks, and now-playing information.
 *
 * **Data source (S99).** Every read goes through {@see MusicLibraryService}, i.e.
 * the normalized `music_artists` / `music_albums` / `music_tracks` tables the
 * scanner actually populates. Until S99 this controller read
 * `media_items.metadata_json.$.artist` / `.album` / `.year` (via
 * `MusicLibraryManager`), which the music scanner never writes — it stamps only
 * `{"name","sub_type"}` — so on a real library (29,245 tracks) every one of those
 * fields fell through to its `'Unknown Artist'` / `'Unknown Album'` / `null`
 * default and `GET /api/v1/music/artists` answered with a single bogus
 * `{"name":"Unknown Artist","album_count":1,"track_count":100}`. Do not
 * reintroduce a `metadata_json` read here.
 *
 * **Identity is name-keyed.** Artists and albums have AUTO_INCREMENT PKs that no
 * client ever sees: `/artists/{mbid}` receives the artist NAME and
 * `/albums/{mbid}` the album TITLE (`phlix-ui` routes `/app/music/artist/:name`
 * and `/app/music/album/:name`). Tracks are keyed by their `media_items` UUID,
 * which is also what `/media/{id}/stream` and `sessions.current_media_id` use.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description HTTP API controller for music library browsing and playback
 * @see MusicLibraryService For the music_* read path
 * @see SessionManager For current session/playback state
 */
class MusicController
{
    /** @var MusicLibraryService Reads the normalized music_artists/albums/tracks tables */
    private MusicLibraryService $musicLibrary;

    /** @var SessionManager Manages user sessions and playback state */
    private SessionManager $sessionManager;

    /**
     * Constructor for MusicController.
     *
     * @param MusicLibraryService $musicLibrary Music hierarchy read path (music_* tables)
     * @param SessionManager $sessionManager Manages user sessions and playback
     */
    public function __construct(
        MusicLibraryService $musicLibrary,
        SessionManager $sessionManager
    ) {
        $this->musicLibrary = $musicLibrary;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Lists all artists in the music libraries.
     *
     * GET /music/artists
     *
     * Returns a JSON array of artists with album and track counts. `music_*` rows
     * carry no `library_id`, so the listing spans every music library — which is
     * exactly what the aggregate-across-all-libraries clients already expect
     * (there is no `library_id` parameter on any `/music/*` route).
     *
     * @param Request $request The HTTP request with optional query params:
     *   - limit: Maximum artists to return (default and hard cap: {@see PageLimit::MAX})
     *   - offset: Pagination offset (default: 0)
     * @param array<string, string> $params Route parameters (unused)
     * @return Response JSON response with artists array
     *
     * @example Response structure:
     * ```json
     * {
     *   "artists": [
     *     {
     *       "name": "Artist Name",
     *       "image_url": null,
     *       "album_count": 5,
     *       "track_count": 42,
     *       "albums": ["Album 1", "Album 2"]
     *     }
     *   ],
     *   "total": 2197,
     *   "limit": 100,
     *   "offset": 0
     * }
     * ```
     */
    public function listArtists(Request $request, array $params): Response
    {
        unset($params);

        $limit = $request->queryPageSize('limit', PageLimit::MAX);
        $offset = $request->queryOffset();

        $artists = $this->musicLibrary->getAllArtists($limit, $offset);

        // ONE batched query for the whole page's album titles (never per artist).
        $artistIds = [];
        foreach ($artists as $artistData) {
            $artistIds[] = $artistData->artist->id;
        }
        $albumTitles = $this->musicLibrary->getAlbumTitlesByArtistIds($artistIds);

        $shaped = [];
        foreach ($artists as $artistData) {
            $shaped[] = [
                'name' => $artistData->artist->name,
                'image_url' => $artistData->artist->imageUrl,
                'album_count' => $artistData->albumCount,
                'track_count' => $artistData->trackCount,
                'albums' => $albumTitles[$artistData->artist->id] ?? [],
            ];
        }

        return (new Response())->json([
            'artists' => $shaped,
            'total' => $this->musicLibrary->getArtistsCount(),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Gets details for a specific artist.
     *
     * GET /music/artists/{mbid}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'mbid' (the artist NAME —
     *   artists have no client-visible PK, so the display name is the identity)
     * @return Response JSON response with artist details and albums, or 404 if not found
     *
     * @example Response structure:
     * ```json
     * {
     *   "artist": {
     *     "name": "Artist Name",
     *     "image_url": null,
     *     "album_count": 5,
     *     "track_count": 42,
     *     "albums": ["Album 1", "Album 2"]
     *   }
     * }
     * ```
     */
    public function getArtist(Request $request, array $params): Response
    {
        unset($request);

        $artistName = $params['mbid'] ?? '';

        if ($artistName === '') {
            return (new Response())->status(400)->json(['error' => 'Artist name is required']);
        }

        $artist = $this->musicLibrary->findArtistByName($artistName);
        if ($artist === null) {
            return (new Response())->status(404)->json(['error' => 'Artist not found']);
        }

        $albumTitles = $this->musicLibrary->getAlbumTitlesByArtistIds([$artist['id']]);

        return (new Response())->json([
            'artist' => [
                'name' => $artist['name'],
                'image_url' => $artist['image_url'],
                'album_count' => $artist['album_count'],
                'track_count' => $artist['track_count'],
                'albums' => $albumTitles[$artist['id']] ?? [],
            ],
        ]);
    }

    /**
     * Lists all albums in the music libraries.
     *
     * GET /music/albums
     *
     * Returns a JSON array of albums with track counts and artist info. Each album
     * embeds its track list (the browse fast-path every client relies on), fetched
     * for the whole page in ONE batched query.
     *
     * @param Request $request The HTTP request with optional query params:
     *   - limit: Maximum albums to return (default and hard cap: {@see PageLimit::MAX})
     *   - offset: Pagination offset (default: 0)
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
     *       "album_art_url": null,
     *       "track_count": 12,
     *       "tracks": [...]
     *     }
     *   ],
     *   "total": 5091,
     *   "limit": 100,
     *   "offset": 0
     * }
     * ```
     */
    public function listAlbums(Request $request, array $params): Response
    {
        unset($params);

        $limit = $request->queryPageSize('limit', PageLimit::MAX);
        $offset = $request->queryOffset();

        $albumRows = $this->musicLibrary->getAllAlbums($limit, $offset);

        $albumIds = [];
        foreach ($albumRows as $row) {
            $albumIds[] = $this->toInt($row['id'] ?? 0);
        }
        $tracksByAlbum = $this->musicLibrary->getTracksByAlbumIds($albumIds);

        $shaped = [];
        foreach ($albumRows as $row) {
            $albumId = $this->toInt($row['id'] ?? 0);
            $shaped[] = $this->formatAlbum($row, $tracksByAlbum[$albumId] ?? []);
        }

        return (new Response())->json([
            'albums' => $shaped,
            'total' => $this->musicLibrary->getAlbumsCount(),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Gets details for a specific album.
     *
     * GET /music/albums/{mbid}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'mbid' (the album TITLE —
     *   albums have no client-visible PK, so the title is the identity)
     * @return Response JSON response with album details and track listing, or 404 if not found
     *
     * @example Response structure:
     * ```json
     * {
     *   "album": {
     *     "name": "Album Name",
     *     "artist": "Artist Name",
     *     "year": 2020,
     *     "album_art_url": null,
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
        unset($request);

        $albumName = $params['mbid'] ?? '';

        if ($albumName === '') {
            return (new Response())->status(400)->json(['error' => 'Album name is required']);
        }

        $album = $this->musicLibrary->findAlbumByTitle($albumName);
        if ($album === null) {
            return (new Response())->status(404)->json(['error' => 'Album not found']);
        }

        $albumId = $this->toInt($album['id'] ?? 0);
        $tracksByAlbum = $this->musicLibrary->getTracksByAlbumIds([$albumId]);

        return (new Response())->json([
            'album' => $this->formatAlbum($album, $tracksByAlbum[$albumId] ?? []),
        ]);
    }

    /**
     * Lists all tracks in the music libraries.
     *
     * GET /music/tracks
     *
     * Returns a JSON array of tracks with pagination support.
     *
     * @param Request $request The HTTP request with optional query params:
     *   - limit: Maximum tracks to return (default and hard cap: {@see PageLimit::MAX})
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
     *   "offset": 0,
     *   "total": 29245
     * }
     * ```
     */
    public function listTracks(Request $request, array $params): Response
    {
        unset($params);

        $limit = $request->queryPageSize('limit', PageLimit::MAX);
        $offset = $request->queryOffset();

        // The rows come back already paged by SQL — the pre-S99 handler then
        // array_slice()d them by $offset a SECOND time, so `?offset=100` always
        // returned an empty page.
        $tracks = [];
        foreach ($this->musicLibrary->getAllTracks($limit, $offset) as $row) {
            $tracks[] = $this->formatTrack($row);
        }

        return (new Response())->json([
            'tracks' => $tracks,
            'limit' => $limit,
            'offset' => $offset,
            // Counted from music_tracks. The pre-S99 handler summed a
            // `libraries.item_count` column that does not exist in the schema, so
            // `?? 0` fired unconditionally and `total` was hardcoded 0 forever.
            'total' => $this->musicLibrary->getTracksCount(),
        ]);
    }

    /**
     * Gets details for a specific track.
     *
     * GET /music/tracks/{id}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id' (the track's
     *   `media_items` UUID)
     * @return Response JSON response with track details, or 404 if not found
     */
    public function getTrack(Request $request, array $params): Response
    {
        unset($request);

        $trackId = $params['id'] ?? '';

        if ($trackId === '') {
            return (new Response())->status(400)->json(['error' => 'Track ID is required']);
        }

        $track = $this->musicLibrary->findTrackByMediaItemId($trackId);

        if ($track === null) {
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
        unset($params);

        $userId = $request->userId ?? '';

        if ($userId === '') {
            // Return empty if no user context
            return (new Response())->json([
                'now_playing' => null,
            ]);
        }

        // Get sessions for user and pick the most recent one
        $sessions = $this->sessionManager->getUserSessions($userId);
        $session = is_array($sessions[0] ?? null) ? $sessions[0] : null;
        if ($session === null) {
            return (new Response())->json([
                'now_playing' => null,
            ]);
        }

        // Get currently playing item from session
        $currentItemId = is_string($session['current_media_id'] ?? null) ? $session['current_media_id'] : null;
        if ($currentItemId === null || $currentItemId === '') {
            return (new Response())->json([
                'now_playing' => null,
            ]);
        }

        // `sessions.current_media_id` IS a media_items UUID, which is the key
        // music_tracks.media_item_id is UNIQUE on.
        $track = $this->musicLibrary->findTrackByMediaItemId($currentItemId);
        if ($track === null) {
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
     * Narrows a mixed value to int, or null when the value is absent/non-numeric.
     *
     * Distinguishes "no track number" from "track 0", which `toInt()` cannot.
     *
     * @param mixed $value Untrusted scalar value (often from DB rows).
     */
    private function toIntOrNull(mixed $value): ?int
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
        return null;
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
     * Narrows a mixed value to a non-empty string, or null.
     *
     * @param mixed $value Untrusted scalar value.
     */
    private function toStringOrNull(mixed $value): ?string
    {
        $string = $this->toString($value);

        return $string === '' ? null : $string;
    }

    /**
     * Formats an album row (plus its already-fetched tracks) for API response.
     *
     * Key names are contract: clients read `name` (NOT `title` — the album title
     * doubles as the drill-down key), `artist`, `year`, `track_count` and the
     * embedded `tracks` list.
     *
     * @param array<string, mixed> $album Album row from {@see MusicLibraryService::getAllAlbums()}
     * @param list<array<string, mixed>> $trackRows This album's joined track rows
     * @return array<string, mixed> Formatted album for response
     */
    private function formatAlbum(array $album, array $trackRows): array
    {
        $tracks = [];
        foreach ($trackRows as $row) {
            $tracks[] = $this->formatTrack($row);
        }

        return [
            'name' => $this->toString($album['title'] ?? ''),
            'artist' => $this->toStringOrNull($album['artist_name'] ?? null),
            'year' => $this->toIntOrNull($album['year'] ?? null),
            'album_art_url' => $this->toStringOrNull($album['album_art_url'] ?? null),
            'track_count' => $this->toInt($album['track_count'] ?? 0),
            'tracks' => $tracks,
        ];
    }

    /**
     * Formats a track row for API response.
     *
     * The public track id is the `media_items` UUID, NOT `music_tracks.id`: it is
     * what `GET /music/tracks/{id}` accepts, what `sessions.current_media_id`
     * stores, and what the signed `/media/{id}/stream` URL below addresses.
     *
     * `album_artist` mirrors `artist` because `music_tracks.artist_id` is the
     * scanner's denormalization of the ALBUM's artist. `genre` / `composer` have
     * no column in the music schema, so they stay null (they were also always null
     * before S99 — the scanner never wrote them into `metadata_json` either) and
     * are kept in the payload so the response shape does not change.
     *
     * There is deliberately NO `path` key. It used to expose the server's absolute
     * filesystem layout, and this payload is now reachable over the internet-facing
     * hub relay (S100 widened the allowlist to `/api/v1/music`); `MediaItemShaper`
     * has always emitted no `path`, so music was the outlier. `stream_url` is the
     * only locator a client needs. (Checked: no client renders it — `phlix-ui`,
     * console, roku and tizen/windows never read it, and the mobile normalizer
     * defaults it to `''`.)
     *
     * @param array<string, mixed> $track Joined track row from {@see MusicLibraryService}
     * @return array<string, mixed> Formatted track for response
     */
    private function formatTrack(array $track): array
    {
        // A music track row IS a media_items row, so its id is directly
        // servable by the generic Range-safe GET /media/{id}/stream endpoint
        // (HttpHandler::serveMediaStream). Mint a signed URL for direct-play,
        // mirroring the AudiobookController convention.
        $trackId = $this->toString($track['media_item_id'] ?? '');
        $streamUrl = null;
        if ($trackId !== '') {
            $streamUrl = SignedUrl::fromEnv()->mint('/media/' . $trackId . '/stream');
        }

        $artist = $this->toStringOrNull($track['artist_name'] ?? null);

        return [
            'id' => $trackId !== '' ? $trackId : null,
            'name' => $this->toStringOrNull($track['title'] ?? null),
            'artist' => $artist,
            'album' => $this->toStringOrNull($track['album_name'] ?? null),
            'album_artist' => $artist,
            'year' => $this->toIntOrNull($track['album_year'] ?? null),
            'genre' => null,
            'track_number' => $this->toIntOrNull($track['track_number'] ?? null),
            'disc_number' => $this->toIntOrNull($track['disc_number'] ?? null),
            'duration_secs' => $this->toIntOrNull($track['duration_secs'] ?? null),
            'composer' => null,
            'stream_url' => $streamUrl,
        ];
    }
}
