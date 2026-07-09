<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * DASH Streaming Controller.
 *
 * Serves the real DASH output of the CMAF transcode pipeline
 * ({@see \Phlix\Media\Transcoding\TranscodeManager}): a `manifest.mpd` and the
 * shared `init-N.m4s` / `chunk-N-NNNNN.m4s` segments (the same fMP4 segments the
 * HLS playlists reference). The manifest references segments by relative
 * filename, so files are served from the job directory verbatim.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/Media/DASH_Adaptive_Streaming
 */
class DashController
{
    use TranscodeFileServer;

    /** @var string Base directory holding per-job CMAF output (shared with HLS). */
    private string $segmentDir;

    public function __construct(string $segmentDir)
    {
        $this->segmentDir = rtrim($segmentDir, '/');
    }

    /**
     * GET /dash/{job_id}/manifest — JSON pointer to the MPD URL.
     *
     * @param array<string, string> $params
     */
    public function getManifest(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        if ($jobId === '') {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        return (new Response())->json([
            'manifest_url' => "/dash/{$jobId}/manifest.mpd",
            'job_id' => $jobId,
            'protocol' => 'DASH',
        ]);
    }

    /**
     * GET /dash/{job_id}/{file} — serve the MPD or a segment from the job dir.
     *
     * Handles `manifest.mpd`, `init-N.m4s` and `chunk-*.m4s`.
     *
     * @param array<string, string> $params
     */
    public function serveFile(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $file = $params['file'] ?? '';
        $dir = $jobId !== '' ? "{$this->segmentDir}/{$jobId}" : '';
        return $this->serveJobFile($request, $dir, $file);
    }
}
