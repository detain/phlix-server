<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Ldap;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Ldap\LdapProvider;
use Phlix\Plugins\Ldap\LdapConnection;
use Phlix\Plugins\Ldap\UserMapper;
use Phlix\Plugins\Ldap\LdapUserInfo;
use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;

final class LdapProviderTest extends TestCase
{
    public function test_implements_provider_interface(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertInstanceOf(ProviderInterface::class, $provider);
    }

    public function test_name_returns_ldap(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertSame('ldap', $provider->name());
    }

    public function test_supports_authentication_with_credentials(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertTrue($provider->supportsAuthentication(['username' => 'testuser', 'password' => 'testpass']));
        $this->assertTrue($provider->supportsAuthentication(['username' => 'alice', 'password' => 'secret123']));
    }

    public function test_supports_authentication_without_credentials(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertFalse($provider->supportsAuthentication([]));
        $this->assertFalse($provider->supportsAuthentication(['username' => 'testuser']));
        $this->assertFalse($provider->supportsAuthentication(['password' => 'testpass']));
        $this->assertFalse($provider->supportsAuthentication(['other' => 'value']));
    }

    public function test_authenticate_missing_username(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $result = $provider->authenticate(['password' => 'testpass']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('missing_username', $result->error);
    }

    public function test_authenticate_missing_password(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $result = $provider->authenticate(['username' => 'testuser']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('missing_password', $result->error);
    }

    public function test_authenticate_empty_username(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $result = $provider->authenticate(['username' => '', 'password' => 'testpass']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('missing_username', $result->error);
    }

    public function test_authenticate_empty_password(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $result = $provider->authenticate(['username' => 'testuser', 'password' => '']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('missing_password', $result->error);
    }

    public function test_authenticate_invalid_credentials(): void
    {
        $mockConnection = $this->createMock(LdapConnection::class);
        $mockConnection->method('authenticate')->willReturn(false);

        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );
        $provider->setConnection($mockConnection);

        $result = $provider->authenticate(['username' => 'testuser', 'password' => 'wrongpass']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('invalid_credentials', $result->error);
    }

    public function test_authenticate_success(): void
    {
        $mockConnection = $this->createMock(LdapConnection::class);
        $mockConnection->method('authenticate')->willReturn(true);
        $mockConnection->method('findUserDn')->willReturn('uid=testuser,ou=users,dc=example,dc=com');
        $mockConnection->method('getUserAttributes')->willReturn([
            'dn' => 'uid=testuser,ou=users,dc=example,dc=com',
            'uid' => ['testuser'],
            'cn' => ['Test User'],
            'mail' => ['testuser@example.com'],
        ]);
        $mockConnection->method('isAdmin')->willReturn(false);

        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );
        $provider->setConnection($mockConnection);

        $result = $provider->authenticate(['username' => 'testuser', 'password' => 'correctpass']);

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->externalId);
        $this->assertStringStartsWith('ldap.', $result->externalId);
        $this->assertNotNull($result->attributes);
        $this->assertSame('testuser', $result->attributes['username']);
        $this->assertSame('testuser@example.com', $result->attributes['email']);
    }

    public function test_authenticate_connection_failure(): void
    {
        $mockConnection = $this->createMock(LdapConnection::class);
        $mockConnection->method('authenticate')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );
        $provider->setConnection($mockConnection);

        $result = $provider->authenticate(['username' => 'testuser', 'password' => 'testpass']);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('ldap_error', $result->error);
    }

    public function test_get_user_info_returns_null(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertNull($provider->getUserInfo('ldap.user123'));
        $this->assertNull($provider->getUserInfo('invalid-prefix.user123'));
    }

    public function test_link_account_does_nothing(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $provider->linkAccount('local-user-id', ['ldap' => 'external-id']);
        $this->assertTrue(true);
    }

    public function test_get_host(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertSame('ldap.example.com', $provider->getHost());
    }

    public function test_get_port(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 636,
            ssl: true,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertSame(636, $provider->getPort());
    }

    public function test_is_ssl(): void
    {
        $providerNoSsl = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $providerWithSsl = new LdapProvider(
            host: 'ldap.example.com',
            port: 636,
            ssl: true,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertFalse($providerNoSsl->isSsl());
        $this->assertTrue($providerWithSsl->isSsl());
    }

    public function test_get_base_dn(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertSame('dc=example,dc=com', $provider->getBaseDn());
    }

    public function test_get_bind_dn(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: 'cn=admin,dc=example,dc=com',
            bindPw: 'secret',
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );

        $this->assertSame('cn=admin,dc=example,dc=com', $provider->getBindDn());
    }

    public function test_get_user_filter(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(&(objectClass=user)(sAMAccountName={{username}}))',
            adminGroup: null,
        );

        $this->assertSame('(&(objectClass=user)(sAMAccountName={{username}}))', $provider->getUserFilter());
    }

    public function test_get_admin_group(): void
    {
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: 'cn=admins,ou=groups,dc=example,dc=com',
        );

        $this->assertSame('cn=admins,ou=groups,dc=example,dc=com', $provider->getAdminGroup());
    }

    public function test_get_user_mapper(): void
    {
        $mapper = new UserMapper();
        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
            userMapper: $mapper,
        );

        $this->assertSame($mapper, $provider->getUserMapper());
    }
}
