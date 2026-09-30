<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Access\StreamSessionService;
use Phlix\Auth\UserProfileManager;
use Phlix\Server\Http\Middleware\StreamLimitMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\MySQL\Connection;
use Workerman\Worker;

/**
 * M-3 (security scan @9e765895) — an authenticated stream WITHOUT a session
 * id must still be counted.
 *
 * The pre-fix middleware bailed out (`return null`) whenever no `session_id`
 * was supplied, so the per-profile concurrency limit was silently unenforceable
 * by any client that simply omits it. The fix synthesizes a STABLE bucket id
 * derived from `(profileId, deviceId)` — deviceId already carries its UA-hash
 * fallback — so every stream of the same device/profile shares one countable,
 * heartbeatable, GC-able row, exactly like a real session.
 *
 * The synthetic id rides the SAME register/heartbeat/stale-GC machinery as real
 * sessions (`cleanupStaleStreams()` deletes on `last_heartbeat_at`, never on id
 * shape), so no row class can leak: while the device keeps streaming, every
 * request refreshes the heartbeat; after ~60 s of silence the row is reaped on
 * the next registration attempt.
 */
final class StreamLimitSyntheticSessionBucketTest extends TestCase
{
    /** @var list<array{sql: string, binds: list<mixed>}> */
    private array $log = [];

    /** @var array<string, mixed> scripted answers keyed by SQL prefix */
    private array $script = [];

    private bool $sessionRowExists = false;

    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = [];
        $this->script = [];
        $this->sessionRowExists = false;
        RequestContext::setUserId('u1');
        RequestContext::setProfileId('p1');

        // S266 house pattern (StreamSessionServiceTest): Workerman\Timer::add()
        // throws unless a Worker exists in the process; snapshot the registry,
        // register a bare non-listening worker, restore it untouched in tearDown.
        $this->savedWorkers = Worker::getAllWorkers();
        if (!Worker::getAllWorkers()) {
            new Worker();
        }
    }

    protected function tearDown(): void
    {
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);
        parent::tearDown();
    }

    /**
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function scriptedDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $binds = []): mixed {
                $this->log[] = ['sql' => $sql, 'binds' => array_values($binds ?? [])];

                if (str_starts_with($sql, 'SELECT 1 FROM active_streams')) {
                    return $this->sessionRowExists ? [['1' => '1']] : [];
                }
                if (str_starts_with($sql, 'SELECT * FROM profile_stream_limits')) {
                    return $this->script['limits'] ?? [];
                }
                if (str_starts_with($sql, 'SELECT COUNT(*) as cnt')) {
                    return $this->script['count'] ?? [['cnt' => '0']];
                }
                if (str_starts_with($sql, 'INSERT INTO active_streams')) {
                    $this->sessionRowExists = true;
                    return null;
                }
                return [];
            }
        );
        return $db;
    }

    private function service(Connection $db): StreamSessionService
    {
        return new StreamSessionService($db);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers = [], array $query = []): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/hls/job-1/master.m3u8';
        $request->userId = 'u1';
        $request->headers = $headers;
        $request->query = $query;

        return $request;
    }

    private function middleware(StreamSessionService $service): StreamLimitMiddleware
    {
        return new StreamLimitMiddleware($service, $this->createMock(UserProfileManager::class));
    }

    /**
     * Snapshot of every captured query. Read through a declared-return
     * accessor so phpstan sees the list type, not the empty-array literal a
     * mid-test `$this->log = []` reset narrows the property to (the scripted
     * DB appends to it across `$middleware->__invoke()` — invisible to
     * property narrowing).
     *
     * @return list<array{sql: string, binds: list<mixed>}>
     */
    private function capturedQueries(): array
    {
        return $this->log;
    }

    /**
     * @return list<mixed>|null binds of the first INSERT into active_streams
     */
    private function insertBinds(): ?array
    {
        foreach ($this->log as $entry) {
            if (str_starts_with($entry['sql'], 'INSERT INTO active_streams')) {
                return $entry['binds'];
            }
        }
        return null;
    }

    private function syntheticId(string $profileId, string $deviceId): string
    {
        return 'synthetic:' . hash('sha256', $profileId . '|' . $deviceId);
    }

    public function testMissingSessionIdRegistersSyntheticBucketInsteadOfSkippingTheLimit(): void
    {
        // Pre-fix this request never reached the service at all: no INSERT,
        // limit unenforced. Post-fix the device header names the bucket.
        $db = $this->scriptedDb();
        $service = $this->service($db);
        $response = $this->middleware($service)->__invoke(
            $this->request(['X-Device-ID' => 'dev-9'])
        );

        $this->assertNull($response, 'under the limit the request must proceed');

        $binds = $this->insertBinds();
        $this->assertIsArray($binds, 'a session-less authenticated stream must register');
        $this->assertSame('p1', $binds[0]);
        $this->assertSame('dev-9', $binds[1]);
        $this->assertSame(
            $this->syntheticId('p1', 'dev-9'),
            $binds[2],
            'the synthetic bucket must be a stable derivation of (profile, device)'
        );
        $this->assertLessThanOrEqual(
            100,
            strlen($binds[2]),
            'active_streams.session_id is VARCHAR(100) — the synthetic id must fit'
        );
    }

    public function testSyntheticBucketIsStablePerDeviceAndDistinctAcrossDevices(): void
    {
        $db = $this->scriptedDb();
        $service = $this->service($db);
        $middleware = $this->middleware($service);

        $first = $this->request(['X-Device-ID' => 'dev-A']);
        $second = $this->request(['X-Device-ID' => 'dev-A']);
        $other = $this->request(['X-Device-ID' => 'dev-B']);

        $this->assertNull($middleware->__invoke($first));
        $insertA = $this->insertBinds();
        $this->assertIsArray($insertA);

        // Repeat request from the same device: idempotent — it must refresh the
        // SAME row (heartbeat UPDATE), never register a second one.
        $this->log = [];
        $this->assertNull($middleware->__invoke($second));
        $this->assertNull($this->insertBinds(), 'the stable id must hit the already-registered path');
        $heartbeat = null;
        foreach ($this->capturedQueries() as $entry) {
            if (str_starts_with($entry['sql'], 'UPDATE active_streams SET last_heartbeat_at')) {
                $heartbeat = $entry['binds'];
            }
        }
        $this->assertIsArray($heartbeat, 'repeat requests keep the synthetic bucket fresh');
        $this->assertSame($insertA[2], $heartbeat[0]);

        // A different device on the same profile gets its own bucket.
        $this->log = [];
        $this->sessionRowExists = false;
        $this->assertNull($middleware->__invoke($other));
        $insertB = $this->insertBinds();
        $this->assertIsArray($insertB);
        $this->assertNotSame($insertA[2], $insertB[2]);
    }

    public function testSyntheticBucketHitsThe429LimitLikeAnyRealSession(): void
    {
        $db = $this->scriptedDb();
        // Explicit limit of one; the count says one is already active.
        $this->script['limits'] = [[
            'profile_id' => 'p1',
            'max_concurrent_streams' => 1,
            'max_total_bandwidth_kbps' => null,
        ]];
        $this->script['count'] = [['cnt' => '1']];
        $service = $this->service($db);

        $response = $this->middleware($service)->__invoke(
            $this->request(['X-Device-ID' => 'dev-9'])
        );

        $this->assertNotNull($response, 'the limit must engage for session-less streams');
        $this->assertSame(429, $response->statusCode);
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('stream.limit_exceeded', $decoded['code'] ?? null);
    }

    public function testSyntheticBucketHeartbeatTimerIsDedupedAndSelfClearing(): void
    {
        $db = $this->scriptedDb();
        $service = $this->service($db);
        $middleware = $this->middleware($service);

        $baseline = $service->activeHeartbeatTimerCount();
        $this->assertNull($middleware->__invoke($this->request(['X-Device-ID' => 'dev-T'])));
        $this->assertSame(
            $baseline + 1,
            $service->activeHeartbeatTimerCount(),
            'the synthetic id must arm the same one-shot heartbeat timer a real session arms'
        );

        $this->assertNull($middleware->__invoke($this->request(['X-Device-ID' => 'dev-T'])));
        $this->assertSame($baseline + 1, $service->activeHeartbeatTimerCount(), 'per-session dedupe holds');

        $synthetic = $this->syntheticId('p1', 'dev-T');
        $service->onHeartbeatTimerFired($synthetic);
        $this->assertSame($baseline, $service->activeHeartbeatTimerCount(), 'firing self-clears the slot');
    }

    public function testRealSessionIdStillWinsOverTheSyntheticBucket(): void
    {
        $db = $this->scriptedDb();
        $service = $this->service($db);

        $this->assertNull($this->middleware($service)->__invoke(
            $this->request(['X-Device-ID' => 'dev-9'], ['session_id' => 'sess-real'])
        ));

        $binds = $this->insertBinds();
        $this->assertIsArray($binds);
        $this->assertSame('sess-real', $binds[2], 'a supplied session_id must never be overridden');
    }
}
