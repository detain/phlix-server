<?php

/**
 * Phlix media server component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Access\AccessScheduleService;
use Phlix\Auth\SignedUrl;
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
 * The identity is read from RequestContext. The global gate
 * ({@see \Phlix\Server\Core\Application::scheduleGateFor()}) publishes the
 * request's own userId and profileId there before calling this, because the
 * route-level AuthMiddleware has not run when the global gate runs. Direct-play
 * and signed-URL paths call {@see self::denyForProfile()} and
 * {@see self::denyForSignedRequest()} directly.
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
     * M25 (copywriter major 5): the schedule-area refusals are child-facing
     * device copy, not syslog lines. One remedy-bearing sentence per state,
     * hoisted so the two no-profile arms cannot drift apart (the historic
     * 'No profile found; access denied' / 'Profile not found; access denied'
     * near-duplicate pair), and named to disambiguate the household member
     * profile from device quality profiles. Envelope keys/codes unchanged.
     */
    private const NO_PROFILE_MESSAGE = 'No member profile is active on this device.'
        . ' An adult needs to set one up before this device can stream.';

    private const WINDOW_MESSAGE = "This profile can't be used right now. Allowed hours are set by your account.";

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
                return (new Response())->error(403, 'access.scheduled', 'AccessScheduled', [
                    'message' => self::NO_PROFILE_MESSAGE,
                ]);
            }

            // P5: Profile IDs are CHAR(36) UUID strings. If we cannot resolve
            // a profile ID, fail closed (deny access) rather than silently allowing
            // unauthenticated or unprofiled users through the schedule check.
            $profileId = $this->resolveProfileId($profile);
            if ($profileId === null) {
                return (new Response())->error(403, 'access.scheduled', 'AccessScheduled', [
                    'message' => self::NO_PROFILE_MESSAGE,
                ]);
            }
        }

        // Check if access is allowed
        $denied = $this->denyForProfile($profileId);
        if ($denied !== null) {
            return $denied;
        }

        // Store profileId in context for downstream use
        RequestContext::setProfileId($profileId);

        return null;
    }

    /**
     * The window test for a profile whose id is already known, with no reliance on
     * {@see RequestContext}: the 403 when the profile's schedule is active now, else
     * null. Used by the direct-play fast path and the signed-URL gate, which hold
     * the profile id directly.
     *
     * @param string $profileId The profile to test (non-empty).
     *
     * @return Response|null The AccessScheduled 403, or null when access is allowed.
     */
    public function denyForProfile(string $profileId): ?Response
    {
        if ($this->accessScheduleService->isAccessAllowed($profileId)) {
            return null;
        }

        return (new Response())->error(403, 'access.scheduled', 'AccessScheduled', [
            'message' => self::WINDOW_MESSAGE,
        ]);
    }

    /**
     * The window test for a request with no session identity whose signed URL is
     * bound to a profile (`pid`). The binding is trusted only once its signature
     * verifies over the request path, `exp` and `pid`: a stripped, swapped or forged
     * `pid` is not trusted, and the request passes this gate (the route's own
     * signed-URL check then refuses it). A legacy token with no `pid` passes, and is
     * bounded only by its expiry.
     *
     * @param Request $request The request with no userId.
     *
     * @return Response|null The AccessScheduled 403, or null to continue.
     */
    public function denyForSignedRequest(Request $request): ?Response
    {
        $profileId = $request->queryString(SignedUrl::PROFILE_PARAM);
        if ($profileId === null || $profileId === '') {
            return null;
        }

        $verified = SignedUrl::fromEnv()->verify(
            $request->path,
            $request->queryString('exp'),
            $request->queryString('sig'),
            null,
            $profileId,
        );
        if (!$verified) {
            return null;
        }

        return $this->denyForProfile($profileId);
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
