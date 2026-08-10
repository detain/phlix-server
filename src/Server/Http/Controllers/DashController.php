<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\RatingGate;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\SegmentCacheFullException;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * DASH Streaming Controller.
 *
 * Serves the real DASH output of the CMAF transcode pipeline
 * ({@see \Phlix\Media\Transcoding\TranscodeManager}): a `manifest.mpd` (S58) over
 * the shared `init-v{V}.m4s` / `seg-v{V}-NNNNN.m4s` fMP4 segments (S56) that the
 * HLS playlists reference too (S57). The manifest references segments by relative
 * filename, so files are served from the job directory verbatim.
 *
 * S59 made the segment half of that real. Before it, this controller was a pure
 * static file server over a directory nothing wrote `.m4s` into: the manifest
 * advertises every segment of the presentation up front, but segments are
 * produced ON DEMAND, so every reference in it 404'd. `serveFile()` now mirrors
 * {@see HlsController::serveFile()} — it parses the segment/init filename and
 * routes it through {@see TranscodeManager::ensureSegment()} to produce-or-serve,
 * with the same `SegmentBusy`/`SegmentCacheFull` → 503 handling — and recovers a
 * swept `manifest.mpd` through {@see TranscodeManager::ensurePlaylistRegenerated()}.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/Media/DASH_Adaptive_Streaming
 */
class DashController
{
    use TranscodeFileServer;

    /** @var string Base directory holding per-job CMAF output (shared with HLS). */
    private string $segmentDir;

    /**
     * The transcoder that produces on-demand fMP4 segments, and the job →
     * media-item resolver for the serve-time parental re-check.
     *
     * Null ONLY in the degenerate container-less construction path (no DB).
     * There it is not a silent pass-through: a segment/init request resolves to
     * a 404 exactly as it does in {@see HlsController}, because nothing can have
     * produced the file. `Application::getDashController()` resolves it from the
     * container unconditionally, the same way `getHlsController()` does.
     */
    private ?TranscodeManager $transcodeManager;

    /**
     * Shared parental-control access gate for the serve-time re-check. Null in
     * legacy/no-container contexts, where the check is a strict no-op (owner-safe).
     */
    private ?RatingGate $ratingGate;

    public function __construct(
        string $segmentDir,
        ?TranscodeManager $transcodeManager = null,
        ?RatingGate $ratingGate = null
    ) {
        $this->segmentDir = rtrim($segmentDir, '/');
        $this->transcodeManager = $transcodeManager;
        $this->ratingGate = $ratingGate;
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
     * `manifest.mpd` is a static file (written up front by
     * {@see TranscodeManager::writeVodPlaylists()}); a miss means the job
     * directory was swept, so it is regenerated exactly as
     * {@see HlsController::serveFile()} regenerates a swept playlist.
     *
     * Every OTHER name the manifest can reference is produced on demand. S58's
     * `SegmentTemplate`s expand to six shapes, and all six route to the
     * transcoder here via {@see SegmentRequestParser::parse()} — the shared
     * filename → `ensureSegment()` router, which carries the per-shape argument
     * table and the reason an init maps to index 0 of its own rendition.
     *
     * ⚠ **`.m4s` ONLY** ({@see SegmentRequestParser::DASH_EXTENSIONS}). A `.ts`
     * name on this route is HlsController's artefact, not a DASH one, and must
     * never start an encode here. S310 widened the HLS side to route BOTH
     * containers; this side deliberately did not widen with it.
     *
     * A filename matching no arm falls through to a plain static lookup that 404s.
     *
     * An MPEG-TS job (the shipped default) has no `.m4s` in it and no
     * `manifest.mpd` either, so a `.m4s` request against one produces the job's
     * `.ts` segment and then 404s on the requested `.m4s` name — the same
     * "genuinely absent file" answer it gave before S59.
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
        // profile, and (S235, deliberately) for a signature-only request that
        // carries no session userId — see TranscodeFileServer::transcodeJobOverCap().
        if ($this->transcodeJobOverCap($request, $jobId, $this->transcodeManager, $this->ratingGate)) {
            return (new Response())->status(404)->json(['error' => 'Not found']);
        }

        $dir = "{$this->segmentDir}/{$jobId}";

        $target = SegmentRequestParser::parse($file, SegmentRequestParser::DASH_EXTENSIONS);
        if ($target !== null) {
            try {
                $ready = $this->transcodeManager?->ensureSegment(
                    $jobId,
                    $target['variant'],
                    $target['index'],
                    $target['audioId']
                );
            } catch (SegmentBusyException $e) {
                // Transient overload — tell the player to retry shortly rather than
                // blocking a worker or timing out. Same contract as the HLS path:
                // a DASH client treats 503 as retryable and backs off, so the
                // in-flight encodes finish first.
                return (new Response())
                    ->status(503)
                    ->header('Retry-After', '1')
                    ->json(['error' => 'segment busy']);
            } catch (SegmentCacheFullException $e) {
                // SV-1.9: ENOSPC guard — the segment cache filesystem is low on
                // space. Sweep opportunistically, then ask for a retry. A plain
                // `->` (not `?->`): reaching this catch means the null-safe call
                // above did NOT short-circuit, so the manager is non-null here.
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

        // A missing manifest means the job directory was evicted by the LRU
        // sweep. writeVodPlaylists() publishes the MPD beside the playlists, and
        // ensurePlaylistRegenerated() runs that same writer, so this restores the
        // manifest from the persisted job row (S58) — the DASH peer of the HLS
        // playlist miss-recovery.
        if ($file === TranscodeManager::MPD_FILENAME && !is_file("{$dir}/{$file}")) {
            $this->transcodeManager?->ensurePlaylistRegenerated($jobId);
        }

        return $this->serveJobFile($request, $dir, $file);
    }
}
