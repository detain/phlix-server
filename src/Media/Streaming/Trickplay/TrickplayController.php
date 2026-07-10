<?php

/**
 * Phlix media server component: Trickplay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming\Trickplay;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Trickplay Controller — HTTP handler for serving trickplay thumbnail images.
 *
 * This controller serves thumbnail grid images and BIF index XML files
 * with the appropriate Content-Type headers for byte-range requests.
 *
 * @since 0.11.0
 */
class TrickplayController
{
    /** @var string Base directory for trickplay files */
    private string $trickplayDir;

    /** @var string Base URL for trickplay endpoints */
    private string $baseUrl;

    /**
     * Creates a new TrickplayController instance.
     *
     * @param string $trickplayDir Base directory for trickplay files
     * @param string $baseUrl Base URL for trickplay endpoints
     */
    public function __construct(string $trickplayDir, string $baseUrl)
    {
        $this->trickplayDir = rtrim($trickplayDir, '/');
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Validates that a job ID contains only safe characters and no path traversal.
     *
     * Job IDs are UUIDs formatted as xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx with
     * only hexadecimal characters and hyphens.
     *
     * @param string $jobId The job ID to validate
     *
     * @return bool True if the job ID is valid, false otherwise
     */
    private function isValidJobId(string $jobId): bool
    {
        // Reject empty or overly long job IDs
        if ($jobId === '' || strlen($jobId) > 64) {
            return false;
        }
        // Reject any path traversal attempts
        if (str_contains($jobId, '..') || str_contains($jobId, '/') || str_contains($jobId, '\\')) {
            return false;
        }
        // Job IDs should only contain alphanumeric characters and hyphens (UUID format)
        return preg_match('/^[a-zA-Z0-9\-]+$/', $jobId) === 1;
    }

    /**
     * Returns the URL for the trickplay thumbnail image file.
     *
     * @param string $jobId Transcode job identifier
     * @param int $imageIndex Grid image index
     *
     * @return string URL path to the thumbnail image
     */
    public function getThumbnailUrl(string $jobId, int $imageIndex): string
    {
        return $this->baseUrl . '/trickplay/' . $jobId . '/thumb-' . $imageIndex . '.jpg';
    }

    /**
     * Returns the URL for the BIF index XML.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string URL path to the BIF index XML
     */
    public function getIndexUrl(string $jobId): string
    {
        return $this->baseUrl . '/trickplay/' . $jobId . '/index.xml';
    }

    /**
     * Returns the URL for the trickplay sprite sheet image.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string URL path to the sprite image
     */
    public function getSpriteUrl(string $jobId): string
    {
        return $this->baseUrl . '/trickplay/' . $jobId . '/sprite.jpg';
    }

    /**
     * Returns the URL for the trickplay timeline JSON.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string URL path to the timeline JSON
     */
    public function getTimelineUrl(string $jobId): string
    {
        return $this->baseUrl . '/trickplay/' . $jobId . '/timeline.json';
    }

    /**
     * Returns the thumbnail image content.
     *
     * @param string $jobId Transcode job identifier
     * @param int $imageIndex Grid image index
     *
     * @return string|null Image content or null if not found
     */
    public function getThumbnailContent(string $jobId, int $imageIndex): ?string
    {
        // Path traversal protection: validate jobId contains only safe characters
        if (!$this->isValidJobId($jobId)) {
            return null;
        }
        $jobDir = $this->trickplayDir . '/trickplay/' . $jobId;

        // Try JPG first
        $gridFile = 'bif_' . str_pad((string) $imageIndex, 2, '0', STR_PAD_LEFT) . '.jpg';
        $imagePath = $jobDir . '/' . $gridFile;

        if (!file_exists($imagePath)) {
            // Try PNG fallback
            $gridFile = 'bif_' . str_pad((string) $imageIndex, 2, '0', STR_PAD_LEFT) . '.png';
            $imagePath = $jobDir . '/' . $gridFile;
        }

        // Final check if file exists
        if (!file_exists($imagePath)) {
            return null;
        }

        // Defense-in-depth: resolve real path and verify it's within trickplay directory
        $realPath = realpath($imagePath);
        if ($realPath === false || !str_starts_with($realPath, $this->trickplayDir)) {
            return null;
        }

        $content = file_get_contents($imagePath);
        return $content !== false ? $content : null;
    }

    /**
     * Returns the BIF index XML content.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string|null XML content or null if not found
     */
    public function getIndexContent(string $jobId): ?string
    {
        // Path traversal protection: validate jobId
        if (!$this->isValidJobId($jobId)) {
            return null;
        }
        $indexPath = $this->trickplayDir . '/trickplay/' . $jobId . '/index.xml';

        // Defense-in-depth: resolve real path and verify it's within trickplay directory
        $realPath = realpath($indexPath);
        if ($realPath === false || !str_starts_with($realPath, $this->trickplayDir)) {
            return null;
        }

        if (!file_exists($indexPath)) {
            return null;
        }

        $content = file_get_contents($indexPath);
        return $content !== false ? $content : null;
    }

    /**
     * Returns the sprite sheet content.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string|null Image content or null if not found
     */
    public function getSpriteContent(string $jobId): ?string
    {
        // Path traversal protection: validate jobId
        if (!$this->isValidJobId($jobId)) {
            return null;
        }
        $jobDir = $this->trickplayDir . '/trickplay/' . $jobId;
        $spritePath = $jobDir . '/sprite.jpg';

        // Defense-in-depth: resolve real path and verify it's within trickplay directory
        $realPath = realpath($spritePath);
        if ($realPath === false || !str_starts_with($realPath, $this->trickplayDir)) {
            return null;
        }

        if (!file_exists($spritePath)) {
            // Try PNG fallback
            $spritePath = $jobDir . '/sprite.png';
            $realPath = realpath($spritePath);
            if ($realPath === false || !str_starts_with($realPath, $this->trickplayDir)) {
                return null;
            }
        }

        if (!file_exists($spritePath)) {
            return null;
        }

        $content = file_get_contents($spritePath);
        return $content !== false ? $content : null;
    }

    /**
     * Returns the timeline JSON content.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string|null JSON content or null if not found
     */
    public function getTimelineContent(string $jobId): ?string
    {
        // Path traversal protection: validate jobId
        if (!$this->isValidJobId($jobId)) {
            return null;
        }
        $timelinePath = $this->trickplayDir . '/trickplay/' . $jobId . '/timeline.json';

        // Defense-in-depth: resolve real path and verify it's within trickplay directory
        $realPath = realpath($timelinePath);
        if ($realPath === false || !str_starts_with($realPath, $this->trickplayDir)) {
            return null;
        }

        if (!file_exists($timelinePath)) {
            return null;
        }

        $content = file_get_contents($timelinePath);
        return $content !== false ? $content : null;
    }

    /**
     * Gets the Content-Type for a thumbnail image.
     *
     * @param string $jobId Transcode job identifier
     * @param int $imageIndex Grid image index
     *
     * @return string Content-Type header value
     */
    public function getThumbnailContentType(string $jobId, int $imageIndex): string
    {
        // Path traversal protection: validate jobId
        if (!$this->isValidJobId($jobId)) {
            return 'application/octet-stream';
        }
        $jobDir = $this->trickplayDir . '/trickplay/' . $jobId;
        $jpgPath = $jobDir . '/bif_' . str_pad((string) $imageIndex, 2, '0', STR_PAD_LEFT) . '.jpg';
        $pngPath = $jobDir . '/bif_' . str_pad((string) $imageIndex, 2, '0', STR_PAD_LEFT) . '.png';

        if (file_exists($jpgPath)) {
            return 'image/jpeg';
        }

        if (file_exists($pngPath)) {
            return 'image/png';
        }

        return 'application/octet-stream';
    }

    /**
     * Checks if trickplay files exist for a job.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return bool True if trickplay exists
     */
    public function hasTrickplay(string $jobId): bool
    {
        $indexPath = $this->trickplayDir . '/trickplay/' . $jobId . '/index.xml';
        return file_exists($indexPath);
    }

    /**
     * Gets the trickplay directory path for a job.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string Full path to the job's trickplay directory
     */
    public function getJobDir(string $jobId): string
    {
        return $this->trickplayDir . '/trickplay/' . $jobId;
    }

    /**
     * HTTP handler for getting a thumbnail image.
     *
     * GET /trickplay/{jobId}/thumb-{index}.jpg
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (jobId, index)
     *
     * @return Response HTTP response with image content
     *
     * @since 0.11.0
     */
    public function getThumbnail(Request $request, array $params): Response
    {
        $jobId = $params['jobId'] ?? '';
        $index = isset($params['index']) ? (int) $params['index'] : 0;

        $content = $this->getThumbnailContent($jobId, $index);
        if ($content === null) {
            return (new Response())
                ->status(404)
                ->json([
                    'error' => 'Not Found',
                    'message' => 'Trickplay thumbnail not found',
                ]);
        }

        $contentType = $this->getThumbnailContentType($jobId, $index);

        return (new Response())
            ->status(200)
            ->header('Content-Type', $contentType)
            ->header('Content-Length', (string) strlen($content))
            ->header('Cache-Control', 'public, max-age=86400')
            ->body($content);
    }

    /**
     * HTTP handler for getting the BIF index XML.
     *
     * GET /trickplay/{jobId}/index.xml
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (jobId)
     *
     * @return Response HTTP response with XML content
     *
     * @since 0.11.0
     */
    public function getIndex(Request $request, array $params): Response
    {
        $jobId = $params['jobId'] ?? '';

        $content = $this->getIndexContent($jobId);
        if ($content === null) {
            return (new Response())
                ->status(404)
                ->json([
                    'error' => 'Not Found',
                    'message' => 'Trickplay index not found',
                ]);
        }

        return (new Response())
            ->status(200)
            ->header('Content-Type', 'application/xml')
            ->header('Content-Length', (string) strlen($content))
            ->header('Cache-Control', 'public, max-age=86400')
            ->body($content);
    }

    /**
     * HTTP handler for getting the trickplay sprite sheet image.
     *
     * GET /trickplay/{jobId}/sprite.jpg
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (jobId)
     *
     * @return Response HTTP response with image content
     *
     * @since 0.11.0
     */
    public function getSprite(Request $request, array $params): Response
    {
        $jobId = $params['jobId'] ?? '';

        $content = $this->getSpriteContent($jobId);
        if ($content === null) {
            return (new Response())
                ->status(404)
                ->json([
                    'error' => 'Not Found',
                    'message' => 'Trickplay sprite not found',
                ]);
        }

        // Determine content type from file extension
        $contentType = 'image/jpeg';
        $jobDir = $this->trickplayDir . '/trickplay/' . $jobId;
        if (file_exists($jobDir . '/sprite.png')) {
            $contentType = 'image/png';
        } elseif (!file_exists($jobDir . '/sprite.jpg')) {
            return (new Response())
                ->status(404)
                ->json([
                    'error' => 'Not Found',
                    'message' => 'Trickplay sprite not found',
                ]);
        }

        return (new Response())
            ->status(200)
            ->header('Content-Type', $contentType)
            ->header('Content-Length', (string) strlen($content))
            ->header('Cache-Control', 'public, max-age=86400')
            ->body($content);
    }

    /**
     * HTTP handler for getting the trickplay timeline JSON.
     *
     * GET /trickplay/{jobId}/timeline.json
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (jobId)
     *
     * @return Response HTTP response with JSON content
     *
     * @since 0.11.0
     */
    public function getTimeline(Request $request, array $params): Response
    {
        $jobId = $params['jobId'] ?? '';

        $content = $this->getTimelineContent($jobId);
        if ($content === null) {
            return (new Response())
                ->status(404)
                ->json([
                    'error' => 'Not Found',
                    'message' => 'Trickplay timeline not found',
                ]);
        }

        return (new Response())
            ->status(200)
            ->header('Content-Type', 'application/json')
            ->header('Content-Length', (string) strlen($content))
            ->header('Cache-Control', 'public, max-age=86400')
            ->body($content);
    }
}
