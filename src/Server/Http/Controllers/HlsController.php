<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\TranscodeManager;

/**
 * Serves HLS playlists and on-demand MPEG-TS segments for transcode jobs.
 *
 * The transcode pipeline ({@see \Phlix\Media\Transcoding\TranscodeManager}) publishes
 * a COMPLETE VOD playlist (`master.m3u8` + `media_0.m3u8`) into the job directory the
 * instant a job is created, so the player knows the full duration and seekable range
 * up front. The `seg-NNNNN.ts` segments listed by that playlist are transcoded ON
 * DEMAND the first time each is fetched (an `-ss` fast-seek encode), which lets the
 * player seek anywhere — including past what has been produced so far. Playlists and
 * subtitle sidecars are static files served verbatim; segment requests are routed
 * through {@see TranscodeManager::ensureSegment()} to produce-or-serve.
 */
class HlsController
{
    use TranscodeFileServer;

    private HlsStreamer $hlsStreamer;

    /**
     * The transcoder that produces on-demand segments. Null only in the degenerate
     * container-less construction path (no DB), where segment requests cannot be
     * served and 404 instead — playlists/static files still serve.
     */
    private ?TranscodeManager $transcodeManager;

    public function __construct(HlsStreamer $hlsStreamer, ?TranscodeManager $transcodeManager = null)
    {
        $this->hlsStreamer = $hlsStreamer;
        $this->transcodeManager = $transcodeManager;
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
     * GET /hls/{job_id}/{file} — serve a playlist, subtitle sidecar, or segment.
     *
     * `master.m3u8` / `media_0.m3u8` / `sub-*.vtt` (and legacy `chunk-*.m4s`) are
     * static files. A `seg-NNNNN.ts` request is routed through the transcoder, which
     * returns a cached segment immediately or transcodes it on demand first.
     *
     * @param array<string, string> $params
     */
    public function serveFile(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $file = $params['file'] ?? '';
        if ($jobId === '' || !$this->isSafeFilename($file)) {
            return (new Response())->status(400)->json(['error' => 'invalid request']);
        }

        // On-demand MPEG-TS segment: produce (or serve cached) this segment before
        // handing it to the static file server.
        if (preg_match('/^seg-(\d{1,9})\.ts$/', $file, $m) === 1) {
            try {
                // A5 changed ensureSegment() to (jobId, variant, index). This legacy
                // unprefixed match passes null for the variant; full variant-aware
                // filename parsing (media_v{V}.m3u8 / seg-v{V}-NNNNN.ts) lands in step A6.
                $ready = $this->transcodeManager?->ensureSegment($jobId, null, (int) $m[1]);
            } catch (SegmentBusyException $e) {
                // Transient overload — tell the player to retry shortly rather than
                // blocking a worker or timing out. hls.js treats 503 as a retryable
                // load error and backs off, so the in-flight encodes finish first.
                return (new Response())
                    ->status(503)
                    ->header('Retry-After', '1')
                    ->json(['error' => 'segment busy']);
            }
            if ($ready === null) {
                return (new Response())->status(404)->json(['error' => 'segment unavailable']);
            }
        }

        $dir = $this->hlsStreamer->getJobDirectory($jobId);
        return $this->serveJobFile($dir, $file);
    }
}
