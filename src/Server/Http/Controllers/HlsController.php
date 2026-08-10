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
 * The `seg-…` segments are transcoded ON DEMAND the first time each is fetched
 * (an `-ss` fast-seek encode), which lets the player seek anywhere — including past
 * what has been produced so far. All playlists (master + per-variant media) and
 * subtitle sidecars are static files served verbatim; only segment/init requests are
 * routed through {@see TranscodeManager::ensureSegment()} to produce-or-serve.
 *
 * S310 — the fMP4 flavour. A job created with `transcoding.segment_format=fmp4`
 * names `.m4s` segments and carries one `#EXT-X-MAP:URI="init-….m4s"` per rendition
 * (S57). Both containers route through the same shared filename router
 * ({@see SegmentRequestParser}, {@see SegmentRequestParser::HLS_EXTENSIONS}), so an
 * fMP4 job's init and segments are produced on demand here exactly as an MPEG-TS
 * job's are. Before S310 this controller matched `\.ts$` only: every `.m4s` fell
 * through to the static lookup and 404'd, and the init — which hls.js fetches
 * FIRST, before any media segment — had no producer at all. S56 could make the
 * bytes and S57 could name them; nothing could serve them.
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
     * playlists are written up front by {@see TranscodeManager}). Only segment and
     * init requests are routed through the transcoder, which returns a cached
     * segment immediately or transcodes it on demand first. S310 delegates the
     * filename → `ensureSegment()` mapping to {@see SegmentRequestParser}, which
     * carries the full per-shape argument table; the shapes this route accepts are
     * {@see SegmentRequestParser::HLS_EXTENSIONS}:
     *
     *   - `seg-NNNNN.{ts,m4s}` (legacy single-variant `variants IS NULL` job),
     *     `seg-v{V}-NNNNN.{ts,m4s}` (a rendition of the ABR ladder), and
     *     `seg-a{A}-NNNNN.{ts,m4s}` (P3B-S3 audio-only). An unknown variant or an
     *     out-of-range index resolves to 404 — a decision, and one that self-heals
     *     via client retry once the segment exists.
     *   - `init.m4s` / `init-v{V}.m4s` / `init-a{A}.m4s`, each mapping to index 0
     *     of its OWN rendition. There is no `.ts` counterpart: only a CMAF job has
     *     an init. hls.js resolves `#EXT-X-MAP` before it fetches any media
     *     segment, so this is the FIRST byte-bearing request of an fMP4 stream —
     *     until S310 added it, a flagged job was unreachable from its own opening
     *     request no matter what else worked.
     *
     * An `.m4s` request against an MPEG-TS job (or the reverse) produces that job's
     * own segment and then 404s on the requested name, because the container is
     * committed at job creation and folded into the job key — see
     * {@see \Phlix\Media\Transcoding\EncodeSettings::fingerprint()}. That is a
     * genuinely-absent-file answer, not a mis-serve: `.ts` and `.m4s` can never
     * co-mingle in one job directory.
     *
     * A filename matching no arm (and passing the earlier {@see isSafeFilename()}
     * gate) falls through to a plain static lookup — which is what keeps the legacy
     * `chunk-*.m4s` names and the subtitle sidecars serving verbatim.
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
        // signed URL cannot reach over-cap bytes. No-op for the owner / un-capped
        // profile, and (S235, deliberately) for a signature-only request that carries
        // no session userId — see TranscodeFileServer::transcodeJobOverCap().
        if ($this->transcodeJobOverCap($request, $jobId, $this->transcodeManager, $this->ratingGate)) {
            return (new Response())->status(404)->json(['error' => 'Not found']);
        }

        // On-demand segment or init: produce (or serve cached) it before handing it
        // to the static file server. S310 replaced three inline `\.ts$` regexes here
        // with the shared router, which adds the fMP4 shapes (`.m4s` segments plus
        // the three init names) that S57's playlists have been naming since the flag
        // existed. A name that is not an on-demand artefact parses to null and is
        // served statically, exactly as before.
        $target = SegmentRequestParser::parse($file, SegmentRequestParser::HLS_EXTENSIONS);

        if ($target !== null) {
            try {
                // A5 changed ensureSegment() to (jobId, variant, index): a null
                // variant selects the legacy single-variant job; a rendition id
                // string selects the matching rung of the multi-variant ladder.
                // P3B-S3 adds $audioId for audio-only segment production.
                $ready = $this->transcodeManager?->ensureSegment(
                    $jobId,
                    $target['variant'],
                    $target['index'],
                    $target['audioId']
                );
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
