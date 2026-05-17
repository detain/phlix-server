<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlex\Auth\UserRepository;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlex\Auth\UserRepository
 */
final class UserRepositoryExternalIdTest extends TestCase
{
    public function test_find_by_external_id(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('provider = ? AND external_id = ?'),
                ['oidc', 'https://accounts.google.com/12345'],
            )
            ->willReturn([[
                'id' => 'user-ext-1',
                'username' => 'alice',
                'provider' => 'oidc',
                'external_id' => 'https://accounts.google.com/12345',
            ]]);

        $repo = new UserRepository($db);
        $user = $repo->findByExternalId('oidc', 'https://accounts.google.com/12345');

        $this->assertIsArray($user);
        $this->assertSame('user-ext-1', $user['id']);
    }

    public function test_find_by_external_id_returns_null_when_not_found(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        $this->assertNull($repo->findByExternalId('oidc', 'nonexistent'));
    }

    public function test_find_or_create_by_external_id_finds(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql) {
                if (strpos($sql, 'SELECT * FROM users WHERE external_id = ?') !== false) {
                    return [[
                        'id' => 'existing-user',
                        'username' => 'alice',
                        'provider' => 'oidc',
                        'external_id' => 'https://accounts.google.com/12345',
                    ]];
                }
                return [];
            });

        $repo = new UserRepository($db);
        $userId = $repo->findOrCreateByExternalId(
            'https://accounts.google.com/12345',
            'alice@example.com',
            'Alice',
        );

        $this->assertSame('existing-user', $userId);
    }

    public function test_find_or_create_by_external_id_creates(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) {
                if (strpos($sql, 'SELECT * FROM users WHERE external_id = ?') !== false) {
                    return [];
                }
                if (strpos($sql, 'INSERT INTO users') !== false) {
                    return [];
                }
                if (strpos($sql, 'INSERT INTO user_settings') !== false) {
                    return [];
                }
                return [];
            });

        $repo = new UserRepository($db);
        $userId = $repo->findOrCreateByExternalId(
            'https://accounts.google.com/99999',
            'newuser@example.com',
            'New User',
        );

        $this->assertIsString($userId);
        $this->assertNotEmpty($userId);
    }

    public function test_find_or_create_by_external_id_creates_with_email_as_username_when_no_email(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) {
                if (strpos($sql, 'SELECT * FROM users WHERE external_id = ?') !== false) {
                    return [];
                }
                if (strpos($sql, 'INSERT INTO users') !== false) {
                    $this->assertSame('user_https://idp.exam', $params[1]);
                    $this->assertSame('', $params[2]);
                    $this->assertSame('user_https://idp.exam', $params[3]);
                    $this->assertSame('external', $params[4]);
                    $this->assertSame('https://idp.example.com/abc', $params[5]);
                    return [];
                }
                return [];
            });

        $repo = new UserRepository($db);
        $userId = $repo->findOrCreateByExternalId('https://idp.example.com/abc', null, null);

        $this->assertIsString($userId);
    }

    public function test_update_provider_data(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE users SET provider_data'),
                $this->callback(fn($v) => is_array($v) && is_string($v[0]) && strpos($v[0], 'refresh_token') !== false),
            );

        $repo = new UserRepository($db);
        $repo->updateProviderData('user-uuid-123', [
            'refresh_token' => 'rt_abc123',
            'expires_at' => 1717000000,
        ]);

        $this->assertTrue(true);
    }
}
