<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 * ## Instance-addressable keys (S47 multi-instance)
 *
 * A provider has a **family** ({@see ProviderInterface::name()}, e.g. `oidc`,
 * `ldap`, `github`) which governs its BEHAVIOUR/dispatch, and an **instance**
 * (e.g. `okta`, `azure`) which distinguishes two separately-configured
 * providers of the SAME family. Before S47 the registry keyed purely on
 * `name()`, so two `OidcProvider`s (both `name()==='oidc'`) collided on the
 * dup-throw and could never coexist. The registry now keys on a composite
 * INSTANCE KEY built by {@see self::instanceKey()}:
 *
 *   - default instance (`''`)      → key = the family name verbatim (`oidc`)
 *   - named instance (`okta`)      → key = `family:instance` (`oidc:okta`)
 *
 * The default-instance key being IDENTICAL to the family name is what keeps
 * every pre-S47 caller working unchanged: `registerProvider($p)` still keys on
 * `name()`, and `getProvider('oidc')` / `hasProvider('oidc')` still resolve the
 * default instance. The `''` sentinel matches
 * `user_identities.provider_instance` (migration 092): a provider registered
 * without an explicit instance is the DEFAULT instance of its family.
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
    /**
     * The default single-instance sentinel. Matches
     * `user_identities.provider_instance`'s `NOT NULL DEFAULT ''` (migration
     * 092): a provider registered without an explicit instance IS the default
     * instance, and its registry key is just the family name.
     */
    public const string DEFAULT_INSTANCE = '';

    /** @var array<string, ProviderInterface> Keyed by instance key (see instanceKey()). */
    private array $providers = [];

    public function __construct()
    {
    }

    /**
     * Build the composite registry key for a (family, instance) pair.
     *
     * The default instance (`''`) maps to the family name verbatim so pre-S47
     * single-instance callers are byte-for-byte unchanged; a named instance maps
     * to `family:instance`.
     *
     * @param string $family   Provider family (the value of {@see ProviderInterface::name()}).
     * @param string $instance Configured-instance key, or '' for the default instance.
     * @return string The composite instance key used to address the provider.
     */
    public static function instanceKey(string $family, string $instance = self::DEFAULT_INSTANCE): string
    {
        return $instance === self::DEFAULT_INSTANCE ? $family : $family . ':' . $instance;
    }

    /**
     * Register a provider instance.
     *
     * The provider's {@see ProviderInterface::name()} supplies the family; the
     * optional `$instance` distinguishes multiple configured providers of that
     * same family (S47). Omitting `$instance` registers the DEFAULT instance,
     * whose key is the family name — the exact pre-S47 behaviour.
     *
     * @param ProviderInterface $provider The provider to register.
     * @param string $instance Configured-instance key (e.g. "okta"), or '' for
     *                         the default single instance.
     * @return void
     *
     * @throws RuntimeException When a provider with the same instance key is
     *         already registered. Two DIFFERENT instances of the same family
     *         (e.g. `oidc:okta` + `oidc:azure`, or `oidc` + `oidc:okta`) do NOT
     *         collide; only a repeat of the SAME instance key throws.
     */
    public function registerProvider(ProviderInterface $provider, string $instance = self::DEFAULT_INSTANCE): void
    {
        $key = self::instanceKey($provider->name(), $instance);
        if (isset($this->providers[$key])) {
            throw new RuntimeException(
                "Auth provider '{$key}' is already registered."
            );
        }
        $this->providers[$key] = $provider;
    }

    /**
     * Remove a registered provider by its instance key.
     *
     * Idempotent: unregistering a key that is not present is a no-op. Used by
     * the admin "disable provider" flow so that turning a provider off takes
     * immediate effect in the current worker (the persisted enable-flag governs
     * every other worker on its next {@see \Phlix\Auth\AuthProviderBootstrapper::registerEnabledProviders()}
     * boot pass, since the registry is per-worker in-memory state).
     *
     * @param string $key Instance key (a family name for the default instance,
     *                     or `family:instance` — see {@see self::instanceKey()}).
     * @return void
     */
    public function unregisterProvider(string $key): void
    {
        unset($this->providers[$key]);
    }

    /**
     * Return all registered providers, keyed by instance key.
     *
     * @return array<string, ProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Return every registered instance of a provider FAMILY, keyed by instance
     * key. Family governs behaviour/dispatch, so this is how a family-level
     * consumer enumerates its (possibly multiple, S47) instances.
     *
     * @param string $family Provider family (the value of {@see ProviderInterface::name()}).
     * @return array<string, ProviderInterface> Instance-key => provider (may be empty).
     */
    public function getProvidersByFamily(string $family): array
    {
        $matches = [];
        foreach ($this->providers as $key => $provider) {
            if ($provider->name() === $family) {
                $matches[$key] = $provider;
            }
        }

        return $matches;
    }

    /**
     * Return true when a provider with the given instance key is registered.
     *
     * @param string $key Instance key (family name for the default instance, or
     *                     `family:instance` — see {@see self::instanceKey()}).
     * @return bool
     */
    public function hasProvider(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * Return a registered provider by its instance key.
     *
     * @param string $key Instance key (family name for the default instance, or
     *                     `family:instance` — see {@see self::instanceKey()}).
     * @return ProviderInterface
     *
     * @throws AuthProviderNotFoundException When no provider is registered with that key.
     */
    public function getProvider(string $key): ProviderInterface
    {
        if (!isset($this->providers[$key])) {
            throw new AuthProviderNotFoundException(
                "No auth provider registered with name '{$key}'."
            );
        }

        return $this->providers[$key];
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
