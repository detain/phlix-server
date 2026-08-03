<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserIdentityRepository;
use Workerman\MySQL\Connection;

final class UserIdentityRepositoryTest extends TestCase
{
    public function test_create_inserts_identity_row_with_all_columns(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = null;
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
                $this->assertStringContainsString('INSERT INTO user_identities', $sql);
                $captured = $params;
                return [];
            });

        $repo = new UserIdentityRepository($db);
        // '' is the NOT NULL default-instance sentinel (migration 092) — the
        // value single-instance identities carry so the UNIQUE index enforces.
        $id = $repo->create('user-1', 'oidc', '', 'sub-123', null);

        $this->assertNotNull($captured);
        // (id, user_id, provider, provider_instance, external_id, provider_data)
        $this->assertSame($id, $captured[0]);
        $this->assertSame('user-1', $captured[1]);
        $this->assertSame('oidc', $captured[2]);
        $this->assertSame('', $captured[3]);
        $this->assertSame('sub-123', $captured[4]);
        $this->assertNull($captured[5]);
        $this->assertNotEmpty($id);
        $this->assertStringContainsString('-', $id);
    }

    public function test_create_coerces_null_instance_to_empty_string_sentinel(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = null;
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
                $captured = $params;
                return [];
            });

        $repo = new UserIdentityRepository($db);
        // A legacy/null default-instance argument must be stored as '' (never
        // NULL) so the UNIQUE (provider, provider_instance, external_id) index
        // actually rejects a duplicate single-instance identity.
        $repo->create('user-1', 'oidc', null, 'sub-123', null);

        $this->assertNotNull($captured);
        $this->assertSame('', $captured[3]);
    }

    public function test_create_json_encodes_array_provider_data(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = null;
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
            $captured = $params;
            return [];
        });

        $repo = new UserIdentityRepository($db);
        $repo->create('user-1', 'github', 'github-enterprise', 'gh-9', ['login' => 'octocat']);

        $this->assertNotNull($captured);
        $this->assertSame('github-enterprise', $captured[3]);
        $this->assertIsString($captured[5]);
        $this->assertStringContainsString('octocat', $captured[5]);
        $this->assertSame(['login' => 'octocat'], json_decode($captured[5], true));
    }

    public function test_create_stores_string_provider_data_verbatim(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = null;
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
            $captured = $params;
            return [];
        });

        $repo = new UserIdentityRepository($db);
        $repo->create('user-1', 'ldap', '', 'ldap.uid=alice', '{"already":"json"}');

        $this->assertNotNull($captured);
        $this->assertSame('', $captured[3]);
        $this->assertSame('{"already":"json"}', $captured[5]);
    }

    public function test_find_by_provider_external_id_uses_equality_and_sentinel_for_default_instance(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = null;
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
                // provider_instance is NOT NULL DEFAULT '' — always a plain
                // equality, never an IS NULL special case.
                $this->assertStringContainsString('provider_instance = ?', $sql);
                $this->assertStringNotContainsString('IS NULL', $sql);
                $captured = $params;
                return [[
                    'id' => 'ident-1',
                    'user_id' => 'user-1',
                    'provider' => 'oidc',
                    'provider_instance' => '',
                    'external_id' => 'sub-123',
                ]];
            });

        $repo = new UserIdentityRepository($db);
        // A null default-instance argument is coerced to the '' sentinel.
        $row = $repo->findByProviderExternalId('oidc', null, 'sub-123');

        $this->assertIsArray($row);
        $this->assertSame('ident-1', $row['id']);
        // The default single instance binds '' for provider_instance.
        $this->assertSame(['oidc', '', 'sub-123'], $captured);
    }

    public function test_find_by_provider_external_id_binds_instance_when_present(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = null;
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
                $this->assertStringContainsString('provider_instance = ?', $sql);
                $captured = $params;
                return [];
            });

        $repo = new UserIdentityRepository($db);
        $row = $repo->findByProviderExternalId('oidc', 'okta-oidc', 'sub-999');

        $this->assertNull($row);
        $this->assertSame(['oidc', 'okta-oidc', 'sub-999'], $captured);
    }

    public function test_find_by_provider_external_id_returns_null_when_not_found(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserIdentityRepository($db);
        $this->assertNull($repo->findByProviderExternalId('oidc', null, 'missing'));
    }

    public function test_find_by_user_id_returns_rows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('WHERE user_id = ?'),
                ['user-42'],
            )
            ->willReturn([
                ['id' => 'ident-1', 'provider' => 'oidc'],
                ['id' => 'ident-2', 'provider' => 'ldap'],
            ]);

        $repo = new UserIdentityRepository($db);
        $rows = $repo->findByUserId('user-42');

        $this->assertCount(2, $rows);
        $this->assertSame('ident-1', $rows[0]['id']);
        $this->assertSame('ident-2', $rows[1]['id']);
    }

    public function test_find_by_user_id_returns_empty_array_when_none(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserIdentityRepository($db);
        $this->assertSame([], $repo->findByUserId('user-none'));
    }

    public function test_delete_by_id_issues_delete(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM user_identities WHERE id = ?'),
                ['ident-9'],
            )
            ->willReturn([]);

        $repo = new UserIdentityRepository($db);
        $repo->deleteById('ident-9');
    }

    public function test_delete_by_key_uses_equality_and_sentinel_for_default_instance(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = null;
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
                // provider_instance is NOT NULL DEFAULT '' — plain equality only.
                $this->assertStringContainsString('provider_instance = ?', $sql);
                $this->assertStringNotContainsString('IS NULL', $sql);
                $captured = $params;
                return [];
            });

        $repo = new UserIdentityRepository($db);
        // A null default-instance argument is coerced to the '' sentinel.
        $repo->delete('user-1', 'oidc', null, 'sub-123');

        $this->assertSame(['user-1', 'oidc', '', 'sub-123'], $captured);
    }

    public function test_delete_by_key_binds_instance_when_present(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = null;
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
                $this->assertStringContainsString('provider_instance = ?', $sql);
                $captured = $params;
                return [];
            });

        $repo = new UserIdentityRepository($db);
        $repo->delete('user-1', 'oidc', 'okta-oidc', 'sub-123');

        $this->assertSame(['user-1', 'oidc', 'okta-oidc', 'sub-123'], $captured);
    }
}
