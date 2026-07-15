<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Markers\ChapterService;
use Phlix\Media\Markers\MarkerSet;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * HTTP controller for marker endpoints.
 *
 * Provides read-only access to intro, outro, and chapter markers
 * for media items via GET endpoints.
 *
 * @since 0.12.0
 */
class MarkerController
{
    /**
     * @param MarkerService $marker_service Service for marker operations
     *
     * @since 0.12.0
     */
    public function __construct(
        private readonly MarkerService $marker_service,
    ) {
    }

    /**
     * GET /api/v1/media/{id}/markers
     *
     * Returns all markers (intro, outro, chapters) for a media item.
     *
     * @param Request         $req    The HTTP request
     * @param array<string, string> $params Route params with 'id' key
     *
     * @return Response 200 with MarkerSet JSON, 404 if item not found
     *
     * @since 0.12.0
     */
    public function getMarkers(Request $req, array $params): Response
    {
        $mediaId = $params['id'] ?? '';

        if ($mediaId === '') {
            return (new Response())->status(400)->json(['error' => 'Media item ID is required']);
        }

        $markerSet = $this->marker_service->getMarkers($mediaId);

        return (new Response())->json($markerSet->toArray());
    }

    /**
     * GET /api/v1/media/{id}/markers/intro
     *
     * Returns the intro marker for a media item.
     *
     * @param Request         $req    The HTTP request
     * @param array<string, string> $params Route params with 'id' key
     *
     * @return Response 200 with intro marker JSON, 404 if not found
     *
     * @since 0.12.0
     */
    public function getIntroMarker(Request $req, array $params): Response
    {
        $mediaId = $params['id'] ?? '';

        if ($mediaId === '') {
            return (new Response())->status(400)->json(['error' => 'Media item ID is required']);
        }

        $markerSet = $this->marker_service->getMarkers($mediaId);

        if ($markerSet->intro === null) {
            return (new Response())->status(404)->json([
                'error' => 'Intro marker not found',
                'message' => 'No intro marker has been detected for this media item',
            ]);
        }

        return (new Response())->json($markerSet->intro->toArray());
    }

    /**
     * GET /api/v1/media/{id}/markers/outro
     *
     * Returns the outro marker for a media item.
     *
     * @param Request         $req    The HTTP request
     * @param array<string, string> $params Route params with 'id' key
     *
     * @return Response 200 with outro marker JSON, 404 if not found
     *
     * @since 0.12.0
     */
    public function getOutroMarker(Request $req, array $params): Response
    {
        $mediaId = $params['id'] ?? '';

        if ($mediaId === '') {
            return (new Response())->status(400)->json(['error' => 'Media item ID is required']);
        }

        $markerSet = $this->marker_service->getMarkers($mediaId);

        if ($markerSet->outro === null) {
            return (new Response())->status(404)->json([
                'error' => 'Outro marker not found',
                'message' => 'No outro marker has been detected for this media item',
            ]);
        }

        return (new Response())->json($markerSet->outro->toArray());
    }

    /**
     * GET /api/v1/shows/{id}/markers/bulk
     *
     * Returns markers for all episodes of a show.
     *
     * @param Request         $req    The HTTP request
     * @param array<string, string> $params Route params with 'id' key (show ID)
     *
     * @return Response 200 with array of episode markers, 404 if show not found
     *
     * @since 0.12.0
     */
    public function getShowMarkers(Request $req, array $params): Response
    {
        $showId = $params['id'] ?? '';

        if ($showId === '') {
            return (new Response())->status(400)->json(['error' => 'Show ID is required']);
        }

        $episodeMarkers = $this->marker_service->getShowMarkers($showId);

        return (new Response())->json([
            'show_id' => $showId,
            'episodes' => array_values($episodeMarkers),
        ]);
    }

    /**
     * GET /api/v1/markers/thumbnails/{path}
     *
     * Serves a marker thumbnail file from the filesystem.
     * The path is URL-encoded and stored as thumbnail_path in media_markers.
     *
     * @param Request         $req    The HTTP request
     * @param array<string, string> $params Route params with 'path' key
     *
     * @return Response 200 with the thumbnail file, 404 if not found
     *
     * @since 0.12.0
     */
    public function getMarkerThumbnail(Request $req, array $params): Response
    {
        $path = $params['path'] ?? '';

        if ($path === '') {
            return (new Response())->status(400)->json(['error' => 'Thumbnail path is required']);
        }

        // Decode the URL-encoded path
        $thumbnailPath = urldecode($path);

        // Security: ensure the path is a valid file
        if (!is_file($thumbnailPath)) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'message' => 'Marker thumbnail file not found',
            ]);
        }

        // ETag + Last-Modified for browser caching
        $fileSize = (int) @filesize($thumbnailPath);
        $lastModified = @filemtime($thumbnailPath);
        $etag = $lastModified !== false ? md5($thumbnailPath . $lastModified) : null;

        $response = (new Response())
            ->header('Content-Type', 'image/jpeg')
            ->header('Content-Length', (string) $fileSize);

        if ($etag !== null) {
            $response = $response->header('ETag', '"' . $etag . '"');
        }
        if ($lastModified !== false) {
            $response = $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }

        return $response->withFile($thumbnailPath);
    }
}
