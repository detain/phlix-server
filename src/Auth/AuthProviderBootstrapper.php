<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Plugins\Ldap\LdapProvider;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Oidc\DiscoveryDocument;
use Phlix\Plugins\Oidc\OidcProvider;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Shared\Auth\ProviderInterface;

/**
 * Persists the enable-state of the built-in OIDC/LDAP auth providers and
 * (re-)registers the enabled + configured ones into the per-worker
 * {@see AuthProviderRegistry}.
 *
 * ## Why a dedicated settings flag, not the plugin pipeline
 *
 * The generic plugin-enable pipeline ({@see \Phlix\Plugins\PluginLoader::bootstrapEnabled()})
 * only iterates rows in the `plugins` DB table, and the bundled `src/Plugins/Oidc`
 * + `src/Plugins/Ldap` are never seeded into that table (there is no built-in
 * plugin seeding — the catalog only installs downloadable plugins into
 * `var/plugins`). They also carry their own `settings.json` config surface
 * ({@see OidcPlugin}/{@see LdapPlugin}), NOT the DB-backed plugin settings store.
 * Turning them into real catalog plugins is a larger migration (S48). Until then
 * the honest, minimal enable-state store is two boolean server settings written
 * through the existing {@see SettingsRepository}:
 *
 *   - `auth.oidc.enabled`
 *   - `auth.ldap.enabled`
 *
 * ## Per-worker registration
 *
 * {@see AuthProviderRegistry} is plain in-memory state, rebuilt per worker. So
 * {@see self::registerEnabledProviders()} must run in every resident HTTP
 * worker's `onWorkerStart` (see `start.php`), re-attaching each enabled +
 * configured provider after a restart/graceful reload. A live admin
 * enable/disable ({@see self::enable()}/{@see self::disable()}) additionally
 * mutates the current worker's registry so the change is effective immediately
 * in at least that worker; the persisted flag governs every other worker on its
 * next boot pass.
 *
 * Not `final`: it is a collaborator of {@see \Phlix\Server\Http\Controllers\AuthProviderController}
 * and the start.php boot step, so tests substitute a double (mirroring the
 * mockable {@see \Phlix\Auth\UserRepository} / {@see \Phlix\Auth\JwtHandler}).
 *
 * @package Phlix\Auth
 * @since 0.99.0
 */
class AuthProviderBootstrapper
{
    /** Provider name / settings-flag segment for OIDC. */
    public const OIDC = 'oidc';

    /** Provider name / settings-flag segment for LDAP. */
    public const LDAP = 'ldap';

    /**
     * The providers this bootstrapper governs. Anything else is not toggleable
     * through the auth-provider admin endpoints.
     *
     * @var list<string>
     */
    public const TOGGLEABLE = [self::OIDC, self::LDAP];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuthProviderRegistry $registry,
        private readonly OidcPlugin $oidcPlugin,
        private readonly LdapPlugin $ldapPlugin,
    ) {
    }

    /**
     * Dotted server-settings key holding a provider's boolean enable flag.
     *
     * @param string $provider One of {@see self::TOGGLEABLE}.
     */
    public static function flagKey(string $provider): string
    {
        return 'auth.' . $provider . '.enabled';
    }

    /**
     * Whether the given provider name is one this bootstrapper can toggle.
     */
    public function isToggleable(string $provider): bool
    {
        return in_array($provider, self::TOGGLEABLE, true);
    }

    /**
     * Whether a provider's persisted enable flag is on. Absent flag = off.
     */
    public function isEnabled(string $provider): bool
    {
        $override = $this->settings->getOverride(self::flagKey($provider));

        return $override !== null && $override['value'] === true;
    }

    /**
     * Whether a provider has enough saved settings to be instantiated.
     */
    public function isConfigured(string $provider): bool
    {
        return $this->buildProvider($provider) !== null;
    }

    /**
     * Boot step: register every enabled AND configured provider into this
     * worker's registry. Idempotent — a provider already present is skipped, so
     * this is safe to call repeatedly (e.g. once per worker start).
     */
    public function registerEnabledProviders(): void
    {
        foreach (self::TOGGLEABLE as $provider) {
            if ($this->isEnabled($provider)) {
                $this->registerProvider($provider);
            }
        }
    }

    /**
     * Request-path self-heal (S44 Finding 3): reconcile a single provider's live
     * registration in THIS worker with its persisted enable flag, then report
     * whether it is usable.
     *
     * The {@see AuthProviderRegistry} is per-worker in-memory state, so a live
     * admin enable/disable only mutates the serving worker; every other HTTP
     * worker keeps its boot-time view until it restarts. That makes
     * `/auth/oidc/authorize`, the OIDC callback, and `ldap:` logins 503 on
     * ~(N-1)/N of workers right after an admin toggles a provider on — the exact
     * opposite of "enabling results in a working login flow". Calling this on the
     * request path makes the PERSISTED flag authoritative:
     *
     *   - flag ON  → lazily (re-)register the provider if this worker missed it
     *     (reuses {@see self::registerProvider()}, which reads settings only — no
     *     network I/O, same as the boot pass);
     *   - flag OFF → drop any stale registration so a since-disabled provider in
     *     this worker stops serving immediately.
     *
     * @param string $provider One of {@see self::TOGGLEABLE}.
     * @return bool True when the provider is enabled, configured, and now
     *              registered in this worker; false otherwise (unknown/disabled/
     *              unconfigured).
     */
    public function ensureProviderRegistered(string $provider): bool
    {
        if (!$this->isToggleable($provider)) {
            return false;
        }

        if ($this->isEnabled($provider)) {
            return $this->registerProvider($provider);
        }

        // Flag OFF: a provider disabled after this worker booted may still be
        // registered here — unregister it so it stops serving immediately.
        if ($this->registry->hasProvider($provider)) {
            $this->registry->unregisterProvider($provider);
        }

        return false;
    }

    /**
     * Persist a provider as enabled and register it into the current worker.
     *
     * @return bool True when the provider was (or already is) live in this
     *              worker; false when it is enabled but not yet configured, so
     *              nothing could be registered.
     */
    public function enable(string $provider): bool
    {
        $this->settings->set(self::flagKey($provider), true, 'bool');

        return $this->registerProvider($provider);
    }

    /**
     * Persist a provider as disabled and remove it from the current worker.
     */
    public function disable(string $provider): void
    {
        $this->settings->set(self::flagKey($provider), false, 'bool');
        $this->registry->unregisterProvider($provider);
    }

    /**
     * Register the given provider if configured and not already present.
     *
     * @return bool True when the provider is now (or was already) registered.
     */
    private function registerProvider(string $provider): bool
    {
        if ($this->registry->hasProvider($provider)) {
            return true;
        }

        $instance = $this->buildProvider($provider);
        if ($instance === null) {
            return false;
        }

        $this->registry->registerProvider($instance);

        return true;
    }

    /**
     * Instantiate a provider from its saved plugin settings, or null when the
     * provider is unknown or not sufficiently configured.
     *
     * Instantiation performs NO network I/O — OIDC discovery/JWKS are fetched
     * lazily on the first authenticate() — so this is safe to run at boot.
     */
    private function buildProvider(string $provider): ?ProviderInterface
    {
        return match ($provider) {
            self::OIDC => $this->buildOidcProvider(),
            self::LDAP => $this->buildLdapProvider(),
            default => null,
        };
    }

    /**
     * Build an {@see OidcProvider} from the OIDC plugin's saved settings.
     *
     * "Configured" mirrors {@see \Phlix\Plugins\Oidc\Controller\OidcAdminController}:
     * a provider URL and client ID are required (the client secret is optional —
     * a PKCE public client may omit it).
     */
    private function buildOidcProvider(): ?OidcProvider
    {
        $settings = $this->oidcPlugin->getSettings();

        $providerUrl = is_string($settings['provider_url'] ?? null) ? $settings['provider_url'] : '';
        $clientId = is_string($settings['client_id'] ?? null) ? $settings['client_id'] : '';
        if ($providerUrl === '' || $clientId === '') {
            return null;
        }

        $clientSecret = is_string($settings['client_secret'] ?? null) ? $settings['client_secret'] : '';
        $scopes = is_string($settings['scopes'] ?? null) && $settings['scopes'] !== ''
            ? $settings['scopes']
            : 'openid profile email';

        return new OidcProvider(
            discovery: new DiscoveryDocument($providerUrl),
            clientId: $clientId,
            clientSecret: $clientSecret,
            scopes: $scopes,
        );
    }

    /**
     * Build an {@see LdapProvider} from the LDAP plugin's saved settings.
     *
     * "Configured" mirrors {@see \Phlix\Plugins\Ldap\Controller\LdapAdminController}:
     * host and base DN are required.
     */
    private function buildLdapProvider(): ?LdapProvider
    {
        $settings = $this->ldapPlugin->getSettings();

        $host = is_string($settings['host'] ?? null) ? $settings['host'] : '';
        $baseDn = is_string($settings['base_dn'] ?? null) ? $settings['base_dn'] : '';
        if ($host === '' || $baseDn === '') {
            return null;
        }

        $port = is_numeric($settings['port'] ?? null) ? (int) $settings['port'] : 389;
        $ssl = isset($settings['ssl']) && is_bool($settings['ssl']) ? $settings['ssl'] : false;
        $bindDn = is_string($settings['bind_dn'] ?? null) && $settings['bind_dn'] !== '' ? $settings['bind_dn'] : null;
        $bindPw = is_string($settings['bind_pw'] ?? null) && $settings['bind_pw'] !== '' ? $settings['bind_pw'] : null;
        $userFilter = is_string($settings['user_filter'] ?? null) && $settings['user_filter'] !== ''
            ? $settings['user_filter']
            : '(uid={{username}})';
        $adminGroup = is_string($settings['admin_group'] ?? null) && $settings['admin_group'] !== ''
            ? $settings['admin_group']
            : null;

        return new LdapProvider(
            host: $host,
            port: $port,
            ssl: $ssl,
            baseDn: $baseDn,
            bindDn: $bindDn,
            bindPw: $bindPw,
            userFilter: $userFilter,
            adminGroup: $adminGroup,
        );
    }
}
