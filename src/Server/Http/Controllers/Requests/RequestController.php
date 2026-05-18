<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Requests;

use Phlex\Requests\RequestManager;
use Phlex\Requests\RequestNotification;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * RequestController handles API endpoints for media requests.
 *
 * Provides REST endpoints for:
 * - GET /api/v1/requests — list user's requests
 * - POST /api/v1/requests — create a new request
 * - PUT /api/v1/requests/{id}/approve — admin approve
 * - PUT /api/v1/requests/{id}/reject — admin reject
 * - DELETE /api/v1/requests/{id} — delete a request
 *
 * @since 0.12.0
 */
class RequestController
{
    /**
     * Creates a new RequestController instance.
     *
     * @param RequestManager $requestManager Manages request lifecycle
     * @param RequestNotification $notification Handles request notifications
     */
    public function __construct(
        private readonly RequestManager $requestManager,
        private readonly RequestNotification $notification
    ) {
    }

    /**
     * Lists requests for the current user.
     *
     * GET /api/v1/requests
     *
     * @param Request $request HTTP request (userId from auth)
     * @param array<string, string> $params Path parameters
     *
     * @return Response JSON response with requests array
     */
    public function listRequests(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';

        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $status = $request->query['status'] ?? null;
        $requests = [];

        if ($status === 'pending') {
            $requests = $this->requestManager->listPendingRequests($userId);
        } elseif ($status === 'available') {
            $requests = $this->requestManager->listAvailableRequests();
        } else {
            $requests = $this->requestManager->listUserRequests($userId);
        }

        return (new Response())->json([
            'requests' => $requests,
            'count' => count($requests),
        ]);
    }

    /**
     * Creates a new media request.
     *
     * POST /api/v1/requests
     *
     * Body parameters:
     * - type: 'movie' or 'series'
     * - tmdb_id: TMDB ID for the requested media
     * - title: Title of the media
     * - poster_url: Optional poster URL
     * - season: Optional season number (for series)
     * - episode: Optional episode number (for series)
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters
     *
     * @return Response JSON response with created request
     */
    public function createRequest(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';

        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $body = $request->body;

        /** @var mixed|null $type */
        $type = $body['type'] ?? null;
        /** @var mixed|null $tmdbIdRaw */
        $tmdbIdRaw = $body['tmdb_id'] ?? null;
        /** @var mixed|null $title */
        $title = $body['title'] ?? null;
        /** @var mixed|null $posterUrl */
        $posterUrl = $body['poster_url'] ?? null;
        /** @var mixed|null $seasonRaw */
        $seasonRaw = $body['season'] ?? null;
        /** @var mixed|null $episodeRaw */
        $episodeRaw = $body['episode'] ?? null;

        if (!is_string($type) || !in_array($type, ['movie', 'series'], true)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid request type. Must be "movie" or "series".',
            ]);
        }

        $tmdbId = is_numeric($tmdbIdRaw) ? (int) $tmdbIdRaw : null;
        if ($tmdbId === null || $tmdbId <= 0) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid tmdb_id. Must be a positive integer.',
            ]);
        }

        if (!is_string($title) || $title === '') {
            return (new Response())->status(400)->json([
                'error' => 'title is required.',
            ]);
        }

        $season = is_numeric($seasonRaw) ? (int) $seasonRaw : null;
        $episode = is_numeric($episodeRaw) ? (int) $episodeRaw : null;
        $posterUrlStr = is_string($posterUrl) ? $posterUrl : null;

        $result = $this->requestManager->createRequest(
            $userId,
            $type,
            $tmdbId,
            $title,
            $posterUrlStr,
            $season,
            $episode
        );

        $this->notification->notifySubmitted($userId, $title);

        return (new Response())->status(201)->json([
            'request' => $result,
            'message' => 'Request created successfully.',
        ]);
    }

    /**
     * Approves a pending request (admin only).
     *
     * PUT /api/v1/requests/{id}/approve
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id'
     *
     * @return Response JSON response indicating success/failure
     */
    public function approveRequest(Request $request, array $params): Response
    {
        $requestId = $params['id'] ?? null;

        if ($requestId === null) {
            return (new Response())->status(400)->json(['error' => 'Request ID is required']);
        }

        $existingRequest = $this->requestManager->getRequestById($requestId);

        if ($existingRequest === null) {
            return (new Response())->status(404)->json(['error' => 'Request not found']);
        }

        $success = $this->requestManager->approveRequest($requestId);

        if (!$success) {
            return (new Response())->status(500)->json([
                'error' => 'Failed to approve request. Check that Radarr/Sonarr is configured.',
            ]);
        }

        $approvedUserId = is_string($existingRequest['user_id']) ? $existingRequest['user_id'] : '';
        $approvedTitle = is_string($existingRequest['title']) ? $existingRequest['title'] : '';
        $this->notification->notifyApproved($approvedUserId, $approvedTitle);

        return (new Response())->json([
            'message' => 'Request approved successfully.',
            'request_id' => $requestId,
        ]);
    }

    /**
     * Rejects a pending request (admin only).
     *
     * PUT /api/v1/requests/{id}/reject
     *
     * Body parameters:
     * - reason: Optional rejection reason
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id'
     *
     * @return Response JSON response indicating success/failure
     */
    public function rejectRequest(Request $request, array $params): Response
    {
        $requestId = $params['id'] ?? null;

        if ($requestId === null) {
            return (new Response())->status(400)->json(['error' => 'Request ID is required']);
        }

        $existingRequest = $this->requestManager->getRequestById($requestId);

        if ($existingRequest === null) {
            return (new Response())->status(404)->json(['error' => 'Request not found']);
        }

        $body = $request->body;
        /** @var mixed $reasonRaw */
        $reasonRaw = $body['reason'] ?? '';
        $reason = is_string($reasonRaw) ? $reasonRaw : '';

        $success = $this->requestManager->rejectRequest($requestId, $reason);

        if (!$success) {
            return (new Response())->status(500)->json([
                'error' => 'Failed to reject request.',
            ]);
        }

        $rejectedUserId = is_string($existingRequest['user_id']) ? $existingRequest['user_id'] : '';
        $rejectedTitle = is_string($existingRequest['title']) ? $existingRequest['title'] : '';
        $this->notification->notifyRejected(
            $rejectedUserId,
            $rejectedTitle,
            $reason
        );

        return (new Response())->json([
            'message' => 'Request rejected successfully.',
            'request_id' => $requestId,
        ]);
    }

    /**
     * Deletes a request.
     *
     * DELETE /api/v1/requests/{id}
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id'
     *
     * @return Response JSON response indicating success/failure
     */
    public function deleteRequest(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';

        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $requestId = $params['id'] ?? null;

        if ($requestId === null) {
            return (new Response())->status(400)->json(['error' => 'Request ID is required']);
        }

        $existingRequest = $this->requestManager->getRequestById($requestId);

        if ($existingRequest === null) {
            return (new Response())->status(404)->json(['error' => 'Request not found']);
        }

        // Only the request owner or admin can delete
        if ($existingRequest['user_id'] !== $userId) {
            return (new Response())->status(403)->json([
                'error' => 'You can only delete your own requests.',
            ]);
        }

        $success = $this->requestManager->deleteRequest($requestId);

        if (!$success) {
            return (new Response())->status(500)->json([
                'error' => 'Failed to delete request.',
            ]);
        }

        return (new Response())->json([
            'message' => 'Request deleted successfully.',
            'request_id' => $requestId,
        ]);
    }

    /**
     * Gets a single request by ID.
     *
     * GET /api/v1/requests/{id}
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters with 'id'
     *
     * @return Response JSON response with request data or error
     */
    public function getRequest(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';

        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $requestId = $params['id'] ?? null;

        if ($requestId === null) {
            return (new Response())->status(400)->json(['error' => 'Request ID is required']);
        }

        $result = $this->requestManager->getRequestById($requestId);

        if ($result === null) {
            return (new Response())->status(404)->json(['error' => 'Request not found']);
        }

        return (new Response())->json(['request' => $result]);
    }

    /**
     * Lists all pending requests (admin only).
     *
     * GET /api/v1/requests/pending
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Path parameters
     *
     * @return Response JSON response with pending requests
     */
    public function listPendingRequests(Request $request, array $params): Response
    {
        $requests = $this->requestManager->listPendingRequests();

        return (new Response())->json([
            'requests' => $requests,
            'count' => count($requests),
        ]);
    }
}
