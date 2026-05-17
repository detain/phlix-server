<?php

declare(strict_types=1);

namespace Phlex\Plugins\Ldap;

use Phlex\Shared\Auth\AuthResult;
use Phlex\Shared\Auth\ProviderInterface;
use Phlex\Shared\Auth\UserInfo;

final class LdapProvider implements ProviderInterface
{
    private ?LdapConnection $connection = null;
    private UserMapper $userMapper;
    private string $host;
    private int $port;
    private bool $ssl;
    private string $baseDn;
    private ?string $bindDn;
    private ?string $bindPw;
    private string $userFilter;
    private ?string $adminGroup;

    public function __construct(
        string $host,
        int $port,
        bool $ssl,
        string $baseDn,
        ?string $bindDn,
        ?string $bindPw,
        string $userFilter,
        ?string $adminGroup,
        ?UserMapper $userMapper = null,
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->ssl = $ssl;
        $this->baseDn = $baseDn;
        $this->bindDn = $bindDn;
        $this->bindPw = $bindPw;
        $this->userFilter = $userFilter;
        $this->adminGroup = $adminGroup;
        $this->userMapper = $userMapper ?? new UserMapper();
    }

    public function name(): string
    {
        return 'ldap';
    }

    public function supportsAuthentication(array $credentials): bool
    {
        return isset($credentials['username']) && isset($credentials['password']);
    }

    public function authenticate(array $credentials): AuthResult
    {
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;

        if (!is_string($username) || $username === '') {
            return new AuthResult(
                success: false,
                error: 'missing_username',
            );
        }

        if (!is_string($password) || $password === '') {
            return new AuthResult(
                success: false,
                error: 'missing_password',
            );
        }

        try {
            $connection = $this->getConnection();

            $authenticated = $connection->authenticate($username, $password);
            if (!$authenticated) {
                return new AuthResult(
                    success: false,
                    error: 'invalid_credentials',
                );
            }

            $userDn = $connection->findUserDn($username);
            if ($userDn === null) {
                return new AuthResult(
                    success: false,
                    error: 'user_not_found',
                );
            }

            $ldapEntry = $connection->getUserAttributes($userDn);
            if ($ldapEntry === null) {
                return new AuthResult(
                    success: false,
                    error: 'failed_to_fetch_user',
                );
            }

            $isAdmin = $connection->isAdmin($userDn, $this->adminGroup);

            $ldapUserInfo = LdapUserInfo::fromLdapEntry($ldapEntry, $this->baseDn, $isAdmin);

            $externalId = 'ldap.' . ($userDn ?: $username);

            $mappedAttributes = $this->userMapper->map($ldapEntry);
            $mappedAttributes['provider'] = 'ldap';
            $mappedAttributes['is_admin'] = $isAdmin;

            return new AuthResult(
                success: true,
                externalId: $externalId,
                attributes: $mappedAttributes,
            );
        } catch (\Exception $e) {
            return new AuthResult(
                success: false,
                error: 'ldap_error: ' . $e->getMessage(),
            );
        }
    }

    public function getUserInfo(string $externalId): ?UserInfo
    {
        if (!str_starts_with($externalId, 'ldap.')) {
            return null;
        }

        return null;
    }

    public function linkAccount(string $localUserId, array $externalIds): void
    {
    }

    public function getConnection(): LdapConnection
    {
        if ($this->connection === null) {
            $this->connection = new LdapConnection(
                host: $this->host,
                port: $this->port,
                ssl: $this->ssl,
                baseDn: $this->baseDn,
                bindDn: $this->bindDn,
                bindPw: $this->bindPw,
                userFilter: $this->userFilter,
            );
        }

        return $this->connection;
    }

    public function setConnection(LdapConnection $connection): void
    {
        $this->connection = $connection;
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

    public function getBindDn(): ?string
    {
        return $this->bindDn;
    }

    public function getUserFilter(): string
    {
        return $this->userFilter;
    }

    public function getAdminGroup(): ?string
    {
        return $this->adminGroup;
    }

    public function getUserMapper(): UserMapper
    {
        return $this->userMapper;
    }
}
