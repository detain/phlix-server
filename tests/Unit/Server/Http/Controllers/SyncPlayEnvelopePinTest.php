<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Router;
use Phlix\Tests\Support\SyncPlay\SyncPlayEnvelopePinHarness;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\MySQL\Connection;

/**
 * S415-pin — the golden pin for the five SyncPlay REST envelopes, driven
 * through the PRODUCTION router.
 *
 * ## What this file is
 *
 * The server-side twin of the S415 contracts rewrite. The wire authority is
 * `SyncPlayController` + `SyncPlayManager` + `SyncPlaySnapshotService` +
 * `GroupState::getState()` at the sha stamped in
 * `tests/Fixtures/SyncPlay/syncplay-envelope-pin-vectors.json`, and this file
 * is what reddens when that wire drifts: every envelope is asserted as an
 * EXACT ORDERED key-set (never substring, never "contains"), from a dispatch
 * that goes through the router `Application::dispatch()` actually serves —
 * the S239 venue (`ContainerFactory::defaultProviders()`, only the MySQL
 * `Connection` doubled, no database). Before this file the five rails had NO
 * controller test at all: `GroupStateTest` pinned eight scalar state keys,
 * the envelope wrappers, the members-dict shape, `queue`, `created_at`,
 * `last_activity_at` and the list-row vocabulary were entirely unpinned, and
 * `ApplicationRouterWirePathGuardTest` explicitly disclaims the SyncPlay
 * envelopes ("NOT dispatch-covered", its coverage statement).
 *
 * ## Venue per rail (the S345 law: write only what was measured)
 *
 * Every rail below — success AND error arm — was dispatched through
 * `Application::dispatch()` (production router + real controller + real
 * manager + real snapshot service). No rail needed the documented
 * next-best venue: create/join/leave never touch the database in production
 * either (`SyncPlayManager::setSnapshotService()` has zero callers on the
 * HTTP path — SP5), and the two read rails run their REAL SQL against the
 * doubled `Connection`, which answers only the exact two snapshot SELECTs
 * those services emit (any other SQL throws — see the harness docblock).
 *
 * ## Anti-vacuity
 *
 * A rail that silently stops dispatching (registration deleted, a loader's
 * `catch (\Throwable)` swallowing construction) answers with the ROUTER's 404
 * `{error, message}` — or the auth 401 — never with the pinned envelope, so
 * every test below goes RED by name rather than skipping. The explicit
 * guards: {@see testAntiVacuityTheFiveSyncPlayRailsAreRegisteredOnTheProductionRouter()}
 * (route table floor + exact presence),
 * {@see testTheAuthGateAndTheRouterMissStayDistinguishableFromHandlerArms()}
 * (the refusal spellings this file must NOT be quietly reading), and the
 * unknown-SQL throw inside the harness (a new DB dependency reddens the run
 * instead of returning null and mis-shaping the envelope).
 *
 * ## Mutation proof discipline (S404 rules)
 *
 * Renaming ONE key on the EMISSION side reddens that rail's test by name:
 * the five proofs (SyncPlaySnapshotService.php:152, SyncPlayController.php
 * :107/:135/:174/:209) are pasted in the lane record, not in this file —
 * a comment cannot prove anything, the CI run can.
 *
 * @see \Phlix\Tests\Support\SyncPlay\SyncPlayEnvelopePinHarness the venue
 * @see \Phlix\Tests\Unit\Server\Core\ApplicationRouterWirePathGuardTest the S239 precedent
 * @see /home/sites/phlix/steps/s404.prompt.md the proven golden-vector method
 */
final class SyncPlayEnvelopePinTest extends TestCase
{
    /** The five rails, in their EXACT registered spellings. */
    private const RAIL_LIST = 'GET /api/v1/syncplay/groups';
    private const RAIL_CREATE = 'POST /api/v1/syncplay/groups';
    private const RAIL_GET = 'GET /api/v1/syncplay/groups/{id}';
    private const RAIL_JOIN = 'POST /api/v1/syncplay/groups/{id}/join';
    private const RAIL_LEAVE = 'POST /api/v1/syncplay/groups/{id}/leave';

    /**
     * Anti-vacuity floor copied in rationale (not by reference) from S239:
     * the production router composes 353 rails; a hand-rolled container
     * yields 53. Below this floor the table is hollow and nothing here means
     * anything.
     */
    private const MIN_EXPECTED_ROUTES = 300;

    /** The committed fixture's provenance marker. */
    private const FIXTURE_MARKER = 'syncplay-pin-v1';

    private string $fixturePath;
    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private Connection $connection;
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->fixturePath = dirname(__DIR__, 4) . '/Fixtures/SyncPlay/syncplay-envelope-pin-vectors.json';

        $this->tempDir = sys_get_temp_dir() . '/phlix_s415pin_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);
        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents(
            $this->loggerConfigPath,
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n"
        );

        $this->connection = $this->createMock(Connection::class);
        SyncPlayEnvelopePinHarness::configureConnection($this->connection);

        $poolStub = $this->createMock(ConnectionPool::class);
        $poolStub->method('getPooledConnection')->willReturn($this->connection);

        $container = SyncPlayEnvelopePinHarness::buildContainer($this->connection, [
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ]);
        SyncPlayEnvelopePinHarness::seedConnectionPool($this->connection);

        $this->application = SyncPlayEnvelopePinHarness::buildApplication($poolStub, $container);
    }

    protected function tearDown(): void
    {
        // S439: the container graph this test resolves constructs MediaAssetJobStore
        // and SimilarityJobStore through MediaServicesProvider's factories at the
        // production default queue paths, and their constructors mint the shared
        // /tmp directories. Sweep them so the suite leaves zero residue.
        foreach (['phlix_media_asset_jobs', 'phlix_similarity_jobs'] as $sharedQueue) {
            $sharedDir = sys_get_temp_dir() . '/' . $sharedQueue;
            if (is_dir($sharedDir)) {
                foreach (glob($sharedDir . '/*') ?: [] as $queued) {
                    @unlink($queued);
                }
                @rmdir($sharedDir);
            }
        }
        SyncPlayEnvelopePinHarness::resetConnectionPool();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. Anti-vacuity: the production table really carries these five rails
    // -----------------------------------------------------------------

    public function testAntiVacuityTheFiveSyncPlayRailsAreRegisteredOnTheProductionRouter(): void
    {
        $router = $this->productionRouter();
        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();

        $count = 0;
        foreach ($routes as $entries) {
            $count += count($entries);
        }
        $this->assertGreaterThanOrEqual(
            self::MIN_EXPECTED_ROUTES,
            $count,
            'ANTI-VACUITY: the composed Application route table holds ' . $count . ' routes — under '
            . self::MIN_EXPECTED_ROUTES . ' this harness stopped reading the production container '
            . '(a hand-rolled one yields 53, S164) and every pin below would be vacuous.'
        );

        // Exact array-key lookups on the LITERAL registered paths — never
        // containment: a mutated superstring contains the real path (S37/S236).
        $byVerbAndLiteral = [];
        foreach ($routes as $verb => $entries) {
            foreach ($entries as $entry) {
                $this->assertIsArray($entry);
                $this->assertIsString($entry['path'] ?? null);
                $byVerbAndLiteral[$verb . ' ' . $entry['path']] = $entry;
            }
        }

        $rails = [
            self::RAIL_LIST => 'listGroups',
            self::RAIL_CREATE => 'createGroup',
            self::RAIL_GET => 'getGroup',
            self::RAIL_JOIN => 'joinGroup',
            self::RAIL_LEAVE => 'leaveGroup',
        ];
        foreach ($rails as $rail => $method) {
            $this->assertArrayHasKey(
                $rail,
                $byVerbAndLiteral,
                'Application must register ' . $rail . ' under exactly that literal (Application::'
                . 'loadSyncPlayRoutes, the S415 authority rails).'
            );

            $handler = $byVerbAndLiteral[$rail]['handler'];
            $this->assertIsArray($handler);
            $this->assertIsObject($handler[0]);
            $this->assertSame('SyncPlayController', $this->shortName($handler[0]::class));
            $this->assertSame($method, $handler[1]);

            $names = [];
            foreach ($byVerbAndLiteral[$rail]['middleware'] ?? [] as $item) {
                $this->assertIsObject($item);
                $names[] = $this->shortName($item::class);
            }
            $this->assertSame(['AuthMiddleware'], $names, $rail . ' must stay auth-gated');
        }
    }

    // -----------------------------------------------------------------
    // 2. Rail 1 — GET /api/v1/syncplay/groups → {groups}, six-key list rows
    // -----------------------------------------------------------------

    public function testListGroupsRailEmitsExactlyGroupsWrappingSixOrderedListRowKeys(): void
    {
        [$status, $body] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'GET',
            '/api/v1/syncplay/groups',
            [],
            'pin_user'
        );

        $this->assertSame(200, $status, 'GET groups must dispatch to its handler; 401/404 spellings '
            . 'are pinned as DISTINGUISHABLE refusals elsewhere in this file.');
        $this->assertSame(['groups'], array_keys($body), 'the list envelope is EXACTLY {groups}');
        $this->assertIsArray($body['groups']);
        $this->assertCount(
            2,
            $body['groups'],
            'EMPTY-SET DEFENCE: the doubled DB was seeded with exactly two rows; a silently empty '
            . 'list here means the rail stopped round-tripping, not that the server is idle.'
        );

        foreach ($body['groups'] as $row) {
            $this->assertSame(
                SyncPlayEnvelopePinHarness::LIST_ROW_KEYS,
                array_keys($row),
                'each list row carries EXACTLY the six list-row keys, in order '
                . '(SyncPlaySnapshotService.php:149-155) — the list-row vocabulary lives HERE only'
            );
        }

        // Whole-value equality on the deterministic rail: casts included
        // ((int) member_count, (bool) has_password/is_playing, string|null
        // current_media), not just key names.
        $this->assertSame(
            SyncPlayEnvelopePinHarness::expectedListRows(),
            $body['groups'],
            'the REAL listGroups() mapping of the seeded rows drifted — values, types AND order'
        );
    }

    // -----------------------------------------------------------------
    // 3. Rail 2 — POST /api/v1/syncplay/groups → {success, group}
    // -----------------------------------------------------------------

    public function testCreateGroupRailEmitsSuccessThenGroupWithTheTwelveOrderedStateKeys(): void
    {
        [$status, $body] = $this->createGroup('Pin Host Group', 'pin_host', 'Host One');

        $this->assertSame(200, $status);
        $this->assertSame(
            ['success', 'group'],
            array_keys($body),
            'the create envelope is EXACTLY {success, group}, in order'
        );
        $this->assertTrue($body['success']);

        $group = $body['group'];
        $this->assertIsArray($group);
        $this->assertSame(
            SyncPlayEnvelopePinHarness::GROUP_STATE_KEYS,
            array_keys($group),
            'getState() must emit exactly the 12 pinned keys in real order — playback_position BEFORE '
            . 'playback_state (GroupState.php:689-702); "13 keys" in the S415 prose is a miscount of '
            . 'its own enumeration; code + contracts vectors agree on 12'
        );

        $this->assertMatchesRegularExpression('/^sp_[0-9a-f]{16}$/', $group['group_id']);
        $this->assertSame('Pin Host Group', $group['group_name']);
        $this->assertSame(1, $group['member_count']);
        $this->assertSame(['pin_host'], array_keys($group['members']), 'members is a DICT keyed by member id');
        $this->assertSame(SyncPlayEnvelopePinHarness::MEMBER_VALUE_KEYS, array_keys($group['members']['pin_host']));
        $this->assertSame('pin_host', $group['members']['pin_host']['id']);
        $this->assertTrue($group['members']['pin_host']['is_host']);
        $this->assertIsInt($group['members']['pin_host']['joined_at']);
        $this->assertSame('pin_host', $group['host_id']);
        $this->assertNull($group['current_media_id']);
        $this->assertSame(0, $group['current_media_duration']);
        $this->assertSame(0, $group['playback_position']);
        $this->assertSame('stopped', $group['playback_state']);
        $this->assertSame([], $group['queue']);
        $this->assertIsInt($group['created_at']);
        $this->assertIsInt($group['last_activity_at']);
    }

    // -----------------------------------------------------------------
    // 4. Rail 3 — GET /api/v1/syncplay/groups/{id} → {group}, full state
    // -----------------------------------------------------------------

    public function testGetGroupRailEmitsGroupOnlyEnvelopeWithTheCompleteDeterministicStateRoundTrip(): void
    {
        [$status, $body] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'GET',
            '/api/v1/syncplay/groups/' . SyncPlayEnvelopePinHarness::SEEDED_GROUP_ID,
            [],
            'pin_user'
        );

        $this->assertSame(200, $status);
        $this->assertSame(['group'], array_keys($body), 'the single-read envelope is EXACTLY {group}');

        $group = $body['group'];
        $this->assertSame(SyncPlayEnvelopePinHarness::GROUP_STATE_KEYS, array_keys($group));

        // The seeded MINIMAL serialized_state went through the REAL
        // json_decode -> GroupState::deserialize() -> getState() path; these
        // values are its emission, keys AND order AND types pinned whole.
        // (This mirrors the contracts vectors' getGroup rail exactly: two
        // members, stopped, no media, empty queue. The POPULATED state —
        // media, queue entry, playing — is pinned whole by
        // {@see testGroupStateFullRoundTripEmitsEveryPinnedNestedShape()}.)
        $frozen = SyncPlayEnvelopePinHarness::FROZEN_CLOCK;
        $this->assertSame([
            'group_id' => SyncPlayEnvelopePinHarness::SEEDED_GROUP_ID,
            'group_name' => 'Pin Vector Group',
            'member_count' => 2,
            'members' => [
                'alpha' => ['id' => 'alpha', 'name' => 'Alpha', 'is_host' => true, 'joined_at' => $frozen],
                'beta' => ['id' => 'beta', 'name' => 'Beta', 'is_host' => false, 'joined_at' => $frozen],
            ],
            'host_id' => 'alpha',
            'current_media_id' => null,
            'current_media_duration' => 0,
            'playback_position' => 0,
            'playback_state' => 'stopped',
            'queue' => [],
            'created_at' => $frozen,
            'last_activity_at' => $frozen,
        ], $group, 'the deserialize()->getState() round trip changed shape');
    }

    // -----------------------------------------------------------------
    // 4b. The POPULATED getState() witness — every nested shape at once
    // -----------------------------------------------------------------

    public function testGroupStateFullRoundTripEmitsEveryPinnedNestedShape(): void
    {
        [$status, $body] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'GET',
            '/api/v1/syncplay/groups/' . SyncPlayEnvelopePinHarness::SEEDED_FULL_GROUP_ID,
            [],
            'pin_user'
        );

        $this->assertSame(200, $status);
        $this->assertSame(['group'], array_keys($body));

        $group = $body['group'];
        $this->assertSame(SyncPlayEnvelopePinHarness::GROUP_STATE_KEYS, array_keys($group));

        $frozen = SyncPlayEnvelopePinHarness::FROZEN_CLOCK;
        $this->assertSame([
            'group_id' => SyncPlayEnvelopePinHarness::SEEDED_FULL_GROUP_ID,
            'group_name' => 'Pin Full Group',
            'member_count' => 2,
            'members' => [
                'alpha' => ['id' => 'alpha', 'name' => 'Alpha', 'is_host' => true, 'joined_at' => $frozen],
                'beta' => ['id' => 'beta', 'name' => 'Beta', 'is_host' => false, 'joined_at' => $frozen],
            ],
            'host_id' => 'alpha',
            'current_media_id' => 'media_42',
            'current_media_duration' => 3600000,
            'playback_position' => 90000,
            'playback_state' => 'playing',
            'queue' => [
                [
                    'media_id' => 'media_43',
                    'media_info' => ['title' => 'Queued Feature', 'kind' => 'movie'],
                    'added_at' => $frozen,
                    'added_by' => 'alpha',
                ],
            ],
            'created_at' => $frozen,
            'last_activity_at' => $frozen,
        ], $group, 'the POPULATED getState() round trip changed shape — playback_position must stay '
            . 'before playback_state and the queue item keys must stay [media_id, media_info, added_at, added_by]');

        $this->assertArrayNotHasKey(
            'connection_id',
            $group['members']['alpha'],
            'the internal connection_id must NEVER leak into the wire member value'
        );
        $this->assertArrayNotHasKey('is_active', $group['members']['alpha']);
    }

    // -----------------------------------------------------------------
    // 5. Rail 4 — POST /api/v1/syncplay/groups/{id}/join → {success, group}
    // -----------------------------------------------------------------

    public function testJoinGroupRailEmitsSuccessThenGroupWithDictMembersKeyedByMemberId(): void
    {
        [, $created] = $this->createGroup('Pin Join Group', 'pin_host', 'Host One');
        $groupId = (string) $created['group']['group_id'];

        [$status, $body] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups/' . $groupId . '/join',
            ['memberId' => 'pin_guest', 'memberName' => 'Guest Two'],
            'pin_guest'
        );

        $this->assertSame(200, $status);
        $this->assertSame(
            ['success', 'group'],
            array_keys($body),
            'the join envelope is EXACTLY {success, group}, in order'
        );
        $this->assertTrue($body['success']);

        $group = $body['group'];
        $this->assertSame(SyncPlayEnvelopePinHarness::GROUP_STATE_KEYS, array_keys($group));
        $this->assertSame(2, $group['member_count']);
        $this->assertSame(
            ['pin_host', 'pin_guest'],
            array_keys($group['members']),
            'members must stay a dict keyed by member id, in join order'
        );
        foreach ($group['members'] as $memberId => $member) {
            $this->assertSame(SyncPlayEnvelopePinHarness::MEMBER_VALUE_KEYS, array_keys($member));
            $this->assertSame($memberId, $member['id'], 'the dict key and the value id must agree');
            $this->assertSame($memberId === 'pin_host', $member['is_host']);
        }
    }

    // -----------------------------------------------------------------
    // 6. Rail 5 — POST /api/v1/syncplay/groups/{id}/leave → {success, message}
    // -----------------------------------------------------------------

    public function testLeaveGroupRailEmitsSuccessThenMessageOnly(): void
    {
        [, $created] = $this->createGroup('Pin Leave Group', 'pin_host', 'Host One');
        $groupId = (string) $created['group']['group_id'];
        SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups/' . $groupId . '/join',
            ['memberId' => 'pin_guest', 'memberName' => 'Guest Two'],
            'pin_guest'
        );

        [$status, $body] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups/' . $groupId . '/leave',
            ['memberId' => 'pin_guest'],
            'pin_guest'
        );

        $this->assertSame(200, $status);
        $this->assertSame(
            ['success', 'message'],
            array_keys($body),
            'the leave envelope is EXACTLY {success, message} — no group key rides along'
        );
        $this->assertTrue($body['success']);
        $this->assertSame('Guest Two left the group', $body['message']);
    }

    // -----------------------------------------------------------------
    // 7. The {error} arms — @400 on three rails, @404 on the single read
    // -----------------------------------------------------------------

    public function testEveryErrorArmCarriesExactlyTheErrorKey(): void
    {
        // create @400 — missing name (SyncPlayController.php:90).
        [$createStatus, $createBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups',
            ['name' => ''],
            'pin_user'
        );
        $this->assertSame(400, $createStatus);
        $this->assertSame(['error'], array_keys($createBody));

        // join @400 — unknown group reaches the manager miss arm (:171).
        [$joinStatus, $joinBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups/sp_does_not_exist/join',
            ['memberId' => 'pin_guest', 'memberName' => 'Guest'],
            'pin_guest'
        );
        $this->assertSame(400, $joinStatus);
        $this->assertSame(['error'], array_keys($joinBody));

        // leave @400 — member in no group (:204).
        [$leaveStatus, $leaveBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups/anything/leave',
            ['memberId' => 'pin_stranger'],
            'pin_stranger'
        );
        $this->assertSame(400, $leaveStatus);
        $this->assertSame(['error'], array_keys($leaveBody));

        // get @404 — the HANDLER's own miss (:132), NOT the router's.
        [$getStatus, $getBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'GET',
            '/api/v1/syncplay/groups/sp_does_not_exist',
            [],
            'pin_user'
        );
        $this->assertSame(404, $getStatus);
        $this->assertSame(
            ['error'],
            array_keys($getBody),
            'handler 404 is {error} alone — the router 404 carries {error, message}'
        );
        $this->assertSame('Group not found', $getBody['error']);
    }

    // -----------------------------------------------------------------
    // 8. Refusal controls — the two 404/401 spellings the pins must not be
    //    accidentally reading
    // -----------------------------------------------------------------

    public function testTheAuthGateAndTheRouterMissStayDistinguishableFromHandlerArms(): void
    {
        [$anonStatus, $anonBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'GET',
            '/api/v1/syncplay/groups'
        );
        $this->assertSame(401, $anonStatus, 'the five rails live in the AuthMiddleware group — an empty '
            . 'request must NOT be able to read the envelope');
        $this->assertSame(['error', 'code'], array_keys($anonBody));
        $this->assertSame('auth.required', $anonBody['code']);

        [$missStatus, $missBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups/whatever/leave-MUTATED',
            ['memberId' => 'pin_guest'],
            'pin_guest'
        );
        $this->assertSame(404, $missStatus);
        $this->assertSame(
            ['error', 'message'],
            array_keys($missBody),
            "the ROUTER's miss ({error, message}) must stay distinguishable from every HANDLER arm "
            . '({error} alone / {success, ...}); if it collapses, the success pins above stop proving dispatch'
        );
        $this->assertSame('Not Found', $missBody['error']);
        $this->assertSame('The requested resource was not found', $missBody['message']);
    }

    // -----------------------------------------------------------------
    // 9. The committed fixture == the live emission (same venue, S404 method)
    // -----------------------------------------------------------------

    public function testTheCommittedFixtureMatchesTheLiveEmissionExactly(): void
    {
        $fixture = $this->loadFixture();
        $this->assertSame(self::FIXTURE_MARKER, $fixture['provenance']['marker'] ?? null);
        $this->assertSame(
            SyncPlayEnvelopePinHarness::AUTHORITY_SHA,
            $fixture['provenance']['authoritySha'] ?? null,
            'the fixture was not dumped at the authority sha this pin guards'
        );

        $live = $this->captureRails();

        foreach ($fixture['rails'] as $rail => $recorded) {
            $this->assertArrayHasKey($rail, $live, "fixture names rail '{$rail}' the live capture does not emit");
            $this->assertSame(
                $recorded['status'],
                $live[$rail]['status'],
                "live status drifted from the fixture on rail '{$rail}'"
            );
            $this->assertSame(
                $recorded['keyPaths'],
                SyncPlayEnvelopePinHarness::orderedKeyPaths($live[$rail]['body']),
                "live key ORDER drifted from the fixture on rail '{$rail}'"
            );
            if (array_key_exists('body', $recorded)) {
                $this->assertSame(
                    $recorded['body'],
                    $live[$rail]['body'],
                    "the deterministic body drifted from the fixture on rail '{$rail}'"
                );
            }
        }

        $this->assertSame(
            $fixture['groupState'],
            $live['groupState']['body'],
            'the populated getState() round trip drifted from the fixture'
        );
    }

    // -----------------------------------------------------------------
    // 10. Cross-repo agreement: live == the contracts golden vectors (by digest)
    // -----------------------------------------------------------------

    public function testTheLiveEmissionShapeMatchesTheContractsGoldenVectorsDigest(): void
    {
        $fixture = $this->loadFixture();
        $crossCheck = $fixture['provenance']['contractsCrossCheck'] ?? null;
        $this->assertIsArray($crossCheck, 'fixture lost its contracts cross-check provenance');

        $abstractByRail = [];
        foreach ($this->captureRails() as $rail => $recorded) {
            $abstractByRail[$rail] = SyncPlayEnvelopePinHarness::abstractKeyPaths($recorded['body']);
        }

        $this->assertSame(
            $crossCheck['orderedKeySetDigest'],
            SyncPlayEnvelopePinHarness::keyPathsDigest($abstractByRail),
            'the live server emission shape and phlix-contracts syncplay-envelope-vectors.json '
            . 'no longer agree — same authority, both sides must match; reconcile against the S415 '
            . 'ruling, do NOT silently re-pin either side'
        );
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function createGroup(string $name, string $memberId, string $memberName): array
    {
        return SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups',
            ['name' => $name, 'memberId' => $memberId, 'memberName' => $memberName],
            $memberId
        );
    }

    /**
     * Drive every rail once (same order the dumper uses) and return the
     * decoded emissions keyed by the fixture's rail names.
     *
     * @return array<string, array{status: int, body: array<string, mixed>}>
     */
    private function captureRails(): array
    {
        [, $created] = $this->createGroup('Movie Night', 'pin_host', 'Host One');
        $groupId = (string) $created['group']['group_id'];

        [, $joined] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups/' . $groupId . '/join',
            ['memberId' => 'pin_guest', 'memberName' => 'Guest Two'],
            'pin_guest'
        );

        [$listStatus, $listBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'GET',
            '/api/v1/syncplay/groups',
            [],
            'pin_user'
        );

        [$getStatus, $getBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'GET',
            '/api/v1/syncplay/groups/' . SyncPlayEnvelopePinHarness::SEEDED_GROUP_ID,
            [],
            'pin_user'
        );

        // The populated getState() witness — the `groupState` digest arm, so it
        // compares against the contracts vectors' populated groupState case.
        [$fullStatus, $fullBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'GET',
            '/api/v1/syncplay/groups/' . SyncPlayEnvelopePinHarness::SEEDED_FULL_GROUP_ID,
            [],
            'pin_user'
        );

        [$leaveStatus, $leaveBody] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups/' . $groupId . '/leave',
            ['memberId' => 'pin_guest'],
            'pin_guest'
        );

        [$err400Status, $err400Body] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'POST',
            '/api/v1/syncplay/groups',
            ['name' => ''],
            'pin_user'
        );

        [$err404Status, $err404Body] = SyncPlayEnvelopePinHarness::drive(
            $this->application,
            'GET',
            '/api/v1/syncplay/groups/sp_does_not_exist',
            [],
            'pin_user'
        );

        return [
            'listGroups' => ['status' => $listStatus, 'body' => $listBody],
            'createGroup' => ['status' => 200, 'body' => $created],
            'getGroup' => ['status' => $getStatus, 'body' => $getBody],
            'joinGroup' => ['status' => 200, 'body' => $joined],
            'leaveGroup' => ['status' => $leaveStatus, 'body' => $leaveBody],
            'createGroupError' => ['status' => $err400Status, 'body' => $err400Body],
            'getGroupNotFound' => ['status' => $err404Status, 'body' => $err404Body],
            // Not one of the five rails — the populated getState() body used
            // as the `groupState` fixture arm + digest term (matches the
            // contracts vectors' populated groupState case, not their empty-queue
            // getGroup rail).
            'groupState' => ['status' => $fullStatus, 'body' => $fullBody['group'] ?? ['__missing__' => true]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(): array
    {
        $this->assertFileExists(
            $this->fixturePath,
            'the provenance-stamped pin fixture is missing — '
            . 'scripts/dump-syncplay-envelope-pin-vectors.php regenerates it'
        );
        $decoded = json_decode((string) file_get_contents($this->fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function productionRouter(): Router
    {
        $property = (new ReflectionClass(Application::class))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($this->application);
        $this->assertInstanceOf(
            Router::class,
            $router,
            'ANTI-VACUITY: Application::$router did not hold a Router, so this file could not read '
            . 'the production route table at all.'
        );

        return $router;
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }
}
