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

        // S47 login-read repoint + S46 dual-write: the create path now issues
        // FIVE queries — the S47 identity-first SELECT (user_identities), then
        // the users SELECT, INSERT users, INSERT user_settings, INSERT
        // user_identities. Captured params are collected into one by-ref array so
        // the closure stays single-line.
        $seen = ['insertProvider' => null, 'identity' => null];
        $db->expects($this->exactly(5))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$seen) {
                if (strpos($sql, 'SELECT * FROM users WHERE provider = ? AND external_id = ?') !== false) {
                    return [];
                }
                if (strpos($sql, 'INSERT INTO users') !== false) {
                    // (id, username, email, display_name, provider, external_id, password_hash)
                    $seen['insertProvider'] = $params[4];
                    $this->assertSame('https://accounts.google.com/99999', $params[5]);
                    return [];
                }
                if (strpos($sql, 'INSERT INTO user_settings') !== false) {
                    return [];
                }
                if (strpos($sql, 'INSERT INTO user_identities') !== false) {
                    // (id, user_id, provider, provider_instance, external_id, provider_data)
                    $seen['identity'] = $params;
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

        $this->assertSame('oidc', $seen['insertProvider']);
        $this->assertStringContainsString('-', $userId);
        $this->assertNotEmpty($userId);

        // The dual-written identity row is keyed to the same new user, carries
        // the REAL provider, the '' default-instance sentinel (NOT NULL, so the
        // UNIQUE index enforces — migration 092), and the identical external_id.
        $this->assertNotNull($seen['identity']);
        $this->assertSame($userId, $seen['identity'][1]);
        $this->assertSame('oidc', $seen['identity'][2]);
        $this->assertSame('', $seen['identity'][3]);
        $this->assertSame('https://accounts.google.com/99999', $seen['identity'][4]);
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

        // When the provider supplies no email, NEITHER the username NOR the
        // email column can be a shared/truncated value (both are NOT NULL +
        // UNIQUE — migration 001 — so a SECOND email-less external user would
        // collide on the unique index). The old username fallback
        // 'user_' . substr($externalId, 0, 16) collided for external_ids that
        // share a 16-char prefix (realistic for LDAP DNs); the create path now
        // derives BOTH from a stable sha256 of the unique identity key
        // (provider, external_id) so each email-less user gets a distinct,
        // deterministic, bounded value. Compute the expectations with the SAME
        // formula rather than a brittle literal.
        $identityHash = hash('sha256', "oidc\0https://idp.example.com/abc");
        $expectedUsername = 'user_' . substr($identityHash, 0, 24);
        $expectedEmail = 'oidc+' . $identityHash . '@no-email.local';

        // S47 login-read repoint adds the identity-first SELECT and S46 dual-write
        // adds the INSERT INTO user_identities, so the create path issues FIVE
        // queries.
        $db->expects($this->exactly(5))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use ($expectedUsername, $expectedEmail) {
                if (strpos($sql, 'SELECT * FROM users WHERE provider = ? AND external_id = ?') !== false) {
                    return [];
                }
                if (strpos($sql, 'INSERT INTO users') !== false) {
                    $this->assertSame($expectedUsername, $params[1]);
                    $this->assertSame($expectedEmail, $params[2]);
                    $this->assertSame($expectedUsername, $params[3]);
                    $this->assertSame('oidc', $params[4]);
                    $this->assertSame('https://idp.example.com/abc', $params[5]);
                    // password_hash stays NULL for external identities.
                    $this->assertNull($params[6]);
                    return [];
                }
                if (strpos($sql, 'INSERT INTO user_identities') !== false) {
                    // (id, user_id, provider, provider_instance, external_id, provider_data)
                    $this->assertSame('oidc', $params[2]);
                    // '' default-instance sentinel (NOT NULL — migration 092).
                    $this->assertSame('', $params[3]);
                    $this->assertSame('https://idp.example.com/abc', $params[4]);
                    $this->assertNull($params[5]);
                    return [];
                }
                return [];
            });

        $repo = new UserRepository($db);
        $userId = $repo->findOrCreateByExternalId('oidc', 'https://idp.example.com/abc', null, null);

        $this->assertNotEmpty($userId);
    }

    /**
     * S47 login-read REPOINT: resolution consults `user_identities` FIRST. An
     * identity linked to an existing account via S45 (a user_identities row that
     * has NO matching users.provider/external_id row) must resolve that SAME
     * account on login — never create a duplicate. Proven here by returning the
     * identity row for the user_identities SELECT and asserting the users SELECT /
     * any INSERT are NEVER reached.
     */
    public function test_find_or_create_resolves_via_user_identities_first(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) {
                if (strpos($sql, 'FROM user_identities') !== false) {
                    // The S47 identity-first lookup is keyed on
                    // (provider, default-instance '', external_id).
                    $this->assertSame('oidc', $params[0]);
                    $this->assertSame('', $params[1]);
                    $this->assertSame('oidc.sub-linked', $params[2]);
                    return [[
                        'id' => 'identity-row',
                        'user_id' => 'owner-account',
                        'provider' => 'oidc',
                        'provider_instance' => '',
                        'external_id' => 'oidc.sub-linked',
                    ]];
                }
                // If the users fallback or any INSERT is reached the repoint is
                // broken — a linked identity would create a duplicate account.
                $this->fail('users fallback / INSERT must not run when the identity resolves: ' . $sql);
            });

        $repo = new UserRepository($db);
        $userId = $repo->findOrCreateByExternalId('oidc', 'oidc.sub-linked', 'linked@example.com', 'Linked');

        // Resolves the identity's OWNER, not a freshly-created user.
        $this->assertSame('owner-account', $userId);
    }

    /**
     * S47 backward-compat FALLBACK: an external user whose `user_identities` row
     * was (for any reason) not backfilled still resolves via the legacy
     * authoritative `users.provider`/`external_id` columns, so NO existing user
     * loses login. The identity SELECT returns nothing; the users SELECT returns
     * the pre-existing row.
     */
    public function test_find_or_create_falls_back_to_users_when_no_identity_row(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) {
                if (strpos($sql, 'FROM user_identities') !== false) {
                    return []; // not backfilled
                }
                if (strpos($sql, 'SELECT * FROM users WHERE provider = ? AND external_id = ?') !== false) {
                    return [[
                        'id' => 'legacy-user',
                        'provider' => 'oidc',
                        'external_id' => 'oidc.legacy',
                    ]];
                }
                $this->fail('no INSERT expected — the users fallback resolved: ' . $sql);
            });

        $repo = new UserRepository($db);
        $userId = $repo->findOrCreateByExternalId('oidc', 'oidc.legacy');

        $this->assertSame('legacy-user', $userId);
    }

    /**
     * S47 unlink guard input: hasLocalPassword() is true only when a non-empty
     * password hash is stored (password_hash is nullable since migration 091).
     */
    public function test_has_local_password_true_when_hash_present(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'user-1',
            'username' => 'alice',
            'password_hash' => password_hash('secret', PASSWORD_ARGON2ID),
        ]]);

        $repo = new UserRepository($db);
        $this->assertTrue($repo->hasLocalPassword('user-1'));
    }

    public function test_has_local_password_false_for_external_only_account(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'user-2',
            'username' => 'ext',
            'password_hash' => null, // purely-external account (OIDC/LDAP)
        ]]);

        $repo = new UserRepository($db);
        $this->assertFalse($repo->hasLocalPassword('user-2'));
    }

    public function test_has_local_password_false_when_user_missing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        $this->assertFalse($repo->hasLocalPassword('nope'));
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
