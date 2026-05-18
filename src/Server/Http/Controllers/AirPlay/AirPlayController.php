<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\AirPlay;

use Phlex\AirPlay\AirPlayManager;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * HTTP controller for AirPlay 2 device control.
 *
 * Provides REST API endpoints for discovering devices and controlling
 * AirPlay playback sessions.
 *
 * @since 0.12.0
 */
class AirPlayController
{
    /** @var AirPlayManager AirPlay manager */
    private AirPlayManager $airPlayManager;

    /**
     * @param AirPlayManager $airPlayManager AirPlay manager instance
     */
    public function __construct(AirPlayManager $airPlayManager)
    {
        $this->airPlayManager = $airPlayManager;
    }

    /**
     * GET /api/v1/airplay/devices — list discovered AirPlay devices.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params (unused)
     *
     * @return Response JSON response with device list
     *
     * @since 0.12.0
     */
    public function listDevices(Request $request, array $params): Response
    {
        $devices = $this->airPlayManager->discoverDevices();

        $deviceList = array_map(function ($device) {
            return [
                'device_id' => $device->deviceId,
                'name' => $device->name,
                'host' => $device->host,
                'port' => $device->port,
                'raop_port' => $device->raopPort,
                'model' => $device->model,
                'supports_video' => $device->supportsVideo,
            ];
        }, $devices);

        return (new Response())->json([
            'devices' => $deviceList,
            'count' => count($deviceList),
        ]);
    }

    /**
     * POST /api/v1/airplay/devices/{id}/stream — start streaming.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params with 'id' (device ID)
     *
     * @return Response JSON response with session info
     *
     * @since 0.12.0
     */
    public function stream(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? '';

        if ($deviceId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing device ID',
            ]);
        }

        // Get request body
        $body = $request->body;
        $audioUrl = is_string($body['audio_url'] ?? null) ? $body['audio_url'] : '';
        $contentType = is_string($body['content_type'] ?? null) ? $body['content_type'] : 'audio/mp4';
        $duration = is_int($body['duration'] ?? null) ? $body['duration'] : 0;

        if ($audioUrl === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing audio_url in request body',
            ]);
        }

        $session = $this->airPlayManager->startSession($deviceId, $audioUrl, $contentType, $duration);

        if ($session === null) {
            return (new Response())->status(404)->json([
                'error' => 'Device not found or unavailable',
            ]);
        }

        return (new Response())->json([
            'status' => 'streaming',
            'session_id' => $session->getSessionId(),
            'device_id' => $deviceId,
            'state' => $session->getState(),
        ]);
    }

    /**
     * POST /api/v1/airplay/devices/{id}/pause — pause playback.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params with 'id' (device ID)
     *
     * @return Response JSON response with status
     *
     * @since 0.12.0
     */
    public function pause(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? '';

        $session = $this->airPlayManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->status(404)->json([
                'error' => 'No active session for device',
            ]);
        }

        $result = $session->pause();

        return (new Response())->json([
            'status' => 'paused',
            'session_id' => $session->getSessionId(),
            'device_id' => $deviceId,
        ]);
    }

    /**
     * POST /api/v1/airplay/devices/{id}/resume — resume playback.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params with 'id' (device ID)
     *
     * @return Response JSON response with status
     *
     * @since 0.12.0
     */
    public function resume(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? '';

        $session = $this->airPlayManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->status(404)->json([
                'error' => 'No active session for device',
            ]);
        }

        $result = $session->resume();

        return (new Response())->json([
            'status' => 'streaming',
            'session_id' => $session->getSessionId(),
            'device_id' => $deviceId,
        ]);
    }

    /**
     * POST /api/v1/airplay/devices/{id}/stop — stop playback.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params with 'id' (device ID)
     *
     * @return Response JSON response with status
     *
     * @since 0.12.0
     */
    public function stop(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? '';

        $session = $this->airPlayManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->status(404)->json([
                'error' => 'No active session for device',
            ]);
        }

        $this->airPlayManager->stopSession($deviceId);

        return (new Response())->json([
            'status' => 'stopped',
            'device_id' => $deviceId,
        ]);
    }

    /**
     * GET /api/v1/airplay/devices/{id}/status — get session status.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params with 'id' (device ID)
     *
     * @return Response JSON response with session info
     *
     * @since 0.12.0
     */
    public function getStatus(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? '';

        $session = $this->airPlayManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->json([
                'device_id' => $deviceId,
                'active' => false,
                'state' => 'idle',
            ]);
        }

        $device = $session->getDevice();

        return (new Response())->json([
            'device_id' => $deviceId,
            'active' => true,
            'state' => $session->getState(),
            'session_id' => $session->getSessionId(),
            'device' => [
                'name' => $device->name,
                'model' => $device->model,
                'supports_video' => $device->supportsVideo,
            ],
        ]);
    }
}
