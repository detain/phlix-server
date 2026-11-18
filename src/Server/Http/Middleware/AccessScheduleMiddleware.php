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
     * The one request a session with NO resolvable profile may make. Creating a profile
     * does not use one, and the "set one up" remedy is unreachable without it. Matched
     * exactly on method and path. It is honoured only on the no-profile branch, so a
     * session that is bound to a profile, or whose account has one, is still gated.
     */
    public const NO_PROFILE_CREATE_METHOD = 'POST';

    public const NO_PROFILE_CREATE_PATH = '/api/v1/profiles';

    /**
     * Staleness bound, in nanoseconds, for a memoised no-profile verdict. Equal to the
     * schedule memo's TTL in {@see AccessScheduleService}. Relayed traffic with no
     * profile id otherwise costs one account-profile read per request, all to reach the
     * same 403.
     */
    private const NO_PROFILE_MEMO_TTL_NS = 30_000_000_000;

    /** Upper bound on the no-profile memo; aged-out entries are pruned past it. */
    private const NO_PROFILE_MEMO_MAX_ENTRIES = 512;

    /**
     * User ids whose account had no resolvable active profile, each with the hrtime the
     * verdict was read at. Only the refusal is memoised, never a profile. This object
     * writes no profiles, so nothing in this process can clear it early: a profile
     * created for the user is therefore refused for at most the TTL. That is fail-closed
     * and bounded, which is the trade made here.
     *
     * @var array<string, int|float>
     */
    private array $noProfileMemo = [];

    /**
     * User ids whose account's active profile was found by {@see denyForSession()}, each
     * with the profile id and the hrtime it was read at. Relayed artwork and avatar requests
     * carry no profile, so each poster in a grid would otherwise read the account profile once.
     * Held for the same 30 s TTL as the schedule rows, so a switch takes up to 30 s to apply to
     * these requests. Written only by {@see denyForSession()}, never by the global gate, and
     * local bearers never reach it (they always carry their own profile id). Bounded like the
     * no-profile memo.
     *
     * @var array<string, array{at: int|float, profileId: string}>
     */
    private array $foundProfileMemo = [];

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
            $accountProfileId = $this->accountProfileId($userId, hrtime(true));
            if ($accountProfileId === null) {
                return $this->noProfileVerdict($request);
            }
            $profileId = $accountProfileId;
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
     * The schedule verdict for a resolved session whose identity is held on the request
     * itself, not in {@see RequestContext}. The fast paths that run before
     * {@see \Phlix\Server\Core\Application::dispatch()} (artwork) use this, so they resolve
     * the profile exactly as {@see __invoke()} does:
     *
     *   - a session with its own profile is tested on that profile;
     *   - a profile-less session (a token minted before S80) is tested on the account's
     *     active profile, through the same bounded no-profile memo;
     *   - an account with no resolvable profile is refused with the same 403 the gate gives.
     *
     * The found profile is memoised for the TTL (see {@see $foundProfileMemo}), so a relayed
     * request grid does not read the account profile once per image. The window is still
     * tested fresh on every call.
     *
     * @param Request     $request   The authorised request, used for the profile-create exemption.
     * @param string      $userId    The session's account (non-empty).
     * @param string|null $profileId The session's own profile, or null when it has none.
     *
     * @return Response|null The AccessScheduled 403, or null when access is allowed.
     */
    public function denyForSession(Request $request, string $userId, ?string $profileId): ?Response
    {
        if ($profileId !== null && $profileId !== '') {
            return $this->denyForProfile($profileId);
        }

        $accountProfileId = $this->accountProfileId($userId, hrtime(true), true);
        if ($accountProfileId === null) {
            return $this->noProfileVerdict($request);
        }

        return $this->denyForProfile($accountProfileId);
    }

    /**
     * The account's active profile id, as the gate resolves it for a session with no
     * profile of its own, or null when the account has none. The refusal (null) is
     * memoised for the TTL.
     *
     * The global gate ({@see __invoke()}) never memoises a found profile, so a switch takes
     * effect on its next request. The relayed artwork and avatar path ({@see denyForSession()})
     * passes `$memoiseFound`, and accepts up to the TTL of switch lag for that path only.
     *
     * @param string    $userId        The account whose active profile is read.
     * @param int|float $readAt        The hrtime captured before any read, for the memos.
     * @param bool      $memoiseFound  True to memoise a found profile for the TTL.
     */
    private function accountProfileId(string $userId, int|float $readAt, bool $memoiseFound = false): ?string
    {
        if ($this->noProfileMemoHit($userId, $readAt)) {
            return null;
        }

        if ($memoiseFound) {
            $hit = $this->foundProfileMemo[$userId] ?? null;
            if ($hit !== null && $readAt - $hit['at'] < self::NO_PROFILE_MEMO_TTL_NS) {
                return $hit['profileId'];
            }
        }

        $profile = $this->profileManager->getActiveProfile($userId);
        if ($profile === null) {
            // P5: No profile exists for an authenticated user — fail closed (deny
            // access) rather than allowing an unprofiled user through. The user
            // should set up a profile before access schedules apply. The verdict is
            // memoised for the TTL, so repeat requests do not re-read the profile.
            $this->rememberNoProfile($userId, $readAt);

            return null;
        }

        // P5: Profile IDs are CHAR(36) UUID strings. If we cannot resolve
        // a profile ID, fail closed (deny access) rather than silently allowing
        // unauthenticated or unprofiled users through the schedule check.
        $profileId = $this->resolveProfileId($profile);
        if ($memoiseFound && $profileId !== null) {
            $this->rememberFoundProfile($userId, $profileId, $readAt);
        }

        return $profileId;
    }

    /**
     * Memoise a profile id found for a profile-less session. Bounded like the no-profile memo:
     * aged-out entries are pruned first, and the map resets if it is still over the bound.
     *
     * @param string    $userId    The account whose active profile was found.
     * @param string    $profileId The profile id it resolved to.
     * @param int|float $readAt    The hrtime captured before the profile read.
     */
    private function rememberFoundProfile(string $userId, string $profileId, int|float $readAt): void
    {
        $this->foundProfileMemo[$userId] = ['at' => $readAt, 'profileId' => $profileId];
        if (count($this->foundProfileMemo) <= self::NO_PROFILE_MEMO_MAX_ENTRIES) {
            return;
        }

        foreach ($this->foundProfileMemo as $id => $entry) {
            if ($readAt - $entry['at'] >= self::NO_PROFILE_MEMO_TTL_NS) {
                unset($this->foundProfileMemo[$id]);
            }
        }
        if (count($this->foundProfileMemo) > self::NO_PROFILE_MEMO_MAX_ENTRIES) {
            $this->foundProfileMemo = [];
        }
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
     * Whether a request may proceed with no resolvable profile: the profile-create route
     * ({@see self::NO_PROFILE_CREATE_METHOD} and {@see self::NO_PROFILE_CREATE_PATH}), or an
     * unmapped relayed principal ({@see Request::$relayUnmapped}). The second has no server
     * account, so there is no profile and no schedule to test, and it passes as master did.
     * A mapped relayed principal and every local session are still refused with no profile.
     * Exposed so the gate and its tests share one definition.
     */
    public static function allowsWithoutProfile(Request $request): bool
    {
        if ($request->relayUnmapped) {
            return true;
        }

        return $request->method === self::NO_PROFILE_CREATE_METHOD
            && $request->path === self::NO_PROFILE_CREATE_PATH;
    }

    /**
     * The verdict for a request whose account has no resolvable profile: null (continue)
     * for the profile-create route, otherwise the refusal. Every no-profile branch routes
     * through here, so the memoised and the fresh paths cannot disagree.
     */
    private function noProfileVerdict(Request $request): ?Response
    {
        if (self::allowsWithoutProfile($request)) {
            return null;
        }

        return $this->noProfileRefusal();
    }

    /**
     * The 403 for a request whose account has no resolvable profile. One builder, so the
     * fresh and the memoised refusals are byte-identical.
     */
    private function noProfileRefusal(): Response
    {
        return (new Response())->error(403, 'access.scheduled', 'AccessScheduled', [
            'message' => self::NO_PROFILE_MESSAGE,
        ]);
    }

    /**
     * True when this user's no-profile verdict was read less than the TTL ago.
     *
     * @param string    $userId The account whose verdict is looked up.
     * @param int|float $now    The current hrtime.
     */
    private function noProfileMemoHit(string $userId, int|float $now): bool
    {
        $readAt = $this->noProfileMemo[$userId] ?? null;

        return $readAt !== null && $now - $readAt < self::NO_PROFILE_MEMO_TTL_NS;
    }

    /**
     * Memoise a no-profile verdict. Past the entry bound, aged-out entries are pruned
     * first; if the map is still over the bound it is reset, so memory stays bounded.
     *
     * @param string    $userId The account that has no resolvable profile.
     * @param int|float $readAt The hrtime captured before the profile read.
     */
    private function rememberNoProfile(string $userId, int|float $readAt): void
    {
        $this->noProfileMemo[$userId] = $readAt;
        if (count($this->noProfileMemo) <= self::NO_PROFILE_MEMO_MAX_ENTRIES) {
            return;
        }

        foreach ($this->noProfileMemo as $id => $at) {
            if ($readAt - $at >= self::NO_PROFILE_MEMO_TTL_NS) {
                unset($this->noProfileMemo[$id]);
            }
        }
        if (count($this->noProfileMemo) > self::NO_PROFILE_MEMO_MAX_ENTRIES) {
            $this->noProfileMemo = [];
        }
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
