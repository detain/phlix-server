<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\UserRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Targeted tests for the S1 signup-approval-gate additions to
 * {@see UserRepository}: status-aware create(), setStatus(), listByStatus().
 *
 * Mirrors {@see UserRepositoryAdminTest}: createMock(Connection) and assert on
 * the SQL + bound params passed to query().
 *
 * @covers \Phlix\Auth\UserRepository
 */
final class UserRepositoryStatusTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────
    // create() — status persistence + coercion
    // ─────────────────────────────────────────────────────────────────

    public function test_create_persists_given_pending_status(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured) {
                $captured[] = ['sql' => $sql, 'params' => $params];
                return [];
            }
        );

        $repo = new UserRepository($db);
        $repo->create([
            'username' => 'nina',
            'email' => 'nina@example.com',
            'password' => 'topsecret123',
            'status' => 'pending',
        ]);

        // First query is the users INSERT; status is the 6th bound param.
        $insert = $captured[0];
        $this->assertStringContainsString('INSERT INTO users', $insert['sql']);
        $this->assertStringContainsString('status', $insert['sql']);
        $this->assertSame('pending', $insert['params'][5]);
    }

    public function test_create_defaults_to_active_when_status_absent(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured) {
                $captured[] = ['sql' => $sql, 'params' => $params];
                return [];
            }
        );

        $repo = new UserRepository($db);
        $repo->create([
            'username' => 'nina',
            'email' => 'nina@example.com',
            'password' => 'topsecret123',
        ]);

        $this->assertSame('active', $captured[0]['params'][5]);
    }

    public function test_create_coerces_unknown_status_to_active(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured) {
                $captured[] = ['sql' => $sql, 'params' => $params];
                return [];
            }
        );

        $repo = new UserRepository($db);
        $repo->create([
            'username' => 'nina',
            'email' => 'nina@example.com',
            'password' => 'topsecret123',
            'status' => 'banana',
        ]);

        $this->assertSame('active', $captured[0]['params'][5]);
    }

    // ─────────────────────────────────────────────────────────────────
    // setStatus() — enum validation
    // ─────────────────────────────────────────────────────────────────

    public function test_set_status_writes_update_for_valid_status(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE users SET status'),
                ['active', 'user-1'],
            );

        $repo = new UserRepository($db);
        $repo->setStatus('user-1', 'active');
    }

    public function test_set_status_is_noop_on_invalid_status(): void
    {
        $db = $this->createMock(Connection::class);
        // An unknown status must never reach the ENUM column.
        $db->expects($this->never())->method('query');

        $repo = new UserRepository($db);
        $repo->setStatus('user-1', 'banana');
    }

    public function test_set_status_accepts_each_valid_enum_value(): void
    {
        foreach (['pending', 'active', 'disabled'] as $status) {
            $db = $this->createMock(Connection::class);
            $db->expects($this->once())
                ->method('query')
                ->with(
                    $this->stringContains('UPDATE users SET status'),
                    [$status, 'user-1'],
                );

            $repo = new UserRepository($db);
            $repo->setStatus('user-1', $status);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // listByStatus()
    // ─────────────────────────────────────────────────────────────────

    public function test_list_by_status_returns_matching_rows(): void
    {
        $rows = [
            ['id' => 'u-1', 'username' => 'nina', 'status' => 'pending'],
            ['id' => 'u-2', 'username' => 'omar', 'status' => 'pending'],
        ];

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('WHERE status = ?'),
                ['pending'],
            )
            ->willReturn($rows);

        $repo = new UserRepository($db);
        $result = $repo->listByStatus('pending');

        $this->assertCount(2, $result);
        $this->assertSame('nina', $result[0]['username']);
    }

    public function test_list_by_status_returns_empty_for_invalid_status(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $repo = new UserRepository($db);
        $this->assertSame([], $repo->listByStatus('banana'));
    }

    public function test_list_by_status_returns_empty_when_query_misses(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(null);

        $repo = new UserRepository($db);
        $this->assertSame([], $repo->listByStatus('disabled'));
    }

    // ─────────────────────────────────────────────────────────────────
    // getStatus() — lightweight PK lookup for the token hot path (S1 fix)
    // ─────────────────────────────────────────────────────────────────

    public function test_get_status_selects_only_status_by_id(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('SELECT status FROM users'),
                    $this->stringContains('WHERE id = ?'),
                ),
                ['user-1'],
            )
            ->willReturn([['status' => 'active']]);

        $repo = new UserRepository($db);
        $this->assertSame('active', $repo->getStatus('user-1'));
    }

    public function test_get_status_returns_disabled_for_disabled_user(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['status' => 'disabled']]);

        $repo = new UserRepository($db);
        $this->assertSame('disabled', $repo->getStatus('user-1'));
    }

    public function test_get_status_returns_null_for_unknown_user(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        $this->assertNull($repo->getStatus('ghost'));
    }
}
