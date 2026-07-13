<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\LiveTv\Recorder;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Serves Live TV recording and timeshift streams.
 *
 * Streaming routes (all under the SignedUrl/StreamLimit middleware group):
 *   - GET /livetv/recording/{id}/stream          — completed/in-progress `.ts` file
 *   - GET /livetv/timeshift/{sessionId}/stream    — rolling HLS buffer playlist
 *   - GET /livetv/timeshift/{sessionId}/{segment} — a rolling-buffer HLS segment
 *
 * The timeshift buffer is a rolling HLS window written by the DVR recorder
 * (SV-3.1 f-b): a `buffer.m3u8` playlist plus `seg_NNNNN.ts` segments under the
 * session's `buffer_dir`. That rolling window IS the seekable timeshift buffer —
 * seeking within it is inherent to HLS and handled client-side; the server does
 * not implement cursor seeking here.
 *
 * File bodies are streamed through Workerman's event loop via
 * {@see Response::withFile()} (never buffered into worker memory) and honour HTTP
 * `Range` (206 + `Content-Range`, unsatisfiable → 416) on both entry points — the
 * CGI/`public/index.php` path mirrors the Workerman byte semantics via
 * {@see Response::finalizeFileHeaders()}.
 *
 * @since SV-3.1
 */
class LiveTvStreamController
{
    use TranscodeFileServer;

    /**
     * Path-jail for timeshift segment names: only the exact `seg_<digits>.ts`
     * filenames the rolling HLS buffer writes are ever resolved to disk. Any
     * other value (path traversal via `../`, absolute paths, the playlist itself,
     * arbitrary filenames) fails this match and is rejected with 404 before the
     * name is ever concatenated onto the buffer directory.
     *
     * The `D` (PCRE_DOLLAR_ENDONLY) modifier is deliberate: without it `$` also
     * matches just before a trailing newline, so `"seg_1.ts\n"` would slip past —
     * `D` anchors the match to the true end of the string.
     */
    private const SEGMENT_NAME_PATTERN = '/^seg_\d+\.ts$/D';

    /** @var Recorder DVR recorder instance */
    private Recorder $recorder;

    /** @var string Storage path for recordings */
    private string $storagePath;

    /**
     * Creates a new LiveTvStreamController.
     *
     * @param Recorder $recorder DVR recorder for path lookups
     * @param string $storagePath Recording storage path (e.g. /var/recordings)
     */
    public function __construct(Recorder $recorder, string $storagePath = '/var/recordings')
    {
        $this->recorder = $recorder;
        $this->storagePath = $storagePath;
    }

    /**
     * Stream a completed (or in-progress) recording.
     *
     * GET /livetv/recording/{id}/stream
     *
     * Looks up the recording to verify it exists and is streamable, then streams
     * the `.ts` file via {@see Response::withFile()} with HTTP `Range` support so
     * both entry points answer a ranged seek with 206 + `Content-Range` (rather
     * than a full-file 200) and a full GET with 200.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params (id => recording_id)
     *
     * @return Response
     *
     * @since SV-3.1
     */
    public function streamRecording(Request $request, array $params): Response
    {
        $recordingId = $params['id'] ?? '';

        if ($recordingId === '') {
            return (new Response())->status(400)->json(['error' => 'Recording ID required']);
        }

        $recording = $this->recorder->getRecording($recordingId);
        if ($recording === null) {
            return (new Response())->status(404)->json(['error' => 'Recording not found']);
        }

        // Only serve completed or recording files.
        $validStatuses = [Recorder::STATUS_COMPLETED, Recorder::STATUS_RECORDING];
        if (!in_array($recording['status'] ?? '', $validStatuses, true)) {
            return (new Response())->status(404)->json(['error' => 'Recording not available']);
        }

        $filePath = $this->storagePath . '/' . $recordingId . '.ts';

        if (!file_exists($filePath)) {
            return (new Response())->status(404)->json(['error' => 'Recording file not found']);
        }

        return $this->serveRecordingFile($request, $filePath);
    }

    /**
     * Stream a timeshift buffer playlist.
     *
     * GET /livetv/timeshift/{sessionId}/stream
     *
     * Resolves the session cross-worker via {@see Recorder::getTimeShift()} (which
     * falls back to the DB-backed store, so a session started on another worker is
     * still resolvable) and serves the rolling `buffer.m3u8` playlist from the
     * session's `buffer_dir` with `Content-Type: application/vnd.apple.mpegurl`.
     * The player then requests the referenced `seg_NNNNN.ts` segments back through
     * {@see self::streamTimeShiftSegment()}.
     *
     * A missing/stopped session → 404. A valid session whose rolling buffer has
     * not produced the playlist yet (no-tuner session with a NULL pid + empty dir,
     * or ffmpeg has not written `buffer.m3u8` yet) → 503 "buffer not ready" rather
     * than a 500 on a missing file.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params (sessionId => session_id)
     *
     * @return Response
     *
     * @since SV-3.1
     */
    public function streamTimeShift(Request $request, array $params): Response
    {
        $sessionId = $params['sessionId'] ?? '';

        if ($sessionId === '') {
            return (new Response())->status(400)->json(['error' => 'Session ID required']);
        }

        $bufferDir = $this->resolveTimeShiftBufferDir($sessionId);
        if ($bufferDir === null) {
            return (new Response())->status(404)->json(['error' => 'Timeshift session not found']);
        }

        $playlistPath = $bufferDir . '/' . Recorder::TIMESHIFT_PLAYLIST_NAME;
        if (!is_file($playlistPath)) {
            // Session is valid but the rolling buffer has not populated the
            // playlist yet. 503 + Retry-After tells the client to poll again
            // shortly instead of surfacing a 500 on a not-yet-written file.
            return (new Response())
                ->status(503)
                ->header('Retry-After', '2')
                ->json(['error' => 'Timeshift buffer not ready', 'session_id' => $sessionId]);
        }

        // serveJobFile() streams via withFile(), sets application/vnd.apple.mpegurl
        // + no-cache for the playlist, and honours Range/conditional-GET.
        return $this->serveJobFile($request, $bufferDir, Recorder::TIMESHIFT_PLAYLIST_NAME);
    }

    /**
     * Stream a single timeshift buffer HLS segment.
     *
     * GET /livetv/timeshift/{sessionId}/{segment}
     *
     * Resolves the session cross-worker, path-jails the requested segment name to
     * the exact `seg_<digits>.ts` shape produced by the rolling buffer (see
     * {@see self::SEGMENT_NAME_PATTERN}), then streams it from the session's
     * `buffer_dir` via {@see Response::withFile()} with HTTP `Range` support.
     *
     * Anything that is not an exact `seg_<digits>.ts` name — path traversal,
     * absolute paths, the playlist, arbitrary files — is rejected with 404 before
     * touching the filesystem. A validated segment that has already rolled out of
     * the window (deleted by ffmpeg's `delete_segments`) also yields 404.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params (sessionId, segment)
     *
     * @return Response
     *
     * @since SV-3.1
     */
    public function streamTimeShiftSegment(Request $request, array $params): Response
    {
        $sessionId = $params['sessionId'] ?? '';
        $segment = $params['segment'] ?? '';

        if ($sessionId === '') {
            return (new Response())->status(400)->json(['error' => 'Session ID required']);
        }

        // SECURITY: path-jail the segment name. The router already constrains
        // {segment} to a single non-slash path element, but a strict allow-list
        // regex is the primary guard — only names ffmpeg actually emits are ever
        // resolved to disk. Reject everything else with 404.
        if (preg_match(self::SEGMENT_NAME_PATTERN, $segment) !== 1) {
            return (new Response())->status(404)->json(['error' => 'Segment not found']);
        }

        $bufferDir = $this->resolveTimeShiftBufferDir($sessionId);
        if ($bufferDir === null) {
            return (new Response())->status(404)->json(['error' => 'Timeshift session not found']);
        }

        // serveJobFile() applies a second-layer isSafeFilename() check, streams via
        // withFile(), sets video/mp2t + immutable caching, honours Range (206/416),
        // and returns 404 when the (validated) segment has aged out of the window.
        return $this->serveJobFile($request, $bufferDir, $segment);
    }

    /**
     * Resolves a timeshift session's on-disk buffer directory, cross-worker.
     *
     * Returns null when the session does not exist / has stopped, or when the
     * stored `buffer_dir` is missing/blank.
     *
     * @param string $sessionId The playback session id (the URL route key).
     *
     * @return string|null The session's buffer directory, or null.
     */
    private function resolveTimeShiftBufferDir(string $sessionId): ?string
    {
        $timeShift = $this->recorder->getTimeShift($sessionId);
        if ($timeShift === null) {
            return null;
        }

        $bufferDir = $timeShift['buffer_dir'] ?? null;
        if (!is_string($bufferDir) || $bufferDir === '') {
            return null;
        }

        return $bufferDir;
    }

    /**
     * Streams a recording `.ts` file with HTTP `Range` support.
     *
     * Mirrors the audiobook/theme-media range idiom: a supported single-range
     * request → 206 + `Content-Range` byte window; an unsatisfiable range → 416;
     * otherwise a full-file 200. No aggressive cache header is set — an in-progress
     * (`status='recording'`) `.ts` is still growing, so it must not be cached as
     * immutable.
     *
     * @param Request $request  HTTP request (for the `Range` header).
     * @param string  $filePath Absolute path to the recording `.ts` file.
     *
     * @return Response
     */
    private function serveRecordingFile(Request $request, string $filePath): Response
    {
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            return (new Response())->status(500)->json(['error' => 'Could not determine file size']);
        }

        $range = ByteRangeParser::parse($request->getHeader('Range'), (int) $fileSize);
        if ($range !== null) {
            if (!$range['satisfiable']) {
                return (new Response())
                    ->status(416)
                    ->header('Content-Type', 'video/mp2t')
                    ->header('Content-Range', "bytes */{$fileSize}");
            }

            return (new Response())
                ->status(206)
                ->header('Content-Type', 'video/mp2t')
                ->header('Accept-Ranges', 'bytes')
                ->withFile($filePath, $range['start'], $range['end'] - $range['start'] + 1);
        }

        return (new Response())
            ->status(200)
            ->header('Content-Type', 'video/mp2t')
            ->header('Accept-Ranges', 'bytes')
            ->withFile($filePath);
    }
}
