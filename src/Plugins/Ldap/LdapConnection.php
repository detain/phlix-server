<?php

/**
 * Phlix media server component: Ldap.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Ldap;

use LdapRecord\Connection;
use LdapRecord\LdapRecordException;
use RuntimeException;

/**
 * Thin wrapper over an {@see \LdapRecord\Connection} for auth queries.
 *
 * ## Accepted blocking-I/O exception #1 (S44 / S44-b)
 *
 * Unlike the OIDC path — whose HTTP calls run through
 * {@see \Phlix\Plugins\Oidc\OidcHttpClient} — the LDAP connect/bind/search here
 * is genuinely BLOCKING and cannot be made otherwise. LdapRecord sits on PHP's
 * `ext-ldap`, i.e. on OpenLDAP's own C socket handling, which no Swoole runtime
 * hook covers — not even `SWOOLE_HOOK_ALL`. There is no drop-in async client to
 * yield to the event loop, and faking async for ext-ldap would be dishonest.
 * This is therefore a **named, registered exception** to the "all NEW I/O must
 * be non-blocking" rule; see `docs/dev/BLOCKING_IO_EXCEPTIONS.md`.
 *
 * ## The bound (S44-b — it did NOT previously exist)
 *
 * The pre-S44-b text claimed the stall was "bounded" by the 5-second `timeout`
 * config key. **It was not.** LdapRecord maps `timeout` to
 * `LDAP_OPT_NETWORK_TIMEOUT` only (`LdapRecord\Connection::configure()`), which
 * bounds the TCP *connect* and nothing else. Measured against a server that
 * ACCEPTS the connection and then never answers — a hung or half-open directory,
 * the common real failure — `testConnection()`, `findUserDn()` and
 * `authenticate()` all hung indefinitely (>300 s), and inside a real Workerman
 * worker the whole event loop froze with them: a 100 ms sibling coroutine ticked
 * 9 times in the first second and then **zero** times for the rest of the run.
 *
 * Both connections therefore also set `LDAP_OPT_TIMEOUT`
 * ({@see self::OPERATION_TIMEOUT_SECONDS}), which bounds the synchronous
 * bind/search result wait. Re-measured with it in place, the same hung server
 * returned in 5 043 ms and the worker resumed ticking immediately after. The
 * option survives LdapRecord's `array_replace()` in `configure()` because that
 * call only overrides `LDAP_OPT_PROTOCOL_VERSION`, `LDAP_OPT_NETWORK_TIMEOUT`
 * and `LDAP_OPT_REFERRALS`.
 *
 * So the exception is: **one HTTP worker stalls for at most
 * {@see self::OPERATION_TIMEOUT_SECONDS} seconds per LDAP operation.** The bound
 * is PER OPERATION, and some entry points issue more than one:
 * {@see self::findUserDn()} retries via {@see self::searchForUserDn()} when a
 * service bind DN is configured, so it is 2 operations, and a full
 * {@see \Phlix\Plugins\Ldap\LdapProvider::authenticate()} against a hung server
 * measured **10 037 ms** end to end (it short-circuits on `invalid_credentials`
 * after the failed user bind). The two reachable entry points are the login path
 * ({@see \Phlix\Auth\AuthManager::loginWithProvider()}, itself behind the per-IP
 * brute-force throttle) and the admin `POST /admin/auth-providers/ldap/test`.
 *
 * If LDAP latency ever becomes a problem under load, the correct fix is to move
 * the bind onto a dedicated worker/queue rather than to pretend the current call
 * is non-blocking — or to shrink these constants.
 */
class LdapConnection
{
    /**
     * TCP connect timeout in seconds (`LDAP_OPT_NETWORK_TIMEOUT`, set by
     * LdapRecord from the `timeout` config key).
     */
    public const NETWORK_TIMEOUT_SECONDS = 5;

    /**
     * Bind/search result-wait timeout in seconds (`LDAP_OPT_TIMEOUT`).
     *
     * Without this the worker stalls forever on a server that accepts the TCP
     * connection and then goes silent — `LDAP_OPT_NETWORK_TIMEOUT` does not
     * cover the operation wait. This constant IS the bound that makes the
     * blocking-I/O exception above legitimate; do not remove it.
     */
    public const OPERATION_TIMEOUT_SECONDS = 5;

    /**
     * @var array<string, Connection>
     */
    private static array $instances = [];

    private Connection $connection;
    private string $host;
    private int $port;
    private bool $ssl;
    private string $baseDn;
    private ?string $bindDn;
    private ?string $bindPw;
    private string $userFilter;

    public function __construct(
        string $host,
        int $port,
        bool $ssl,
        string $baseDn,
        ?string $bindDn,
        ?string $bindPw,
        string $userFilter,
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->ssl = $ssl;
        $this->baseDn = $baseDn;
        $this->bindDn = $bindDn;
        $this->bindPw = $bindPw;
        $this->userFilter = $userFilter;

        $this->connection = $this->createConnection();
    }

    private function createConnection(): Connection
    {
        $config = $this->connectionConfig($this->bindDn, $this->bindPw);

        $key = $this->host . ':' . $this->port . ':' . ($this->ssl ? 'ssl' : 'plain');

        if (!isset(self::$instances[$key])) {
            $connection = new Connection($config);
            self::$instances[$key] = $connection;
        }

        return self::$instances[$key];
    }

    public function authenticate(string $username, string $password): bool
    {
        try {
            $userDn = $this->findUserDn($username);
            if ($userDn === null) {
                return false;
            }

            $userConnection = $this->createUserConnection($userDn, $password);
            $userConnection->connect();

            return $userConnection->isConnected();
        } catch (LdapRecordException $e) {
            return false;
        }
    }

    public function findUserDn(string $username): ?string
    {
        $filter = $this->buildUserFilter($username);

        try {
            $query = $this->connection->query();
            $query->setDn($this->baseDn);

            $results = $query->rawFilter($filter)->get();

            if (empty($results)) {
                if ($this->bindDn !== null) {
                    return $this->searchForUserDn($username);
                }
                return null;
            }

            return $results[0]['dn'] ?? null;
        } catch (LdapRecordException $e) {
            if ($this->bindDn !== null) {
                return $this->searchForUserDn($username);
            }
            return null;
        }
    }

    private function searchForUserDn(string $username): ?string
    {
        try {
            $query = $this->connection->query();
            $query->setDn($this->baseDn);

            $filter = $this->buildUserFilter($username);
            $results = $query->rawFilter($filter)->get();

            return $results[0]['dn'] ?? null;
        } catch (LdapRecordException $e) {
            return null;
        }
    }

    /**
     * Build the LDAP user search filter with the supplied username safely
     * substituted.
     *
     * The username is escaped using RFC 4515 filter rules via
     * {@see ldap_escape()} with the LDAP_ESCAPE_FILTER flag. Without this
     * step a malicious username such as `*)(uid=*` could break out of the
     * filter and enumerate users or bypass authentication.
     *
     * @internal Exposed for unit testing of the escape behaviour.
     */
    public function buildUserFilter(string $username): string
    {
        $escaped = ldap_escape($username, '', LDAP_ESCAPE_FILTER);

        return str_replace('{{username}}', $escaped, $this->userFilter);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUserAttributes(string $userDn): ?array
    {
        try {
            $query = $this->connection->query();
            $query->setDn($userDn);

            $results = $query->get();

            if (empty($results)) {
                return null;
            }

            return $results[0];
        } catch (LdapRecordException $e) {
            return null;
        }
    }

    public function isAdmin(string $userDn, ?string $adminGroup): bool
    {
        if ($adminGroup === null || $adminGroup === '') {
            return false;
        }

        try {
            $query = $this->connection->query();
            $query->setDn($adminGroup);

            $results = $query->rawFilter('(member=' . $userDn . ')')->get();

            return !empty($results);
        } catch (LdapRecordException $e) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        try {
            $this->connection->connect();

            if (!$this->connection->isConnected()) {
                return [
                    'success' => false,
                    'error' => 'connection_failed',
                    'message' => 'Failed to connect to LDAP server',
                ];
            }

            if ($this->bindDn !== null && $this->bindPw !== null) {
                if (!$this->connection->auth()->attempt($this->bindDn, $this->bindPw)) {
                    return [
                        'success' => false,
                        'error' => 'bind_failed',
                        'message' => 'Failed to bind with configured credentials',
                    ];
                }
            }

            return [
                'success' => true,
                'message' => 'Connection successful',
            ];
        } catch (LdapRecordException $e) {
            return [
                'success' => false,
                'error' => 'ldap_error',
                'message' => $e->getMessage(),
            ];
        } catch (RuntimeException $e) {
            return [
                'success' => false,
                'error' => 'runtime_error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isSsl(): bool
    {
        return $this->ssl;
    }

    public function getBaseDn(): string
    {
        return $this->baseDn;
    }

    public function getUserFilter(): string
    {
        return $this->userFilter;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    private function createUserConnection(string $userDn, string $password): Connection
    {
        return new Connection($this->connectionConfig($userDn, $password));
    }

    /**
     * Build the LdapRecord config shared by the service-bind connection and the
     * per-user bind connection.
     *
     * BOTH timeouts are mandatory and neither substitutes for the other:
     *  - `timeout`                → `LDAP_OPT_NETWORK_TIMEOUT`, bounds the TCP connect;
     *  - `options[LDAP_OPT_TIMEOUT]` → bounds the bind/search result wait, which
     *    is the case that actually hangs (see the class docblock). Passing it via
     *    `options` is deliberate: `LdapRecord\Connection::configure()` uses the
     *    `options` array as the BASE of an `array_replace()` and only overrides
     *    protocol-version / network-timeout / referrals, so this survives.
     *
     * @return array<string, mixed>
     */
    private function connectionConfig(?string $username, ?string $password): array
    {
        return [
            'hosts' => [$this->host],
            'port' => $this->port,
            'base_dn' => $this->baseDn,
            'username' => $username,
            'password' => $password,
            'use_ssl' => $this->ssl,
            'use_tls' => !$this->ssl,
            'timeout' => self::NETWORK_TIMEOUT_SECONDS,
            'options' => [
                LDAP_OPT_TIMEOUT => self::OPERATION_TIMEOUT_SECONDS,
            ],
        ];
    }

    public static function clearCache(): void
    {
        self::$instances = [];
    }
}
