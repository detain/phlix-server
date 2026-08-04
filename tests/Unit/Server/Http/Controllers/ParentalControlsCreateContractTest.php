<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Access\AccessScheduleService;
use Phlix\Access\ProfileAccessPolicy;
use Phlix\Access\ProfileTagService;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Controllers\AccessScheduleController;
use Phlix\Server\Http\Controllers\Admin\AdminProfileController;
use Phlix\Server\Http\Controllers\ProfileTagController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S233 — the parental-controls CREATE contract.
 *
 * S208 made the routes resolve and S209 made the page open; neither made a
 * create succeed. Three separate defects sat between the admin SPA and a
 * created row, and this file pins each of them **separately** so that a single
 * over-broad assertion cannot stand in for all three.
 *
 * ## Why the payload SHAPE is the whole point of this file
 *
 * `ProfileTagController::createForProfile` read `$data['type']`. Every existing
 * test that exercised it posted `['type' => 'blocked']`, so every one of them
 * was green while the admin SPA — which posts `tag_type`, the column name —
 * got a 400 on every single attempt. A test is only evidence about production
 * if its fixture is the payload production actually sends, so the bodies below
 * are transcribed from the clients rather than invented:
 *
 *  - **admin SPA** (`phlix-ui/src/api/admin/users.ts::addProfileTag`, measured at
 *    `23b74860`) posts `{ tag, tag_type }`.
 *  - **console client** (`phlix-console-client/src/Api/Admin/AdminClient.php::addProfileTag`,
 *    measured at `049ea65`) posts `{ tag, type }` and reads only `message`.
 *
 * Those two spellings are why the fix is ADDITIVE and not a rename: reading only
 * `type` 400s the SPA, reading only `tag_type` 400s the console client, and a
 * brand-new console `ParentalControlsScreen` calls the same method. Both
 * directions are pinned below, so a later "tidy-up" to a single key reddens.
 *
 * ## Disjoint pins
 *
 * - **(a) body field** — the `type` / `tag_type` spelling. Asserted on the status
 *   code and on the INSERT that reached the connection, and deliberately read
 *   back through `tag_id`, the key that predates this step, so that removing the
 *   defect-(b) fix cannot redden a defect-(a) test.
 * - **(b) response key** — `id` alongside `tag_id` / `schedule_id`. Driven with
 *   the CONSOLE payload, which parses with or without the defect-(a) fix, so
 *   that reinstating defect (a) cannot redden a defect-(b) test.
 * - **(c) profile-create id** — `(int)` applied to a CHAR(36) UUID. A different
 *   controller entirely.
 *
 * Exactly one test, {@see self::testTheAdminSpaCreateCallSucceedsEndToEndWithTheExactBodyItSends},
 * spans (a) and (b) on purpose: it is the acceptance criterion itself ("POSTing
 * the SPA's exact body creates a tag and returns a key the SPA reads") and there
 * is no honest way to assert that without touching both halves.
 */
final class ParentalControlsCreateContractTest extends TestCase
{
    private const USER_A = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';

    /** Owned by USER_A. */
    private const PROFILE_A1 = 'a1a1a1a1-1111-4111-8111-a1a1a1a1a1a1';

    /** @var list<array{sql: string, params: array<int|string, mixed>}> Every statement the controllers issued. */
    private array $statements = [];

    /** @var list<array<string, mixed>> The in-memory `profile_tags` table. */
    private array $tagRows = [];

    /** @var list<array<string, mixed>> The in-memory `access_schedules` table. */
    private array $scheduleRows = [];

    /** Emulates AUTO_INCREMENT / LAST_INSERT_ID(). */
    private int $lastInsertId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statements = [];
        $this->tagRows = [];
        $this->scheduleRows = [];
        $this->lastInsertId = 0;
    }

    // ------------------------------------------------------------------
    // DEFECT (a) — the body field the client never sent
    // ------------------------------------------------------------------

    /**
     * The exact body `phlix-ui`'s `addProfileTag` puts on the wire.
     *
     * Note what is NOT in it: there is no `type` key at all. That is the whole
     * defect — the handler read a key the SPA has never sent, so `validateTagType`
     * received null and the SPA got a 400 for every tag it ever tried to add.
     */
    public function testTheAdminSpaTagPayloadCreatesATag(): void
    {
        $response = $this->tags()->createForProfile(
            $this->request(self::USER_A, ['tag' => 'violence', 'tag_type' => 'blocked']),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(201, $response->statusCode);
        $body = $this->decode($response);
        // Read through the key that predates S233, so this pin is about the
        // request body and nothing else.
        $this->assertSame(1, $body['tag_id']);

        // The status code alone would also be produced by a handler that
        // answered 201 without writing, so assert the write itself.
        $inserts = $this->statementsMatching('INSERT INTO profile_tags');
        $this->assertCount(1, $inserts);
        $this->assertSame([self::PROFILE_A1, 'violence', 'blocked'], $inserts[0]['params']);
    }

    /**
     * The exact body the SHIPPED console client puts on the wire.
     *
     * This is the test that makes the fix additive rather than a rename. If a
     * later change moves the handler to `tag_type` only, this reddens — which is
     * precisely the breakage that would otherwise reach the console client's
     * in-flight `ParentalControlsScreen` silently.
     */
    public function testTheConsoleClientTagPayloadStillCreatesATag(): void
    {
        $response = $this->tags()->createForProfile(
            $this->request(self::USER_A, ['tag' => 'nudity', 'type' => 'allowed']),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(201, $response->statusCode);
        $this->assertSame(1, $this->decode($response)['tag_id']);

        $inserts = $this->statementsMatching('INSERT INTO profile_tags');
        $this->assertCount(1, $inserts);
        $this->assertSame([self::PROFILE_A1, 'nudity', 'allowed'], $inserts[0]['params']);
    }

    /**
     * Accepting two spellings must not become accepting anything at all.
     */
    public function testATagBodyCarryingNeitherSpellingIsStillRefused(): void
    {
        $response = $this->tags()->createForProfile(
            $this->request(self::USER_A, ['tag' => 'violence']),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(400, $response->statusCode);
        $this->assertStringContainsString('tag_type', (string) $this->decode($response)['error']);
        $this->assertSame([], $this->statementsMatching('INSERT INTO profile_tags'));
    }

    /**
     * An invalid value must be refused under EITHER spelling — otherwise the
     * fallback would have widened the accepted vocabulary rather than the
     * accepted key names.
     */
    public function testAnInvalidTagTypeIsRefusedUnderTheTagTypeSpelling(): void
    {
        $response = $this->tags()->createForProfile(
            $this->request(self::USER_A, ['tag' => 'violence', 'tag_type' => 'bogus']),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(400, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('INSERT INTO profile_tags'));
    }

    public function testAnInvalidTagTypeIsRefusedUnderTheTypeSpelling(): void
    {
        $response = $this->tags()->createForProfile(
            $this->request(self::USER_A, ['tag' => 'violence', 'type' => 'bogus']),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(400, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('INSERT INTO profile_tags'));
    }

    /**
     * Precedence: an explicitly present `tag_type` wins, even when it is invalid.
     *
     * A `$data['type'] ?? $data['tag_type']` ordering, or a `?:` chain that
     * treats an invalid value as absent, would silently honour a stale `type`
     * key the caller did not mean — writing 'blocked' when the caller asked for
     * 'bogus'. That is a wrong write, not a refusal, so it is pinned on the
     * absence of the INSERT and not only on the status.
     */
    public function testAnInvalidTagTypeDoesNotFallThroughToAStaleTypeKey(): void
    {
        $response = $this->tags()->createForProfile(
            $this->request(self::USER_A, [
                'tag' => 'violence',
                'tag_type' => 'bogus',
                'type' => 'blocked',
            ]),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(400, $response->statusCode);
        $this->assertSame([], $this->statementsMatching('INSERT INTO profile_tags'));
    }

    /**
     * The round trip: the SPA's body goes in, and the tag comes back out of the
     * list endpoint the same page reads on refresh.
     *
     * A 201 by itself has been wrong before; this drives the created row back
     * through `ProfileTagService::getTagsForProfile()` and the list handler so
     * the tag type that was persisted is the tag type that was asked for.
     */
    public function testTheAdminSpaTagPayloadRoundTripsIntoTheListEndpoint(): void
    {
        $controller = $this->tags();

        $created = $controller->createForProfile(
            $this->request(self::USER_A, ['tag' => 'gore', 'tag_type' => 'blocked']),
            ['profileId' => self::PROFILE_A1],
        );
        $this->assertSame(201, $created->statusCode);

        $listed = $controller->listForProfile(
            $this->request(self::USER_A, []),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(200, $listed->statusCode);
        /** @var list<array<string, mixed>> $tags */
        $tags = $this->decode($listed)['tags'];
        $this->assertCount(1, $tags);
        $this->assertSame('gore', $tags[0]['tag']);
        $this->assertSame('blocked', $tags[0]['tag_type']);
        $this->assertSame(self::PROFILE_A1, $tags[0]['profile_id']);
    }

    // ------------------------------------------------------------------
    // DEFECT (b) — the response key the client never read
    // ------------------------------------------------------------------

    /**
     * Driven with the CONSOLE body on purpose: `{tag, type}` parses with or
     * without the defect-(a) fix, so this test says something about the response
     * shape alone.
     */
    public function testTagCreateReturnsTheNewIdUnderBothIdKeys(): void
    {
        $response = $this->tags()->createForProfile(
            $this->request(self::USER_A, ['tag' => 'violence', 'type' => 'blocked']),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(201, $response->statusCode);
        $body = $this->decode($response);
        // `phlix-ui` declares `Promise<{ id: number; message: string }>`.
        $this->assertArrayHasKey('id', $body);
        $this->assertSame(1, $body['id']);
        // The pre-S233 key stays: dropping it is the only change that could
        // break a consumer nobody has enumerated.
        $this->assertArrayHasKey('tag_id', $body);
        $this->assertSame($body['tag_id'], $body['id']);
    }

    public function testScheduleCreateReturnsTheNewIdUnderBothIdKeys(): void
    {
        $response = $this->schedules()->createForProfile(
            $this->request(self::USER_A, self::spaSchedulePayload()),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(201, $response->statusCode);
        $body = $this->decode($response);
        $this->assertArrayHasKey('id', $body);
        $this->assertSame(1, $body['id']);
        $this->assertArrayHasKey('schedule_id', $body);
        $this->assertSame($body['schedule_id'], $body['id']);
    }

    // ------------------------------------------------------------------
    // (a) + (b) together — the acceptance criterion, stated once
    // ------------------------------------------------------------------

    /**
     * "POSTing the SPA's exact body creates a tag and returns a key the SPA
     * reads." This is the only test in the file that spans two defects, and it
     * does so because the criterion itself does.
     */
    public function testTheAdminSpaCreateCallSucceedsEndToEndWithTheExactBodyItSends(): void
    {
        $controller = $this->tags();

        // Verbatim from phlix-ui: `{ tag, tag_type: tagType }`.
        $spaBody = ['tag' => 'horror', 'tag_type' => 'blocked'];
        $this->assertArrayNotHasKey('type', $spaBody, 'fixture guard: the SPA never sends `type`');

        $response = $controller->createForProfile(
            $this->request(self::USER_A, $spaBody),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(201, $response->statusCode);
        $body = $this->decode($response);
        $this->assertSame(1, $body['id']);

        // …and the id handed back addresses the row that was written.
        $listed = $this->decode($controller->listForProfile(
            $this->request(self::USER_A, []),
            ['profileId' => self::PROFILE_A1],
        ));
        /** @var list<array<string, mixed>> $tags */
        $tags = $listed['tags'];
        $this->assertSame($body['id'], $tags[0]['id']);
    }

    /**
     * The schedule half of the same journey. The SPA and the console client send
     * an identical snake_case body here, so there is no defect-(a) analogue for
     * schedules — this is a plain regression guard on the write path.
     */
    public function testTheAdminSpaSchedulePayloadRoundTripsIntoTheListEndpoint(): void
    {
        $controller = $this->schedules();

        $created = $controller->createForProfile(
            $this->request(self::USER_A, self::spaSchedulePayload()),
            ['profileId' => self::PROFILE_A1],
        );
        $this->assertSame(201, $created->statusCode);

        $listed = $controller->listForProfile(
            $this->request(self::USER_A, []),
            ['profileId' => self::PROFILE_A1],
        );

        $this->assertSame(200, $listed->statusCode);
        /** @var list<array<string, mixed>> $schedules */
        $schedules = $this->decode($listed)['schedules'];
        $this->assertCount(1, $schedules);
        $this->assertSame('Bedtime', $schedules[0]['name']);
        $this->assertSame(['mon', 'tue'], $schedules[0]['days_of_week']);
        $this->assertSame(self::PROFILE_A1, $schedules[0]['profile_id']);
    }

    // ------------------------------------------------------------------
    // DEFECT (c) — `(int)` applied to a CHAR(36) UUID
    // ------------------------------------------------------------------

    /**
     * `UserProfileManager::create()` is typed `: string` and returns the UUID it
     * generated for `user_profiles.id`. `(int)` on a UUID beginning with a hex
     * letter is 0 — an id that addresses no row, shipped with a 201.
     *
     * The pre-existing happy-path test asserted only `assertArrayHasKey('profile_id')`,
     * which is true of `0` as well, so it was green throughout.
     */
    public function testProfileCreateReturnsTheUuidTheManagerGenerated(): void
    {
        $uuid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $response = $this->createProfileReturning($uuid);

        $this->assertSame(201, $response->statusCode);
        $this->assertSame($uuid, $this->decode($response)['profile_id']);
    }

    /**
     * A second SHAPE, not merely a second case. A UUID beginning with a decimal
     * digit does not cast to 0 — it casts to that leading digit run — so a fix
     * asserted only against `!== 0`, or only against a letter-leading UUID,
     * would miss the digit-leading half of the corpus. Roughly a third of v4
     * UUIDs start with a decimal digit.
     */
    public function testProfileCreateDoesNotTruncateAUuidThatBeginsWithDigits(): void
    {
        $uuid = '7318a4c2-9b0d-4f1e-8a55-6d2c19e07b44';
        $response = $this->createProfileReturning($uuid);

        $this->assertSame(201, $response->statusCode);
        $profileId = $this->decode($response)['profile_id'];
        $this->assertSame($uuid, $profileId);
        // Spelt out because `(int) '7318a4c2-…'` is 7318, not 0 — a value that
        // looks far more like a plausible id than 0 does.
        $this->assertNotSame(7318, $profileId);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * The body `phlix-ui`'s `createProfileSchedule` puts on the wire.
     *
     * @return array<string, mixed>
     */
    private static function spaSchedulePayload(): array
    {
        return [
            'name' => 'Bedtime',
            'start_time' => '20:00',
            'end_time' => '22:00',
            'days_of_week' => ['mon', 'tue'],
            'is_active' => true,
        ];
    }

    private function tags(): ProfileTagController
    {
        $db = $this->connection();

        return new ProfileTagController(new ProfileTagService($db), $this->policy($db));
    }

    private function schedules(): AccessScheduleController
    {
        $db = $this->connection();

        return new AccessScheduleController(new AccessScheduleService($db), $this->policy($db));
    }

    private function policy(Connection $db): ProfileAccessPolicy
    {
        return new ProfileAccessPolicy(new UserProfileManager($db), new UserRepository($db));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(?string $userId, array $body): Request
    {
        $request = new Request();
        $request->userId = $userId;
        $request->body = $body;

        return $request;
    }

    /**
     * Drive `AdminProfileController::createForUser` with a manager whose
     * `create()` returns `$uuid`, exactly as the real one does.
     */
    private function createProfileReturning(string $uuid): Response
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // An unstubbed mock returns 0 here, which would 400 every creation on
        // the `count($existing) >= maxProfiles()` pre-check — a double artifact,
        // not a real state.
        $profileManager->method('maxProfiles')->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->method('findByUserId')->willReturn([]);
        $profileManager->method('create')->willReturn($uuid);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->willReturn(['id' => self::USER_A, 'username' => 'alice']);

        $controller = new AdminProfileController($profileManager, $userRepository);

        return $controller->createForUser(
            $this->request(self::USER_A, ['name' => 'Kid']),
            ['userId' => self::USER_A],
        );
    }

    /**
     * A stateful connection: writes land in an in-memory table and the matching
     * SELECT serves them back, so a create can genuinely be read back rather
     * than being asserted against a canned row.
     */
    private function connection(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (mixed $sql = '', mixed $params = null): mixed {
                $sql = is_string($sql) ? $sql : '';
                $params = is_array($params) ? $params : [];
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return $this->answer($sql, $params);
            },
        );
        $db->method('lastInsertId')->willReturnCallback(fn(): string => (string) $this->lastInsertId);

        return $db;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function answer(string $sql, array $params): mixed
    {
        if (str_contains($sql, 'FROM user_profiles')) {
            $id = is_string($params[0] ?? null) ? $params[0] : '';
            if (strcasecmp($id, self::PROFILE_A1) !== 0) {
                return [];
            }

            return [['id' => $id, 'user_id' => self::USER_A, 'name' => 'Kid']];
        }

        if (str_contains($sql, 'FROM users')) {
            // No admins in this fixture — every allowed call here is the OWNER
            // branch, so an authorization regression cannot hide behind
            // "the caller happened to be an admin".
            return [];
        }

        if (str_contains($sql, 'INSERT INTO profile_tags')) {
            $this->tagRows[] = [
                'id' => (string) ++$this->lastInsertId,
                'profile_id' => is_string($params[0] ?? null) ? $params[0] : '',
                'tag' => is_string($params[1] ?? null) ? $params[1] : '',
                'tag_type' => is_string($params[2] ?? null) ? $params[2] : '',
            ];

            return true;
        }

        if (str_contains($sql, 'INSERT INTO access_schedules')) {
            $this->scheduleRows[] = [
                'id' => (string) ++$this->lastInsertId,
                'profile_id' => is_string($params[0] ?? null) ? $params[0] : '',
                'name' => is_string($params[1] ?? null) ? $params[1] : '',
                'start_time' => is_string($params[2] ?? null) ? $params[2] : '',
                'end_time' => is_string($params[3] ?? null) ? $params[3] : '',
                'days_of_week' => is_string($params[4] ?? null) ? $params[4] : '',
                'is_active' => ($params[5] ?? false) ? 1 : 0,
            ];

            return true;
        }

        if (str_contains($sql, 'FROM profile_tags')) {
            return $this->rowsFor($this->tagRows, $params);
        }

        if (str_contains($sql, 'FROM access_schedules')) {
            return $this->rowsFor($this->scheduleRows, $params);
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>>  $rows
     * @param array<int|string, mixed>    $params
     *
     * @return list<array<string, mixed>>
     */
    private function rowsFor(array $rows, array $params): array
    {
        $profileId = is_string($params[0] ?? null) ? $params[0] : '';

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['profile_id'] ?? null) === $profileId,
        ));
    }

    /**
     * @return list<array{sql: string, params: array<int|string, mixed>}>
     */
    private function statementsMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn(array $entry): bool => str_contains($entry['sql'], $needle),
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
