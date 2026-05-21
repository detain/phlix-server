<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Streaming\HlsStreamer;

class HlsController
{
    private HlsStreamer $hlsStreamer;

    public function __construct(HlsStreamer $hlsStreamer)
    {
        $this->hlsStreamer = $hlsStreamer;
    }

    /**
     * @param array<string, string> $params
     */
    public function getMasterPlaylist(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';

        if (empty($jobId)) {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        $playlist = $this->hlsStreamer->generateMasterPlaylist($jobId, [
            ['bandwidth' => 5000000, 'width' => 1920, 'height' => 1080, 'name' => '1080p'],
            ['bandwidth' => 2500000, 'width' => 1280, 'height' => 720, 'name' => '720p'],
            ['bandwidth' => 1000000, 'width' => 854, 'height' => 480, 'name' => '480p'],
        ]);

        return (new Response())
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'public, max-age=60')
            ->text($playlist);
    }

    /**
     * @param array<string, string> $params
     */
    public function getVariantPlaylist(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $variantIndex = (int)($params['variant_index'] ?? 0);

        // In real implementation, this would read from cache or generate on-demand
        $segments = [];
        for ($i = 0; $i < 120; $i++) {
            $segments[] = ['duration' => 6.0];
        }

        $playlist = $this->hlsStreamer->generateVariantPlaylist($jobId, $variantIndex, $segments, 6);

        return (new Response())
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'public, max-age=60')
            ->text($playlist);
    }

    /**
     * @param array<string, string> $params
     */
    public function getSegment(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $variantIndex = (int)($params['variant_index'] ?? 0);
        $segmentNumber = (int)($params['segment_number'] ?? 0);

        $content = $this->hlsStreamer->getSegmentContent($jobId, $variantIndex, $segmentNumber);

        if ($content === null) {
            return (new Response())->status(404)->json(['error' => 'Segment not found']);
        }

        return (new Response())
            ->header('Content-Type', 'video/mp2t')
            ->header('Cache-Control', 'public, max-age=31536000')
            ->header('Content-Length', (string)strlen($content))
            ->header('Accept-Ranges', 'bytes')
            ->body($content);
    }

    /**
     * @param array<string, string> $params
     */
    public function getPlaylist(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';

        if (empty($jobId)) {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        $playlist = $this->hlsStreamer->getPlaylistUrl($jobId);

        return (new Response())->json([
            'playlist_url' => $playlist,
            'job_id' => $jobId,
        ]);
    }
}
