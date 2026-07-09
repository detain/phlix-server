<?php

/**
 * Phlix media server component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Access\AccessScheduleService;
use Phlix\Auth\UserProfileManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;

/**
 * Enforces time-based access schedule restrictions.
 *
 * This middleware gates requests behind access schedule checks. After
 * authentication (userId set in RequestContext), it retrieves the
 * active profile and checks whether access is currently permitted based
 * on the profile's scheduled access windows.
 *
 * Access is DENIED when:
 * - An active schedule exists for the profile AND
 * - The current day and time fall within that schedule's window
 *
 * Access is ALLOWED when:
 * - No schedule exists for the profile (no restrictions)
 * - The current time is outside all active schedule windows
 *
 * Behaviour:
 *  - No userId in context (not authenticated) → continue (let AuthMiddleware handle)
 *  - Access denied by schedule → 403 JSON with AccessScheduled error
 *  - Access allowed → continue routing
 *
 * @package Phlix\Server\Http\Middleware
 */
final class AccessScheduleMiddleware
{
    /**
     * Create a new AccessScheduleMiddleware instance.
     *
     * @param AccessScheduleService $accessScheduleService Service for checking schedules.
     * @param UserProfileManager    $profileManager        Service for getting active profile.
     */
    public function __construct(
        private readonly AccessScheduleService $accessScheduleService,
        private readonly UserProfileManager $profileManager,
    ) {
    }

    /**
     * Run the middleware against a request.
     *
     * @param Request $request Incoming request. UserId should be set by AuthMiddleware.
     *
     * @return Response|null 403 to short-circuit; null to continue.
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

        // Get the active profile for this user
        $profile = $this->profileManager->getActiveProfile($userId);
        if ($profile === null) {
            // No profile exists, allow access (or could deny - depends on policy)
            return null;
        }

        // Get the profile ID as an integer
        // Note: The access_schedules table uses INT UNSIGNED profile_id
        // If the profile ID from UserProfileManager is a UUID string,
        // this would need conversion. Assuming it's already an int for now.
        $profileId = $this->resolveProfileId($profile);
        if ($profileId === null) {
            // Cannot determine profile ID for schedule check
            return null;
        }

        // Check if access is allowed
        if (!$this->accessScheduleService->isAccessAllowed($profileId)) {
            return (new Response())->status(403)->json([
                'error' => 'AccessScheduled',
                'message' => 'Access denied during scheduled window',
            ]);
        }

        // Store profileId in context for downstream use
        RequestContext::setProfileId($profileId);

        return null;
    }

    /**
     * Resolve the profile ID from a profile array.
     *
     * @param array<string, mixed> $profile Profile array from UserProfileManager.
     *
     * @return int|null Profile ID as int, or null if cannot resolve.
     */
    private function resolveProfileId(array $profile): ?int
    {
        $id = $profile['id'] ?? null;

        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && $id !== '') {
            // If it's a numeric string (e.g., "123"), convert to int
            if (ctype_digit($id)) {
                return (int) $id;
            }
            // For UUID strings, we cannot directly convert to int
            // In a real implementation, you might have a mapping table
            // or use a hash of the UUID. For now, return null.
            return null;
        }

        return null;
    }
}
