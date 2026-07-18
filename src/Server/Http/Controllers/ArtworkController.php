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
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Serves locally-cached artwork (posters/backdrops) with cache headers.
 *
 * This controller enables responsive images via srcset without requiring
 * clients to fetch from TMDB directly, solving the offline/LAN install issue.
 *
 * Route: GET /api/v1/artwork/{itemId}?size={size}
 *
 * Authorization: Valid signed-URL token (so <img src="..."> works without a Bearer header).
 */
class ArtworkController
{
    /** Cache-Control header for cached artwork (1 year). */
    private const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        private ArtworkStorage $artworkStorage,
    ) {
    }

    /**
     * Serve a specific artwork variant.
     *
     * GET /api/v1/artwork/{itemId}?size={size}
     *
     * @param Request              $request The HTTP request
     * @param array<string,string> $params  Route parameters (itemId)
     *
     * @return Response
     */
    public function serve(Request $request, array $params): Response
    {
        $itemId = $params['itemId'] ?? '';
        if ($itemId === '') {
            return (new Response())->status(400)->json(['error' => 'Missing item ID']);
        }

        $size = is_string($request->query['size']) ? $request->query['size'] : 'original';

        // Validate size parameter
        if (!$this->isValidSize($size)) {
            return (new Response())->status(400)->json(['error' => 'Invalid size parameter']);
        }

        // Authorize via signed URL token (so <img src> works without Bearer)
        if (!$this->isAuthorized($request, $itemId, $size)) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        // Get variant path
        $variantPath = $this->artworkStorage->variantPath($itemId, $size);
        if ($variantPath === null || !is_file($variantPath) || !is_readable($variantPath)) {
            return (new Response())->status(404)->json(['error' => 'Artwork not found']);
        }

        // Serve with cache headers
        $mime = $this->mimeType($size);
        $resp = (new Response())->status(200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', self::CACHE_CONTROL)
            ->header('ETag', $this->computeETag($variantPath));

        return $resp->withFile($variantPath);
    }

    /**
     * Serve the srcset for an item's artwork.
     *
     * GET /api/v1/artwork/{itemId}/srcset
     *
     * Returns a srcset descriptor string for use in <img srcset="...">.
     *
     * @param Request              $request The HTTP request
     * @param array<string,string> $params  Route parameters (itemId)
     *
     * @return Response
     */
    public function srcset(Request $request, array $params): Response
    {
        $itemId = $params['itemId'] ?? '';
        if ($itemId === '') {
            return (new Response())->status(400)->json(['error' => 'Missing item ID']);
        }

        // Authorize via signed URL token
        // Note: srcset returns URLs that will be authorized individually when fetched
        if (!$this->isAuthorized($request, $itemId, 'original')) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $srcset = $this->artworkStorage->srcset($itemId);
        if ($srcset === null) {
            return (new Response())->status(404)->json(['error' => 'No artwork found']);
        }

        return (new Response())
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', self::CACHE_CONTROL)
            ->text($srcset);
    }

    /**
     * Check if the request is authorized via signed URL token.
     *
     * @param Request  $request The HTTP request
     * @param string   $itemId  Media item ID for the resource path
     * @param string   $size    Size variant for the resource path
     */
    private function isAuthorized(Request $request, string $itemId, string $size): bool
    {
        // If user is authenticated via Bearer/session, allow
        if ($request->userId !== null && $request->userId !== '') {
            return true;
        }

        // Otherwise, require valid signed URL token
        $signer = SignedUrl::fromEnv();
        $exp = $request->query['exp'] ?? null;
        $sig = $request->query['sig'] ?? null;

        if (!is_string($exp) || !is_string($sig)) {
            return false;
        }

        // Verify the token is valid (without minting a new one)
        $resourcePath = '/api/v1/artwork/' . $itemId . '?size=' . $size;

        return $signer->verify($resourcePath, $exp, $sig);
    }

    /**
     * Validate size parameter against known variants.
     */
    private function isValidSize(string $size): bool
    {
        // Accept w### sizes (w185, w342, w500, w780), 'original', and 'logo'
        // (the transparency-safe title-logo PNG).
        if ($size === 'original' || $size === ArtworkStorage::LOGO_SIZE) {
            return true;
        }

        if (preg_match('/^w\d+$/', $size) !== 1) {
            return false;
        }

        // Validate against known widths
        $widths = ArtworkStorage::WIDTHS;
        $width = (int) substr($size, 1);
        return in_array($width, $widths, true);
    }

    /**
     * Get MIME type for an artwork response. Poster variants are JPEG; the title
     * logo (`size=logo`) is a transparency-preserving PNG.
     */
    private function mimeType(string $size): string
    {
        return $size === ArtworkStorage::LOGO_SIZE ? 'image/png' : 'image/jpeg';
    }

    /**
     * Compute ETag for a file based on its size and mtime.
     */
    private function computeETag(string $path): string
    {
        $stat = stat($path);
        if ($stat === false) {
            return '';
        }

        return sprintf('"%x-%x"', $stat['size'], $stat['mtime']);
    }
}
