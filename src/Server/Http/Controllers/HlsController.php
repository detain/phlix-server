<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Transcoding\TranscodeManager;

/**
 * Serves HLS playlists and segments for transcode jobs.
 *
 * The variant playlist and segments are read straight from the files the
 * detached FFmpeg HLS encode writes ({@see TranscodeManager}), so this serves
 * REAL transcoded output rather than the placeholder manifests it used to.
 */
class HlsController
{
    private HlsStreamer $hlsStreamer;
    private ?TranscodeManager $transcodeManager;

    public function __construct(HlsStreamer $hlsStreamer, ?TranscodeManager $transcodeManager = null)
    {
        $this->hlsStreamer = $hlsStreamer;
        $this->transcodeManager = $transcodeManager;
    }

    /**
     * GET /hls/{job_id}/master.m3u8 — master playlist referencing the variant(s).
     *
     * Built from the job's recorded variant descriptor (resolution / bandwidth)
     * so an adaptive client gets accurate STREAM-INF metadata. Falls back to
     * 1080p defaults when the descriptor is unavailable.
     *
     * @param array<string, string> $params
     */
    public function getMasterPlaylist(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        if ($jobId === '') {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        $variant = $this->transcodeManager?->getJobVariant($jobId);
        $width = (int) ($variant['width'] ?? 1920);
        $height = (int) ($variant['height'] ?? 1080);
        $bandwidth = (int) ($variant['bandwidth'] ?? 5000000);

        $playlist = "#EXTM3U\n#EXT-X-VERSION:3\n";
        $playlist .= sprintf(
            "#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d\n",
            $bandwidth > 0 ? $bandwidth : 5000000,
            $width > 0 ? $width : 1920,
            $height > 0 ? $height : 1080
        );
        // Relative to /hls/{jobId}/master.m3u8 -> /hls/{jobId}/0/playlist.m3u8.
        $playlist .= "0/playlist.m3u8\n";

        return (new Response())
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'no-cache')
            ->body($playlist);
    }

    /**
     * GET /hls/{job_id}/{variant_index}/playlist.m3u8 — the real variant playlist.
     *
     * Reads the FFmpeg-produced `stream_{variant}.m3u8` from the job directory
     * and rewrites each `segment_{variant}_NNN.ts` URI to the canonical route
     * form (`NNN.ts`, resolved against this playlist's URL → the segment route).
     * 404 while the encode has not written the playlist yet, so the client
     * retries until the first segments land.
     *
     * @param array<string, string> $params
     */
    public function getVariantPlaylist(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $variantIndex = (int) ($params['variant_index'] ?? 0);
        if ($jobId === '') {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        $file = $this->hlsStreamer->getJobDirectory($jobId) . "/stream_{$variantIndex}.m3u8";
        if (!is_file($file)) {
            return (new Response())->status(404)->json(['error' => 'Playlist not ready']);
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to read playlist']);
        }

        $content = $this->rewriteSegmentUris($content, $variantIndex);

        return (new Response())
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'no-cache')
            ->body($content);
    }

    /**
     * GET /hls/{job_id}/{variant_index}/{segment_number}.ts — one segment file.
     *
     * @param array<string, string> $params
     */
    public function getSegment(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $variantIndex = (int) ($params['variant_index'] ?? 0);
        $segmentNumber = (int) ($params['segment_number'] ?? 0);

        $content = $this->hlsStreamer->getSegmentContent($jobId, $variantIndex, $segmentNumber);
        if ($content === null) {
            return (new Response())->status(404)->json(['error' => 'Segment not found']);
        }

        return (new Response())
            ->header('Content-Type', 'video/mp2t')
            ->header('Cache-Control', 'public, max-age=31536000')
            ->header('Content-Length', (string) strlen($content))
            ->header('Accept-Ranges', 'bytes')
            ->body($content);
    }

    /**
     * GET /hls/{job_id}/playlist — JSON pointer to the master playlist URL.
     *
     * @param array<string, string> $params
     */
    public function getPlaylist(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        if ($jobId === '') {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        return (new Response())->json([
            'playlist_url' => "/hls/{$jobId}/master.m3u8",
            'job_id' => $jobId,
        ]);
    }

    /**
     * Rewrites `segment_{variant}_NNN.ts` URIs to the canonical `NNN.ts` route form.
     *
     * @param string $playlist     Raw FFmpeg variant playlist.
     * @param int    $variantIndex Variant index whose segment prefix to strip.
     *
     * @return string Playlist with rewritten segment URIs.
     */
    private function rewriteSegmentUris(string $playlist, int $variantIndex): string
    {
        $pattern = '/^segment_' . $variantIndex . '_(\d+)\.ts$/m';
        $result = preg_replace_callback(
            $pattern,
            static fn(array $m): string => ((int) $m[1]) . '.ts',
            $playlist
        );
        return is_string($result) ? $result : $playlist;
    }
}
