<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Access\AccessScheduleService;
use Phlix\Access\ProfileAccessPolicy;
use Phlix\Access\ProfileTagService;
use Phlix\Access\StreamSessionService;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Controllers\AccessScheduleController;
use Phlix\Server\Http\Controllers\ProfileTagController;
use Phlix\Server\Http\Controllers\StreamLimitController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S208 defect (2) — the IDOR pin, and the pin for who may edit restrictions.
 *
 * ## Why a route table cannot stand in for this file
 *
 * Every by-id parental-controls route DECLARED `{profileId}` and no handler
 * READ it: `AccessScheduleController::getSchedule/updateSchedule/deleteSchedule`
 * referenced `profileId` zero times each and acted on `scheduleId` alone, and
 * `ProfileTagController::deleteTag` did the same with `tagId`. A route manifest
 * renders those paths as correctly scoped and green, because the path *is*
 * correctly scoped — the parameter was simply inert. So every assertion here is
 * on HANDLER BEHAVIOUR, driven with a record that belongs to a DIFFERENT
 * profile, and the mutating cases assert the absence of the write statement
 * rather than only the status code.
 *
 * ## The fixture world, and why it has three shapes of negative case
 *
 * One plant is not coverage. The cross-profile cases here vary in SHAPE, not
 * just in count, and each isolates a different layer of the fix:
 *
 *  - **same user, other profile** (`SCHEDULE_A2` under `PROFILE_A1`) — the
 *    caller passes the owner check and is stopped ONLY by the record-scoping
 *    check. This is the pure IDOR pin.
 *  - **different user** (`USER_B` naming `PROFILE_A1`) — stopped by the owner
 *    check before any record is fetched. This is the original attack.
 *  - **admin, other user's record** (`SCHEDULE_B1` under `PROFILE_A1`) — the
 *    caller passes the owner check *via the admin branch* and is again stopped
 *    only by record scoping, proving the record check is not merely a
 *    re-spelling of the authorization check.
 *
 * A single fixture would have passed for the wrong reason in at least two of
 * those three.
 */
final class ParentalControlsAuthorizationTest extends TestCase
{
    private const USER_A = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
    private const USER_B = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
    private const USER_ADMIN = 'cccccccc-3333-4333-8333-cccccccccccc';

    /** Owned by USER_A. */
    private const PROFILE_A1 = 'a1a1a1a1-1111-4111-8111-a1a1a1a1a1a1';
    /** Also owned by USER_A — a sibling profile under the same account. */
    private const PROFILE_A2 = 'a2a2a2a2-2222-4222-8222-a2a2a2a2a2a2';
    /** Owned by USER_B. */
    private const PROFILE_B1 = 'b1b1b1b1-1111-4111-8111-b1b1b1b1b1b1';

    private const SCHEDULE_A1 = 1;
    private const SCHEDULE_A2 = 2;
    private const SCHEDULE_B1 = 3;

    private const TAG_A1 = 10;
    private const TAG_A2 = 11;
    private const TAG_B1 = 12;

    /** @var list<string> Every SQL statement the controllers put through the connection. */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->statements = [];
    }

    // ------------------------------------------------------------------
    // getSchedule — the read half of the IDOR
    // ------------------------------------------------------------------

    public function testGetScheduleRefusesAScheduleBelongingToAnotherProfileOfTheSameUser(): void
    {
        $response = $this->schedules()->getSchedule(
            $this->request(self::USER_A),
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_A2],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['error' => 'Schedule not found'], $this->decode($response));
        // The refusal must not leak the schedule's contents in any form.
        $this->assertStringNotContainsString('Bedtime A2', (string) $response->body);
    }

    public function testGetScheduleRefusesAScheduleBelongingToAnotherUsersProfileEvenForAnAdmin(): void
    {
        // The admin legitimately reaches PROFILE_A1 (that is the whole point of
        // the /api/v1/admin/... routes), but SCHEDULE_B1 is not PROFILE_A1's.
        $response = $this->schedules()->getSchedule(
            $this->request(self::USER_ADMIN),
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_B1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertStringNotContainsString('Bedtime B1', (string) $response->body);
    }

    public function testGetScheduleRefusesAProfileThatIsNotTheCallersWithoutRevealingItExists(): void
    {
        $response = $this->schedules()->getSchedule(
            $this->request(self::USER_B),
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_A1],
        );

        // 404, NOT 403: a 403 would confirm the profile exists.
        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['error' => 'Profile not found'], $this->decode($response));
        $this->assertSame(
            [],
            $this->statementsMatching('FROM access_schedules'),
            'a caller who does not own the profile must not reach the schedule table at all',
        );
    }

    public function testGetScheduleStillServesTheOwnersOwnSchedule(): void
    {
        $response = $this->schedules()->getSchedule(
            $this->request(self::USER_A),
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_A1],
        );

        $this->assertSame(200, $response->statusCode);
        $decoded = $this->decode($response);
        $this->assertIsArray($decoded['schedule'] ?? null);
        $this->assertSame('Bedtime A1', $decoded['schedule']['name']);
        // The DTO must carry the real CHAR(36) profile id, not the 0 that the
        // pre-S208 `is_numeric()` narrowing produced for every UUID.
        $this->assertSame(self::PROFILE_A1, $decoded['schedule']['profile_id']);
    }

    // ------------------------------------------------------------------
    // updateSchedule — the write half, pinned separately
    // ------------------------------------------------------------------

    public function testUpdateScheduleDoesNotMutateAScheduleBelongingToAnotherProfileOfTheSameUser(): void
    {
        $request = $this->request(self::USER_A);
        $request->body = ['name' => 'PWNED'];

        $response = $this->schedules()->updateSchedule(
            $request,
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_A2],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(
            [],
            $this->statementsMatching('UPDATE access_schedules'),
            'a cross-profile update must issue no UPDATE at all',
        );
    }

    public function testUpdateScheduleDoesNotMutateAnotherUsersScheduleForAnAdminEither(): void
    {
        $request = $this->request(self::USER_ADMIN);
        $request->body = ['name' => 'PWNED'];

        $response = $this->schedules()->updateSchedule(
            $request,
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_B1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('UPDATE access_schedules'));
    }

    public function testUpdateScheduleDoesNotMutateWhenTheCallerDoesNotOwnTheProfile(): void
    {
        $request = $this->request(self::USER_B);
        $request->body = ['name' => 'PWNED'];

        $response = $this->schedules()->updateSchedule(
            $request,
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_A1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['error' => 'Profile not found'], $this->decode($response));
        $this->assertSame([], $this->statementsMatching('UPDATE access_schedules'));
    }

    public function testUpdateScheduleStillUpdatesTheOwnersOwnSchedule(): void
    {
        $request = $this->request(self::USER_A);
        $request->body = ['name' => 'Later bedtime'];

        $response = $this->schedules()->updateSchedule(
            $request,
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_A1],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertCount(1, $this->statementsMatching('UPDATE access_schedules'));
    }

    // ------------------------------------------------------------------
    // deleteSchedule — the case the step names explicitly, pinned separately
    // ------------------------------------------------------------------

    public function testDeleteScheduleDoesNotDeleteAScheduleBelongingToAnotherProfileOfTheSameUser(): void
    {
        $response = $this->schedules()->deleteSchedule(
            $this->request(self::USER_A),
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_A2],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(
            [],
            $this->statementsMatching('DELETE FROM access_schedules'),
            'DELETE /profiles/{other}/schedules/{n} must issue no DELETE',
        );
    }

    public function testDeleteScheduleDoesNotDeleteAnotherUsersScheduleForAnAdminEither(): void
    {
        $response = $this->schedules()->deleteSchedule(
            $this->request(self::USER_ADMIN),
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_B1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('DELETE FROM access_schedules'));
    }

    /**
     * The verbatim attack from the step: any authenticated caller, any profile
     * uuid in the path, a sequential schedule id.
     */
    public function testDeleteScheduleRefusesTheOriginalCrossUserAttack(): void
    {
        $response = $this->schedules()->deleteSchedule(
            $this->request(self::USER_B),
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_A1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['error' => 'Profile not found'], $this->decode($response));
        $this->assertSame([], $this->statementsMatching('DELETE FROM access_schedules'));
    }

    public function testDeleteScheduleStillDeletesTheOwnersOwnSchedule(): void
    {
        $response = $this->schedules()->deleteSchedule(
            $this->request(self::USER_A),
            ['profileId' => self::PROFILE_A1, 'scheduleId' => (string) self::SCHEDULE_A1],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertCount(1, $this->statementsMatching('DELETE FROM access_schedules'));
    }

    public function testDeleteScheduleLetsAnAdminDeleteAnotherUsersScheduleUnderItsOwnProfile(): void
    {
        // The admin SPA's actual job: an admin edits a profile that is not theirs.
        $response = $this->schedules()->deleteSchedule(
            $this->request(self::USER_ADMIN),
            ['profileId' => self::PROFILE_B1, 'scheduleId' => (string) self::SCHEDULE_B1],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertCount(1, $this->statementsMatching('DELETE FROM access_schedules'));
    }

    // ------------------------------------------------------------------
    // Collection endpoints — no by-id record, so the owner check is the whole gate
    // ------------------------------------------------------------------

    public function testListSchedulesRefusesAProfileTheCallerDoesNotOwn(): void
    {
        $response = $this->schedules()->listForProfile(
            $this->request(self::USER_B),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('FROM access_schedules'));
    }

    public function testCreateScheduleRefusesAProfileTheCallerDoesNotOwn(): void
    {
        $request = $this->request(self::USER_B);
        $request->body = [
            'name' => 'Injected',
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => ['mon'],
        ];

        $response = $this->schedules()->createForProfile($request, ['profileId' => self::PROFILE_A1]);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('INSERT INTO access_schedules'));
    }

    public function testUnauthenticatedCallerIsRefusedEvenIfTheGroupMiddlewareIsBypassed(): void
    {
        // Belt and braces: AuthMiddleware/AdminMiddleware gate the groups, but
        // the handler must not depend on that to have run.
        $response = $this->schedules()->listForProfile(
            $this->request(null),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(404, $response->statusCode);
    }

    // ------------------------------------------------------------------
    // Tags — the same defect in ProfileTagController::deleteTag
    // ------------------------------------------------------------------

    public function testDeleteTagDoesNotDeleteATagBelongingToAnotherProfileOfTheSameUser(): void
    {
        $response = $this->tags()->deleteTag(
            $this->request(self::USER_A),
            ['profileId' => self::PROFILE_A1, 'tagId' => (string) self::TAG_A2],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['error' => 'Tag not found'], $this->decode($response));
        $this->assertSame([], $this->statementsMatching('DELETE FROM profile_tags'));
    }

    public function testDeleteTagDoesNotDeleteAnotherUsersTagForAnAdminEither(): void
    {
        $response = $this->tags()->deleteTag(
            $this->request(self::USER_ADMIN),
            ['profileId' => self::PROFILE_A1, 'tagId' => (string) self::TAG_B1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('DELETE FROM profile_tags'));
    }

    public function testDeleteTagStillDeletesTheOwnersOwnTag(): void
    {
        $response = $this->tags()->deleteTag(
            $this->request(self::USER_A),
            ['profileId' => self::PROFILE_A1, 'tagId' => (string) self::TAG_A1],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertCount(1, $this->statementsMatching('DELETE FROM profile_tags'));
    }

    public function testListTagsRefusesAProfileTheCallerDoesNotOwn(): void
    {
        $response = $this->tags()->listForProfile(
            $this->request(self::USER_B),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('FROM profile_tags'));
    }

    public function testCreateTagRefusesAProfileTheCallerDoesNotOwn(): void
    {
        $request = $this->request(self::USER_B);
        $request->body = ['tag' => 'violence', 'type' => 'blocked'];

        $response = $this->tags()->createForProfile($request, ['profileId' => self::PROFILE_A1]);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('INSERT INTO profile_tags'));
    }

    public function testListTagsCarriesTheRealUuidProfileIdNotZero(): void
    {
        $response = $this->tags()->listForProfile(
            $this->request(self::USER_A),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(200, $response->statusCode);
        $decoded = $this->decode($response);
        $this->assertSame(self::PROFILE_A1, $decoded['tags'][0]['profile_id']);
    }

    // ------------------------------------------------------------------
    // Stream limits — the profile in the path IS the record
    // ------------------------------------------------------------------

    public function testGetStreamLimitsRefusesAProfileTheCallerDoesNotOwn(): void
    {
        $response = $this->streamLimits()->getStreamLimits(
            $this->request(self::USER_B),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('FROM profile_stream_limits'));
    }

    public function testUpdateStreamLimitsDoesNotWriteForAProfileTheCallerDoesNotOwn(): void
    {
        $request = $this->request(self::USER_B);
        $request->body = ['max_concurrent_streams' => 99];

        $response = $this->streamLimits()->updateStreamLimits($request, ['profileId' => self::PROFILE_A1]);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(
            [],
            $this->statementsMatching('INSERT INTO profile_stream_limits'),
            'lifting another profile\'s stream cap must issue no write',
        );
    }

    public function testUpdateStreamLimitsStillWritesForTheOwner(): void
    {
        $request = $this->request(self::USER_A);
        $request->body = ['max_concurrent_streams' => 3];

        $response = $this->streamLimits()->updateStreamLimits($request, ['profileId' => self::PROFILE_A1]);

        $this->assertSame(200, $response->statusCode);
        $this->assertCount(1, $this->statementsMatching('INSERT INTO profile_stream_limits'));
    }

    public function testUpdateStreamLimitsLetsAnAdminWriteForSomeoneElsesProfile(): void
    {
        $request = $this->request(self::USER_ADMIN);
        $request->body = ['max_concurrent_streams' => 3];

        $response = $this->streamLimits()->updateStreamLimits($request, ['profileId' => self::PROFILE_A1]);

        $this->assertSame(200, $response->statusCode);
        $this->assertCount(1, $this->statementsMatching('INSERT INTO profile_stream_limits'));
    }

    public function testGetActiveStreamsRefusesAProfileTheCallerDoesNotOwn(): void
    {
        $response = $this->streamLimits()->getActiveStreams(
            $this->request(self::USER_B),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('FROM active_streams'));
    }

    // ------------------------------------------------------------------
    // The policy itself
    // ------------------------------------------------------------------

    public function testPolicyAllowsTheOwnerAndTheAdminAndRefusesEverybodyElse(): void
    {
        $policy = $this->policy($this->connection());

        $this->assertTrue($policy->canManageProfile(self::USER_A, self::PROFILE_A1));
        $this->assertTrue($policy->canManageProfile(self::USER_A, self::PROFILE_A2));
        $this->assertTrue($policy->canManageProfile(self::USER_ADMIN, self::PROFILE_A1));
        $this->assertFalse($policy->canManageProfile(self::USER_B, self::PROFILE_A1));
        $this->assertFalse($policy->canManageProfile(null, self::PROFILE_A1));
        $this->assertFalse($policy->canManageProfile('', self::PROFILE_A1));
        $this->assertFalse($policy->canManageProfile(self::USER_A, 'ffffffff-9999-4999-8999-ffffffffffff'));
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function schedules(): AccessScheduleController
    {
        $db = $this->connection();
        return new AccessScheduleController(new AccessScheduleService($db), $this->policy($db));
    }

    private function tags(): ProfileTagController
    {
        $db = $this->connection();
        return new ProfileTagController(new ProfileTagService($db), $this->policy($db));
    }

    private function streamLimits(): StreamLimitController
    {
        $db = $this->connection();
        return new StreamLimitController(new StreamSessionService($db), $this->policy($db));
    }

    private function policy(Connection $db): ProfileAccessPolicy
    {
        return new ProfileAccessPolicy(new UserProfileManager($db), new UserRepository($db));
    }

    private function request(?string $userId): Request
    {
        $request = new Request();
        $request->userId = $userId;

        return $request;
    }

    /**
     * A connection that answers the handful of statements these controllers
     * issue, and records every one of them so the "did not mutate" assertions
     * can be made on the STATEMENT rather than only on the status code.
     */
    private function connection(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (mixed $sql = '', mixed $params = null): mixed {
                $sql = is_string($sql) ? $sql : '';
                $this->statements[] = $sql;

                return $this->answer($sql, is_array($params) ? $params : []);
            },
        );
        $db->method('lastInsertId')->willReturn('99');

        return $db;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function answer(string $sql, array $params): mixed
    {
        if (str_contains($sql, 'FROM user_profiles')) {
            $owners = [
                self::PROFILE_A1 => self::USER_A,
                self::PROFILE_A2 => self::USER_A,
                self::PROFILE_B1 => self::USER_B,
            ];
            $id = is_string($params[0] ?? null) ? $params[0] : '';
            if (!isset($owners[$id])) {
                return [];
            }

            return [['id' => $id, 'user_id' => $owners[$id], 'name' => 'Profile ' . $id]];
        }

        if (str_contains($sql, 'FROM users')) {
            $id = is_string($params[0] ?? null) ? $params[0] : '';

            return $id === self::USER_ADMIN ? [['id' => $id, 'is_admin' => 1, 'status' => 'active']] : [];
        }

        if (str_contains($sql, 'FROM access_schedules')) {
            return $this->scheduleRows($sql, $params);
        }

        if (str_contains($sql, 'FROM profile_tags')) {
            return $this->tagRows($sql, $params);
        }

        if (str_contains($sql, 'FROM profile_stream_limits')) {
            $id = is_string($params[0] ?? null) ? $params[0] : '';

            return [[
                'profile_id' => $id,
                'max_concurrent_streams' => 2,
                'max_total_bandwidth_kbps' => null,
            ]];
        }

        // Writes and everything else: a truthy non-array, as the client returns
        // for a successful statement with no result set.
        return true;
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function scheduleRows(string $sql, array $params): array
    {
        $byId = [
            self::SCHEDULE_A1 => [self::PROFILE_A1, 'Bedtime A1'],
            self::SCHEDULE_A2 => [self::PROFILE_A2, 'Bedtime A2'],
            self::SCHEDULE_B1 => [self::PROFILE_B1, 'Bedtime B1'],
        ];

        $row = static fn (int $id, string $profileId, string $name): array => [
            'id' => (string) $id,
            'profile_id' => $profileId,
            'name' => $name,
            'start_time' => '20:00:00',
            'end_time' => '22:00:00',
            'days_of_week' => 'mon,tue',
            'is_active' => 1,
        ];

        if (str_contains($sql, 'WHERE profile_id = ?')) {
            $profileId = is_string($params[0] ?? null) ? $params[0] : '';
            $rows = [];
            foreach ($byId as $id => [$owner, $name]) {
                if ($owner === $profileId) {
                    $rows[] = $row($id, $owner, $name);
                }
            }

            return $rows;
        }

        $id = is_numeric($params[0] ?? null) ? (int) $params[0] : 0;
        if (!isset($byId[$id])) {
            return [];
        }

        return [$row($id, $byId[$id][0], $byId[$id][1])];
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function tagRows(string $sql, array $params): array
    {
        $byId = [
            self::TAG_A1 => [self::PROFILE_A1, 'violence'],
            self::TAG_A2 => [self::PROFILE_A2, 'nudity'],
            self::TAG_B1 => [self::PROFILE_B1, 'language'],
        ];

        $row = static fn (int $id, string $profileId, string $tag): array => [
            'id' => (string) $id,
            'profile_id' => $profileId,
            'tag' => $tag,
            'tag_type' => 'blocked',
        ];

        if (str_contains($sql, 'WHERE profile_id = ?')) {
            $profileId = is_string($params[0] ?? null) ? $params[0] : '';
            $rows = [];
            foreach ($byId as $id => [$owner, $tag]) {
                if ($owner === $profileId) {
                    $rows[] = $row($id, $owner, $tag);
                }
            }

            return $rows;
        }

        $id = is_numeric($params[0] ?? null) ? (int) $params[0] : 0;
        if (!isset($byId[$id])) {
            return [];
        }

        return [$row($id, $byId[$id][0], $byId[$id][1])];
    }

    /**
     * @return list<string>
     */
    private function statementsMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (string $sql): bool => str_contains($sql, $needle),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->body, true) ?? [];

        return $decoded;
    }
}
