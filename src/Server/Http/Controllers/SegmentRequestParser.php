<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Transcoding\TranscodeManager;

/**
 * Maps a requested segment/init filename onto the
 * {@see TranscodeManager::ensureSegment()} arguments that PRODUCE it.
 *
 * Extracted by S310 from {@see DashController}, which had grown the only copy of
 * this router while {@see HlsController} kept an independent, `.ts`-only one
 * inline. That divergence is exactly what shipped the S56–S59 defect: S57 taught
 * the HLS playlist writer to name `.m4s` files, S58/S59 taught the DASH serve
 * path to produce them, and the HLS serve path — the one every real client
 * actually uses — was never widened, so `transcoding.segment_format=fmp4` 404'd
 * every segment. Two hand-maintained six-arm filename routers is how that gap
 * hid in plain sight; there is now one, and both controllers call it.
 *
 * The naming contract lives in {@see TranscodeManager}'s private
 * `segmentFileName()` / `initSegmentFileName()`. This class is its inverse, and
 * the two must be read together:
 *
 * | producer                                    | request filename        | `ensureSegment()` arguments        |
 * |---------------------------------------------|-------------------------|------------------------------------|
 * | `segmentFileName('{V}', N)`                 | `seg-v{V}-NNNNN.{ext}`  | `($job, '{V}', N, null)`           |
 * | `segmentFileName(null, N, 'a{A}')`          | `seg-a{A}-NNNNN.{ext}`  | `($job, null, N, 'a{A}')`          |
 * | `segmentFileName(null, N)`                  | `seg-NNNNN.{ext}`       | `($job, null, N, null)`            |
 * | `initSegmentFileName('{V}')`                | `init-v{V}.m4s`         | `($job, '{V}', 0, null)`           |
 * | `initSegmentFileName(null, 'a{A}')`         | `init-a{A}.m4s`         | `($job, null, 0, 'a{A}')`          |
 * | `initSegmentFileName(null)`                 | `init.m4s`              | `($job, null, 0, null)`            |
 *
 * ⚠ **The audio arm keeps the `a` in the id.** `ensureSegment()`'s `$audioId`
 * is the audio GROUP id (`a0`, `a1`) — the same token the producer interpolates
 * whole — so the capture group excludes the leading `a` and the arm puts it
 * back. Passing the bare digit would resolve to no audio track at all. Both
 * controllers already did it this way before the extraction; the HLS docblock
 * that described it as `'{A}'` was wrong about its own code, and S310 corrected
 * the prose rather than the behaviour.
 *
 * ⚠ **An init maps to index 0 of its own rendition.** An init segment has no
 * producer of its own: it is published beside the FIRST fragment by the same
 * atomic chain ({@see \Phlix\Media\Transcoding\FfmpegRunner::startSegmentEncode()}'s
 * `init_file`) and is byte-identical whatever index produced it (S56 dropped
 * `-output_ts_offset` on the CMAF branch precisely so that holds). Producing
 * segment 0 is therefore what makes the init exist — and it must be an arm here,
 * because both clients fetch the init FIRST (`#EXT-X-MAP` for HLS,
 * `SegmentTemplate@initialization` for DASH). Without it the presentation is
 * unreachable from its own opening request.
 *
 * @since S310
 */
final class SegmentRequestParser
{
    /** MPEG-TS on-demand segment extension (the shipped default container). */
    public const EXT_MPEGTS = 'ts';

    /** fMP4/CMAF on-demand segment extension (`transcoding.segment_format=fmp4`). */
    public const EXT_FMP4 = 'm4s';

    /**
     * Extensions the HLS serve path routes: a job is committed to ONE container
     * at creation, but the controller serves jobs of both kinds, so it accepts
     * either name and lets `ensureSegment()` decide what the job can produce.
     */
    public const HLS_EXTENSIONS = [self::EXT_MPEGTS, self::EXT_FMP4];

    /**
     * Extensions the DASH serve path routes. DASH carries ISO-BMFF only: an
     * MPEG-TS job has no manifest and no `.m4s`, so a `.ts` name on `/dash/` is
     * not an on-demand artefact and must NOT start an encode.
     */
    public const DASH_EXTENSIONS = [self::EXT_FMP4];

    /**
     * Parses a requested filename into `ensureSegment()` arguments, or null when
     * the name is not an on-demand artefact (a playlist, a manifest, a subtitle
     * sidecar, a legacy `chunk-*.m4s`, an unknown name) and should fall through
     * to a plain static lookup.
     *
     * The variant/audio character class `[a-z0-9]+` is anchored inside `^…$` and
     * excludes `.` `/` `\`, so it cannot smuggle a traversal sequence past the
     * caller's {@see TranscodeFileServer::isSafeFilename()} gate. The extension
     * alternation is built by INTERSECTING `$extensions` with the two constants
     * above rather than interpolating the caller's strings, so no caller can
     * inject regex metacharacters into the pattern.
     *
     * @param string       $file       Requested filename, already `isSafeFilename()`-checked.
     * @param list<string> $extensions Segment extensions to route, e.g.
     *                                 {@see self::HLS_EXTENSIONS}. Init arms are
     *                                 recognised only when `m4s` is among them,
     *                                 because an MPEG-TS segment is
     *                                 self-contained and has no init.
     *
     * @return array{variant: string|null, audioId: string|null, index: int}|null
     */
    public static function parse(string $file, array $extensions): ?array
    {
        $allowed = array_values(array_intersect([self::EXT_MPEGTS, self::EXT_FMP4], $extensions));
        if ($allowed === []) {
            return null;
        }
        $ext = '(?:' . implode('|', $allowed) . ')';

        if (preg_match('/^seg-v([a-z0-9]+)-(\d{1,9})\.' . $ext . '$/', $file, $m) === 1) {
            return ['variant' => $m[1], 'audioId' => null, 'index' => (int) $m[2]];
        }
        if (preg_match('/^seg-a([a-z0-9]+)-(\d{1,9})\.' . $ext . '$/', $file, $m) === 1) {
            return ['variant' => null, 'audioId' => 'a' . $m[1], 'index' => (int) $m[2]];
        }
        if (preg_match('/^seg-(\d{1,9})\.' . $ext . '$/', $file, $m) === 1) {
            return ['variant' => null, 'audioId' => null, 'index' => (int) $m[1]];
        }

        // Init arms below. There is no `init-*.ts`: an MPEG-TS segment carries
        // its own headers, so a caller that only routes `.ts` must not match
        // these — otherwise a `.ts`-only job directory would answer an `.m4s`
        // init request by launching an encode that can never publish it.
        if (!in_array(self::EXT_FMP4, $allowed, true)) {
            return null;
        }
        if (preg_match('/^init-v([a-z0-9]+)\.m4s$/', $file, $m) === 1) {
            return ['variant' => $m[1], 'audioId' => null, 'index' => 0];
        }
        if (preg_match('/^init-a([a-z0-9]+)\.m4s$/', $file, $m) === 1) {
            return ['variant' => null, 'audioId' => 'a' . $m[1], 'index' => 0];
        }
        if ($file === 'init.m4s') {
            return ['variant' => null, 'audioId' => null, 'index' => 0];
        }

        return null;
    }
}
