<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Dlna;

use Phlex\Dlna\PlayToManager;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * HTTP controller for DLNA renderer discovery and AVTransport control.
 *
 * Provides REST API endpoints for:
 * - GET /api/v1/dlna/renderers — list discovered renderers
 * - POST /api/v1/dlna/renderers/{id}/play — start "play to" session
 * - POST /api/v1/dlna/renderers/{id}/pause — pause playback
 * - POST /api/v1/dlna/renderers/{id}/stop — stop playback
 * - POST /api/v1/dlna/renderers/{id}/seek — seek to position
 * - GET /api/v1/dlna/renderers/{id}/status — get renderer state
 *
 * @since 0.12.0
 */
class RendererListController
{
    /** @var PlayToManager Play-to session manager */
    private PlayToManager $playToManager;

    /**
     * @param PlayToManager $playToManager Play-to session manager
     *
     * @since 0.12.0
     */
    public function __construct(PlayToManager $playToManager)
    {
        $this->playToManager = $playToManager;
    }

    /**
     * GET /api/v1/dlna/renderers — list discovered renderers.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters
     *
     * @return Response JSON response with renderer list
     *
     * @since 0.12.0
     */
    public function listRenderers(Request $request, array $params): Response
    {
        $renderers = $this->playToManager->discoverRenderers();

        // Add active session info to each renderer
        $result = [];
        foreach ($renderers as $renderer) {
            $rendererId = is_string($renderer['udn'] ?? null) ? $renderer['udn'] : '';
            $session = $rendererId !== '' ? $this->playToManager->getSession($rendererId) : null;

            $renderer['has_active_session'] = $session !== null;
            if ($session !== null) {
                $renderer['session_state'] = $session->getState();
                $renderer['session_position'] = $session->getPosition();
            }

            $result[] = $renderer;
        }

        return (new Response())->json([
            'renderers' => $result,
            'count' => count($result),
        ]);
    }

    /**
     * POST /api/v1/dlna/renderers/{id}/play — start "play to" session.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (id = renderer UDN)
     *
     * @return Response JSON response with session info
     *
     * @since 0.12.0
     */
    public function playTo(Request $request, array $params): Response
    {
        $rendererId = $params['id'] ?? '';

        if ($rendererId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing renderer ID',
            ]);
        }

        // Get media item info from request body
        $body = $request->body;
        $mediaItemIdRaw = $body['media_item_id'] ?? $body['item_id'] ?? '';
        $mediaItemId = is_string($mediaItemIdRaw) ? $mediaItemIdRaw : '';
        $uri = is_string($body['uri'] ?? null) ? $body['uri'] : '';
        $metadata = is_string($body['metadata'] ?? null) ? $body['metadata'] : '';

        if ($mediaItemId === '' || $uri === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing media_item_id or uri in request body',
            ]);
        }

        $session = $this->playToManager->startSession($rendererId, $mediaItemId, $uri, $metadata);

        if ($session === null) {
            return (new Response())->status(500)->json([
                'error' => 'Failed to start play-to session',
            ]);
        }

        return (new Response())->json([
            'session_id' => $session->getSessionId(),
            'renderer_id' => $session->getRendererId(),
            'renderer_name' => $session->getRendererName(),
            'state' => $session->getState(),
            'position' => $session->getPosition(),
        ]);
    }

    /**
     * POST /api/v1/dlna/renderers/{id}/pause — pause playback.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (id = renderer UDN)
     *
     * @return Response JSON response
     *
     * @since 0.12.0
     */
    public function pause(Request $request, array $params): Response
    {
        $rendererId = $params['id'] ?? '';

        if ($rendererId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing renderer ID',
            ]);
        }

        $session = $this->playToManager->getSession($rendererId);

        if ($session === null) {
            return (new Response())->status(404)->json([
                'error' => 'No active session for this renderer',
            ]);
        }

        $session->pause();

        return (new Response())->json([
            'state' => $session->getState(),
            'position' => $session->getPosition(),
        ]);
    }

    /**
     * POST /api/v1/dlna/renderers/{id}/stop — stop playback.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (id = renderer UDN)
     *
     * @return Response JSON response
     *
     * @since 0.12.0
     */
    public function stop(Request $request, array $params): Response
    {
        $rendererId = $params['id'] ?? '';

        if ($rendererId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing renderer ID',
            ]);
        }

        $session = $this->playToManager->getSession($rendererId);

        if ($session === null) {
            return (new Response())->status(404)->json([
                'error' => 'No active session for this renderer',
            ]);
        }

        $session->stop();

        return (new Response())->json([
            'state' => $session->getState(),
        ]);
    }

    /**
     * POST /api/v1/dlna/renderers/{id}/seek — seek to position.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (id = renderer UDN)
     *
     * @return Response JSON response
     *
     * @since 0.12.0
     */
    public function seek(Request $request, array $params): Response
    {
        $rendererId = $params['id'] ?? '';

        if ($rendererId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing renderer ID',
            ]);
        }

        $session = $this->playToManager->getSession($rendererId);

        if ($session === null) {
            return (new Response())->status(404)->json([
                'error' => 'No active session for this renderer',
            ]);
        }

        $body = $request->body;
        $positionRaw = $body['position_ticks'] ?? null;
        $positionTicks = is_numeric($positionRaw) ? (int) $positionRaw : 0;

        if ($positionTicks <= 0) {
            return (new Response())->status(400)->json([
                'error' => 'Missing or invalid position_ticks in request body',
            ]);
        }

        $session->seek($positionTicks);

        return (new Response())->json([
            'position' => $session->getPosition(),
        ]);
    }

    /**
     * GET /api/v1/dlna/renderers/{id}/status — get renderer state.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route parameters (id = renderer UDN)
     *
     * @return Response JSON response with renderer status
     *
     * @since 0.12.0
     */
    public function getStatus(Request $request, array $params): Response
    {
        $rendererId = $params['id'] ?? '';

        if ($rendererId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing renderer ID',
            ]);
        }

        $session = $this->playToManager->getSession($rendererId);

        if ($session === null) {
            // Check if renderer is available
            $renderers = $this->playToManager->discoverRenderers();
            $rendererInfo = null;
            foreach ($renderers as $renderer) {
                if (($renderer['udn'] ?? '') === $rendererId) {
                    $rendererInfo = $renderer;
                    break;
                }
            }

            if ($rendererInfo === null) {
                return (new Response())->status(404)->json([
                    'error' => 'Renderer not found',
                ]);
            }

            return (new Response())->json([
                'renderer_id' => $rendererId,
                'renderer_name' => $rendererInfo['friendly_name'] ?? 'Unknown',
                'has_active_session' => false,
            ]);
        }

        return (new Response())->json([
            'renderer_id' => $session->getRendererId(),
            'renderer_name' => $session->getRendererName(),
            'session_id' => $session->getSessionId(),
            'state' => $session->getState(),
            'position' => $session->getPosition(),
            'has_active_session' => true,
        ]);
    }
}
