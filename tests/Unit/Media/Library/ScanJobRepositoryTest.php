<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use InvalidArgumentException;
use Phlix\Media\Library\ScanJobRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for the library scan-job store (Step 1.1a).
 *
 * Drives a mocked {@see Connection} per the repo convention
 * (`$this->createMock(Connection::class)`, stub `->query(...)`), covering
 * every public method, both branches of {@see ScanJobRepository::claimNext()},
 * the {@see ScanJobRepository::enqueue()} invalid-type reject, and the
 * {@see ScanJobRepository::getHistoryForLibrary()} `$limit` clamp.
 *
 * @covers \Phlix\Media\Library\ScanJobRepository
 */
final class ScanJobRepositoryTest extends TestCase
{
    public function testEnqueueInsertsQueuedRowAndReturnsGeneratedId(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                static function (string $sql, ?array $params = null, $fetchmode = \PDO::FETCH_ASSOC) use (&$captured) {
                    $captured = ['sql' => $sql, 'params' => $params ?? []];
                    return 'job-id';
                },
            );

        $repo = new ScanJobRepository($db);
        $id = $repo->enqueue('lib-1', 'scan');

        $this->assertStringContainsString('INSERT INTO library_scan_jobs', $captured['sql']);
        // [id, library_id, type, status]
        $this->assertSame($id, $captured['params'][0]);
        $this->assertSame('lib-1', $captured['params'][1]);
        $this->assertSame('scan', $captured['params'][2]);
        $this->assertSame('queued', $captured['params'][3]);
        // The returned id must be the freshly generated UUID, not the DB return.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testEnqueueAcceptsRescanType(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO library_scan_jobs'),
                $this->callback(static fn (array $p): bool => $p[2] === 'rescan'),
            )
            ->willReturn('x');

        $repo = new ScanJobRepository($db);
        $this->assertNotSame('', $repo->enqueue('lib-1', 'rescan'));
    }

    public function testEnqueueRejectsInvalidType(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $repo = new ScanJobRepository($db);

        $this->expectException(InvalidArgumentException::class);
        $repo->enqueue('lib-1', 'bogus');
    }

    public function testClaimNextReturnsNullWhenNothingQueued(): void
    {
        $db = $this->createMock(Connection::class);
        // The initial SELECT for the oldest queued id returns no rows.
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains("status = 'queued'"))
            ->willReturn([]);

        $repo = new ScanJobRepository($db);
        $this->assertNull($repo->claimNext());
    }

    public function testClaimNextClaimsOldestQueuedJob(): void
    {
        $calls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null, $fetchmode = \PDO::FETCH_ASSOC) use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    // SELECT oldest queued id.
                    $this->assertStringContainsString("status = 'queued'", $sql);
                    $this->assertStringContainsString('ORDER BY queued_at ASC', $sql);
                    return [['id' => 'job-7']];
                }
                if ($calls === 2) {
                    // Conditional UPDATE -> claim. Affected-row count = 1.
                    $this->assertStringContainsString('UPDATE library_scan_jobs', $sql);
                    $this->assertStringContainsString("status = 'running'", $sql);
                    $this->assertSame(['job-7'], $params);
                    return 1;
                }
                // findById(job-7) reload.
                $this->assertStringContainsString('WHERE id = ?', $sql);
                $this->assertSame(['job-7'], $params);
                return [[
                    'id'         => 'job-7',
                    'library_id' => 'lib-1',
                    'type'       => 'scan',
                    'status'     => 'running',
                ]];
            },
        );

        $repo = new ScanJobRepository($db);
        $job = $repo->claimNext();

        $this->assertIsArray($job);
        $this->assertSame('job-7', $job['id']);
        $this->assertSame('running', $job['status']);
    }

    public function testClaimNextReturnsNullWhenConditionalUpdateLosesRace(): void
    {
        $calls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, ?array $params = null, $fetchmode = \PDO::FETCH_ASSOC) use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    return [['id' => 'job-9']];
                }
                // Conditional UPDATE changed nothing (another claimant won).
                return 0;
            },
        );

        $repo = new ScanJobRepository($db);
        // Only the SELECT + UPDATE run; no findById reload happens on a lost race.
        $this->assertNull($repo->claimNext());
        $this->assertSame(2, $calls);
    }

    public function testClaimNextReturnsNullWhenCandidateRowHasNoStringId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturn([['id' => 123]]);

        $repo = new ScanJobRepository($db);
        $this->assertNull($repo->claimNext());
    }

    public function testUpdateProgressWritesOnlyKnownCountersAndPath(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                static function (string $sql, ?array $params = null) use (&$captured) {
                    $captured = ['sql' => $sql, 'params' => $params ?? []];
                    return 1;
                },
            );

        $repo = new ScanJobRepository($db);
        $repo->updateProgress('job-1', [
            'items_found'   => '10',   // string -> cast to int
            'items_added'   => 4,
            'bogus_column'  => 99,     // ignored
        ], '/media/movies');

        $this->assertStringContainsString('items_found = ?', $captured['sql']);
        $this->assertStringContainsString('items_added = ?', $captured['sql']);
        $this->assertStringContainsString('current_path = ?', $captured['sql']);
        $this->assertStringNotContainsString('bogus_column', $captured['sql']);
        // [items_found(int), items_added(int), current_path, jobId]
        $this->assertSame([10, 4, '/media/movies', 'job-1'], $captured['params']);
    }

    public function testUpdateProgressIsNoOpWhenNothingToWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $repo = new ScanJobRepository($db);
        $repo->updateProgress('job-1', ['unknown' => 1], null);
    }

    public function testMarkCompletedSetsStatusAndOptionalFinalCounts(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                static function (string $sql, ?array $params = null) use (&$captured) {
                    $captured = ['sql' => $sql, 'params' => $params ?? []];
                    return 1;
                },
            );

        $repo = new ScanJobRepository($db);
        $repo->markCompleted('job-1', ['items_found' => 42]);

        $this->assertStringContainsString("status = 'completed'", $captured['sql']);
        $this->assertStringContainsString('completed_at = NOW()', $captured['sql']);
        $this->assertStringContainsString('items_found = ?', $captured['sql']);
        // [items_found(int), jobId]
        $this->assertSame([42, 'job-1'], $captured['params']);
    }

    public function testMarkCompletedWithoutCountsStillStampsStatus(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                static function (string $sql, ?array $params = null) use (&$captured) {
                    $captured = ['sql' => $sql, 'params' => $params ?? []];
                    return 1;
                },
            );

        $repo = new ScanJobRepository($db);
        $repo->markCompleted('job-1');

        $this->assertStringContainsString("status = 'completed'", $captured['sql']);
        $this->assertSame(['job-1'], $captured['params']);
    }

    public function testMarkFailedRecordsError(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains("status = 'failed'"),
                    $this->stringContains('completed_at = NOW()'),
                ),
                $this->identicalTo(['disk full', 'job-1']),
            )
            ->willReturn(1);

        $repo = new ScanJobRepository($db);
        $repo->markFailed('job-1', 'disk full');
    }

    public function testFindByIdReturnsDecodedRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id'            => 'job-1',
            'library_id'    => 'lib-1',
            'type'          => 'rescan',
            'status'        => 'completed',
            'items_found'   => '12',
            'items_added'   => '3',
            'items_updated' => '2',
            'items_removed' => '1',
            'current_path'  => null,
            'error'         => null,
            'queued_at'     => '2026-05-27 10:00:00',
            'started_at'    => '2026-05-27 10:00:01',
            'completed_at'  => '2026-05-27 10:05:00',
        ]]);

        $repo = new ScanJobRepository($db);
        $row = $repo->findById('job-1');

        $this->assertIsArray($row);
        $this->assertSame('job-1', $row['id']);
        $this->assertSame('rescan', $row['type']);
        $this->assertSame('completed', $row['status']);
        // Counters are cast to int.
        $this->assertSame(12, $row['items_found']);
        $this->assertSame(3, $row['items_added']);
        $this->assertSame(2, $row['items_updated']);
        $this->assertSame(1, $row['items_removed']);
        $this->assertNull($row['current_path']);
        $this->assertNull($row['error']);
        $this->assertSame('2026-05-27 10:05:00', $row['completed_at']);
    }

    public function testFindByIdReturnsNullWhenNoRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new ScanJobRepository($db);
        $this->assertNull($repo->findById('nope'));
    }

    public function testFindByIdReturnsNullWhenResultNotArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(false);

        $repo = new ScanJobRepository($db);
        $this->assertNull($repo->findById('nope'));
    }

    public function testFindByIdReturnsNullWhenRowNotArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(['scalar-not-a-row']);

        $repo = new ScanJobRepository($db);
        $this->assertNull($repo->findById('nope'));
    }

    public function testGetLatestForLibraryReturnsNewestRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('WHERE library_id = ?'),
                    $this->stringContains('ORDER BY queued_at DESC'),
                    $this->stringContains('LIMIT 1'),
                ),
                $this->identicalTo(['lib-1']),
            )
            ->willReturn([[
                'id'         => 'job-latest',
                'library_id' => 'lib-1',
                'type'       => 'scan',
                'status'     => 'running',
            ]]);

        $repo = new ScanJobRepository($db);
        $row = $repo->getLatestForLibrary('lib-1');

        $this->assertIsArray($row);
        $this->assertSame('job-latest', $row['id']);
    }

    public function testGetLatestForLibraryReturnsNullWhenNone(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new ScanJobRepository($db);
        $this->assertNull($repo->getLatestForLibrary('lib-1'));
    }

    public function testGetLatestForLibraryReturnsNullWhenRowNotArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(['not-a-row']);

        $repo = new ScanJobRepository($db);
        $this->assertNull($repo->getLatestForLibrary('lib-1'));
    }

    public function testGetHistoryForLibraryReturnsDecodedRows(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, ?array $params = null) use (&$captured) {
                $captured = ['sql' => $sql, 'params' => $params ?? []];
                return [
                    ['id' => 'a', 'library_id' => 'lib-1', 'type' => 'scan', 'status' => 'completed'],
                    'scalar-skipped',
                    ['id' => 'b', 'library_id' => 'lib-1', 'type' => 'scan', 'status' => 'failed'],
                ];
            },
        );

        $repo = new ScanJobRepository($db);
        $rows = $repo->getHistoryForLibrary('lib-1', 5);

        $this->assertStringContainsString('ORDER BY queued_at DESC', $captured['sql']);
        $this->assertSame(['lib-1', 5], $captured['params']);
        // The non-array element is skipped.
        $this->assertCount(2, $rows);
        $this->assertSame('a', $rows[0]['id']);
        $this->assertSame('b', $rows[1]['id']);
    }

    public function testGetHistoryForLibraryClampsLimitToUpperBound(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, ?array $params = null) use (&$captured) {
                $captured = $params ?? [];
                return [];
            },
        );

        $repo = new ScanJobRepository($db);
        $repo->getHistoryForLibrary('lib-1', 5000);

        // Clamped to the documented max of 100.
        $this->assertSame(['lib-1', 100], $captured);
    }

    public function testGetHistoryForLibraryClampsLimitToLowerBound(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, ?array $params = null) use (&$captured) {
                $captured = $params ?? [];
                return [];
            },
        );

        $repo = new ScanJobRepository($db);
        $repo->getHistoryForLibrary('lib-1', 0);

        // Clamped up to the documented min of 1.
        $this->assertSame(['lib-1', 1], $captured);
    }

    public function testGetHistoryForLibraryReturnsEmptyWhenResultNotArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(false);

        $repo = new ScanJobRepository($db);
        $this->assertSame([], $repo->getHistoryForLibrary('lib-1'));
    }
}
