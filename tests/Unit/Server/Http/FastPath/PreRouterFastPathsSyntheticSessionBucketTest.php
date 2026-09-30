<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\FastPath;

use Phlix\Access\StreamSessionService;
use Phlix\Auth\SignedUrl;
use Phlix\Auth\UserProfileManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\FastPath\PreRouterFastPaths;
use Phlix\Server\Http\Middleware\StreamLimitMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Workerman\MySQL\Connection;
use Workerman\Worker;

/**
 * M-3 TWIN (security scan follow-up to da70cfa8) — the pre-router direct-play
 * fast path applied the middleware's PRE-fix session-null skip: an
 * authenticated client that omitted `session_id` dodged the per-profile
 * concurrency cap entirely on `/media/{id}/stream`, because that route is
 * answered by {@see PreRouterFastPaths} before the router (and therefore
 * before StreamLimitMiddleware) ever runs.
 *
 * These tests pin the fast path to the middleware's SYNTHETIC-bucket semantics
 * — both call sites derive the id through the single shared helper
 * {@see StreamLimitMiddleware::syntheticSessionBucket()}, so one device
 * crossing both paths lands in the identical slot — and the still-documented
 * pass-through for a request with no device name at all.
 *
 * Harness idiom copied from PreRouterFastPathsStreamLimitTest (S301): real
 * StreamSessionService over a scripted Connection mock, resolved through the
 * container mock; S266 Worker-registry save/restore because the heartbeat
 * timer registration requires a resident Worker.
 */
final class PreRouterFastPathsSyntheticSessionBucketTest extends TestCase
{
    private string $mediaPath = '';

    /** @var list<array{sql: string, binds: list<mixed>}> */
    private array $log = [];

    /** @var list<string> session_ids with a registered (INSERTed) row */
    private array $registeredSessions = [];

    /** @var array<string, mixed>|null scripted profile_stream_limits row */
    private ?array $limitRow = null;

    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();
        SignedUrl::resetSharedForTesting();
        // checkStreamLimit prefers RequestContext::getProfileId(); force the
        // empty path so 'p1' resolves deterministically via getActiveProfile,
        // immune to any process-static leak from sibling tests.
        RequestContext::setProfileId(null);
        $this->log = [];
        $this->registeredSessions = [];
        $this->limitRow = null;

        $this->mediaPath = sys_get_temp_dir() . '/phlix-synth-' . bin2hex(random_bytes(6)) . '.mp4';
        file_put_contents($this->mediaPath, 'ABCDEFGHIJKLMNOP');

        // S266 house pattern: Workerman\Timer::add() throws unless a Worker
        // exists in the process; snapshot the registry and restore untouched.
        $this->savedWorkers = Worker::getAllWorkers();
        if (!Worker::getAllWorkers()) {
            new Worker();
        }
    }

    protected function tearDown(): void
    {
        RequestContext::setProfileId(null);
        if ($this->mediaPath !== '' && is_file($this->mediaPath)) {
            @unlink($this->mediaPath);
        }
        SignedUrl::resetSharedForTesting();
        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);
        parent::tearDown();
    }

    private function makeFastPaths(): PreRouterFastPaths
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'path' => $this->mediaPath,
        ]);
        $repo->method('effectiveContentRatingsForIds')->willReturn([]);

        $profiles = $this->createMock(UserProfileManager::class);
        // checkStreamLimit: RequestContext profile is empty here, so the
        // profile id 'p1' resolves through this active-profile row.
        $profiles->method('getActiveProfile')->willReturn(['id' => 'p1']);
        $profiles->method('getActiveRatingFilter')->willReturn(null);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $binds = []): mixed {
                $this->log[] = ['sql' => $sql, 'binds' => array_values($binds ?? [])];

                if (str_starts_with($sql, 'SELECT 1 FROM active_streams')) {
                    // Scripted per (profile_id, session_id) like the real query.
                    $session = (string) (($binds ?? [])[1] ?? '');
                    return in_array($session, $this->registeredSessions, true) ? [['1' => '1']] : [];
                }
                if (str_starts_with($sql, 'SELECT * FROM profile_stream_limits')) {
                    return $this->limitRow === null ? [] : [$this->limitRow];
                }
                if (str_starts_with($sql, 'SELECT COUNT(*) as cnt')) {
                    return [['cnt' => (string) count($this->registeredSessions)]];
                }
                if (str_starts_with($sql, 'INSERT INTO active_streams')) {
                    $this->registeredSessions[] = (string) (($binds ?? [])[2] ?? '');
                    return null;
                }
                return [];
            }
        );

        $streams = new StreamSessionService($db);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $class): object => match ($class) {
                UserProfileManager::class => $profiles,
                StreamSessionService::class => $streams,
                default => $repo,
            },
        );

        return new PreRouterFastPaths(
            $this->createMock(ArtworkStorage::class),
            $this->createMock(AvatarStorage::class),
            $container,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function streamRequest(array $headers = []): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/media/m1/stream';
        $request->userId = 'u1';
        $request->headers = $headers;

        return $request;
    }

    /**
     * @return array{sql: string, binds: list<mixed>}
     */
    private function lastInsert(): array
    {
        foreach (array_reverse($this->log) as $entry) {
            if (str_starts_with($entry['sql'], 'INSERT INTO active_streams')) {
                return $entry;
            }
        }

        $this->fail('no INSERT INTO active_streams was issued; log: ' . print_r($this->log, true));
    }

    /**
     * Device present, session omitted → the stream is COUNTED under the
     * shared synthetic bucket, and the served response stays 200. Pre-fix
     * this request returned null (untracked pass-through).
     */
    public function testSessionlessDeviceStreamRegistersUnderSyntheticBucket(): void
    {
        $response = $this->makeFastPaths()->dispatch($this->streamRequest(['X-Device-Id' => 'device-1']));

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);

        $insert = $this->lastInsert();
        $expectedBucket = StreamLimitMiddleware::syntheticSessionBucket('p1', 'device-1');
        self::assertSame('p1', $insert['binds'][0] ?? null);
        self::assertSame('device-1', $insert['binds'][1] ?? null);
        self::assertSame(
            $expectedBucket,
            $insert['binds'][2] ?? null,
            'TWIN: the fast path must derive the M-3 synthetic bucket via the shared helper',
        );
        self::assertLessThanOrEqual(100, strlen($expectedBucket), "bucket must fit the session_id column");
    }

    /**
     * Stability + idempotency: the same device repeating a sessionless request
     * re-uses the ONE bucket (dedupe SELECT hits → heartbeat UPDATE, no
     * second INSERT).
     */
    public function testRepeatedSessionlessRequestsShareOneStableBucket(): void
    {
        $fastPaths = $this->makeFastPaths();
        $headers = ['X-Device-Id' => 'device-1'];

        $one = $fastPaths->dispatch($this->streamRequest($headers));
        self::assertNotNull($one);
        self::assertSame(200, $one->statusCode);
        $first = $this->lastInsert();
        $two = $fastPaths->dispatch($this->streamRequest($headers));
        self::assertNotNull($two);
        self::assertSame(200, $two->statusCode);

        $inserts = array_filter(
            $this->log,
            static fn (array $e): bool => str_starts_with($e['sql'], 'INSERT INTO active_streams')
        );
        self::assertCount(1, $inserts, 'second request must ride the existing bucket, not double-book');

        $heartbeats = array_filter(
            $this->log,
            static fn (array $e): bool => str_starts_with($e['sql'], 'UPDATE active_streams')
        );
        self::assertNotEmpty($heartbeats, 'the repeat must refresh the bucket heartbeat');
        $firstInsert = $inserts[array_key_first($inserts)];
        self::assertSame($first['binds'][2], $firstInsert['binds'][2]);
    }

    /**
     * A real session_id still wins over synthesis (explicit ids stay distinct).
     */
    public function testRealSessionIdIsNotSynthesizedOver(): void
    {
        $response = $this->makeFastPaths()->dispatch($this->streamRequest([
            'X-Device-Id' => 'device-1',
            'X-Session-Id' => 'session-9',
        ]));

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);
        $insert = $this->lastInsert();
        self::assertSame('session-9', $insert['binds'][2] ?? null);
        self::assertStringStartsNotWith('synthetic:', (string) $insert['binds'][2]);
    }

    /**
     * The cap now bites sessionless direct-play traffic: one synthetic slot,
     * limit 1, a second DISTINCT device is refused 429 — pre-fix both slipped
     * through uncounted.
     */
    public function testSyntheticBucketIsCountedByTheCap(): void
    {
        $fastPaths = $this->makeFastPaths();
        $this->limitRow = ['profile_id' => 'p1', 'max_concurrent_streams' => 1];

        $first = $fastPaths->dispatch($this->streamRequest(['X-Device-Id' => 'device-1']));
        self::assertNotNull($first);
        self::assertSame(200, $first->statusCode);

        $second = $fastPaths->dispatch($this->streamRequest(['X-Device-Id' => 'device-2']));

        self::assertNotNull($second);
        self::assertSame(429, $second->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $second->body, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('stream_limit_exceeded', $body['denial_type']);
        self::assertSame('p1', $body['profile_id']);
    }

    /**
     * The surviving documented pass-through: NO device name at all (neither
     * X-Device-ID nor User-Agent) → nothing to key a bucket on → unregistered,
     * request served. Mirrors the middleware's twin behaviour exactly.
     */
    public function testRequestWithoutAnyDeviceNameStillPassesThrough(): void
    {
        $response = $this->makeFastPaths()->dispatch($this->streamRequest());

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);

        foreach ($this->log as $entry) {
            self::assertFalse(
                str_starts_with($entry['sql'], 'INSERT INTO active_streams'),
                'no device → no registration attempt at all',
            );
        }
    }
}
