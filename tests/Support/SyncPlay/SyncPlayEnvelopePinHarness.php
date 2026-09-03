<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\SyncPlay;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Session\SyncPlay\GroupState;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S415-pin — the shared no-DB venue for the SyncPlay five-envelope golden pin
 * (`SyncPlayEnvelopePinTest` + `scripts/dump-syncplay-envelope-pin-vectors.php`).
 *
 * ## What this venue is
 *
 * The PRODUCTION router: an `Application` built from
 * `ContainerFactory::defaultProviders()` — the same provider stack
 * `public/index.php` and the Workerman daemon build — with ONLY the MySQL
 * `Connection` doubled. This is the `ApplicationRouterWirePathGuardTest` venue
 * (S239) applied to the five SyncPlay rails. Nothing about routing, the
 * controller, the manager, the snapshot service, or `GroupState::getState()`
 * is substituted: every asserted byte in the pin test is emitted by the real
 * production code path on the way out of `Application::dispatch()`.
 *
 * ## How the double reaches the snapshot service
 *
 * `SyncPlaySnapshotService::getDb()` calls the STATIC
 * `ConnectionPool::getConnection('mysql')` — the container override alone
 * would not reach it, and a live MySQL would make this file skip in CI's
 * `test-server` job (no service container there), turning a pin into a
 * non-pin. So the pool's static map is seeded with the SAME doubled
 * `Connection` by reflection before dispatch, and reset in teardown. The
 * reflection reset of exactly those statics is prior art in this repo:
 * `tests/Unit/Server/Core/ApplicationTest.php::resetConnectionPool()`.
 *
 * ## What the mock DB returns, and why that is not a hand-built response
 *
 * The ban is on hand-built RESPONSE arrays. The response is shaped by real
 * code in every case:
 *  - `listGroups()` receives RAW `syncplay_snapshots` rows in the exact
 *    MySQL text-protocol spelling (string numerics, `null` media id) and does
 *    the real `(int)`/`(bool)`/key-rename mapping itself — that mapping
 *    (SyncPlaySnapshotService.php:149-155) is precisely what the pin pins.
 *  - `getGroupState()` receives a `serialized_state` BLOB produced by the
 *    real `GroupState::serialize()` (clock fields then frozen so the fixture
 *    is stable across days) and does the real
 *    `json_decode → GroupState::deserialize()->getState()` emission itself.
 *  - The three mutation rails (create/join/leave) never touch the DB in this
 *    venue at all: `SyncPlayManager::setSnapshotService()` has no caller on
 *    the HTTP path (SP5: REST mutations are local, the WS worker owns
 *    publishing), so `publishSnapshot()` no-ops and the group comes straight
 *    out of the real in-memory manager.
 *
 * Any OTHER SQL reaching the double throws — an unrecognised query means the
 * dispatch path grew a new DB dependency this venue no longer describes, and
 * that must be a loud red, never a silent null.
 *
 * ## Per-request context hygiene
 *
 * `RequestContext` is process-static. Production gives every HTTP request a
 * fresh one; a multi-drive test does not. The global
 * `AccessScheduleMiddleware` answers 403 (after hitting `getActiveProfile()`
 * → the DB double) whenever a stale user id lingers in the context, so
 * `drive()` clears the context before every dispatch — the same hygiene
 * `ApplicationRouterWirePathGuardTest` pins in its own `setUp()`.
 *
 * @see \Phlix\Tests\Unit\Server\Http\Controllers\SyncPlayEnvelopePinTest the pin itself
 * @see \Phlix\Tests\Unit\Server\Core\ApplicationRouterWirePathGuardTest the venue precedent (S239)
 */
final class SyncPlayEnvelopePinHarness
{
    /** Authority sha the S415 ruling was recorded at — stamped into the fixture provenance. */
    public const AUTHORITY_SHA = '0134063318bf601dcc152c6c175368cdf9168378';

    /** Group id of the MINIMAL seeded snapshot — drives the getGroup RAIL. */
    public const SEEDED_GROUP_ID = 'sp_pinvector';

    /**
     * Group id of the FULLY POPULATED seeded snapshot (media, queue, playing) —
     * the `groupState` witness. The contracts vectors make exactly this split
     * (their getGroup rail has `queue: []`; their groupState case is the
     * populated one), and the cross-check digest compares like with like.
     */
    public const SEEDED_FULL_GROUP_ID = 'sp_pinfull';

    /** Frozen clock value for the seeded snapshot so the fixture bytes are stable. */
    public const FROZEN_CLOCK = 1788300111;

    /**
     * `getState()`'s emission in its REAL order — 12 keys. (The S415 prose said
     * "13 keys" while enumerating twelve; the code at GroupState.php:689-702,
     * the contracts golden vectors @ 97bcda06, and this pin all agree on 12.
     * Deviation recorded in the PR.)
     */
    public const GROUP_STATE_KEYS = [
        'group_id',
        'group_name',
        'member_count',
        'members',
        'host_id',
        'current_media_id',
        'current_media_duration',
        'playback_position',
        'playback_state',
        'queue',
        'created_at',
        'last_activity_at',
    ];

    /** The members-dict VALUE shape — exactly these four keys, in this order. */
    public const MEMBER_VALUE_KEYS = ['id', 'name', 'is_host', 'joined_at'];

    /** The list-row shape — exactly these six keys, in this order. */
    public const LIST_ROW_KEYS = ['id', 'name', 'member_count', 'has_password', 'current_media', 'is_playing'];

    private function __construct()
    {
    }

    // -----------------------------------------------------------------
    // Venue construction
    // -----------------------------------------------------------------

    /**
     * Configure the query routing of the doubled `Connection`.
     *
     * Called by BOTH venues so they cannot drift: the PHPUnit test (via
     * `createMock`) and the CLI dumper (via PHPUnit's Generator).
     */
    public static function configureConnection(Connection&\PHPUnit\Framework\MockObject\MockObject $connection): void
    {
        $connection->method('query')
            ->willReturnCallback(
                /**
                 * @param mixed $sql
                 * @param mixed $values
                 * @return list<array<string, mixed>>
                 */
                static function ($sql, $values = []): array {
                    if (!is_string($sql)) {
                        throw new \RuntimeException('SyncPlayEnvelopePin venue: non-string SQL reached the double.');
                    }

                    $isListQuery = str_contains($sql, 'FROM syncplay_snapshots')
                        && str_contains($sql, 'ORDER BY updated_at DESC');
                    if ($isListQuery) {
                        return self::listRows();
                    }

                    if (str_contains($sql, 'SELECT serialized_state FROM syncplay_snapshots')) {
                        $groupId = is_array($values) ? ($values[0] ?? null) : null;
                        if (is_string($groupId) && $groupId === self::SEEDED_GROUP_ID) {
                            $minimal = json_encode(self::serializedState(false), JSON_THROW_ON_ERROR);

                            return [['serialized_state' => $minimal]];
                        }
                        if (is_string($groupId) && $groupId === self::SEEDED_FULL_GROUP_ID) {
                            $populated = json_encode(self::serializedState(true), JSON_THROW_ON_ERROR);

                            return [['serialized_state' => $populated]];
                        }

                        return [];
                    }

                    throw new \RuntimeException(sprintf(
                        "SyncPlayEnvelopePin venue: unexpected SQL reached the Connection double: %s\n"
                        . 'This venue doubles ONLY MySQL and enumerates ONLY the two snapshot SELECTs the five '
                        . 'rails emit. A new query on the dispatch path means the venue no longer describes '
                        . 'production — re-measure before trusting any pin here.',
                        $sql
                    ));
                }
            );
    }

    /**
     * The production container: `defaultProviders()` with ONLY the (already
     * configured) MySQL `Connection` doubled, exactly as S239's venue does.
     *
     * @param array<string, mixed> $appConfig
     */
    public static function buildContainer(Connection $connection, array $appConfig): \Psr\Container\ContainerInterface
    {
        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        return ContainerFactory::create($appConfig, $providers);
    }

    /**
     * Seed the STATIC `ConnectionPool` map so `SyncPlaySnapshotService::getDb()`
     * resolves the same double. Reflection on exactly these statics is prior
     * art (`ApplicationTest::resetConnectionPool()`).
     */
    public static function seedConnectionPool(Connection $connection): void
    {
        $property = (new ReflectionClass(ConnectionPool::class))->getProperty('connections');
        $property->setAccessible(true);
        /** @var array<string, Connection> $existing */
        $existing = $property->getValue(null) ?? [];
        $existing['mysql'] = $connection;
        $property->setValue(null, $existing);
    }

    /** Restore every `ConnectionPool` static so sibling tests see a clean slate. */
    public static function resetConnectionPool(): void
    {
        $ref = new ReflectionClass(ConnectionPool::class);
        foreach (['instance' => null, 'connections' => [], 'configPath' => ''] as $prop => $value) {
            if (!$ref->hasProperty($prop)) {
                continue;
            }
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
    }

    /**
     * The composed production Application (router included) over the doubled
     * Connection. `$poolMock` must be a mock of the pool CLASS for the
     * constructor parameter; the STATIC seed is separate (see class docblock).
     */
    public static function buildApplication(
        ConnectionPool&\PHPUnit\Framework\MockObject\Stub $poolStub,
        \Psr\Container\ContainerInterface $container
    ): Application {
        return new Application($container, [], $poolStub);
    }

    // -----------------------------------------------------------------
    // Driving rails
    // -----------------------------------------------------------------

    /**
     * Dispatch one request through `Application::dispatch()` and return
     * [status, decoded JSON body]. The context reset mirrors production's
     * fresh-coroutine-per-request isolation (see class docblock).
     *
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function drive(
        Application $application,
        string $method,
        string $path,
        array $body = [],
        ?string $userId = null
    ): array {
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->remoteIp = '127.0.0.1';
        $request->body = $body;
        if ($userId !== null) {
            $request->userId = $userId;
        }

        $response = $application->dispatch($request);
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf(
                'SyncPlayEnvelopePin venue: %s %s did not answer with a JSON object (got %s).',
                $method,
                $path,
                get_debug_type($decoded)
            ));
        }

        return [$response->statusCode, $decoded];
    }

    // -----------------------------------------------------------------
    // The seeded snapshot DB content (INPUT — the emission under test is the
    // real service code reading these rows back)
    // -----------------------------------------------------------------

    /**
     * Raw `syncplay_snapshots` rows exactly as the MySQL text protocol would
     * return them: numerics as strings, NULL media id as null.
     *
     * @return list<array<string, string|null>>
     */
    public static function listRows(): array
    {
        return [
            [
                'group_id' => 'sp_pina',
                'group_name' => 'Pinned Public',
                'member_count' => '3',
                'has_password' => '0',
                'current_media_id' => 'media_9',
                'is_playing' => '1',
            ],
            [
                'group_id' => 'sp_pinb',
                'group_name' => 'Pinned Private',
                'member_count' => '1',
                'has_password' => '1',
                'current_media_id' => null,
                'is_playing' => '0',
            ],
        ];
    }

    /**
     * The list envelope `listGroups()`'s REAL mapping of {@see listRows()}
     * must produce — asserted whole (keys, order, and cast types).
     *
     * @return list<array<string, string|int|bool|null>>
     */
    public static function expectedListRows(): array
    {
        return [
            [
                'id' => 'sp_pina',
                'name' => 'Pinned Public',
                'member_count' => 3,
                'has_password' => false,
                'current_media' => 'media_9',
                'is_playing' => true,
            ],
            [
                'id' => 'sp_pinb',
                'name' => 'Pinned Private',
                'member_count' => 1,
                'has_password' => true,
                'current_media' => null,
                'is_playing' => false,
            ],
        ];
    }

    /**
     * A `serialized_state` payload built by the REAL `GroupState::serialize()`
     * of a REAL group, then clock-frozen (created_at / last_activity_at /
     * joined_at / added_at) so the bytes are stable across re-runs. This is
     * DB CONTENT — the emission the pin checks is `getGroupState()`'s real
     * `deserialize()->getState()` of exactly these bytes.
     *
     * The minimal row mirrors the contracts vectors' getGroup RAIL (two
     * members, stopped, no media, empty queue); the full row mirrors their
     * populated `groupState` witness (media, a queue entry, playing) and is
     * stored under {@see SEEDED_FULL_GROUP_ID} so the digest compares like
     * with like.
     *
     * @return array<string, mixed>
     */
    public static function serializedState(bool $full): array
    {
        if ($full) {
            $group = new GroupState(self::SEEDED_FULL_GROUP_ID, 'Pin Full Group');
        } else {
            $group = new GroupState(self::SEEDED_GROUP_ID, 'Pin Vector Group');
        }
        $group->addMember('alpha', ['name' => 'Alpha']);
        $group->addMember('beta', ['name' => 'Beta']);
        $group->setHost('alpha');
        if ($full) {
            $group->setCurrentMedia('media_42', 3600000);
            $group->addToQueue('media_43', ['title' => 'Queued Feature', 'kind' => 'movie']);
            $group->updatePlayback(GroupState::STATE_PLAYING, 90000);
        }

        $serialized = $group->serialize();
        $serialized['created_at'] = self::FROZEN_CLOCK;
        $serialized['last_activity_at'] = self::FROZEN_CLOCK;
        foreach ($serialized['members'] as &$member) {
            $member['joined_at'] = self::FROZEN_CLOCK;
        }
        unset($member);
        foreach ($serialized['playback_queue'] as &$queued) {
            $queued['added_at'] = self::FROZEN_CLOCK;
        }
        unset($queued);

        return $serialized;
    }

    // -----------------------------------------------------------------
    // Ordered-key machinery
    // -----------------------------------------------------------------

    /**
     * Every key path of a decoded response, in EMISSION order.
     *
     * `list`-shaped values recurse with `[i]` indexes so a re-ordered or
     * grown row set reddens too; dict-shaped values recurse with `.key`
     * segments so a renamed nested key reddens exactly where it lives.
     *
     * @param array<array-key, mixed> $value
     * @return list<string>
     */
    public static function orderedKeyPaths(array $value, string $prefix = ''): array
    {
        return self::keyPaths($value, $prefix, false);
    }

    /**
     * The same walk with runtime identity abstracted away: list indexes
     * become `[*]` and the member ids keying the `members` dict become `*`.
     * That is the spelling shared by BOTH golden-vector files — the contracts
     * fixture seeds its rails with `member_host`/`member_guest`, this venue
     * uses `pin_host`/`pin_guest` and a two-row list against their one — so
     * the cross-repo digest compares EMISSION SHAPE, not fixture input.
     *
     * @param array<array-key, mixed> $value
     * @return list<string>
     */
    public static function abstractKeyPaths(array $value, string $prefix = ''): array
    {
        return self::keyPaths($value, $prefix, true);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return list<string>
     */
    private static function keyPaths(array $value, string $prefix, bool $abstract): array
    {
        $paths = [];
        $emittedMembersShape = false;

        foreach ($value as $key => $child) {
            $atMembers = $prefix === 'members' || str_ends_with($prefix, '.members');
            $isMemberEntry = $abstract && $atMembers;
            $segment = $isMemberEntry ? '*' : (string) $key;
            $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

            // In the abstract walk every member entry collapses onto the one
            // canonical `members.*` shape — emitted once, for the first entry.
            if ($isMemberEntry && $emittedMembersShape) {
                continue;
            }
            if ($isMemberEntry) {
                $emittedMembersShape = true;
            }

            $paths[] = $path;
            if (!is_array($child)) {
                continue;
            }

            if (array_is_list($child)) {
                $index = 0;
                foreach ($child as $item) {
                    $itemPath = $abstract ? $path . '[*]' : $path . '[' . $index . ']';
                    $paths[] = $itemPath;
                    if (is_array($item)) {
                        $paths = array_merge($paths, self::keyPaths($item, $itemPath, $abstract));
                    }
                    if ($abstract) {
                        break; // one representative element per list
                    }
                    $index++;
                }

                continue;
            }

            $paths = array_merge($paths, self::keyPaths($child, $path, $abstract));
        }

        return $paths;
    }

    /**
     * Canonical digest over {rail => abstract ordered key paths}.
     * Cross-language counterpart: phlix-contracts
     * test/fixtures/syncplay-envelope-vectors.json — the dumper extracts the
     * SAME digest from the contracts file at dump time and stamps it into the
     * fixture, so the pin asserts live == fixture == contracts-vectors
     * WITHOUT shipping the other repo's file into this CI.
     *
     * @param array<string, list<string>> $keyPathsByRail
     */
    public static function keyPathsDigest(array $keyPathsByRail): string
    {
        ksort($keyPathsByRail);

        return md5(json_encode($keyPathsByRail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
