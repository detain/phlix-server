<?php

declare(strict_types=1);

namespace Phlix\Server\WebPortal\Controllers;

use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MusicLibraryManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\PageRenderer;

/**
 * MusicPageController renders the music web-portal HTML pages.
 *
 * Serves the browser-facing music section (albums, artists, tracks and the
 * standalone player) using the Smarty templates under
 * `public/templates/music/`. Data is sourced from {@see MusicLibraryManager}
 * — the same manager the JSON API ({@see \Phlix\Server\Http\Controllers\MusicController})
 * uses — so the HTML and API views stay consistent.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Renders music portal pages (albums/artists/tracks/player)
 *
 * @see MusicLibraryManager For album/artist/track aggregation
 * @see PageRenderer::renderTemplate() For Smarty rendering
 */
class MusicPageController
{
    /** @var MusicLibraryManager Aggregates albums, artists and tracks. */
    private MusicLibraryManager $musicManager;

    /** @var LibraryManager Enumerates configured libraries. */
    private LibraryManager $libraryManager;

    /** @var string Absolute path to the Smarty template root. */
    private string $templateDir;

    /**
     * @param MusicLibraryManager $musicManager   Music aggregation manager.
     * @param LibraryManager      $libraryManager Library enumeration manager.
     * @param string              $templateDir    Absolute path to templates.
     */
    public function __construct(
        MusicLibraryManager $musicManager,
        LibraryManager $libraryManager,
        string $templateDir
    ) {
        $this->musicManager = $musicManager;
        $this->libraryManager = $libraryManager;
        $this->templateDir = $templateDir;
    }

    /**
     * Renders the albums grid (default music landing page).
     *
     * GET /music or GET /music/albums
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters (unused).
     * @return Response HTML response with the albums page.
     */
    public function albums(Request $request, array $params): Response
    {
        $albums = [];
        foreach ($this->musicLibraryIds() as $libraryId) {
            foreach ($this->musicManager->getAlbums($libraryId) as $album) {
                if (!is_array($album)) {
                    continue;
                }
                $key = $this->stringOf($album['name'] ?? '') . ' - ' . $this->stringOf($album['artist'] ?? '');
                $albums[$key] = $album;
            }
        }

        return $this->render('music/albums.tpl', [
            'current_page' => 'music',
            'albums' => array_values($albums),
        ]);
    }

    /**
     * Renders a single album with its track listing.
     *
     * GET /music/albums/{name}
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters including 'name'.
     * @return Response HTML response with the album page, or 404.
     */
    public function album(Request $request, array $params): Response
    {
        $name = $params['name'] ?? '';
        if ($name === '') {
            return (new Response())->status(400)->html('<h1>400 — album name is required</h1>');
        }

        foreach ($this->musicLibraryIds() as $libraryId) {
            foreach ($this->musicManager->getAlbums($libraryId) as $album) {
                if (is_array($album) && strcasecmp($this->stringOf($album['name'] ?? ''), $name) === 0) {
                    return $this->render('music/album.tpl', [
                        'current_page' => 'music',
                        'album' => $album,
                    ]);
                }
            }
        }

        return (new Response())->status(404)->html('<h1>404 — album not found</h1>');
    }

    /**
     * Renders the artists grid.
     *
     * GET /music/artists
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters (unused).
     * @return Response HTML response with the artists page.
     */
    public function artists(Request $request, array $params): Response
    {
        /** @var array<string,array<string,mixed>> $merged */
        $merged = [];
        foreach ($this->musicLibraryIds() as $libraryId) {
            foreach ($this->musicManager->getArtists($libraryId) as $artist) {
                if (!is_array($artist)) {
                    continue;
                }
                $key = $this->stringOf($artist['name'] ?? '');
                if (!isset($merged[$key])) {
                    $merged[$key] = $artist;
                    continue;
                }
                $merged[$key]['track_count'] = $this->intOf($merged[$key]['track_count'] ?? 0)
                    + $this->intOf($artist['track_count'] ?? 0);
                $merged[$key]['album_count'] = $this->intOf($merged[$key]['album_count'] ?? 0)
                    + $this->intOf($artist['album_count'] ?? 0);
            }
        }

        return $this->render('music/artists.tpl', [
            'current_page' => 'music',
            'artists' => array_values($merged),
        ]);
    }

    /**
     * Renders a single artist with their albums and tracks.
     *
     * GET /music/artists/{name}
     *
     * The artist template expects `albums` as a list of `{name, year}` maps
     * and `tracks` as raw track items (with a nested `metadata` map), which is
     * a richer shape than {@see MusicLibraryManager::getArtists()} returns, so
     * it is assembled here from the album and track listings.
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters including 'name'.
     * @return Response HTML response with the artist page, or 404.
     */
    public function artist(Request $request, array $params): Response
    {
        $name = $params['name'] ?? '';
        if ($name === '') {
            return (new Response())->status(400)->html('<h1>400 — artist name is required</h1>');
        }

        $albums = [];
        $tracks = [];
        $found = false;

        foreach ($this->musicLibraryIds() as $libraryId) {
            foreach ($this->musicManager->getAlbums($libraryId) as $album) {
                if (!is_array($album) || strcasecmp($this->stringOf($album['artist'] ?? ''), $name) !== 0) {
                    continue;
                }
                $found = true;
                $albums[] = [
                    'name' => $this->stringOf($album['name'] ?? ''),
                    'year' => $album['year'] ?? null,
                ];
                $albumTracks = is_array($album['tracks'] ?? null) ? $album['tracks'] : [];
                foreach ($albumTracks as $track) {
                    if (is_array($track)) {
                        $tracks[] = $track;
                    }
                }
            }
        }

        if (!$found) {
            return (new Response())->status(404)->html('<h1>404 — artist not found</h1>');
        }

        return $this->render('music/artist.tpl', [
            'current_page' => 'music',
            'artist' => [
                'name' => $name,
                'album_count' => count($albums),
                'track_count' => count($tracks),
                'albums' => $albums,
                'tracks' => $tracks,
            ],
        ]);
    }

    /**
     * Renders the all-tracks listing with pagination.
     *
     * GET /music/tracks?limit=&offset=
     *
     * @param Request              $request The HTTP request (limit/offset query).
     * @param array<string,string> $params  Route parameters (unused).
     * @return Response HTML response with the tracks page.
     */
    public function tracks(Request $request, array $params): Response
    {
        $limit = $request->queryInt('limit', 100);
        if ($limit <= 0) {
            $limit = 100;
        }
        $offset = max(0, $request->queryInt('offset', 0));

        $all = [];
        foreach ($this->musicLibraryIds() as $libraryId) {
            foreach ($this->musicManager->getTracks($libraryId, $limit, $offset) as $track) {
                if (is_array($track)) {
                    $all[] = $this->formatTrack($track);
                }
            }
        }

        return $this->render('music/tracks.tpl', [
            'current_page' => 'music',
            'tracks' => $all,
            'total' => count($all),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Renders the standalone music player page.
     *
     * GET /music/player
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters (unused).
     * @return Response HTML response with the player page.
     */
    public function player(Request $request, array $params): Response
    {
        return $this->render('music/player.tpl', [
            'current_page' => 'music',
        ]);
    }

    /**
     * Collects the IDs of all music-type libraries.
     *
     * @return list<string> Music library IDs.
     */
    private function musicLibraryIds(): array
    {
        $ids = [];
        foreach ($this->libraryManager->getAllLibraries() as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'music') {
                continue;
            }
            $id = $library['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Flattens a raw track item into the shape the tracks template expects.
     *
     * @param array<string,mixed> $track Raw track item with nested metadata.
     * @return array<string,mixed> Flat track row.
     */
    private function formatTrack(array $track): array
    {
        $metadata = is_array($track['metadata'] ?? null) ? $track['metadata'] : [];

        return [
            'id' => $track['id'] ?? null,
            'name' => $metadata['title'] ?? ($track['name'] ?? null),
            'artist' => $metadata['artist'] ?? null,
            'album' => $metadata['album'] ?? null,
            'track_number' => $metadata['track_number'] ?? null,
            'duration_secs' => $metadata['duration_secs'] ?? null,
            'path' => $track['path'] ?? null,
        ];
    }

    /**
     * Renders a template to an HTML response.
     *
     * @param string              $template Template path relative to the root.
     * @param array<string,mixed> $vars     Variables to assign.
     * @return Response HTML response.
     */
    private function render(string $template, array $vars): Response
    {
        $vars['user'] = $vars['user'] ?? ['display_name' => 'Guest'];
        $html = PageRenderer::renderTemplate($this->templateDir, $template, $vars);
        return (new Response())->html($html);
    }

    /**
     * Narrows a mixed value to a string, defaulting to '' for non-stringable input.
     */
    private function stringOf(mixed $value): string
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
     * Narrows a mixed value to an int, defaulting to 0 for non-numeric input.
     */
    private function intOf(mixed $value): int
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
}
