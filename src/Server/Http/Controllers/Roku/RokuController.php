<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Roku;

use Phlex\Roku\RokuManager;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * Roku HTTP API controller.
 *
 * Provides REST endpoints for discovering Roku devices
 * and controlling "send to Roku" sessions.
 *
 * @since 0.12.0
 */
class RokuController
{
    /** @var RokuManager Roku session manager */
    private RokuManager $rokuManager;

    /**
     * @param RokuManager $rokuManager Roku session manager
     *
     * @since 0.12.0
     */
    public function __construct(RokuManager $rokuManager)
    {
        $this->rokuManager = $rokuManager;
    }

    /**
     * List discovered Roku devices.
     *
     * GET /api/v1/roku/devices
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
        $devices = $this->rokuManager->discoverDevices();

        $deviceList = array_map(function ($device) {
            return [
                'device_id' => $device->deviceId,
                'name' => $device->name,
                'host' => $device->host,
                'port' => $device->port,
                'model' => $device->model,
                'software_version' => $device->softwareVersion,
                'address' => $device->getAddress(),
            ];
        }, $devices);

        return (new Response())->json([
            'devices' => $deviceList,
            'count' => count($deviceList),
        ]);
    }

    /**
     * Send media to a Roku device.
     *
     * POST /api/v1/roku/devices/{id}/send
     *
     * Required body fields:
     * - media_url: URL of the media to play
     * - mime_type: Content type (e.g., 'application/x-mpegurl')
     * - title: Media title (optional)
     * - thumbnail: Thumbnail URL (optional)
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id' = device ID
     *
     * @return Response JSON response with session info
     *
     * @since 0.12.0
     */
    public function sendMedia(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? null;
        if ($deviceId === null) {
            return (new Response())->status(400)->json(['error' => 'Device ID is required']);
        }

        $body = $request->body;
        $mediaUrl = $body['media_url'] ?? null;
        $mimeType = $body['mime_type'] ?? 'application/x-mpegurl';
        $title = $body['title'] ?? '';
        $thumbnail = $body['thumbnail'] ?? '';

        if ($mediaUrl === null || $mediaUrl === '') {
            return (new Response())->status(400)->json(['error' => 'media_url is required']);
        }

        $session = $this->rokuManager->startSession($deviceId, $mediaUrl, $mimeType, $title, $thumbnail);

        if ($session === null) {
            return (new Response())->status(500)->json(['error' => 'Failed to start Roku session']);
        }

        return (new Response())->json([
            'session_id' => $session->getSessionId(),
            'device_id' => $deviceId,
            'state' => $session->getState(),
        ]);
    }

    /**
     * Launch a channel on a Roku device.
     *
     * POST /api/v1/roku/devices/{id}/launch/{channelId}
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id' = device ID, 'channelId' = channel ID
     *
     * @return Response JSON response
     *
     * @since 0.12.0
     */
    public function launchChannel(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? null;
        $channelId = $params['channelId'] ?? null;

        if ($deviceId === null) {
            return (new Response())->status(400)->json(['error' => 'Device ID is required']);
        }

        if ($channelId === null) {
            return (new Response())->status(400)->json(['error' => 'Channel ID is required']);
        }

        $session = $this->rokuManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->status(404)->json(['error' => 'No active session for device']);
        }

        try {
            $result = $session->sendKey($channelId);
            return (new Response())->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return (new Response())->status(500)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Send a keypress to a Roku device.
     *
     * POST /api/v1/roku/devices/{id}/key/{keyName}
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id' = device ID, 'keyName' = key name
     *
     * @return Response JSON response
     *
     * @since 0.12.0
     */
    public function sendKey(Request $request, array $params): Response
    {
        $deviceId = $params['id'] ?? null;
        $keyName = $params['keyName'] ?? null;

        if ($deviceId === null) {
            return (new Response())->status(400)->json(['error' => 'Device ID is required']);
        }

        if ($keyName === null) {
            return (new Response())->status(400)->json(['error' => 'Key name is required']);
        }

        $session = $this->rokuManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->status(404)->json(['error' => 'No active session for device']);
        }

        try {
            $result = $session->sendKey($keyName);
            return (new Response())->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return (new Response())->status(500)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get session status for a Roku device.
     *
     * GET /api/v1/roku/devices/{id}/status
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

        $session = $this->rokuManager->getSession($deviceId);
        if ($session === null) {
            return (new Response())->json([
                'device_id' => $deviceId,
                'active' => false,
            ]);
        }

        // Get current player state
        $playerState = $session->getPlayerState();

        return (new Response())->json([
            'device_id' => $deviceId,
            'active' => true,
            'session_id' => $session->getSessionId(),
            'state' => $session->getState(),
            'player_state' => $playerState,
        ]);
    }
}
