<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\UserRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Targeted tests for the admin-flag helpers added to
 * {@see UserRepository} in Step A.5.
 */
final class UserRepositoryAdminTest extends TestCase
{
    public function test_find_admin_by_id_returns_row_for_admin(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('is_admin = 1'),
                ['user-1'],
            )
            ->willReturn([['id' => 'user-1', 'username' => 'root', 'is_admin' => 1]]);

        $repo = new UserRepository($db);
        $row  = $repo->findAdminById('user-1');

        $this->assertIsArray($row);
        $this->assertSame('user-1', $row['id']);
    }

    public function test_find_admin_by_id_returns_null_for_non_admin(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        $this->assertNull($repo->findAdminById('user-2'));
    }

    /**
     * S1 security fix (finding 2): findAdminById() must gate on
     * status = 'active' so a disabled admin is treated as a non-admin and
     * cannot pass AdminMiddleware::checkAccess().
     */
    public function test_find_admin_by_id_query_requires_active_status(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('is_admin = 1'),
                    $this->stringContains("status = 'active'"),
                ),
                ['user-1'],
            )
            ->willReturn([['id' => 'user-1', 'username' => 'root', 'is_admin' => 1, 'status' => 'active']]);

        $repo = new UserRepository($db);
        $row  = $repo->findAdminById('user-1');

        $this->assertIsArray($row);
        $this->assertSame('user-1', $row['id']);
    }

    /**
     * A disabled admin row is filtered out by the SQL predicate, so the
     * (empty) result set maps to null — the same contract as a non-admin.
     */
    public function test_find_admin_by_id_returns_null_for_disabled_admin(): void
    {
        $db = $this->createMock(Connection::class);
        // The "AND status = 'active'" predicate makes the DB return no row for a
        // disabled admin; the repository maps that empty result to null.
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        $this->assertNull($repo->findAdminById('disabled-admin'));
    }

    public function test_count_users_returns_integer(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['c' => '7']]);

        $repo = new UserRepository($db);
        $this->assertSame(7, $repo->countUsers());
    }

    public function test_count_users_returns_zero_on_empty_result(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        $this->assertSame(0, $repo->countUsers());
    }

    public function test_set_admin_writes_expected_update(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE users SET is_admin'),
                [1, 'user-1'],
            );

        $repo = new UserRepository($db);
        $repo->setAdmin('user-1', true);
    }

    public function test_set_admin_demotes(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE users SET is_admin'),
                [0, 'user-1'],
            );

        $repo = new UserRepository($db);
        $repo->setAdmin('user-1', false);
    }

    // --- S61: repository-level last-admin guard -----------------------------

    public function test_count_admins_uses_is_admin_predicate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('is_admin = 1'))
            ->willReturn([['c' => '3']]);

        $repo = new UserRepository($db);
        $this->assertSame(3, $repo->countAdmins());
    }

    public function test_is_last_admin_true_for_the_only_admin(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['c' => '1']]);

        $repo = new UserRepository($db);
        $this->assertTrue($repo->isLastAdmin(['id' => 'root', 'is_admin' => 1]));
    }

    public function test_is_last_admin_false_when_more_than_one_admin(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['c' => '2']]);

        $repo = new UserRepository($db);
        $this->assertFalse($repo->isLastAdmin(['id' => 'root', 'is_admin' => 1]));
    }

    public function test_is_last_admin_false_for_non_admin_without_counting(): void
    {
        $db = $this->createMock(Connection::class);
        // A non-admin short-circuits before any SELECT COUNT is issued, so the
        // query must never run — this is the guard's OWN anti-vacuity control:
        // if the short-circuit were removed the query would fire and this fails.
        $db->expects($this->never())->method('query');

        $repo = new UserRepository($db);
        $this->assertFalse($repo->isLastAdmin(['id' => 'alice', 'is_admin' => 0]));
    }
}
