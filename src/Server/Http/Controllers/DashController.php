<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Media\Streaming\Dash\DashStreamer;
use Phlex\Media\Streaming\Dash\AdaptationSet;

/**
 * DASH Streaming Controller.
 *
 * Handles DASH streaming endpoints for manifest and segment delivery.
 * DASH uses MPD (Media Presentation Description) manifests with M4S segments.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @since 0.11.0
 * @see https://developer.mozilla.org/en-US/docs/Web/Media/DASH_Adaptive_Streaming
 */
class DashController
{
    private DashStreamer $dashStreamer;

    public function __construct(DashStreamer $dashStreamer)
    {
        $this->dashStreamer = $dashStreamer;
    }

    /**
     * GET /dash/{jobId}/manifest.mpd - Master manifest.
     *
     * @param Request $request
     * @param array{job_id: string} $params
     * @return Response
     */
    public function getMasterManifest(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';

        if (empty($jobId)) {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        // Create adaptation sets for video and audio
        $videoSet = new AdaptationSet(
            'video-1080',
            'video',
            'avc1.64001f',
            1920,
            1080,
            5000000
        );
        $audioSet = new AdaptationSet(
            'audio-en',
            'audio',
            'mp4a.40.2',
            0,
            0,
            128000,
            48000
        );

        $mpd = $this->dashStreamer->generateMasterMpd($jobId, [$videoSet, $audioSet]);

        return (new Response())
            ->header('Content-Type', 'application/dash+xml')
            ->header('Cache-Control', 'public, max-age=60')
            ->text($mpd);
    }

    /**
     * GET /dash/{jobId}/{setId}/manifest.mpd - Adaptation set manifest.
     *
     * @param Request $request
     * @param array{job_id: string, set_id: string} $params
     * @return Response
     */
    public function getAdaptationSetManifest(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $setId = (int)($params['set_id'] ?? 0);

        if (empty($jobId)) {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        $segments = [];
        for ($i = 1; $i <= 120; $i++) {
            $segments[] = ['duration' => 6.0, 'url' => "segment_{$i}.m4s"];
        }

        $params = [
            'content_type' => $setId === 0 ? 'video' : 'audio',
            'bandwidth' => $setId === 0 ? 5000000 : 128000,
            'codec' => $setId === 0 ? 'avc1.64001f' : 'mp4a.40.2',
        ];

        if ($setId === 0) {
            $params['width'] = 1920;
            $params['height'] = 1080;
        } else {
            $params['sample_rate'] = 48000;
        }

        $mpd = $this->dashStreamer->generateAdaptationSetMpd($jobId, $setId, $segments, $params);

        return (new Response())
            ->header('Content-Type', 'application/dash+xml')
            ->header('Cache-Control', 'public, max-age=60')
            ->text($mpd);
    }

    /**
     * GET /dash/{jobId}/{setId}/segment_{n}.m4s - Segment file.
     *
     * @param Request $request
     * @param array{job_id: string, set_id: string, segment_number: string} $params
     * @return Response
     */
    public function getSegment(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $setId = (int)($params['set_id'] ?? 0);
        $segmentNumber = (int)($params['segment_number'] ?? 0);

        if (empty($jobId)) {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        $path = $this->dashStreamer->getSegmentPath($jobId, $setId, $segmentNumber);

        if (!file_exists($path)) {
            return (new Response())->status(404)->json(['error' => 'Segment not found']);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to read segment']);
        }

        return (new Response())
            ->header('Content-Type', 'video/mp4')
            ->header('Cache-Control', 'public, max-age=31536000')
            ->header('Content-Length', (string) strlen($content))
            ->header('Accept-Ranges', 'bytes')
            ->body($content);
    }

    /**
     * GET /dash/{jobId}/manifest - Returns manifest info.
     *
     * @param Request $request
     * @param array{job_id: string} $params
     * @return Response
     */
    public function getManifest(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';

        if (empty($jobId)) {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        $manifestUrl = $this->dashStreamer->getMasterMpdUrl($jobId);

        return (new Response())->json([
            'manifest_url' => $manifestUrl,
            'job_id' => $jobId,
            'protocol' => 'DASH',
        ]);
    }
}
