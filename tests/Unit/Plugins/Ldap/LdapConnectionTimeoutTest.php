<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Ldap;

use LdapRecord\Connection;
use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Ldap\LdapConnection;
use ReflectionMethod;

/**
 * Pins the bound that makes the LDAP blocking-I/O exception legitimate.
 *
 * `LdapConnection` is a *registered, accepted* blocking-I/O exception (see
 * `docs/dev/BLOCKING_IO_EXCEPTIONS.md`): ext-ldap is covered by no Swoole
 * runtime hook, so a bind stalls the whole worker. That is only acceptable
 * while the stall is bounded, and it is bounded by TWO options that are NOT
 * interchangeable:
 *
 *  - `timeout`               -> LDAP_OPT_NETWORK_TIMEOUT, bounds the TCP connect;
 *  - `options[LDAP_OPT_TIMEOUT]` -> bounds the bind/search RESULT WAIT.
 *
 * Only the second one bounds the case that actually hangs — a server that
 * accepts the TCP connection and then never answers. Before S44-b it was absent
 * and the worker froze indefinitely (measured >300 s standalone; inside a real
 * Workerman worker the sibling coroutine stopped ticking entirely).
 *
 * These tests read the config off the real LdapRecord Connection objects the
 * production code builds, for BOTH the service-bind connection and the per-user
 * bind connection, because the per-user one is a separate config literal that
 * has drifted from the service one before.
 */
final class LdapConnectionTimeoutTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        LdapConnection::clearCache();
    }

    private function makeConnection(): LdapConnection
    {
        return new LdapConnection(
            host: 'ldap.timeout.test',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: 'cn=admin,dc=example,dc=com',
            bindPw: 'service-secret',
            userFilter: '(uid={{username}})',
        );
    }

    /**
     * @return array<int, int>
     */
    private function optionsOf(Connection $connection): array
    {
        /** @var array<int, int> $options */
        $options = $connection->getConfiguration()->get('options');

        return $options;
    }

    public function test_service_connection_bounds_the_tcp_connect(): void
    {
        $connection = $this->makeConnection()->getConnection();

        $this->assertSame(
            LdapConnection::NETWORK_TIMEOUT_SECONDS,
            $connection->getConfiguration()->get('timeout'),
            'LdapRecord maps the `timeout` key to LDAP_OPT_NETWORK_TIMEOUT (the TCP connect bound).'
        );
    }

    public function test_service_connection_bounds_the_operation_wait(): void
    {
        $options = $this->optionsOf($this->makeConnection()->getConnection());

        $this->assertArrayHasKey(
            LDAP_OPT_TIMEOUT,
            $options,
            'LDAP_OPT_TIMEOUT is missing: the service bind/search result wait is UNBOUNDED again, '
            . 'which is exactly the defect S44-b fixed. See docs/dev/BLOCKING_IO_EXCEPTIONS.md.'
        );
        $this->assertSame(LdapConnection::OPERATION_TIMEOUT_SECONDS, $options[LDAP_OPT_TIMEOUT]);
    }

    public function test_per_user_bind_connection_carries_both_bounds(): void
    {
        $ldap = $this->makeConnection();

        $method = new ReflectionMethod(LdapConnection::class, 'createUserConnection');
        /** @var Connection $userConnection */
        $userConnection = $method->invoke($ldap, 'uid=alice,dc=example,dc=com', 'user-secret');

        $this->assertSame(
            LdapConnection::NETWORK_TIMEOUT_SECONDS,
            $userConnection->getConfiguration()->get('timeout'),
            'The per-user bind is a separate connection and needs the connect bound too.'
        );

        $options = $this->optionsOf($userConnection);
        $this->assertArrayHasKey(
            LDAP_OPT_TIMEOUT,
            $options,
            'LDAP_OPT_TIMEOUT is missing on the per-user bind connection — the credential-check '
            . 'bind is the one on the public login path.'
        );
        $this->assertSame(LdapConnection::OPERATION_TIMEOUT_SECONDS, $options[LDAP_OPT_TIMEOUT]);
    }

    public function test_per_user_bind_uses_the_supplied_credentials_not_the_service_ones(): void
    {
        // Guards the refactor that merged the two config literals into
        // connectionConfig(): the per-user connection must bind AS THE USER.
        $ldap = $this->makeConnection();

        $method = new ReflectionMethod(LdapConnection::class, 'createUserConnection');
        /** @var Connection $userConnection */
        $userConnection = $method->invoke($ldap, 'uid=alice,dc=example,dc=com', 'user-secret');

        $config = $userConnection->getConfiguration();
        $this->assertSame('uid=alice,dc=example,dc=com', $config->get('username'));
        $this->assertSame('user-secret', $config->get('password'));
        $this->assertSame(['ldap.timeout.test'], $config->get('hosts'));
        $this->assertSame(389, $config->get('port'));
    }

    public function test_bounds_are_positive_and_finite(): void
    {
        // A "bound" of 0 means "no timeout" in OpenLDAP, which would silently
        // restore the unbounded stall while keeping every assertion above green.
        $this->assertGreaterThan(0, LdapConnection::OPERATION_TIMEOUT_SECONDS);
        $this->assertGreaterThan(0, LdapConnection::NETWORK_TIMEOUT_SECONDS);
        $this->assertLessThanOrEqual(30, LdapConnection::OPERATION_TIMEOUT_SECONDS);
        $this->assertLessThanOrEqual(30, LdapConnection::NETWORK_TIMEOUT_SECONDS);
    }
}
