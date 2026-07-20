<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Core\Application;
use Phlix\Stats\StatsCollector;
use Phlix\Stats\StorageSnapshotHelper;
use Workerman\MySQL\Connection;

/**
 * Tests for Application::recordStorageSnapshots(), the daemon-timer half of
 * the storage snapshot.
 *
 * It used to carry a byte-identical copy of StorageSnapshotHelper's bucket
 * fold — and the same four missing ENUM members. It now delegates, and these
 * tests pin that delegation so the copy cannot come back.
 */
class ApplicationStorageSnapshotTest extends TestCase
{
    /**
     * Invoke the private recordStorageSnapshots() on a constructor-less
     * Application, as the surrounding Application tests do.
     */
    private function invoke(
        StatsCollector $collector,
        Connection $db,
        StructuredLogger $logger
    ): void {
        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        $method = $ref->getMethod('recordStorageSnapshots');
        $method->setAccessible(true);
        $method->invoke($app, $collector, $db, $logger);
    }

    /**
     * @param array<int, array{type: string, item_count: int}> $rows
     * @return Connection&MockObject
     */
    private function dbReturningTypeCounts(array $rows)
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($rows);

        return $db;
    }

    public function testRecordsOneSnapshotPerBucketWithTheSharedFold(): void
    {
        $db = $this->dbReturningTypeCounts([
            ['type' => 'movie', 'item_count' => 10],
            ['type' => 'video', 'item_count' => 2],
            ['type' => 'episode', 'item_count' => 300],
            ['type' => 'track', 'item_count' => 900],
            ['type' => 'audiobook', 'item_count' => 5],
        ]);

        /** @var array<string, int> $recorded */
        $recorded = [];
        $collector = $this->createMock(StatsCollector::class);
        $collector->method('recordStorageSnapshot')
            ->willReturnCallback(
                function (string $mediaType, int $count) use (&$recorded): void {
                    $recorded[$mediaType] = $count;
                }
            );

        $this->invoke($collector, $db, $this->createMock(StructuredLogger::class));

        $this->assertSame(StorageSnapshotHelper::BUCKETS, array_keys($recorded));
        $this->assertSame(12, $recorded['movie'], 'movie + video');
        $this->assertSame(300, $recorded['series'], 'episode folds to series');
        $this->assertSame(900, $recorded['music'], 'track folds to music');
        $this->assertSame(5, $recorded['book'], 'audiobook folds to book');
        $this->assertSame(0, $recorded['photo']);
    }

    /**
     * The log context is derived from the buckets rather than hand-written,
     * so a newly added bucket is logged without touching Application.
     */
    public function testLogContextCoversEveryBucket(): void
    {
        $db = $this->dbReturningTypeCounts([['type' => 'book', 'item_count' => 7]]);

        /** @var array<string, mixed> $context */
        $context = [];
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('info')
            ->willReturnCallback(
                function (string $message, array $ctx) use (&$context): void {
                    $context = $ctx;
                }
            );

        $this->invoke($this->createMock(StatsCollector::class), $db, $logger);

        foreach (StorageSnapshotHelper::BUCKETS as $bucket) {
            $this->assertArrayHasKey($bucket, $context);
            $this->assertArrayHasKey($bucket . '_bytes', $context);
        }

        $this->assertSame(7, $context['book']);
    }

    /**
     * A snapshot run must never take down the worker.
     */
    public function testDatabaseFailureIsLoggedAndSwallowed(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new \RuntimeException('db is down'));

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to record storage snapshot'),
                $this->callback(
                    fn(array $ctx): bool => ($ctx['error'] ?? null) === 'db is down'
                )
            );

        $this->invoke($this->createMock(StatsCollector::class), $db, $logger);
    }
}
