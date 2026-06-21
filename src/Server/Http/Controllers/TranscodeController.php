<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\SignedUrl;
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

        // Sign the streaming URLs: the player feeds these straight to hls.js /
        // <video>, which can't attach a Bearer header to the initial manifest
        // request. The token is prefix-scoped to the per-job directory, so it
        // authorises every variant playlist and segment too.
        $signer = SignedUrl::fromEnv();
        $sign = static fn (mixed $url): mixed => is_string($url) && $url !== '' ? $signer->mint($url) : $url;

        return (new Response())->json([
            'job_id' => $job['job_id'],
            'master_url' => $sign($job['master_url']),
            'hls_url' => $sign($job['hls_url']),
            'dash_url' => $sign($job['dash_url']),
            'status' => $job['status'],
            'reused' => $job['reused'],
            'subtitles' => self::signSubtitleUrls($job['subtitles'], $sign),
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

        // Signed, prefix-scoped streaming URLs (see start()).
        $signer = SignedUrl::fromEnv();
        $sign = static fn (mixed $url): mixed => is_string($url) && $url !== '' ? $signer->mint($url) : $url;

        return (new Response())->json([
            'job_id' => $readiness['job_id'],
            'status' => $readiness['status'],
            'segments' => $readiness['segments'],
            'playlist_ready' => $readiness['playlist_ready'],
            'progress' => $readiness['progress'],
            'master_url' => $sign("/hls/{$readiness['job_id']}/master.m3u8"),
            'dash_url' => $sign("/dash/{$readiness['job_id']}/manifest.mpd"),
            'subtitles' => self::signSubtitleUrls($readiness['subtitles'], $sign),
        ]);
    }

    /**
     * Signs the `url` of each subtitle track in a subtitle list.
     *
     * Sidecar VTTs are served from the per-job directory under `/hls/{job}/`,
     * which is now gated; the player loads them via a `<track>` element that
     * can't attach a Bearer header. Signing each URL (prefix-scoped to the same
     * job) lets the track load. The list shape is otherwise preserved.
     *
     * @param mixed    $subtitles The raw subtitle list from the transcode manager.
     * @param \Closure $sign      Per-URL signer: `fn(mixed $url): mixed`.
     *
     * @return mixed The subtitle list with each `url` signed.
     */
    private static function signSubtitleUrls(mixed $subtitles, \Closure $sign): mixed
    {
        if (!is_array($subtitles)) {
            return $subtitles;
        }

        return array_map(static function (mixed $track) use ($sign): mixed {
            if (is_array($track) && isset($track['url'])) {
                $track['url'] = $sign($track['url']);
            }

            return $track;
        }, $subtitles);
    }
}
