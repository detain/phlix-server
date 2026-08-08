<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Access\AccessScheduleService;
use Phlix\Access\StreamSessionService;
use Phlix\Auth\UserProfileManager;
use Phlix\Server\Http\Middleware\AccessScheduleMiddleware;
use Phlix\Server\Http\Middleware\StreamLimitMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S80 — the middlewares that gate a request by profile use the SESSION's profile,
 * not the account-wide `user_profiles.is_active` flag.
 *
 * ## The defect this pins
 *
 * The step named this its biggest correctness risk, and it is a real one.
 * `AccessScheduleMiddleware` used to call `getActiveProfile($userId)` — a
 * `WHERE is_active = TRUE` query against a single account-wide DB flag — and then
 * `RequestContext::setProfileId()` with the result. Under per-session profiles
 * that is not merely a missed optimisation: it OVERWRITES the session's own
 * profile on every single request. A tablet watching as "Kid" would have its
 * access schedule, its `profile_tags` content filter (via
 * `ItemRepository::doFilterItemsByTags()`, the only reader of that context key)
 * and its concurrent-stream budget evaluated against whichever profile the
 * account last switched to on some other device.
 *
 * `StreamLimitMiddleware` had the same acquisition, with a sharper consequence:
 * stream budgets are per profile, so two tablets on two different child profiles
 * would have shared one budget.
 *
 * ## Why each test is a pair
 *
 * "The middleware used profile X" proves nothing on its own if X also happens to
 * be what the fallback returns. So in every test the session profile and the
 * account-active profile are DIFFERENT values, and the fallback branch is
 * exercised beside the primary one — a middleware that ignored the context
 * entirely fails the first, and one that ignored the account flag entirely fails
 * the second.
 *
 * ## Why the services are real, not doubled
 *
 * `AccessScheduleService` and `StreamSessionService` are both `final`, so PHPUnit
 * cannot double them. They are therefore constructed for real over a recording
 * `Connection` double, and the assertion is made on the profile id that actually
 * reached SQL. That is a STRONGER statement than a mocked expectation anyway: it
 * shows the id the database was asked about, not merely the argument a collaborator
 * received.
 */
final class ProfileContextPrecedenceTest extends TestCase
{
    private const USER = 'user-1';

    /** What the session's token says — must win. */
    private const SESSION_PROFILE = 'profile-session';

    /** What `user_profiles.is_active` says — must only be a fallback. */
    private const ACCOUNT_ACTIVE_PROFILE = 'profile-account-active';

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
    }

    protected function tearDown(): void
    {
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        parent::tearDown();
    }

    /**
     * AccessScheduleMiddleware checks the SESSION's profile, and does not re-read
     * (or clobber with) the account-wide active profile.
     */
    public function testAccessScheduleUsesTheSessionProfileAndNeverQueriesTheAccountFlag(): void
    {
        RequestContext::setUserId(self::USER);
        RequestContext::setProfileId(self::SESSION_PROFILE);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->never())
            ->method('getActiveProfile')
            ->with(self::USER);

        $bound = [];
        $middleware = new AccessScheduleMiddleware(
            new AccessScheduleService($this->recordingConnection($bound)),
            $profiles
        );

        $this->assertNull($middleware(new Request()), 'no schedule row means access is allowed');
        $this->assertContains(
            self::SESSION_PROFILE,
            $bound,
            "the schedule lookup must be made for the SESSION's profile"
        );
        $this->assertNotContains(
            self::ACCOUNT_ACTIVE_PROFILE,
            $bound,
            'the account-wide active profile must never reach the schedule lookup'
        );
        $this->assertSame(
            self::SESSION_PROFILE,
            RequestContext::getProfileId(),
            "the middleware must not overwrite the session's profile with the account flag"
        );
    }

    /**
     * The fallback control: with no session profile — a token minted before S80 —
     * the middleware still reads the account-wide active profile, i.e. the
     * pre-S80 behaviour is intact.
     */
    public function testAccessScheduleFallsBackToTheAccountActiveProfileWhenNoSessionProfile(): void
    {
        RequestContext::setUserId(self::USER);
        // Deliberately NOT setting a profile — this is the legacy-token case.

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->once())
            ->method('getActiveProfile')
            ->with(self::USER)
            ->willReturn(['id' => self::ACCOUNT_ACTIVE_PROFILE]);

        $bound = [];
        $middleware = new AccessScheduleMiddleware(
            new AccessScheduleService($this->recordingConnection($bound)),
            $profiles
        );

        $this->assertNull($middleware(new Request()));
        $this->assertContains(
            self::ACCOUNT_ACTIVE_PROFILE,
            $bound,
            'the legacy path must still consult the account-wide active profile'
        );
        $this->assertSame(self::ACCOUNT_ACTIVE_PROFILE, RequestContext::getProfileId());
    }

    /**
     * The fail-closed path survives: no session profile AND no account profile is
     * still a 403, not an open door.
     */
    public function testAccessScheduleStillFailsClosedWhenNoProfileCanBeResolvedAtAll(): void
    {
        RequestContext::setUserId(self::USER);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveProfile')->willReturn(null);

        $bound = [];
        $schedules = new AccessScheduleService($this->recordingConnection($bound));

        $response = (new AccessScheduleMiddleware($schedules, $profiles))(new Request());

        $this->assertNotNull($response, 'an unprofiled request must be refused');
        $this->assertSame(403, $response->statusCode);
        $this->assertSame([], $bound, 'no schedule lookup may be attempted without a profile');
    }

    /**
     * StreamLimitMiddleware charges the stream to the SESSION's profile.
     *
     * Stream budgets are per profile, so binding to the account-wide flag would
     * make two devices on two child profiles share one budget — and would move a
     * tablet's stream onto whichever profile the TV last switched to.
     */
    public function testStreamLimitRegistersAgainstTheSessionProfile(): void
    {
        RequestContext::setUserId(self::USER);
        RequestContext::setProfileId(self::SESSION_PROFILE);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->never())->method('getActiveProfile');

        $bound = [];
        $middleware = new StreamLimitMiddleware(
            new StreamSessionService($this->recordingConnection($bound)),
            $profiles
        );

        $this->runStreamMiddleware($middleware);
        $this->assertContains(
            self::SESSION_PROFILE,
            $bound,
            "the stream must be charged to the SESSION's profile"
        );
        $this->assertNotContains(
            self::ACCOUNT_ACTIVE_PROFILE,
            $bound,
            'the account-wide active profile must never reach the stream budget'
        );
    }

    /**
     * The fallback control for the test above.
     */
    public function testStreamLimitFallsBackToTheAccountActiveProfile(): void
    {
        RequestContext::setUserId(self::USER);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->once())
            ->method('getActiveProfile')
            ->with(self::USER)
            ->willReturn(['id' => self::ACCOUNT_ACTIVE_PROFILE]);

        $bound = [];
        $middleware = new StreamLimitMiddleware(
            new StreamSessionService($this->recordingConnection($bound)),
            $profiles
        );

        $this->runStreamMiddleware($middleware);
        $this->assertContains(
            self::ACCOUNT_ACTIVE_PROFILE,
            $bound,
            'the legacy path must still charge the account-wide active profile'
        );
        $this->assertNotContains(
            self::SESSION_PROFILE,
            $bound,
            'there is no session profile in this scenario, so it must not appear'
        );
    }

    /**
     * Invoke StreamLimitMiddleware and tolerate ONLY the heartbeat timer.
     *
     * `StreamSessionService::registerStream()` succeeds, and the middleware then
     * calls `registerHeartbeatTimer()`, which needs a running Workerman event loop
     * — absent under PHPUnit. The registration (and therefore the profile id that
     * reached SQL, which is what this suite is about) has already happened by then.
     *
     * ⚠ Only that ONE error is tolerated, and it is asserted on explicitly: any
     * other throwable is re-raised, so this cannot become a blanket swallow that
     * hides a genuine regression.
     */
    private function runStreamMiddleware(StreamLimitMiddleware $middleware): void
    {
        try {
            $response = $middleware($this->streamRequest());
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'Timer can only be used in workerman')) {
                throw $e;
            }

            // Registration completed; only the post-registration timer failed.
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertNull($response, 'an under-budget stream must not be refused');
    }

    /**
     * A {@see Connection} double that RECORDS every bound parameter into `$bound`
     * and answers every SELECT with no rows.
     *
     * No rows means: no access-schedule row (so access is allowed) and no existing
     * or counted active stream (so the stream is under budget). Both middlewares
     * therefore run to completion down their success path, which is what makes the
     * recorded profile id meaningful — a middleware that short-circuited early
     * would record nothing and the `assertContains` would fail.
     *
     * @param list<mixed> $bound Filled in place with every parameter bound.
     */
    private function recordingConnection(array &$bound): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * ⚠ `$params` is typed `mixed`, not `array`: several callers here pass
             * `null` (`Connection::query()`'s own default), and a narrower hint
             * would fatal before anything could be recorded.
             *
             * @return list<array<string, mixed>>
             */
            function (string $sql, mixed $params = null) use (&$bound): array {
                // Record only. Assertions run OUTSIDE this callback so a failure
                // cannot be swallowed (S120 assertion-escape rule).
                if (is_array($params)) {
                    foreach ($params as $param) {
                        $bound[] = $param;
                    }
                }

                return [];
            }
        );

        return $db;
    }

    /**
     * A request carrying a device and session id, which StreamLimitMiddleware
     * needs before it will register anything.
     */
    private function streamRequest(): Request
    {
        $request = new Request();
        $request->headers['x-device-id'] = 'device-1';
        $request->headers['x-session-id'] = 'session-1';
        $request->query['device_id'] = 'device-1';
        $request->query['session_id'] = 'session-1';

        return $request;
    }
}
