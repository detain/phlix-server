<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Ldap;

use PHPUnit\Framework\TestCase;
use Phlex\Plugins\Ldap\LdapConnection;

final class LdapConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        LdapConnection::clearCache();
    }

    public function test_cached_connection_same_instance(): void
    {
        $connection1 = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $connection2 = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $this->assertSame($connection1->getConnection(), $connection2->getConnection());
    }

    public function test_different_connection_for_different_host(): void
    {
        $connection1 = new LdapConnection(
            host: 'ldap1.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $connection2 = new LdapConnection(
            host: 'ldap2.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $this->assertNotSame($connection1, $connection2);
    }

    public function test_different_connection_for_different_port(): void
    {
        $connection1 = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $connection2 = new LdapConnection(
            host: 'ldap.example.com',
            port: 636,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $this->assertNotSame($connection1, $connection2);
    }

    public function test_different_connection_for_ssl_vs_plain(): void
    {
        $connection1 = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $connection2 = new LdapConnection(
            host: 'ldap.example.com',
            port: 636,
            ssl: true,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $this->assertNotSame($connection1, $connection2);
    }

    public function test_get_host(): void
    {
        $connection = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $this->assertSame('ldap.example.com', $connection->getHost());
    }

    public function test_get_port(): void
    {
        $connection = new LdapConnection(
            host: 'ldap.example.com',
            port: 636,
            ssl: true,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $this->assertSame(636, $connection->getPort());
    }

    public function test_is_ssl(): void
    {
        $connectionNoSsl = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $connectionWithSsl = new LdapConnection(
            host: 'ldap.example.com',
            port: 636,
            ssl: true,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $this->assertFalse($connectionNoSsl->isSsl());
        $this->assertTrue($connectionWithSsl->isSsl());
    }

    public function test_get_base_dn(): void
    {
        $connection = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $this->assertSame('dc=example,dc=com', $connection->getBaseDn());
    }

    public function test_get_user_filter(): void
    {
        $connection = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(&(objectClass=user)(sAMAccountName={{username}}))',
        );

        $this->assertSame('(&(objectClass=user)(sAMAccountName={{username}}))', $connection->getUserFilter());
    }

    public function test_clear_cache(): void
    {
        $connection1 = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        LdapConnection::clearCache();

        $connection2 = new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
        );

        $this->assertNotSame($connection1, $connection2);
    }
}
