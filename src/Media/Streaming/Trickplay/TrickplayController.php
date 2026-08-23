<?php

/**
 * Phlix media server component: Trickplay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming\Trickplay;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Trickplay Controller — HTTP handler for serving trickplay artefacts.
 *
 * Serves the three files the background media-asset worker writes per item:
 * `sprite.jpg` and `timeline.json` (the web player's scrubber preview) and
 * `thumbs.bif` (the Roku trick-mode archive).
 *
 * S275 removed `getIndex()`/`getThumbnail()` and their routes. They served
 * `index.xml` and `bif_NN.jpg`, which only the deleted `TrickplayGenerator`
 * could ever have produced — proven at runtime never to have run — so they were
 * live routes over files nothing writes. Real BIF data is now `thumbs.bif`.
 *
 * @since 0.11.0
 */
class TrickplayController
{
    /**
     * File name of the Roku BIF archive within a job's trickplay directory.
     *
     * Aliased from {@see BifWriter::FILENAME} rather than re-spelled, so the
     * name the producer writes and the name this class serves cannot drift.
     */
    public const BIF_FILENAME = BifWriter::FILENAME;

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
     * Returns the URL for the Roku BIF archive.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string URL path to the `.bif` archive
     *
     * @since 0.103.0
     */
    public function getBifUrl(string $jobId): string
    {
        return $this->baseUrl . '/trickplay/' . $jobId . '/' . self::BIF_FILENAME;
    }

    /**
     * Absolute path of a job's BIF archive, whether or not it exists.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string Filesystem path to the `.bif` archive
     *
     * @since 0.103.0
     */
    public function getBifPath(string $jobId): string
    {
        return $this->getJobDir($jobId) . '/' . self::BIF_FILENAME;
    }

    /**
     * Whether a job's BIF archive is actually on disk.
     *
     * Callers advertise `trickplay_bif_url` on the strength of this and nothing
     * else — a URL offered for a file that is not there is a 404 the client has
     * been told to expect.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return bool True if the `.bif` exists and is non-empty
     *
     * @since 0.103.0
     */
    public function hasBif(string $jobId): bool
    {
        if (!$this->isValidJobId($jobId)) {
            return false;
        }

        $path = $this->getBifPath($jobId);

        return is_file($path) && filesize($path) > 0;
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
        return $this->baseUrl . '/trickplay/' . $jobId . '/' . FfmpegRunner::SPRITE_FILENAME;
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
        return $this->baseUrl . '/trickplay/' . $jobId . '/' . FfmpegRunner::TIMELINE_FILENAME;
    }

    /**
     * Returns the Roku BIF archive content.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string|null Archive bytes or null if not found
     *
     * @since 0.103.0
     */
    public function getBifContent(string $jobId): ?string
    {
        // Path traversal protection: validate jobId
        if (!$this->isValidJobId($jobId)) {
            return null;
        }
        $bifPath = $this->getBifPath($jobId);

        // Defense-in-depth: resolve real path and verify it's within trickplay directory
        $realPath = realpath($bifPath);
        if ($realPath === false || !str_starts_with($realPath, $this->trickplayDir)) {
            return null;
        }

        if (!is_file($bifPath)) {
            return null;
        }

        $content = file_get_contents($bifPath);
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
        $spritePath = $jobDir . '/' . FfmpegRunner::SPRITE_FILENAME;

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
        $timelinePath = $this->trickplayDir . '/trickplay/' . $jobId . '/' . FfmpegRunner::TIMELINE_FILENAME;

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
     * HTTP handler for getting the Roku BIF archive.
     *
     * GET /trickplay/{jobId}/thumbs.bif
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (jobId)
     *
     * @return Response HTTP response with the archive bytes
     *
     * @since 0.103.0
     */
    public function getBif(Request $request, array $params): Response
    {
        $jobId = $params['jobId'] ?? '';

        $content = $this->getBifContent($jobId);
        if ($content === null) {
            return (new Response())
                ->status(404)
                ->json([
                    'error' => 'Not Found',
                    'message' => 'Trickplay BIF not found',
                ]);
        }

        return (new Response())
            ->status(200)
            ->header('Content-Type', 'application/octet-stream')
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
        } elseif (!file_exists($jobDir . '/' . FfmpegRunner::SPRITE_FILENAME)) {
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
