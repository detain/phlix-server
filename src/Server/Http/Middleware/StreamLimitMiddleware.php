<?php

/**
 * Phlix media server component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Access\StreamSessionService;
use Phlix\Auth\UserProfileManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;

/**
 * Enforces per-profile concurrent stream limits.
 *
 * This middleware gates streaming requests (/hls/... and /media/...)
 * behind stream limit checks. After authentication (userId set in
 * RequestContext), it retrieves the active profile and checks whether
 * a new stream can be started based on the profile's max_concurrent_streams.
 *
 * Behaviour:
 *  - No userId in context (not authenticated) → continue (let AuthMiddleware handle)
 *  - Stream limit exceeded → 429 JSON with StreamLimitExceeded error
 *  - Stream registered successfully → continue routing
 *
 * Heartbeat: A background timer is registered that calls heartbeat() every
 * 30 seconds for the duration of the streaming request. This is handled via
 * Workerman's timer functionality.
 *
 * @package Phlix\Server\Http\Middleware
 */
final class StreamLimitMiddleware
{
    /**
     * Create a new StreamLimitMiddleware instance.
     *
     * @param StreamSessionService $streamSessionService Service for managing stream sessions.
     * @param UserProfileManager   $profileManager       Service for getting active profile.
     */
    public function __construct(
        private readonly StreamSessionService $streamSessionService,
        private readonly UserProfileManager $profileManager,
    ) {
    }

    /**
     * Run the middleware against a request.
     *
     * @param Request $request Incoming request. UserId should be set by AuthMiddleware.
     *
     * @return Response|null 429 to short-circuit on limit exceeded; null to continue.
     */
    public function __invoke(Request $request): ?Response
    {
        // If no user is authenticated, let the request continue.
        // AuthMiddleware will handle the 401 response.
        if (!RequestContext::hasUserId()) {
            return null;
        }

        $userId = RequestContext::getUserId();
        if ($userId === null) {
            return null;
        }

        // Get the active profile for this user.
        // P5: Profile IDs are CHAR(36) UUID strings. If we cannot resolve
        // a profile ID, fail closed (deny access) rather than silently allowing
        // unauthenticated or unprofiled users through the stream limit check.
        $profile = $this->profileManager->getActiveProfile($userId);
        if ($profile === null) {
            return (new Response())->status(403)->json([
                'error' => 'StreamLimitExceeded',
                'denial_type' => 'profile_not_found',
                'message' => 'Profile not found; access denied',
            ]);
        }
        $profileId = $this->resolveProfileId($profile);
        if ($profileId === null) {
            return (new Response())->status(403)->json([
                'error' => 'StreamLimitExceeded',
                'denial_type' => 'profile_not_found',
                'message' => 'Profile not found; access denied',
            ]);
        }

        // Extract device and session from the request
        $deviceId = $this->getDeviceId($request);
        $sessionId = $this->getSessionId($request);

        if ($sessionId === null || $deviceId === null) {
            return null;
        }

        // Try to register the stream
        $registered = $this->streamSessionService->registerStream($profileId, $deviceId, $sessionId);
        if (!$registered) {
            return (new Response())->status(429)->json([
                'error' => 'StreamLimitExceeded',
                'denial_type' => 'stream_limit_exceeded',
                'message' => 'Maximum concurrent streams reached for this profile',
                'profile_id' => $profileId,
            ]);
        }

        // Register (or refresh) the heartbeat timer for this streaming session.
        // Keyed + deduped per session inside the service, so repeated requests
        // never accumulate timers; torn down on stream release.
        $this->streamSessionService->registerHeartbeatTimer($sessionId);

        return null;
    }

    /**
     * Resolve the profile ID from a profile array.
     *
     * Profile IDs are CHAR(36) UUID strings. Returns the ID as-is for use
     * in database queries against the profile_stream_limits table.
     *
     * @param array<string, mixed> $profile Profile array from UserProfileManager.
     *
     * @return string|null Profile ID as string, or null if cannot resolve.
     */
    private function resolveProfileId(array $profile): ?string
    {
        $id = $profile['id'] ?? null;

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return null;
    }

    /**
     * Extract the device ID from the request.
     *
     * @param Request $request The incoming request.
     *
     * @return string|null The device ID, or null if not found.
     */
    private function getDeviceId(Request $request): ?string
    {
        // Try header first
        $deviceId = $request->getHeader('X-Device-ID');
        if (is_string($deviceId) && $deviceId !== '') {
            return $deviceId;
        }

        // Fall back to a hash of the user agent
        $userAgent = $request->getHeader('User-Agent');
        if (is_string($userAgent) && $userAgent !== '') {
            return hash('sha256', $userAgent);
        }

        return null;
    }

    /**
     * Extract the session ID from the request.
     *
     * @param Request $request The incoming request.
     *
     * @return string|null The session ID, or null if not found.
     */
    private function getSessionId(Request $request): ?string
    {
        // Check for session_id query param (used by HLS clients)
        $sessionId = $request->queryString('session_id');
        if (is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        // Check for session_id in route params (if any)
        $sessionId = $request->query['session_id'] ?? null;
        if (is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        // Try X-Session-ID header
        $sessionId = $request->getHeader('X-Session-ID');
        if (is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        return null;
    }
}
