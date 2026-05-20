<?php

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;

/**
 * Bridges {@see AuthManager} to the external provider system.
 *
 * Handles provider-prefix parsing ("oidc:alice@example.com" → provider "oidc",
 * username "alice@example.com") and delegates to either a registered external
 * provider or falls back to the standard password-based flow.
 *
 * @package Phlix\Auth
 * @author Phlix Team
 * @version 1.0.0
 * @description Provider-prefix resolution and auth delegation.
 *
 * @see AuthProviderRegistry Where registered providers are stored.
 * @see ProviderInterface    The provider contract.
 *
 * @example
 * ```php
 * $manager = new ProviderManager($registry, $userRepository);
 * // Provider-prefixed login
 * $result = $manager->authenticate('oidc:alice@example.com', [...credentials...]);
 * // Plain username fallback
 * $result = $manager->authenticate('alice', ['password' => '...']);
 * ```
 */
final class ProviderManager
{
    public function __construct(
        private readonly AuthProviderRegistry $registry,
        private readonly UserRepository $userRepository
    ) {
    }

    /**
     * Parse a provider prefix from a username.
     *
     * Splits on the first colon. If no colon is found, returns null (plain username).
     *
     * @param string $username The username which may carry a provider prefix.
     * @return array{0: string, 1: string}|null [providerName, localUsername] or null if no prefix.
     *
     * @example
     * ```php
     * $result = $this->parseProviderPrefix('oidc:alice@example.com');
     * // ['oidc', 'alice@example.com']
     *
     * $result = $this->parseProviderPrefix('alice');
     * // null
     * ```
     */
    public function parseProviderPrefix(string $username): ?array
    {
        $colonPos = strpos($username, ':');
        if ($colonPos === false) {
            return null;
        }

        $providerName = strtolower(substr($username, 0, $colonPos));
        $localUsername = substr($username, $colonPos + 1);

        return [$providerName, $localUsername];
    }

    /**
     * Authenticate a user.
     *
     * When the username carries a provider prefix (e.g. "oidc:alice@example.com"),
     * delegates to the corresponding external provider. Otherwise falls back
     * to the standard password-based flow via {@see UserRepository}.
     *
     * @param string $username    Username or "provider:username" string.
     * @param array<string, mixed> $credentials Provider-specific credentials or password.
     * @return AuthResult
     *
     * @throws AuthProviderNotFoundException When a provider-prefixed username
     *         references an unknown (unregistered) provider.
     */
    public function authenticate(string $username, array $credentials = []): AuthResult
    {
        $prefix = $this->parseProviderPrefix($username);

        if ($prefix === null) {
            return $this->fallbackPasswordAuth($username, $credentials);
        }

        [$providerName, $localUsername] = $prefix;

        if (!$this->registry->hasProvider($providerName)) {
            throw new AuthProviderNotFoundException(
                "Provider '{$providerName}' is not registered."
            );
        }

        $provider = $this->registry->getProvider($providerName);

        return $provider->authenticate($credentials);
    }

    /**
     * Fall back to standard password-based authentication.
     *
     * @param string $username Plain username.
     * @param array<string, mixed> $credentials Must contain 'password' key.
     * @return AuthResult
     */
    private function fallbackPasswordAuth(string $username, array $credentials): AuthResult
    {
        $rawPassword = $credentials['password'] ?? '';
        $password = is_string($rawPassword) ? $rawPassword : '';

        $user = $this->userRepository->findByUsername($username);

        if (!$user) {
            return new AuthResult(success: false, error: 'user_not_found');
        }

        /** @var string $userId */
        $userId = $user['id'];

        if (!$this->userRepository->verifyPassword($userId, $password)) {
            return new AuthResult(success: false, error: 'invalid_credentials');
        }

        return new AuthResult(
            success: true,
            userId: $userId,
            externalId: null,
            error: null,
            attributes: [
                'email' => $user['email'] ?? null,
                'name' => $user['display_name'] ?? null,
            ],
        );
    }
}
