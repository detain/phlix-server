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

        // S80: prefer the profile THIS SESSION is running as, published by
        // RequestAuthenticator from the token's verified `profile_id` claim.
        //
        // ⚠ This is the correctness fix the step called its biggest risk. Before
        // S80 this middleware read `getActiveProfile()` — the account-wide
        // `user_profiles.is_active` DB flag — and then OVERWROTE the request
        // context with it. Under per-session profiles that would clobber the
        // session's own profile on every request: a tablet watching as "Kid" would
        // have its schedule, its tag filter and its stream limit silently
        // evaluated against whichever profile the account last switched to on some
        // other device.
        $profileId = RequestContext::getProfileId();

        if ($profileId === null || $profileId === '') {
            // No session profile (a token minted before S80, or an account with no
            // resolvable profile). Fall back to the account-wide active profile,
            // which is exactly the pre-S80 behaviour.
            $profile = $this->profileManager->getActiveProfile($userId);
            if ($profile === null) {
                // P5: No profile exists for an authenticated user — fail closed (deny
                // access) rather than allowing an unprofiled user through. The user
                // should set up a profile before access schedules apply.
                return (new Response())->status(403)->json([
                    'error' => 'AccessScheduled',
                    'message' => 'No profile found; access denied',
                ]);
            }

            // P5: Profile IDs are CHAR(36) UUID strings. If we cannot resolve
            // a profile ID, fail closed (deny access) rather than silently allowing
            // unauthenticated or unprofiled users through the schedule check.
            $profileId = $this->resolveProfileId($profile);
            if ($profileId === null) {
                return (new Response())->status(403)->json([
                    'error' => 'AccessScheduled',
                    'message' => 'Profile not found; access denied',
                ]);
            }
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
     * Profile IDs are CHAR(36) UUID strings. Returns the ID as-is for use
     * in database queries against the access_schedules table.
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
}
