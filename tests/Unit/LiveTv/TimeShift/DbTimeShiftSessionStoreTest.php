<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\TimeShift;

use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use Phlix\LiveTv\TimeShift\TimeShiftSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore
 * @covers \Phlix\LiveTv\TimeShift\TimeShiftSession
 */
class DbTimeShiftSessionStoreTest extends TestCase
{
    /** @return Connection&MockObject */
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    /**
     * @return array<string, mixed> A representative table row.
     */
    private function sampleRow(): array
    {
        return [
            'id' => 'ts-1',
            'session_id' => 'sess-9',
            'channel_id' => 'chan-7',
            'buffer_dir' => '/var/recordings/timeshift/ts-1',
            'pid' => 4242,
            'buffer_start_at' => 1_000_000,
            'buffer_end_at' => 1_007_200,
            'window_seconds' => 7200,
            'cursor_position' => 120,
            'status' => 'active',
            'created_at' => '2026-07-13 10:00:00',
            'updated_at' => '2026-07-13 10:05:00',
        ];
    }

    public function testCanCreateStore(): void
    {
        $store = new DbTimeShiftSessionStore($this->createMockConnection());

        $this->assertInstanceOf(DbTimeShiftSessionStore::class, $store);
    }

    public function testSaveIssuesUpsertWithPositionalParams(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO livetv_timeshift_sessions'),
                    $this->stringContains('ON DUPLICATE KEY UPDATE')
                ),
                $this->callback(function ($params): bool {
                    return is_array($params)
                        && count($params) === 10
                        && $params[0] === 'ts-1'          // id
                        && $params[1] === 'sess-9'        // session_id
                        && $params[2] === 'chan-7'        // channel_id
                        && $params[3] === '/buf/ts-1'     // buffer_dir
                        && $params[4] === null            // pid (unset until spawn)
                        && $params[5] === 1000            // buffer_start_at
                        && $params[6] === 8200            // buffer_end_at
                        && $params[7] === 7200            // window_seconds
                        && $params[8] === 0               // cursor_position
                        && $params[9] === 'active';       // status
                })
            );

        $session = new TimeShiftSession(
            id: 'ts-1',
            session_id: 'sess-9',
            channel_id: 'chan-7',
            buffer_dir: '/buf/ts-1',
            buffer_start_at: 1000,
            buffer_end_at: 8200,
            window_seconds: 7200,
        );

        (new DbTimeShiftSessionStore($db))->save($session);
    }

    public function testFindByIdReturnsHydratedSessionWhenFound(): void
    {
        $db = $this->createMockConnection();
        $row = $this->sampleRow();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT * FROM livetv_timeshift_sessions WHERE id = ?'),
                $this->callback(fn($p): bool => is_array($p) && $p[0] === 'ts-1')
            )
            ->willReturn([$row]);

        $session = (new DbTimeShiftSessionStore($db))->findById('ts-1');

        $this->assertInstanceOf(TimeShiftSession::class, $session);
        $this->assertSame('ts-1', $session->id);
        $this->assertSame('sess-9', $session->session_id);
        $this->assertSame('chan-7', $session->channel_id);
        $this->assertSame('/var/recordings/timeshift/ts-1', $session->buffer_dir);
        $this->assertSame(4242, $session->pid);
        $this->assertSame(1_000_000, $session->buffer_start_at);
        $this->assertSame(1_007_200, $session->buffer_end_at);
        $this->assertSame(7200, $session->window_seconds);
        $this->assertSame(120, $session->cursor_position);
        $this->assertSame('active', $session->status);
        $this->assertSame('2026-07-13 10:00:00', $session->created_at);
        $this->assertSame('2026-07-13 10:05:00', $session->updated_at);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->willReturn([]);

        $this->assertNull((new DbTimeShiftSessionStore($db))->findById('missing'));
    }

    public function testFindByIdReturnsNullOnFalseResult(): void
    {
        $db = $this->createMockConnection();

        // A failed query returns false in the Workerman driver.
        $db->expects($this->once())
            ->method('query')
            ->willReturn(false);

        $this->assertNull((new DbTimeShiftSessionStore($db))->findById('ts-1'));
    }

    public function testFindBySessionIdSelectsNewestFirst(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('WHERE session_id = ?'),
                    $this->stringContains('ORDER BY created_at DESC'),
                    $this->stringContains('LIMIT 1')
                ),
                $this->callback(fn($p): bool => is_array($p) && $p[0] === 'sess-9')
            )
            ->willReturn([$this->sampleRow()]);

        $session = (new DbTimeShiftSessionStore($db))->findBySessionId('sess-9');

        $this->assertInstanceOf(TimeShiftSession::class, $session);
        $this->assertSame('sess-9', $session->session_id);
    }

    public function testUpdateCursorBindsIntCursorThenId(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE livetv_timeshift_sessions SET cursor_position = ? WHERE id = ?'),
                $this->callback(function ($params): bool {
                    return is_array($params)
                        && $params[0] === 300
                        && is_int($params[0])
                        && $params[1] === 'ts-1';
                })
            );

        (new DbTimeShiftSessionStore($db))->updateCursor('ts-1', 300);
    }

    public function testUpdateBufferWindowBindsBoundsThenId(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SET buffer_start_at = ?, buffer_end_at = ?'),
                $this->callback(function ($params): bool {
                    return is_array($params)
                        && $params[0] === 2000
                        && $params[1] === 9200
                        && $params[2] === 'ts-1';
                })
            );

        (new DbTimeShiftSessionStore($db))->updateBufferWindow('ts-1', 2000, 9200);
    }

    public function testUpdatePidBindsIntPid(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SET pid = ? WHERE id = ?'),
                $this->callback(function ($params): bool {
                    return is_array($params)
                        && $params[0] === 5150
                        && is_int($params[0])
                        && $params[1] === 'ts-1';
                })
            );

        (new DbTimeShiftSessionStore($db))->updatePid('ts-1', 5150);
    }

    public function testUpdatePidCanClearPidToNull(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SET pid = ? WHERE id = ?'),
                $this->callback(fn($p): bool => is_array($p) && $p[0] === null && $p[1] === 'ts-1')
            );

        (new DbTimeShiftSessionStore($db))->updatePid('ts-1', null);
    }

    public function testUpdateStatusBindsStatusThenId(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SET status = ? WHERE id = ?'),
                $this->callback(fn($p): bool => is_array($p)
                    && $p[0] === TimeShiftSession::STATUS_STOPPED
                    && $p[1] === 'ts-1')
            );

        (new DbTimeShiftSessionStore($db))->updateStatus('ts-1', TimeShiftSession::STATUS_STOPPED);
    }

    public function testDeleteRemovesById(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM livetv_timeshift_sessions WHERE id = ?'),
                $this->callback(fn($p): bool => is_array($p) && $p[0] === 'ts-1')
            );

        (new DbTimeShiftSessionStore($db))->delete('ts-1');
    }

    public function testListActiveHydratesRowsAndBindsActiveStatus(): void
    {
        $db = $this->createMockConnection();
        $rowA = $this->sampleRow();
        $rowB = $this->sampleRow();
        $rowB['id'] = 'ts-2';

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('WHERE status = ?'),
                    $this->stringContains('ORDER BY created_at ASC')
                ),
                $this->callback(fn($p): bool => is_array($p) && $p[0] === TimeShiftSession::STATUS_ACTIVE)
            )
            ->willReturn([$rowA, $rowB]);

        $sessions = (new DbTimeShiftSessionStore($db))->listActive();

        $this->assertCount(2, $sessions);
        $this->assertContainsOnlyInstancesOf(TimeShiftSession::class, $sessions);
        $this->assertSame('ts-1', $sessions[0]->id);
        $this->assertSame('ts-2', $sessions[1]->id);
    }

    public function testListActiveReturnsEmptyOnFalseResult(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->willReturn(false);

        $this->assertSame([], (new DbTimeShiftSessionStore($db))->listActive());
    }

    public function testStartFactoryProducesFreshActiveSession(): void
    {
        $before = time();
        $session = TimeShiftSession::start('sess-9', 'chan-7', '/buf/x', 3600);
        $after = time();

        $this->assertNotSame('', $session->id);
        $this->assertSame('sess-9', $session->session_id);
        $this->assertSame('chan-7', $session->channel_id);
        $this->assertSame('/buf/x', $session->buffer_dir);
        $this->assertSame(3600, $session->window_seconds);
        $this->assertSame(0, $session->cursor_position);
        $this->assertNull($session->pid);
        $this->assertSame(TimeShiftSession::STATUS_ACTIVE, $session->status);
        $this->assertGreaterThanOrEqual($before, $session->buffer_start_at);
        $this->assertLessThanOrEqual($after, $session->buffer_end_at);
    }

    public function testFromRowCoercesLooseTypesAndNullPid(): void
    {
        // Workerman/PDO may return column values as strings; a NULL pid must
        // stay null rather than coerce to 0.
        $session = TimeShiftSession::fromRow([
            'id' => 'ts-1',
            'session_id' => 'sess-9',
            'channel_id' => 'chan-7',
            'buffer_dir' => '/buf/ts-1',
            'pid' => null,
            'buffer_start_at' => '1000000',
            'buffer_end_at' => '1007200',
            'window_seconds' => '7200',
            'cursor_position' => '0',
            'status' => 'active',
        ]);

        $this->assertNull($session->pid);
        $this->assertSame(1_000_000, $session->buffer_start_at);
        $this->assertSame(1_007_200, $session->buffer_end_at);
        $this->assertSame(0, $session->cursor_position);
        $this->assertNull($session->created_at);
    }
}
