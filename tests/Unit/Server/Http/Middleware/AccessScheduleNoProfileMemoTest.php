<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Access\AccessScheduleService;
use Phlix\Auth\UserProfileManager;
use Phlix\Server\Http\Middleware\AccessScheduleMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * The no-profile verdict is memoised per user for the 30 s schedule TTL.
 *
 * Relayed traffic with no profile id used to read the account profile on every request
 * just to reach the same 403. The verdict is now memoised per user, bounded, and only
 * for the refusal: on the global gate a found profile is never memoised, and a schedule
 * lookup still runs on every request that has one.
 *
 * The relayed artwork and avatar path ({@see AccessScheduleMiddleware::denyForSession()})
 * is the one exception: it memoises the FOUND profile for the same TTL, bounded, and only
 * for a session with no profile id of its own.
 */
final class AccessScheduleNoProfileMemoTest extends TestCase
{
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

    /** A schedule service over a connection that has no rows, so an allowed profile passes. */
    private function emptySchedules(): AccessScheduleService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        return new AccessScheduleService($db);
    }

    /**
     * The memo map on the middleware instance.
     *
     * @return array<string, int|float>
     */
    private function memoOf(AccessScheduleMiddleware $middleware): array
    {
        $property = new ReflectionProperty(AccessScheduleMiddleware::class, 'noProfileMemo');

        /** @var array<string, int|float> $memo */
        $memo = $property->getValue($middleware);

        return $memo;
    }

    /** @param array<string, int|float> $memo */
    private function setMemoOf(AccessScheduleMiddleware $middleware, array $memo): void
    {
        $property = new ReflectionProperty(AccessScheduleMiddleware::class, 'noProfileMemo');
        $property->setValue($middleware, $memo);
    }

    private function asUser(string $userId): void
    {
        RequestContext::setUserId($userId);
        RequestContext::setProfileId(null);
    }

    public function test_repeat_requests_for_an_unprofiled_user_read_the_profile_once(): void
    {
        $this->asUser('u1');

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::once())->method('getActiveProfile')->with('u1')->willReturn(null);

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);

        for ($i = 0; $i < 3; $i++) {
            $response = $middleware(new Request());

            self::assertNotNull($response, 'an unprofiled request must still be refused');
            self::assertSame(403, $response->statusCode);
            self::assertStringContainsString('access.scheduled', $response->body);
        }
    }

    public function test_an_expired_no_profile_verdict_is_read_again(): void
    {
        $this->asUser('u1');

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::exactly(2))->method('getActiveProfile')->with('u1')->willReturn(null);

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);
        $middleware(new Request());

        // Age the one memoised verdict past the 30 s TTL, then the profile is read again.
        $memo = $this->memoOf($middleware);
        self::assertArrayHasKey('u1', $memo);
        $memo['u1'] = hrtime(true) - 31_000_000_000;
        $this->setMemoOf($middleware, $memo);

        self::assertNotNull($middleware(new Request()));
    }

    public function test_a_found_profile_is_never_memoised(): void
    {
        $this->asUser('u1');

        // Two requests, both reading the profile: a profile that exists is never cached,
        // so the account profile is read again on each request with no session profile.
        // asUser() clears the profile id the first request publishes, as a fresh request does.
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::exactly(2))->method('getActiveProfile')->with('u1')->willReturn(['id' => 'p1']);

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);

        $this->asUser('u1');
        self::assertNull($middleware(new Request()));
        $this->asUser('u1');
        self::assertNull($middleware(new Request()));
        self::assertSame([], $this->memoOf($middleware));
    }

    public function test_the_verdict_is_per_user_and_does_not_block_another_account(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveProfile')->willReturnCallback(
            static fn (string $userId): ?array => $userId === 'u1' ? null : ['id' => 'p2'],
        );

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);

        $this->asUser('u1');
        self::assertNotNull($middleware(new Request()));

        // u2 has a profile, so its request is not refused by u1's memoised verdict.
        $this->asUser('u2');
        self::assertNull($middleware(new Request()));
    }

    public function test_the_no_profile_memo_is_bounded(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveProfile')->willReturn(null);

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);

        // More distinct unprofiled users than the bound: all are fresh, so the map resets
        // rather than growing without limit.
        for ($i = 0; $i < 600; $i++) {
            $this->asUser('user-' . $i);
            $middleware(new Request());
        }

        $size = count($this->memoOf($middleware));
        self::assertGreaterThan(0, $size);
        self::assertLessThanOrEqual(512, $size);
    }

    public function test_aged_out_entries_are_pruned_before_the_map_resets(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveProfile')->willReturn(null);

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);

        // Pre-fill with aged-out verdicts; a fresh one must survive the prune.
        $aged = [];
        for ($i = 0; $i < 512; $i++) {
            $aged['old-' . $i] = hrtime(true) - 60_000_000_000;
        }
        $this->setMemoOf($middleware, $aged);

        $this->asUser('fresh');
        $middleware(new Request());

        $memo = $this->memoOf($middleware);
        self::assertArrayHasKey('fresh', $memo);
        self::assertCount(1, $memo, 'every aged-out entry must be pruned');
    }

    /**
     * The found-profile memo map ({@see AccessScheduleMiddleware::denyForSession()}) on the instance.
     *
     * @return array<string, array{at: int|float, profileId: string}>
     */
    private function foundMemoOf(AccessScheduleMiddleware $middleware): array
    {
        $property = new ReflectionProperty(AccessScheduleMiddleware::class, 'foundProfileMemo');

        /** @var array<string, array{at: int|float, profileId: string}> $memo */
        $memo = $property->getValue($middleware);

        return $memo;
    }

    public function test_a_relayed_session_reads_its_account_profile_once_per_ttl(): void
    {
        // A relayed poster grid carries no profile id. Every poster used to read the account
        // profile again; the found profile is now memoised for the TTL on this path.
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::once())->method('getActiveProfile')->with('u1')->willReturn(['id' => 'p1']);

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);

        for ($i = 0; $i < 3; $i++) {
            self::assertNull($middleware->denyForSession(new Request(), 'u1', null));
        }
    }

    public function test_an_expired_found_profile_is_read_again(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::exactly(2))->method('getActiveProfile')->with('u1')->willReturn(['id' => 'p1']);

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);
        $middleware->denyForSession(new Request(), 'u1', null);

        // Age the memoised profile past the 30 s TTL, then the account profile is read again.
        $memo = $this->foundMemoOf($middleware);
        self::assertArrayHasKey('u1', $memo);
        $memo['u1']['at'] = hrtime(true) - 31_000_000_000;
        $property = new ReflectionProperty(AccessScheduleMiddleware::class, 'foundProfileMemo');
        $property->setValue($middleware, $memo);

        $middleware->denyForSession(new Request(), 'u1', null);
    }

    public function test_a_session_that_carries_its_own_profile_never_reads_the_account_profile(): void
    {
        // A local bearer always carries its profile, so the account profile is never read and
        // nothing is memoised for it.
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::never())->method('getActiveProfile');

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);

        self::assertNull($middleware->denyForSession(new Request(), 'u1', 'p1'));
        self::assertSame([], $this->foundMemoOf($middleware));
    }

    public function test_the_found_profile_memo_is_bounded(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveProfile')->willReturn(['id' => 'p1']);

        $middleware = new AccessScheduleMiddleware($this->emptySchedules(), $profiles);

        // More distinct relayed accounts than the bound: the map resets rather than growing.
        for ($i = 0; $i < 600; $i++) {
            $middleware->denyForSession(new Request(), 'user-' . $i, null);
        }

        $size = count($this->foundMemoOf($middleware));
        self::assertGreaterThan(0, $size);
        self::assertLessThanOrEqual(512, $size);
    }
}
