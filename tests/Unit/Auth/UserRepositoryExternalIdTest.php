<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Auth\UserRepository
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
            ->willReturnCallback(function (string $sql, array $params = []) {
                if (strpos($sql, 'SELECT * FROM users WHERE provider = ? AND external_id = ?') !== false) {
                    // The lookup is scoped by BOTH provider and external_id.
                    $this->assertSame('oidc', $params[0]);
                    $this->assertSame('https://accounts.google.com/12345', $params[1]);
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
            'oidc',
            'https://accounts.google.com/12345',
            'alice@example.com',
            'Alice',
        );

        $this->assertSame('existing-user', $userId);
    }

    /**
     * S44 provider-column fix: the INSERT must persist the REAL provider that
     * authenticated (here 'oidc'), never the old hardcoded literal 'external'.
     */
    public function test_find_or_create_by_external_id_creates_with_real_provider(): void
    {
        $db = $this->createMock(Connection::class);

        $insertProvider = null;
        $db->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$insertProvider) {
                if (strpos($sql, 'SELECT * FROM users WHERE provider = ? AND external_id = ?') !== false) {
                    return [];
                }
                if (strpos($sql, 'INSERT INTO users') !== false) {
                    // (id, username, email, display_name, provider, external_id, password_hash)
                    $insertProvider = $params[4];
                    $this->assertSame('https://accounts.google.com/99999', $params[5]);
                    return [];
                }
                if (strpos($sql, 'INSERT INTO user_settings') !== false) {
                    return [];
                }
                return [];
            });

        $repo = new UserRepository($db);
        $userId = $repo->findOrCreateByExternalId(
            'oidc',
            'https://accounts.google.com/99999',
            'newuser@example.com',
            'New User',
        );

        $this->assertSame('oidc', $insertProvider);
        $this->assertStringContainsString('-', $userId);
        $this->assertNotEmpty($userId);
    }

    /**
     * S44 scoping fix: the existence lookup is keyed by (provider, external_id),
     * so an external_id that exists under one provider does NOT cross-match a
     * different provider — a new row is created for the second provider.
     */
    public function test_find_or_create_scopes_lookup_by_provider(): void
    {
        $db = $this->createMock(Connection::class);

        $selectParams = null;
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$selectParams) {
                if (strpos($sql, 'SELECT * FROM users WHERE provider = ? AND external_id = ?') !== false) {
                    $selectParams = $params;
                    // No LDAP row exists for this external_id (only an OIDC one
                    // would, but that must not be returned for provider 'ldap').
                    return [];
                }
                return [];
            });

        $repo = new UserRepository($db);
        $userId = $repo->findOrCreateByExternalId(
            'ldap',
            'ldap.uid=alice,dc=example,dc=com',
        );

        $this->assertNotNull($selectParams);
        $this->assertSame('ldap', $selectParams[0]);
        $this->assertSame('ldap.uid=alice,dc=example,dc=com', $selectParams[1]);
        $this->assertNotEmpty($userId);
    }

    public function test_find_or_create_by_external_id_creates_with_email_as_username_when_no_email(): void
    {
        $db = $this->createMock(Connection::class);

        // When the provider supplies no email, the email column can NOT be a
        // shared '' (users.email is NOT NULL + UNIQUE — migration 001 — so a
        // SECOND email-less external user would collide on the unique index).
        // The create path derives a stable, per-identity placeholder from the
        // unique identity key (provider, external_id) via sha256 so each
        // email-less user gets a distinct, deterministic, bounded value.
        $expectedEmail = 'oidc+'
            . hash('sha256', "oidc\0https://idp.example.com/abc")
            . '@no-email.local';

        $db->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use ($expectedEmail) {
                if (strpos($sql, 'SELECT * FROM users WHERE provider = ? AND external_id = ?') !== false) {
                    return [];
                }
                if (strpos($sql, 'INSERT INTO users') !== false) {
                    $this->assertSame('user_https://idp.exam', $params[1]);
                    $this->assertSame($expectedEmail, $params[2]);
                    $this->assertSame('user_https://idp.exam', $params[3]);
                    $this->assertSame('oidc', $params[4]);
                    $this->assertSame('https://idp.example.com/abc', $params[5]);
                    // password_hash stays NULL for external identities.
                    $this->assertNull($params[6]);
                    return [];
                }
                return [];
            });

        $repo = new UserRepository($db);
        $userId = $repo->findOrCreateByExternalId('oidc', 'https://idp.example.com/abc', null, null);

        $this->assertNotEmpty($userId);
    }

    public function test_update_provider_data(): void
    {
        $db = $this->createMock(Connection::class);
        $capturedBindings = null;
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$capturedBindings) {
                $this->assertStringContainsString('UPDATE users SET provider_data', $sql);
                $capturedBindings = $params;
                return [];
            });

        $repo = new UserRepository($db);
        $repo->updateProviderData('user-uuid-123', [
            'refresh_token' => 'rt_abc123',
            'expires_at' => 1717000000,
        ]);

        $this->assertNotNull($capturedBindings);
        $this->assertIsString($capturedBindings[0]);
        $this->assertStringContainsString('refresh_token', $capturedBindings[0]);
    }
}
