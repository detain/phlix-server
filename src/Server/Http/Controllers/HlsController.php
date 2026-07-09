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
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\TranscodeManager;

/**
 * Serves HLS playlists and on-demand MPEG-TS segments for transcode jobs.
 *
 * The transcode pipeline ({@see \Phlix\Media\Transcoding\TranscodeManager}) publishes
 * a COMPLETE VOD playlist into the job directory the instant a job is created, so the
 * player knows the full duration and seekable range up front. Two job shapes exist:
 *
 *   - Legacy single-variant: `master.m3u8` + `media_0.m3u8` listing `seg-NNNNN.ts`.
 *   - Multi-variant ABR ladder (A5): `master.m3u8` lists one `#EXT-X-STREAM-INF` per
 *     rung, each pointing at a per-variant media playlist `media_v{V}.m3u8` (e.g.
 *     `media_v1080p.m3u8`, `media_voriginal.m3u8`) that lists `seg-v{V}-NNNNN.ts`
 *     segments, where `{V}` is the rendition id from `AbrLadder` (`240p`…`2160p`,
 *     `original` — lowercase letters + digits only).
 *
 * The `seg-…\.ts` segments are transcoded ON DEMAND the first time each is fetched
 * (an `-ss` fast-seek encode), which lets the player seek anywhere — including past
 * what has been produced so far. All playlists (master + per-variant media) and
 * subtitle sidecars are static files served verbatim; only segment requests are
 * routed through {@see TranscodeManager::ensureSegment()} to produce-or-serve.
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
     * `master.m3u8` / `media_0.m3u8` / `media_v{V}.m3u8` / `media_a{N}.m3u8` /
     * `sub-*.vtt` (and legacy `chunk-*.m4s`) are static files served verbatim by
     * {@see serveJobFile()} — no transcoder involvement (the multi-variant media
     * playlists are written up front by {@see TranscodeManager}). Only segment
     * requests are routed through the transcoder, which returns a cached segment
     * immediately or transcodes it on demand first. Three segment shapes are
     * recognized:
     *
     *   - Legacy `seg-NNNNN.ts` → `ensureSegment($jobId, null, NNNNN)` (the null
     *     variant selects the single-variant `variants IS NULL` job path).
     *   - Multi-variant `seg-v{V}-NNNNN.ts` → `ensureSegment($jobId, '{V}', NNNNN)`,
     *     where `{V}` is a rendition id (`[a-z0-9]+`, e.g. `1080p`, `original`). An
     *     unknown variant / out-of-range index resolves to 404 (self-heals via client
     *     retry once the segment exists).
     *   - Audio-only `seg-a{A}-NNNNN.ts` (P3B-S3) → `ensureSegment($jobId, null, NNNNN, '{A}')`,
     *     where `{A}` is an audio stream index (e.g. `0`, `1`). Produces an audio-only
     *     segment for multi-audio HLS playback.
     *
     * The variant character class `[a-z0-9]+` is anchored inside `^…$` and excludes
     * `.` `/` `\`, so it is a defense-in-depth allowlist that cannot smuggle a
     * traversal sequence; a filename that fails both regexes (and passes the earlier
     * {@see isSafeFilename()} gate) falls through to a plain static lookup that 404s.
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
        // handing it to the static file server. Three filename shapes route here —
        // the legacy unprefixed name (null variant), the multi-variant name (rendition
        // id parsed from the URL), and the audio-only name (audio group id).
        $variant = null;
        $audioId = null;
        $index = null;
        if (preg_match('/^seg-v([a-z0-9]+)-(\d{1,9})\.ts$/', $file, $m) === 1) {
            $variant = $m[1];
            $index = (int) $m[2];
        } elseif (preg_match('/^seg-a([a-z0-9]+)-(\d{1,9})\.ts$/', $file, $m) === 1) {
            // P3B-S3 audio-only segment: seg-a{A}-NNNNN.ts → audio group id A, index NNNNN.
            $audioId = 'a' . $m[1];
            $index = (int) $m[2];
        } elseif (preg_match('/^seg-(\d{1,9})\.ts$/', $file, $m) === 1) {
            $index = (int) $m[1];
        }

        if ($index !== null) {
            try {
                // A5 changed ensureSegment() to (jobId, variant, index): a null
                // variant selects the legacy single-variant job; a rendition id
                // string selects the matching rung of the multi-variant ladder.
                // P3B-S3 adds $audioId for audio-only segment production.
                $ready = $this->transcodeManager?->ensureSegment($jobId, $variant, $index, $audioId);
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
        return $this->serveJobFile($request, $dir, $file);
    }
}
