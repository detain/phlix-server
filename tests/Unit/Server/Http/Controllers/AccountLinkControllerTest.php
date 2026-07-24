<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Plugins\Ldap\LdapConnection;
use Phlix\Plugins\Ldap\LdapProvider;
use Phlix\Server\Http\Controllers\AccountLinkController;
use Phlix\Server\Http\Request;

/**
 * S45 — the authenticated account-linking endpoints (list + LDAP link).
 *
 * @covers \Phlix\Server\Http\Controllers\AccountLinkController
 */
final class AccountLinkControllerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // GET /auth/identities
    // -----------------------------------------------------------------------

    public function test_list_requires_authentication(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('findByUserId');

        $controller = new AccountLinkController($identities, new AuthProviderRegistry());

        $request = new Request();
        // No userId set → unauthenticated.

        $response = $controller->listIdentities($request, []);

        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('auth.required', $body['code']);
    }

    public function test_list_returns_identities_without_leaking_provider_data(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->once())
            ->method('findByUserId')
            ->with('user-1')
            ->willReturn([
                [
                    'id' => 'id-1',
                    'user_id' => 'user-1',
                    'provider' => 'oidc',
                    'provider_instance' => '',
                    'external_id' => 'oidc.sub-123',
                    // A secret the client must NEVER see.
                    'provider_data' => '{"access_token":"super-secret"}',
                    'created_at' => '2026-07-24 10:00:00',
                ],
            ]);

        $controller = new AccountLinkController($identities, new AuthProviderRegistry());

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->listIdentities($request, []);

        $this->assertSame(200, $response->statusCode);
        // The secret provider_data must not appear anywhere in the payload.
        $this->assertStringNotContainsString('super-secret', $response->body);
        $this->assertStringNotContainsString('provider_data', $response->body);

        /** @var array{identities: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true);
        $this->assertCount(1, $body['identities']);
        $identity = $body['identities'][0];
        $this->assertSame('id-1', $identity['id']);
        $this->assertSame('oidc', $identity['provider']);
        $this->assertSame('', $identity['provider_instance']);
        $this->assertSame('oidc.sub-123', $identity['external_id']);
        $this->assertSame('2026-07-24 10:00:00', $identity['linked_at']);
        $this->assertArrayNotHasKey('provider_data', $identity);
    }

    // -----------------------------------------------------------------------
    // POST /auth/identities/link/ldap
    // -----------------------------------------------------------------------

    public function test_link_ldap_requires_authentication(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('create');

        $controller = new AccountLinkController($identities, new AuthProviderRegistry());

        $request = new Request();
        $request->body = ['username' => 'alice', 'password' => 'pw'];

        $response = $controller->linkLdap($request, []);

        $this->assertSame(401, $response->statusCode);
    }

    public function test_link_ldap_missing_credentials_returns_400(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('create');

        $controller = new AccountLinkController($identities, new AuthProviderRegistry());

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = ['username' => 'alice']; // no password

        $response = $controller->linkLdap($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('missing_credentials', $body['error']);
    }

    public function test_link_ldap_provider_not_registered_returns_503(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('create');

        // Empty registry — LDAP not enabled/registered.
        $controller = new AccountLinkController($identities, new AuthProviderRegistry());

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = ['username' => 'alice', 'password' => 'pw'];

        $response = $controller->linkLdap($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('provider_unavailable', $body['code']);
    }

    public function test_link_ldap_links_verified_identity_on_successful_bind(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn(null);
        // The linked identity MUST be the provider-verified `ldap.<dn>`, linked to
        // the CURRENT user — never login tokens, never a mutation of `users`.
        $identities->expects($this->once())
            ->method('create')
            ->with(
                'user-1',
                'ldap',
                '',
                'ldap.uid=alice,ou=users,dc=example,dc=com',
                $this->anything(),
            )
            ->willReturn('new-id');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->ldapProviderWithSuccessfulBind());

        $controller = new AccountLinkController($identities, $registry);

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = ['username' => 'alice', 'password' => 'correct'];

        $response = $controller->linkLdap($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
        $this->assertSame('ldap', $body['provider']);
        $this->assertTrue($body['created']);
    }

    public function test_link_ldap_failed_bind_does_not_link(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects($this->never())->method('create');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->ldapProviderWithFailedBind());

        $controller = new AccountLinkController($identities, $registry);

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = ['username' => 'alice', 'password' => 'wrong'];

        $response = $controller->linkLdap($request, []);

        // A single generic 401 — no linking, no user-enumeration oracle.
        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid credentials', $body['message']);
    }

    public function test_link_ldap_conflict_when_identity_owned_by_another_user(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        // The verified identity already belongs to a DIFFERENT account.
        $identities->method('findByProviderExternalId')->willReturn([
            'id' => 'existing',
            'user_id' => 'someone-else',
            'provider' => 'ldap',
            'provider_instance' => '',
            'external_id' => 'ldap.uid=alice,ou=users,dc=example,dc=com',
        ]);
        $identities->expects($this->never())->method('create');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->ldapProviderWithSuccessfulBind());

        $controller = new AccountLinkController($identities, $registry);

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = ['username' => 'alice', 'password' => 'correct'];

        $response = $controller->linkLdap($request, []);

        $this->assertSame(409, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('identity_already_linked', $body['error']);
    }

    public function test_link_ldap_idempotent_when_already_linked_to_same_user(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn([
            'id' => 'existing',
            'user_id' => 'user-1',
            'provider' => 'ldap',
            'provider_instance' => '',
            'external_id' => 'ldap.uid=alice,ou=users,dc=example,dc=com',
        ]);
        $identities->expects($this->never())->method('create');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->ldapProviderWithSuccessfulBind());

        $controller = new AccountLinkController($identities, $registry);

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = ['username' => 'alice', 'password' => 'correct'];

        $response = $controller->linkLdap($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
        $this->assertFalse($body['created']);
    }

    /**
     * SECURITY LINCHPIN: a client-supplied `external_id` (or `provider`) in the
     * request body is NEVER trusted. The identity that gets linked is the one the
     * LDAP bind verified, not anything the caller claimed.
     */
    public function test_link_ldap_never_trusts_client_supplied_external_id(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->method('findByProviderExternalId')->willReturn(null);
        $identities->expects($this->once())
            ->method('create')
            ->with(
                'user-1',
                'ldap',
                '',
                // The VERIFIED dn — NOT the attacker-supplied 'ldap.victim'.
                'ldap.uid=alice,ou=users,dc=example,dc=com',
                $this->anything(),
            )
            ->willReturn('new-id');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->ldapProviderWithSuccessfulBind());

        $controller = new AccountLinkController($identities, $registry);

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = [
            'username' => 'alice',
            'password' => 'correct',
            // Hostile fields that must be ignored entirely.
            'external_id' => 'ldap.victim',
            'provider' => 'github',
            'user_id' => 'victim-user',
        ];

        $response = $controller->linkLdap($request, []);

        $this->assertSame(200, $response->statusCode);
    }

    public function test_link_ldap_conflict_maps_db_duplicate_to_409_not_500(): void
    {
        $identities = $this->createMock(UserIdentityRepository::class);
        // No pre-existing row on the first check → we attempt create().
        $identities->method('findByProviderExternalId')->willReturnOnConsecutiveCalls(
            null,
            // Post-create re-read: a concurrent link by ANOTHER user won the race.
            [
                'id' => 'raced',
                'user_id' => 'someone-else',
                'external_id' => 'ldap.uid=alice,ou=users,dc=example,dc=com',
            ],
        );
        // create() throws the DB UNIQUE (1062) violation.
        $identities->method('create')->willThrowException(
            new \RuntimeException('Duplicate entry for key idx_identity (1062)'),
        );

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->ldapProviderWithSuccessfulBind());

        $controller = new AccountLinkController($identities, $registry);

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = ['username' => 'alice', 'password' => 'correct'];

        $response = $controller->linkLdap($request, []);

        // The duplicate-key throw is mapped to 409, never surfaced as a 500.
        $this->assertSame(409, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('identity_already_linked', $body['error']);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function ldapProviderWithSuccessfulBind(): LdapProvider
    {
        $connection = $this->createMock(LdapConnection::class);
        $connection->method('authenticate')->willReturn(true);
        $connection->method('findUserDn')->willReturn('uid=alice,ou=users,dc=example,dc=com');
        $connection->method('getUserAttributes')->willReturn([
            'dn' => 'uid=alice,ou=users,dc=example,dc=com',
            'uid' => ['alice'],
            'cn' => ['Alice Example'],
            'mail' => ['alice@example.com'],
        ]);
        $connection->method('isAdmin')->willReturn(false);

        $provider = $this->newLdapProvider();
        $provider->setConnection($connection);

        return $provider;
    }

    private function ldapProviderWithFailedBind(): LdapProvider
    {
        $connection = $this->createMock(LdapConnection::class);
        $connection->method('authenticate')->willReturn(false);

        $provider = $this->newLdapProvider();
        $provider->setConnection($connection);

        return $provider;
    }

    private function newLdapProvider(): LdapProvider
    {
        return new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );
    }
}
