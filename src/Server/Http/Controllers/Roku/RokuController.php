<?php

/**
 * Phlix media server component: Roku.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Roku;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Roku\RokuManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

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
    /**
     * The ECP key names a client may drive via {@see self::sendKey()}.
     *
     * Parse-don't-validate at the HTTP boundary: anything outside this set is
     * rejected with 400 before it can reach the raw `key=…` form append in
     * RokuEcpClient::sendKeypress(), which would otherwise let a caller forge
     * the POST body or smuggle extra form fields.
     */
    private const ALLOWED_KEYS = [
        'SendHome', 'Select', 'Up', 'Down', 'Left', 'Right',
        'Back', 'Play', 'Pause', 'Rev', 'Fwd', 'Info', 'Backspace', 'Enter',
    ];

    /** @var RokuManager Roku session manager */
    private RokuManager $rokuManager;

    /** @var StructuredLogger Diagnostic sink for device-side failures. */
    private StructuredLogger $logger;

    /**
     * @param RokuManager $rokuManager Roku session manager
     * @param StructuredLogger|null $logger Optional logger (defaults to the MEDIA channel)
     *
     * @since 0.12.0
     */
    public function __construct(RokuManager $rokuManager, ?StructuredLogger $logger = null)
    {
        $this->rokuManager = $rokuManager;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
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
        if (!is_string($deviceId) || $deviceId === '') {
            return (new Response())->status(400)->json(['error' => 'Device ID is required']);
        }

        $body = $request->body;
        $mediaUrl = is_string($body['media_url'] ?? null) ? $body['media_url'] : '';
        $mimeType = is_string($body['mime_type'] ?? null) ? $body['mime_type'] : 'application/x-mpegurl';
        $title = is_string($body['title'] ?? null) ? $body['title'] : '';
        $thumbnail = is_string($body['thumbnail'] ?? null) ? $body['thumbnail'] : '';

        if ($mediaUrl === '') {
            return (new Response())->status(400)->json(['error' => 'media_url is required']);
        }

        $session = $this->rokuManager->startSession(
            $deviceId,
            $mediaUrl,
            $mimeType,
            $title,
            $thumbnail,
            $request->userId,
        );

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

        // M6: the channel id is interpolated into the ECP /launch/{id} path, so
        // it must be a plain numeric channel number before it reaches the client.
        if (!is_string($channelId) || !ctype_digit($channelId)) {
            return (new Response())->status(400)->json(['error' => 'Channel ID must be numeric']);
        }

        $session = $this->rokuManager->getSession($deviceId, $request->userId);
        if ($session === null) {
            return (new Response())->status(404)->json(['error' => 'No active session for device']);
        }

        try {
            // M3: this route launches a channel (POST /launch/{id}); it must not
            // be routed to sendKey(), which posts the value as an ECP keypress.
            $result = $session->launchChannel($channelId);
            return (new Response())->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Roku channel launch failed', [
                'device_id' => $deviceId,
                'channel_id' => $channelId,
                'error' => $e->getMessage(),
            ]);
            // L2: constant client-facing message; the internal LAN URL stays in
            // the log only.
            return (new Response())->status(500)->json(['error' => 'Roku device request failed']);
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

        // M6: allowlist the key at the boundary so the raw `key=` form append in
        // the ECP client can never be steered by caller-controlled bytes.
        if (!is_string($keyName) || !in_array($keyName, self::ALLOWED_KEYS, true)) {
            return (new Response())->status(400)->json(['error' => 'Unsupported key name']);
        }

        $session = $this->rokuManager->getSession($deviceId, $request->userId);
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
            $this->logger->error('Roku keypress failed', [
                'device_id' => $deviceId,
                'key' => $keyName,
                'error' => $e->getMessage(),
            ]);
            // L2: constant client-facing message; the internal LAN URL stays in
            // the log only.
            return (new Response())->status(500)->json(['error' => 'Roku device request failed']);
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

        $session = $this->rokuManager->getSession($deviceId, $request->userId);
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
