<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\SkipButtonSpec;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\SessionManager;
use Phlix\Session\PlaybackController;

/**
 * Handles playback session-related HTTP requests.
 *
 * This controller manages playback sessions, progress tracking,
 * and watch history for authenticated users.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Session controller for playback management and progress tracking.
 * @see Request For request representation
 * @see Response For response generation
 * @see SessionManager For session management
 * @see PlaybackController For playback control
 */
class SessionController
{
    /** @var SessionManager Manages playback sessions */
    private SessionManager $sessionManager;

    /** @var PlaybackController Handles playback progress tracking */
    private PlaybackController $playbackController;

    /** @var MarkerService Handles marker data for media items */
    private MarkerService $markerService;

    /**
     * Creates a new SessionController instance.
     *
     * @param SessionManager $sessionManager The session manager
     * @param PlaybackController $playbackController The playback controller
     * @param MarkerService $markerService The marker service for intro/outro/chapter data
     */
    public function __construct(
        SessionManager $sessionManager,
        PlaybackController $playbackController,
        MarkerService $markerService
    ) {
        $this->sessionManager = $sessionManager;
        $this->playbackController = $playbackController;
        $this->markerService = $markerService;
    }

    /**
     * Lists all active sessions for the authenticated user.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with sessions array
     *
     * @requires Authenticated user
     */
    public function listSessions(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $sessions = $this->sessionManager->getUserSessions($userId);
        return (new Response())->json(['sessions' => $sessions]);
    }

    /**
     * Ends a specific playback session.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters with 'id' for session ID
     * @return Response JSON response or error
     *
     * @requires Authenticated user and session ownership
     */
    public function endSession(Request $request, array $params): Response
    {
        $sessionId = $params['id'] ?? '';
        $session = $this->sessionManager->getSession($sessionId);

        if (!$session) {
            return (new Response())->status(404)->json(['error' => 'Session not found']);
        }

        // Verify ownership
        if ($session['user_id'] !== ($request->userId ?? '')) {
            return (new Response())->status(403)->json(['error' => 'Forbidden']);
        }

        $this->sessionManager->endSession($sessionId);

        return (new Response())->json(['message' => 'Session ended']);
    }

    /**
     * Reports playback progress for a session.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters with 'id' for session ID
     * @return Response JSON response confirming update
     *
     * @required_fields media_item_id, position_ticks
     */
    public function reportProgress(Request $request, array $params): Response
    {
        $sessionId = $params['id'] ?? '';
        $session = $this->sessionManager->getSession($sessionId);

        if (!$session) {
            return (new Response())->status(404)->json(['error' => 'Session not found']);
        }

        if ($session['user_id'] !== ($request->userId ?? '')) {
            return (new Response())->status(403)->json(['error' => 'Forbidden']);
        }

        $data = $request->body;

        $mediaItemId = $data['media_item_id'] ?? null;
        $positionTicks = $data['position_ticks'] ?? null;
        if (!is_string($mediaItemId) || $mediaItemId === '' || !is_numeric($positionTicks)) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: media_item_id, position_ticks',
            ]);
        }

        $durationTicks = $data['duration_ticks'] ?? 0;
        $isPaused = $data['is_paused'] ?? false;

        $this->playbackController->reportProgress(
            $sessionId,
            $mediaItemId,
            (int)$positionTicks,
            is_numeric($durationTicks) ? (int)$durationTicks : 0,
            (bool)$isPaused
        );

        return (new Response())->json(['message' => 'Progress updated']);
    }

    /**
     * Gets the current playback state for a session.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters with 'id' for session ID
     * @return Response JSON response with progress state and marker data
     */
    public function getProgress(Request $request, array $params): Response
    {
        $sessionId = $params['id'] ?? '';
        $session = $this->sessionManager->getSession($sessionId);

        if (!$session) {
            return (new Response())->status(404)->json(['error' => 'Session not found']);
        }

        if ($session['user_id'] !== ($request->userId ?? '')) {
            return (new Response())->status(403)->json(['error' => 'Forbidden']);
        }

        $state = $this->playbackController->getPlaybackState($sessionId);

        if (!$state) {
            return (new Response())->json(['progress' => null]);
        }

        $mediaItemId = $state['media_item_id'] ?? null;
        if (!is_string($mediaItemId)) {
            $mediaItemId = null;
        }

        $markers = $this->buildMarkerData($mediaItemId);

        return (new Response())->json([
            'progress' => $state,
            'intro_marker' => $markers['intro_marker'],
            'outro_marker' => $markers['outro_marker'],
            'skip_button_spec' => $markers['skip_button_spec'],
            'chapters' => $markers['chapters'],
        ]);
    }

    /**
     * Build marker data array for a media item.
     *
     * @param string|null $mediaItemId The media item ID
     *
     * @return array{
     *     intro_marker: array{start_seconds: int, end_seconds: int}|null,
     *     outro_marker: array{start_seconds: int, end_seconds: int}|null,
     *     skip_button_spec: array{
     *         skip_intro_start: int|null,
     *         skip_intro_end: int|null,
     *         skip_outro_start: int|null,
     *         skip_outro_end: int|null
     *     },
     *     chapters: array<int, array{start_seconds: int, end_seconds: int, title?: string|null}>
     * }
     */
    private function buildMarkerData(?string $mediaItemId): array
    {
        if ($mediaItemId === null) {
            return [
                'intro_marker' => null,
                'outro_marker' => null,
                'skip_button_spec' => [
                    'skip_intro_start' => null,
                    'skip_intro_end' => null,
                    'skip_outro_start' => null,
                    'skip_outro_end' => null,
                ],
                'chapters' => [],
            ];
        }

        $markerSet = $this->markerService->getMarkers($mediaItemId);

        $introMarker = null;
        if ($markerSet->intro !== null) {
            $introMarker = [
                'start_seconds' => $markerSet->intro->start_seconds,
                'end_seconds' => $markerSet->intro->end_seconds,
            ];
        }

        $outroMarker = null;
        if ($markerSet->outro !== null) {
            $outroMarker = [
                'start_seconds' => $markerSet->outro->start_seconds,
                'end_seconds' => $markerSet->outro->end_seconds,
            ];
        }

        $skipButtonSpec = SkipButtonSpec::fromMarkerSet($markerSet)->toArray();

        $chapters = array_map(
            static fn(\Phlix\Media\Markers\ChapterMarker $chapter): array => [
                'start_seconds' => $chapter->start_seconds,
                'end_seconds' => $chapter->end_seconds,
                'title' => $chapter->title,
            ],
            $markerSet->chapters
        );

        return [
            'intro_marker' => $introMarker,
            'outro_marker' => $outroMarker,
            'skip_button_spec' => $skipButtonSpec,
            'chapters' => $chapters,
        ];
    }

    /**
     * Gets items the user has partially watched (continue watching).
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with items array
     *
     * @requires Authenticated user
     */
    public function getContinueWatching(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $items = $this->playbackController->getContinueWatching($userId);
        return (new Response())->json(['items' => $items]);
    }

    /**
     * Gets recently watched items for the user.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with items array
     *
     * @requires Authenticated user
     */
    public function getRecentlyWatched(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $items = $this->playbackController->getRecentlyWatched($userId);
        return (new Response())->json(['items' => $items]);
    }
}
