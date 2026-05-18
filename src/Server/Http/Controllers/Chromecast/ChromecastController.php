<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Chromecast;

use Phlex\Chromecast\CastManager;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * Chromecast HTTP API controller.
 *
 * Provides REST endpoints for discovering Chromecast devices
 * and controlling cast sessions.
 *
 * @since 0.12.0
 */
class ChromecastController
{
    /** @var CastManager Cast session manager */
    private CastManager $castManager;

    /**
     * @param CastManager $castManager Cast session manager
     *
     * @since 0.12.0
     */
    public function __construct(CastManager $castManager)
    {
        $this->castManager = $castManager;
    }

    /**
     * List discovered Chromecast devices.
     *
     * GET /api/v1/cast/devices
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters
     *
     * @return Response JSON response with device list
     *
     * @since 0.12.0
     */
    public function listDevices(Request $request, array $params): Response
    {
        $devices = $this->castManager->discoverDevices();

        $deviceList = array_map(function ($device) {
            return [
                'device_id' => $device->deviceId,
                'name' => $device->name,
                'host' => $device->host,
                'port' => $device->port,
                'model' => $device->model,
                'address' => $device->getAddress(),
            ];
        }, $devices);

        return (new Response())->json([
            'devices' => $deviceList,
            'count' => count($deviceList),
        ]);
    }

    /**
     * Start casting to a device.
     *
     * POST /api/v1/cast/devices/{id}/cast
     *
     * Required body fields:
     * - media_url: URL of the media to cast
     * - mime_type: Content type (e.g., 'application/x-mpegurl')
     * - title: Media title (optional)
     * - duration: Duration in seconds (optional)
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id' = device ID
     *
     * @return Response JSON response with session info
     *
     * @since 0.12.0
     */
    public function cast(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? null;
        if ($deviceId === null) {
            return (new Response())->status(400)->json(['error' => 'Device ID is required']);
        }

        $body = $request->body;
        $mediaUrl = $body['media_url'] ?? null;
        $mimeType = $body['mime_type'] ?? 'application/x-mpegurl';
        $title = $body['title'] ?? '';
        $duration = (int)($body['duration'] ?? 0);

        if ($mediaUrl === null || $mediaUrl === '') {
            return (new Response())->status(400)->json(['error' => 'media_url is required']);
        }

        $session = $this->castManager->startSession($deviceId, $mediaUrl, $mimeType, $title, $duration);

        if ($session === null) {
            return (new Response())->status(500)->json(['error' => 'Failed to start cast session']);
        }

        return (new Response())->json([
            'session_id' => $session->getSessionId(),
            'device_id' => $deviceId,
            'state' => $session->getState(),
        ]);
    }

    /**
     * Resume playback.
     *
     * POST /api/v1/cast/devices/{id}/play
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id' = device ID
     *
     * @return Response JSON response
     *
     * @since 0.12.0
     */
    public function play(Request $request, array $params): Response
    {
        return $this->controlSession($params['id'] ?? null, 'play');
    }

    /**
     * Pause playback.
     *
     * POST /api/v1/cast/devices/{id}/pause
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id' = device ID
     *
     * @return Response JSON response
     *
     * @since 0.12.0
     */
    public function pause(Request $request, array $params): Response
    {
        return $this->controlSession($params['id'] ?? null, 'pause');
    }

    /**
     * Stop playback.
     *
     * POST /api/v1/cast/devices/{id}/stop
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id' = device ID
     *
     * @return Response JSON response
     *
     * @since 0.12.0
     */
    public function stop(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? null;
        if ($deviceId === null) {
            return (new Response())->status(400)->json(['error' => 'Device ID is required']);
        }

        try {
            $this->castManager->stopSession($deviceId);
            return (new Response())->json(['success' => true, 'message' => 'Session stopped']);
        } catch (\Throwable $e) {
            return (new Response())->status(500)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Seek to position.
     *
     * POST /api/v1/cast/devices/{id}/seek
     *
     * Required body field:
     * - position_ms: Position in milliseconds
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id' = device ID
     *
     * @return Response JSON response
     *
     * @since 0.12.0
     */
    public function seek(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? null;
        if ($deviceId === null) {
            return (new Response())->status(400)->json(['error' => 'Device ID is required']);
        }

        $session = $this->castManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->status(404)->json(['error' => 'No active session for device']);
        }

        $body = $request->body;
        $positionMs = (int)($body['position_ms'] ?? 0);

        try {
            $session->seek($positionMs);
            return (new Response())->json([
                'success' => true,
                'position_ms' => $positionMs,
            ]);
        } catch (\Throwable $e) {
            return (new Response())->status(500)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get session status.
     *
     * GET /api/v1/cast/devices/{id}/status
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id' = device ID
     *
     * @return Response JSON response with session status
     *
     * @since 0.12.0
     */
    public function getStatus(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? null;
        if ($deviceId === null) {
            return (new Response())->status(400)->json(['error' => 'Device ID is required']);
        }

        $session = $this->castManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->json([
                'device_id' => $deviceId,
                'active' => false,
            ]);
        }

        // Get current media status
        $mediaStatus = $session->getMediaStatus();

        return (new Response())->json([
            'device_id' => $deviceId,
            'active' => true,
            'session_id' => $session->getSessionId(),
            'state' => $session->getState(),
            'media_status' => $mediaStatus,
        ]);
    }

    /**
     * Helper to control a session (play/pause).
     *
     * @param string|null $deviceId Device ID
     * @param string $action Action to perform ('play' or 'pause')
     *
     * @return Response JSON response
     */
    private function controlSession(?string $deviceId, string $action): Response
    {
        if ($deviceId === null) {
            return (new Response())->status(400)->json(['error' => 'Device ID is required']);
        }

        $session = $this->castManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->status(404)->json(['error' => 'No active session for device']);
        }

        try {
            if ($action === 'play') {
                $session->play();
            } else {
                $session->pause();
            }

            return (new Response())->json([
                'success' => true,
                'state' => $session->getState(),
            ]);
        } catch (\Throwable $e) {
            return (new Response())->status(500)->json(['error' => $e->getMessage()]);
        }
    }
}
