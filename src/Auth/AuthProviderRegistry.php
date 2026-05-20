<?php

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Singleton registry of enabled authentication providers.
 *
 * Holds currently-installed {@see ProviderInterface} instances and resolves
 * provider-prefixed usernames (e.g. "oidc:alice@example.com") to the
 * correct provider. Used by {@see ProviderManager} during authentication.
 *
 * @package Phlix\Auth
 * @author Phlix Team
 * @version 1.0.0
 * @description Registry of pluggable external authentication providers.
 *
 * @see ProviderInterface The provider contract.
 * @see ProviderManager   The bridge that uses this registry.
 */
class AuthProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
    }

    /**
     * Register a provider instance.
     *
     * @param ProviderInterface $provider The provider to register.
     * @return void
     *
     * @throws RuntimeException When a provider with the same name is already registered.
     */
    public function registerProvider(ProviderInterface $provider): void
    {
        $name = $provider->name();
        if (isset($this->providers[$name])) {
            throw new RuntimeException(
                "Auth provider '{$name}' is already registered."
            );
        }
        $this->providers[$name] = $provider;
    }

    /**
     * Return all registered providers.
     *
     * @return array<string, ProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Return true when a provider with the given name is registered.
     *
     * @param string $name Lowercase provider name.
     * @return bool
     */
    public function hasProvider(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * Return a registered provider by name.
     *
     * @param string $name Lowercase provider name.
     * @return ProviderInterface
     *
     * @throws AuthProviderNotFoundException When no provider is registered with that name.
     */
    public function getProvider(string $name): ProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new AuthProviderNotFoundException(
                "No auth provider registered with name '{$name}'."
            );
        }

        return $this->providers[$name];
    }

    /**
     * Authenticate using a specific provider.
     *
     * @param string $providerName Lowercase provider name.
     * @param array<string, mixed> $credentials Provider-specific credentials.
     * @return AuthResult
     *
     * @throws AuthProviderNotFoundException When no provider is registered with that name.
     */
    public function authenticate(string $providerName, array $credentials = []): AuthResult
    {
        $provider = $this->getProvider($providerName);

        return $provider->authenticate($credentials);
    }
}
