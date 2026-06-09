<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Starts on-demand HLS transcode jobs and reports their progress.
 *
 * The web player calls {@see self::start()} when a media item can't be
 * direct-played (incompatible container/codec); it returns the HLS master
 * playlist URL the player feeds to hls.js. {@see self::status()} backs the
 * client's readiness polling while the detached FFmpeg encode warms up.
 */
class TranscodeController
{
    private TranscodeManager $transcodeManager;

    public function __construct(TranscodeManager $transcodeManager)
    {
        $this->transcodeManager = $transcodeManager;
    }

    /**
     * POST /api/v1/media/{id}/transcode — start (or reuse) an HLS transcode.
     *
     * Accepts an optional `profile` query/body param (device profile, default
     * 'web'). Returns the job id, the HLS master playlist URL, the current
     * status and whether an existing job was reused.
     *
     * @param array<string, string> $params
     */
    public function start(Request $request, array $params): Response
    {
        $mediaId = $params['id'] ?? '';
        if ($mediaId === '') {
            return (new Response())->status(400)->json(['error' => 'media id is required']);
        }

        $profile = $request->queryString('profile') ?? 'web';
        if ($profile === '') {
            $profile = 'web';
        }

        try {
            $job = $this->transcodeManager->ensureHlsJob($mediaId, $profile);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(404)->json(['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            // Concurrency exhausted / launch failure — retryable.
            return (new Response())->status(503)->json(['error' => $e->getMessage()]);
        }

        return (new Response())->json([
            'job_id' => $job['job_id'],
            'master_url' => $job['master_url'],
            'hls_url' => $job['hls_url'],
            'dash_url' => $job['dash_url'],
            'status' => $job['status'],
            'reused' => $job['reused'],
        ]);
    }

    /**
     * GET /api/v1/transcode/{jobId}/status — report job readiness.
     *
     * @param array<string, string> $params
     */
    public function status(Request $request, array $params): Response
    {
        $jobId = $params['jobId'] ?? ($params['job_id'] ?? '');
        if ($jobId === '') {
            return (new Response())->status(400)->json(['error' => 'job id is required']);
        }

        $readiness = $this->transcodeManager->getJobReadiness($jobId);
        if ($readiness['status'] === 'not_found') {
            return (new Response())->status(404)->json(['error' => 'Job not found']);
        }

        return (new Response())->json([
            'job_id' => $readiness['job_id'],
            'status' => $readiness['status'],
            'segments' => $readiness['segments'],
            'playlist_ready' => $readiness['playlist_ready'],
            'progress' => $readiness['progress'],
            'master_url' => "/hls/{$readiness['job_id']}/master.m3u8",
            'dash_url' => "/dash/{$readiness['job_id']}/manifest.mpd",
        ]);
    }
}
