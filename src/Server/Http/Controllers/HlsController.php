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
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\SegmentCacheFullException;
use Phlix\Media\Transcoding\TranscodeManager;

/**
 * Serves HLS playlists and on-demand MPEG-TS segments for transcode jobs.
 *
 * The transcode pipeline ({@see \Phlix\Media\Transcoding\TranscodeManager}) publishes
 * a COMPLETE VOD playlist into the job directory the instant a job is created, so the
 * player knows the full duration and seekable range up front. Two job shapes exist:
 *
 *   - Legacy single-variant: `master.m3u8` + `media_0.m3u8` listing `seg-NNNNN.ts`.
 *   - Multi-variant ABR ladder (A5): every variant gets its own media playlist
 *     `media_v{V}.m3u8` (e.g. `media_v1080p.m3u8`, `media_voriginal.m3u8`) listing
 *     `seg-v{V}-NNNNN.ts` segments, where `{V}` is the rendition id from `AbrLadder`
 *     (`240p`…`2160p`, `original` — lowercase letters + digits only). `master.m3u8`
 *     lists one `#EXT-X-STREAM-INF` per ABR-SWITCHABLE variant (SV-4.6,
 *     {@see \Phlix\Media\Transcoding\TranscodeManager}): every variant except a
 *     stream-COPY one and except a transcode `original` that duplicates the top
 *     rung's frame+BANDWIDTH. Those two keep their own media playlist and are served
 *     on direct request without appearing in the master, so `media_voriginal.m3u8`
 *     may or may not be a master level depending on the source — but it always
 *     exists on disk (S49).
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

    /**
     * Shared parental-control access gate for the serve-time re-check. Null in
     * legacy/no-container contexts, where the check is a strict no-op (owner-safe).
     */
    private ?RatingGate $ratingGate;

    public function __construct(
        HlsStreamer $hlsStreamer,
        ?TranscodeManager $transcodeManager = null,
        ?RatingGate $ratingGate = null
    ) {
        $this->hlsStreamer = $hlsStreamer;
        $this->transcodeManager = $transcodeManager;
        $this->ratingGate = $ratingGate;
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

        // Serve-time parental re-check (Finding 1b): deny a capped profile any
        // file of an over-cap job before producing/serving it, so a leaked/replayed
        // signed URL cannot reach over-cap bytes. No-op for the owner / un-capped /
        // unauthenticated request.
        if ($this->transcodeJobOverCap($request, $jobId, $this->transcodeManager, $this->ratingGate)) {
            return (new Response())->status(404)->json(['error' => 'Not found']);
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
            } catch (SegmentCacheFullException $e) {
                // SV-1.9: ENOSPC guard — the segment cache filesystem is low on space.
                // Trigger an opportunistic sweep to reclaim what we can, then tell
                // the player to retry shortly. hls.js treats 503 as a retryable load
                // error, so a subsequent retry after the sweep may succeed.
                $this->transcodeManager->sweepSegmentCache();
                return (new Response())
                    ->status(503)
                    ->header('Retry-After', '3')
                    ->json(['error' => 'segment cache full']);
            }
            if ($ready === null) {
                return (new Response())->status(404)->json(['error' => 'segment unavailable']);
            }
        }

        $dir = $this->hlsStreamer->getJobDirectory($jobId);

        // If a playlist file doesn't exist, the job directory may have been
        // evicted by the LRU sweep — attempt to regenerate the VOD playlists
        // from the persisted job data before falling through to serveJobFile.
        if (!is_file("{$dir}/{$file}") && $this->isPlaylistFile($file)) {
            $this->transcodeManager?->ensurePlaylistRegenerated($jobId);
        }

        // S98 REMOVED the legacy folded-original alias that used to sit here.
        //
        // The v7/v8 ABR ladder FOLDED a re-encoded "Original" that was
        // byte-identical to the top rung, so `media_voriginal.m3u8` was never
        // produced and a client requesting it directly got a fatal 404. A
        // serve-time alias resolved it to the top rung's playlist instead.
        //
        // S49 removed the fold ({@see \Phlix\Media\Streaming\LadderResult::streamVariants()}),
        // so every v9+ job writes a real `media_voriginal.m3u8` and the alias could
        // never engage for one. It was kept only for pre-v9 job directories still on
        // disk. That window closed: `sweepSegmentCache()` deletes a whole job dir
        // after `SEGMENT_CACHE_MAX_AGE` idle (3 h) or under the LRU size budget, and
        // the `ensurePlaylistRegenerated()` call ABOVE now writes a real Original
        // playlist even for a pre-v9 row (its persisted ladder always carried
        // `original`) — so regeneration, not aliasing, is what covers a swept dir.
        //
        // A missing `media_voriginal.m3u8` is therefore now a plain 404, exactly
        // like any other genuinely absent rung.

        return $this->serveJobFile($request, $dir, $file);
    }

    /**
     * Returns true if the given filename is an HLS playlist that can be
     * regenerated from the persisted job data.
     *
     * @param string $file Requested filename.
     */
    private function isPlaylistFile(string $file): bool
    {
        // master.m3u8, media_0.m3u8 (legacy), media_v{id}.m3u8 (multi-variant),
        // media_a{id}.m3u8 (audio-only).
        return $file === 'master.m3u8'
            || preg_match('/^media_(v\w+|a\d+|0)\.m3u8$/i', $file) === 1;
    }
}
