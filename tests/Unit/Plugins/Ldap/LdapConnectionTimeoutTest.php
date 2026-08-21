<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Ldap;

use LdapRecord\Connection;
use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Ldap\LdapConnection;
use ReflectionMethod;

/**
 * Pins the bound that makes the LDAP blocking-I/O exception legitimate — that
 * it is both DECLARED in the config and actually FIRES at runtime.
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
 * This file therefore carries two classes of test:
 *
 *  - **Config-presence** (below): read the options off the real LdapRecord
 *    Connection objects the production code builds, for BOTH the service-bind
 *    connection and the per-user bind connection, because the per-user one is a
 *    separate config literal that has drifted from the service one before.
 *
 *  - **Real-timing** (new): prove the operation bound is not just declared but
 *    FIRES, by opening a real socket against a controlled dead dependency on
 *    loopback and timing `testConnection()`. The peer is a listening socket
 *    that is never `accept()`ed: the kernel still completes the TCP handshake
 *    from the backlog, so ext-ldap gets a connection, sends the bind/startTLS
 *    request, and waits for an answer that never comes — only LDAP_OPT_TIMEOUT
 *    can end that wait. `LDAP_OPT_NETWORK_TIMEOUT` alone does NOT bound the
 *    operation wait on this stack.
 *
 * ## The identical zero
 *
 * Both dead dependencies fail with the SAME shape — `success: false`,
 * `error: 'ldap_error'` — so the failure result alone cannot tell them apart.
 * A HUNG peer (accepts, never answers) is ended by the 5-second operation
 * bound; a REFUSED peer (connection reset) fails in well under a second. That
 * is why the timing cases below carry opposite elapsed-time guards: the hung
 * case must NOT return fast (a fast return means a refusal, which would not
 * detect a missing LDAP_OPT_TIMEOUT), and the refused case must NOT wait near
 * the bound (a near-bound wait means the dependency hung and the bound — or its
 * absence — is what ended it).
 *
 * ## How this file fails
 *
 * If `options[LDAP_OPT_TIMEOUT]` is removed, the config-presence assertions go
 * red AND the hung-peer case does not report a failure — it **hangs**, and the
 * CI job times out. That is the correct signal and not a flake: an unbounded
 * bind has no deadline for the assertion to check. Do not "fix" a timeout here
 * by relaxing the bounds below; look for a missing `LDAP_OPT_TIMEOUT` in
 * `LdapConnection::connectionConfig()`. This is the same hang signal
 * `OAuth2HttpClientTimeoutTest` uses to detect a missing `CURLOPT_TIMEOUT`.
 */
final class LdapConnectionTimeoutTest extends TestCase
{
    /** @var resource|null */
    private $silentServer = null;

    private int $silentPort = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('Cannot bind a loopback listener: ' . $errstr);
        }

        $name = stream_socket_get_name($server, false);
        if ($name === false) {
            fclose($server);
            $this->markTestSkipped('Cannot read the loopback listener address.');
        }

        // Deliberately never accept()ed: the kernel completes the TCP handshake
        // from the backlog, so the LDAP client gets a connection and then waits
        // for a bind/startTLS answer that never comes. Only LDAP_OPT_TIMEOUT
        // can end that wait.
        $this->silentServer = $server;
        $this->silentPort = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $this->silentPort);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->silentServer)) {
            fclose($this->silentServer);
        }
        $this->silentServer = null;
        LdapConnection::clearCache();
        parent::tearDown();
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

    private function makeDeadDependencyConnection(): LdapConnection
    {
        return new LdapConnection(
            host: '127.0.0.1',
            port: $this->silentPort,
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

    /**
     * @group slow
     */
    public function test_a_peer_that_never_answers_consumes_the_operation_bound(): void
    {
        // THE load-bearing case: the dead dependency accepts the TCP connection
        // (kernel-completed from the backlog) and never answers, so the only
        // thing that can end testConnection() is LDAP_OPT_TIMEOUT.
        $ldap = $this->makeDeadDependencyConnection();

        $started = hrtime(true);
        $result = $ldap->testConnection();
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertFalse(
            $result['success'],
            'A peer that never answers must fail the connection test, not hang.'
        );
        $this->assertSame(
            'ldap_error',
            $result['error'],
            'The identical zero: a hung peer and a refused peer fail with the same shape; '
            . 'only elapsed time distinguishes them.'
        );
        $this->assertGreaterThanOrEqual(
            0.5,
            $elapsed,
            'Returned far too fast to have consumed the 5-second operation bound — the '
            . 'request failed for an unrelated reason (e.g. a fast refusal), so this case '
            . 'would NOT detect a missing LDAP_OPT_TIMEOUT.'
        );
        $this->assertLessThan(
            8.0,
            $elapsed,
            'The 5-second operation bound did not fire; LDAP_OPT_TIMEOUT is the only thing '
            . 'that ends this bind; without it the call hangs (see '
            . 'docs/dev/BLOCKING_IO_EXCEPTIONS.md).'
        );
    }

    /**
     * @group slow
     */
    public function test_a_refused_peer_fails_fast_distinguishing_refusal_from_hang(): void
    {
        // DEAD-DEPENDENCY CONTROL: a just-closed loopback port refuses the
        // connection, so testConnection() fails in well under the bound — with
        // the SAME failure shape as the hang case (success:false,
        // error:'ldap_error'), which only elapsed time can tell apart.
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('Cannot bind a loopback listener: ' . $errstr);
        }

        $name = stream_socket_get_name($server, false);
        if ($name === false) {
            fclose($server);
            $this->markTestSkipped('Cannot read the loopback listener address.');
        }

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($server);

        $ldap = new LdapConnection(
            host: '127.0.0.1',
            port: $port,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: 'cn=admin,dc=example,dc=com',
            bindPw: 'service-secret',
            userFilter: '(uid={{username}})',
        );

        $started = hrtime(true);
        $result = $ldap->testConnection();
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertFalse($result['success'], 'A refused peer must fail the connection test.');
        $this->assertSame(
            'ldap_error',
            $result['error'],
            'The identical zero: a refused peer and a hung peer fail with the same shape; '
            . 'only elapsed time distinguishes them.'
        );
        $this->assertLessThan(
            0.5,
            $elapsed,
            'A refused dependency fails in well under the bound; a near-bound wait would '
            . 'mean the dependency hung and the bound (or its absence) is what ended it.'
        );
    }
}
