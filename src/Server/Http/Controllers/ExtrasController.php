<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Extras\TrailerResolver;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * ExtrasController handles API endpoints for media trailers and extras.
 *
 * Provides access to trailers and extras for media items, merging
 * local files and TMDB sources.
 *
 * @since 0.14.0
 */
class ExtrasController
{
    /** @var TrailerResolver Resolver for trailers and extras */
    private TrailerResolver $trailerResolver;

    /**
     * @param TrailerResolver $trailerResolver Resolver for trailers and extras
     */
    public function __construct(TrailerResolver $trailerResolver)
    {
        $this->trailerResolver = $trailerResolver;
    }

    /**
     * Get all extras (trailers + non-trailer extras) for a media item.
     *
     * GET /api/v1/media/{id}/extras
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route params including 'id'
     *
     * @return Response JSON response with merged extras list
     */
    public function getExtras(Request $request, array $params): Response
    {
        $mediaItemId = $params['id'] ?? '';

        if ($mediaItemId === '') {
            return (new Response())
                ->status(400)
                ->json(['error' => 'Missing media item ID']);
        }

        try {
            $extras = $this->trailerResolver->getAllExtras($mediaItemId);

            return (new Response())
                ->status(200)
                ->json([
                    'extras' => array_map(fn($e) => $e->toArray(), $extras),
                    'count' => count($extras),
                ]);
        } catch (\Throwable $e) {
            return (new Response())
                ->status(500)
                ->json(['error' => 'Failed to retrieve extras: ' . $e->getMessage()]);
        }
    }

    /**
     * Get trailers only for a media item.
     *
     * GET /api/v1/media/{id}/trailers
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route params including 'id'
     *
     * @return Response JSON response with trailers list
     */
    public function getTrailers(Request $request, array $params): Response
    {
        $mediaItemId = $params['id'] ?? '';

        if ($mediaItemId === '') {
            return (new Response())
                ->status(400)
                ->json(['error' => 'Missing media item ID']);
        }

        try {
            $trailers = $this->trailerResolver->getTrailers($mediaItemId);

            return (new Response())
                ->status(200)
                ->json([
                    'trailers' => array_map(fn($t) => $t->toArray(), $trailers),
                    'count' => count($trailers),
                ]);
        } catch (\Throwable $e) {
            return (new Response())
                ->status(500)
                ->json(['error' => 'Failed to retrieve trailers: ' . $e->getMessage()]);
        }
    }

    /**
     * Get non-trailer extras for a media item.
     *
     * GET /api/v1/media/{id}/extras/other
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route params including 'id'
     *
     * @return Response JSON response with non-trailer extras list
     */
    public function getOtherExtras(Request $request, array $params): Response
    {
        $mediaItemId = $params['id'] ?? '';

        if ($mediaItemId === '') {
            return (new Response())
                ->status(400)
                ->json(['error' => 'Missing media item ID']);
        }

        try {
            $extras = $this->trailerResolver->getExtras($mediaItemId);

            return (new Response())
                ->status(200)
                ->json([
                    'extras' => array_map(fn($e) => $e->toArray(), $extras),
                    'count' => count($extras),
                ]);
        } catch (\Throwable $e) {
            return (new Response())
                ->status(500)
                ->json(['error' => 'Failed to retrieve extras: ' . $e->getMessage()]);
        }
    }
}
