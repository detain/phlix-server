<?php

declare(strict_types=1);

namespace Phlex\Media\Streaming\Trickplay;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

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
     * Returns the thumbnail image content.
     *
     * @param string $jobId Transcode job identifier
     * @param int $imageIndex Grid image index
     *
     * @return string|null Image content or null if not found
     */
    public function getThumbnailContent(string $jobId, int $imageIndex): ?string
    {
        $jobDir = $this->trickplayDir . '/trickplay/' . $jobId;
        $gridFile = 'bif_' . str_pad((string) $imageIndex, 2, '0', STR_PAD_LEFT) . '.jpg';
        $imagePath = $jobDir . '/' . $gridFile;

        if (!file_exists($imagePath)) {
            $gridFile = 'bif_' . str_pad((string) $imageIndex, 2, '0', STR_PAD_LEFT) . '.png';
            $imagePath = $jobDir . '/' . $gridFile;
        }

        if (!file_exists($imagePath)) {
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
        $indexPath = $this->trickplayDir . '/trickplay/' . $jobId . '/index.xml';

        if (!file_exists($indexPath)) {
            return null;
        }

        $content = file_get_contents($indexPath);
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
}
