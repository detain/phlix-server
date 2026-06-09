<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Streaming\HlsStreamer;

/**
 * Serves HLS playlists and CMAF segments for transcode jobs.
 *
 * The transcode pipeline ({@see \Phlix\Media\Transcoding\TranscodeManager}) runs
 * one CMAF (fMP4) encode that writes `master.m3u8` + `media_N.m3u8` (HLS) and the
 * shared `init-N.m4s` / `chunk-N-NNNNN.m4s` segments into the job directory. Every
 * playlist references its segments by relative filename, so this serves the job
 * directory's files verbatim — no URI rewriting.
 */
class HlsController
{
    use TranscodeFileServer;

    private HlsStreamer $hlsStreamer;

    public function __construct(HlsStreamer $hlsStreamer)
    {
        $this->hlsStreamer = $hlsStreamer;
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
     * GET /hls/{job_id}/{file} — serve a playlist or segment from the job dir.
     *
     * Handles `master.m3u8`, `media_N.m3u8`, `init-N.m4s` and `chunk-*.m4s`.
     *
     * @param array<string, string> $params
     */
    public function serveFile(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $file = $params['file'] ?? '';
        $dir = $jobId !== '' ? $this->hlsStreamer->getJobDirectory($jobId) : '';
        return $this->serveJobFile($dir, $file);
    }
}
