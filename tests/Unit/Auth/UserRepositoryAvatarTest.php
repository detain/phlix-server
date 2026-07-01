<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\UserRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Auth\UserRepository::clearAvatar
 */
final class UserRepositoryAvatarTest extends TestCase
{
    public function test_clear_avatar_calls_correct_sql_with_user_id_bound(): void
    {
        $capturedSql = null;
        $capturedBindings = null;

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $bindings = []) use (&$capturedSql, &$capturedBindings) {
                $capturedSql = $sql;
                $capturedBindings = $bindings;
                return [];
            });

        $repo = new UserRepository($db);
        $repo->clearAvatar('user-uuid-123');

        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('UPDATE users', $capturedSql);
        $this->assertStringContainsString('avatar_url = NULL', $capturedSql);
        $this->assertStringContainsString('WHERE id = ?', $capturedSql);
        $this->assertSame(['user-uuid-123'], $capturedBindings);
    }

    public function test_clear_avatar_uses_null_not_empty_string(): void
    {
        $capturedSql = null;

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return [];
            });

        $repo = new UserRepository($db);
        $repo->clearAvatar('user-uuid-456');

        // Must use NULL keyword, not empty string ''
        $this->assertStringContainsString('avatar_url = NULL', $capturedSql);
        $this->assertStringNotContainsString("avatar_url = ''", $capturedSql);
        $this->assertStringNotContainsString('avatar_url = ""', $capturedSql);
    }
}
