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
 *     lists one `#EXT-X-STREAM-INF` per ABR-SWITCHABLE variant, which is the ladder
 *     rungs only: `original` always has a playlist but is never an ABR level (SV-4.6
 *     — the client selects it explicitly), so `media_voriginal.m3u8` is served on
 *     direct request and does not appear in the master.
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

        // LEGACY (pre-v9 jobs only) — DEAD for every job created after S49.
        //
        // The v7/v8 ABR ladder FOLDED a re-encoded "Original" that was
        // byte-identical to the top rung, and because the playlist writer iterates
        // exactly that list, `media_voriginal.m3u8` was then never produced. A
        // client requesting it directly (e.g. a persisted "Original" quality
        // preference) got a 404 → fatal playback error, so this serve-time alias
        // resolves it to the TOP available rung's playlist instead (served in
        // place, no redirect — playlists are `no-cache`, so this is cache-safe).
        //
        // S49 removed the fold ({@see \Phlix\Media\Streaming\LadderResult::streamVariants()}):
        // every v9+ job writes a real `media_voriginal.m3u8`, so the `!is_file()`
        // guard below is never true for one and this alias never engages. It is
        // retained for exactly one release so that pre-v9 job directories still on
        // disk — reachable by any already-issued signed URL until the cache sweep
        // ages them out — keep playing instead of hard-failing. REMOVE IT (with
        // {@see resolveTopVariantPlaylist()} and their tests) in a follow-up once
        // no pre-v9 job can still be served. Applied ONLY to the `original` alias,
        // and only when that playlist is genuinely absent — a truly unknown rung
        // still 404s.
        if ($file === 'media_voriginal.m3u8' && !is_file("{$dir}/{$file}")) {
            $topVariant = $this->resolveTopVariantPlaylist($dir);
            if ($topVariant !== null) {
                $file = $topVariant;
            }
        }

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

    /**
     * Resolve the TOP (highest-BANDWIDTH) per-variant media playlist for a job,
     * used as the serve-time alias for a FOLDED `original` variant playlist.
     *
     * LEGACY (pre-v9 jobs only) — see the call site in {@see serveFile()}: S49
     * removed the fold, so no job created after it can reach this method. Delete
     * with its call site once pre-v9 job directories can no longer be served.
     *
     * The master playlist lists one `#EXT-X-STREAM-INF:...BANDWIDTH=N` line per
     * rung, each immediately followed by its `media_v{id}.m3u8` URI (highest-first
     * per {@see \Phlix\Media\Streaming\LadderResult::streamVariants()}). We parse
     * every such pair and pick the highest-BANDWIDTH variant whose playlist file
     * ACTUALLY exists on disk — deriving "top rung" from the job's real rung set,
     * not a hardcoded name or a trusted master ordering.
     *
     * Guards: `media_voriginal.m3u8` is excluded from the candidate set (it is the
     * folded alias being resolved — never self-select, so no serve loop), and only
     * an existing file is returned. Returns null when there is no master, no video
     * stream-inf, or no usable variant on disk — in which case the caller keeps the
     * original request and lets it 404 (a genuinely missing job is NOT masked).
     *
     * @param string $dir Absolute job directory.
     */
    private function resolveTopVariantPlaylist(string $dir): ?string
    {
        $masterPath = "{$dir}/master.m3u8";
        if (!is_file($masterPath)) {
            return null;
        }
        $contents = @file_get_contents($masterPath);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $bestFile = null;
        $bestBandwidth = -1;
        $pendingBandwidth = null;
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $pendingBandwidth = preg_match('/(?:^|,)BANDWIDTH=(\d+)/', $line, $m) === 1
                    ? (int) $m[1]
                    : 0;
                continue;
            }
            // Only the URI line immediately following a STREAM-INF is a candidate.
            if ($pendingBandwidth === null || $line === '' || $line[0] === '#') {
                continue;
            }
            $uri = $line;
            $bandwidth = $pendingBandwidth;
            $pendingBandwidth = null;
            if (
                $uri === 'media_voriginal.m3u8'
                || preg_match('/^media_v[a-z0-9]+\.m3u8$/', $uri) !== 1
            ) {
                continue;
            }
            if ($bandwidth > $bestBandwidth && is_file("{$dir}/{$uri}")) {
                $bestBandwidth = $bandwidth;
                $bestFile = $uri;
            }
        }

        return $bestFile;
    }
}
