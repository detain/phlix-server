<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use Phlix\Admin\WatchHistoryService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit coverage for {@see WatchHistoryService::getRecentWatchHistory()}.
 *
 * Asserts the SQL is well-formed (starts with `SELECT`, never `WITH`, ends with
 * the ordered `LIMIT ?`), that WHERE conditions + positional params are built in
 * the documented order for every filter combination, that a DB row maps into the
 * exact all-scalar typed shape (NULLs → `''`, progress → float), and that a
 * non-array `query()` result degrades to `[]`.
 */
final class WatchHistoryServiceTest extends TestCase
{
    /**
     * A representative DB row as Workerman would return it.
     *
     * @return array<string, mixed>
     */
    private function sampleRow(): array
    {
        return [
            'id' => 'wh-1',
            'media_item_id' => 'media-9',
            'media_name' => 'Test Movie',
            'media_type' => 'movie',
            'library_id' => 'lib-3',
            'user_id' => 'user-7',
            'username' => 'alice',
            'display_name' => null,        // NULL → '' in the mapped shape.
            'profile_name' => 'Kids',
            'last_watched_at' => '2026-06-01 12:00:00',
            'completed_at' => null,        // NULL → '' in the mapped shape.
            'progress_percent' => '42.50', // numeric string → float.
            'playback_status' => 'paused',
        ];
    }

    public function testNoFiltersBuildsBareQueryAndMapsRow(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [$this->sampleRow()];
            });

        $service = new WatchHistoryService($conn);
        $result = $service->getRecentWatchHistory(50);

        // SQL shape.
        $this->assertIsString($capturedSql);
        $this->assertStringStartsWith('SELECT', $capturedSql);
        $this->assertStringNotContainsStringIgnoringCase('WITH ', $capturedSql);
        $this->assertStringNotContainsString('WHERE', $capturedSql);
        $this->assertStringEndsWith('ORDER BY wh.last_watched_at DESC LIMIT ?', $capturedSql);

        // Params: just the limit, bound last.
        $this->assertSame([50], $capturedParams);

        // Mapped shape.
        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertSame('wh-1', $row['id']);
        $this->assertSame('media-9', $row['media_item_id']);
        $this->assertSame('Test Movie', $row['media_name']);
        $this->assertSame('movie', $row['media_type']);
        $this->assertSame('lib-3', $row['library_id']);
        $this->assertSame('user-7', $row['user_id']);
        $this->assertSame('alice', $row['username']);
        $this->assertSame('', $row['display_name']);        // NULL collapsed.
        $this->assertSame('Kids', $row['profile_name']);
        $this->assertSame('2026-06-01 12:00:00', $row['last_watched_at']);
        $this->assertSame('', $row['completed_at']);        // NULL collapsed.
        $this->assertIsFloat($row['progress_percent']);
        $this->assertSame(42.5, $row['progress_percent']);
        $this->assertSame('paused', $row['playback_status']);
    }

    public function testUserIdFilterOnly(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [];
            });

        $service = new WatchHistoryService($conn);
        $service->getRecentWatchHistory(25, 'user-7');

        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('WHERE u.id = ?', $capturedSql);
        // The library filter condition must be absent (the SELECT column
        // `mi.library_id AS library_id` is always present and is not a filter).
        $this->assertStringNotContainsString('mi.library_id = ?', $capturedSql);
        $this->assertStringEndsWith('ORDER BY wh.last_watched_at DESC LIMIT ?', $capturedSql);
        $this->assertSame(['user-7', 25], $capturedParams);
    }

    public function testLibraryIdFilterOnly(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [];
            });

        $service = new WatchHistoryService($conn);
        $service->getRecentWatchHistory(10, null, 'lib-3');

        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('WHERE mi.library_id = ?', $capturedSql);
        $this->assertStringNotContainsString('u.id = ?', $capturedSql);
        $this->assertSame(['lib-3', 10], $capturedParams);
    }

    public function testBothFilters(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [];
            });

        $service = new WatchHistoryService($conn);
        $service->getRecentWatchHistory(75, 'user-7', 'lib-3');

        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('WHERE u.id = ? AND mi.library_id = ?', $capturedSql);
        $this->assertStringEndsWith('ORDER BY wh.last_watched_at DESC LIMIT ?', $capturedSql);
        // Params in the same order they appear in the string: userId, libraryId, limit.
        $this->assertSame(['user-7', 'lib-3', 75], $capturedParams);
    }

    public function testNonArrayQueryResultReturnsEmpty(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('query')->willReturn(null);

        $service = new WatchHistoryService($conn);
        $result = $service->getRecentWatchHistory(50);

        $this->assertSame([], $result);
    }
}
