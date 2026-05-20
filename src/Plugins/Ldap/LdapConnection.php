<?php

declare(strict_types=1);

namespace Phlix\Plugins\Ldap;

use LdapRecord\Connection;
use LdapRecord\LdapRecordException;
use RuntimeException;

class LdapConnection
{
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
        $config = [
            'hosts' => [$this->host],
            'port' => $this->port,
            'base_dn' => $this->baseDn,
            'username' => $this->bindDn,
            'password' => $this->bindPw,
            'use_ssl' => $this->ssl,
            'use_tls' => !$this->ssl,
            'timeout' => 5,
        ];

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
        $config = [
            'hosts' => [$this->host],
            'port' => $this->port,
            'base_dn' => $this->baseDn,
            'username' => $userDn,
            'password' => $password,
            'use_ssl' => $this->ssl,
            'use_tls' => !$this->ssl,
            'timeout' => 5,
        ];

        return new Connection($config);
    }

    public static function clearCache(): void
    {
        self::$instances = [];
    }
}
