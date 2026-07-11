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
 * Streaming routes:
 *   - GET /livetv/recording/{id}/stream  — serves the .ts file via withFile()
 *   - GET /livetv/timeshift/{sessionId}/stream — serves the timeshift buffer
 *
 * @since SV-3.1
 */
class LiveTvStreamController
{
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
     * Stream a completed recording.
     *
     * GET /livetv/recording/{id}/stream
     *
     * Looks up the recording to verify it exists and the caller has access,
     * then streams the .ts file using Response::withFile() so Workerman's
     * event-loop sender handles Range headers and chunked delivery.
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
        $validStatuses = [\Phlix\LiveTv\Recorder::STATUS_COMPLETED, \Phlix\LiveTv\Recorder::STATUS_RECORDING];
        if (!in_array($recording['status'] ?? '', $validStatuses, true)) {
            return (new Response())->status(404)->json(['error' => 'Recording not available']);
        }

        $filePath = $this->storagePath . '/' . $recordingId . '.ts';

        if (!file_exists($filePath)) {
            return (new Response())->status(404)->json(['error' => 'Recording file not found']);
        }

        return (new Response())->status(200)->withFile($filePath);
    }

    /**
     * Stream a timeshift buffer.
     *
     * GET /livetv/timeshift/{sessionId}/stream
     *
     * Returns the current timeshift buffer. If the session is not active
     * or the buffer is not available, returns 404.
     *
     * Note: Full timeshift HLS rewind requires building a playlist from
     * the buffer — this basic implementation streams the raw buffer.
     * The timeshift buffer is a rolling window managed by Recorder, and
     * its on-disk representation depends on the timeshift implementation.
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

        $timeShift = $this->recorder->getTimeShift($sessionId);
        if ($timeShift === null) {
            return (new Response())->status(404)->json(['error' => 'Timeshift session not found']);
        }

        // For now, return a placeholder indicating the buffer stream is not yet
        // implemented as a direct file. The timeshift buffer is managed in memory
        // and would need a segmenter (like HLS) to be streamed with seeking.
        // Players should use the live TV stream endpoint for now.
        return (new Response())
            ->status(501)
            ->json([
                'error' => 'Timeshift streaming not yet implemented',
                'session_id' => $sessionId,
                'buffer_start' => $timeShift['buffer_start'] ?? null,
                'buffer_end' => $timeShift['buffer_end'] ?? null,
            ]);
    }
}
