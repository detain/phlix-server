<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\AuthProviderNotFoundException;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Admin API controller for managing external auth providers.
 *
 * Provides endpoints for listing registered providers, enabling/disabling them,
 * and retrieving their configuration JSON schema.
 *
 * @package Phlix\Server\Http\Controllers
 * @author Phlix Team
 * @version 1.0.0
 * @description Admin API for managing external authentication providers.
 *
 * @see AuthProviderRegistry Where providers are registered and stored.
 *
 * Endpoints:
 * - GET    /api/v1/admin/auth-providers           — list all registered providers
 * - POST   /api/v1/admin/auth-providers/{name}/enable  — enable a provider
 * - POST   /api/v1/admin/auth-providers/{name}/disable — disable a provider
 * - GET    /api/v1/admin/auth-providers/{name}/config-schema — get provider's config schema
 */
final class AuthProviderController
{
    /** @var AuthProviderRegistry The provider registry. */
    private AuthProviderRegistry $registry;

    /**
     * @param AuthProviderRegistry $registry The auth provider registry.
     */
    public function __construct(AuthProviderRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * List all registered auth providers.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function listProviders(Request $request, array $params): Response
    {
        $providers = $this->registry->getProviders();

        $list = [];
        foreach ($providers as $name => $provider) {
            $list[] = [
                'name' => $provider->name(),
                'supports_authentication' => method_exists($provider, 'supportsAuthentication'),
            ];
        }

        return (new Response())->json(['providers' => $list]);
    }

    /**
     * Message returned by {@see enableProvider()} / {@see disableProvider()}.
     *
     * ## Why these endpoints return 501 instead of doing the work
     *
     * There is no persistence mechanism for a provider's enabled state to write
     * to, and no way to build one without inventing an architecture:
     *
     *  - {@see AuthProviderRegistry} keeps providers in a plain in-memory
     *    `private array $providers` (`src/Auth/AuthProviderRegistry.php:37`).
     *    It exposes `registerProvider()` / `hasProvider()` / `getProvider()`
     *    and NOTHING else -- there is no enabled flag, no unregister, and no
     *    store behind it. Nothing about it survives a worker restart.
     *  - The only writers are the two built-in auth plugins' `onEnable()`:
     *    `src/Plugins/Oidc/Plugin.php:95` and `src/Plugins/Ldap/Plugin.php:77`.
     *    Both hold their own enable state; neither has an `onDisable()` body
     *    (`src/Plugins/Oidc/Plugin.php:97`, `src/Plugins/Ldap/Plugin.php:81`
     *    are empty).
     *  - Those `onEnable()` hooks only ever run via
     *    {@see \Phlix\Plugins\PluginLoader::bootstrapEnabled()}
     *    (`src/Plugins/PluginLoader.php:771`), which has ZERO callers -- the
     *    service provider that would call it documents the call site as a
     *    "future commit"
     *    (`src/Common/Container/Providers/PluginsProvider.php:47-54`).
     *    So in the resident server the registry is EMPTY at runtime.
     *
     * Both handlers previously validated `hasProvider($name)` and then returned
     * `['enabled' => true|false]` with "Provider 'x' is now enabled." while
     * mutating and persisting nothing whatsoever. That is a fabricated success.
     * Until a real enable-state store exists, saying so is the honest answer.
     */
    private const NOT_IMPLEMENTED_MESSAGE =
        'Enabling and disabling auth providers is not implemented. Provider state is held '
        . 'in memory only and is not persisted, so this endpoint cannot change it. Configure '
        . 'OIDC and LDAP through their own settings endpoints instead.';

    /**
     * Enable an auth provider.
     *
     * Not implemented -- see {@see self::NOT_IMPLEMENTED_MESSAGE} for the
     * evidence that no persistence path exists.
     *
     * @param Request $request
     * @param array<string, string> $params Must contain 'name'.
     * @return Response
     */
    public function enableProvider(Request $request, array $params): Response
    {
        return $this->notImplemented($params['name'] ?? '');
    }

    /**
     * Disable an auth provider.
     *
     * Not implemented -- see {@see self::NOT_IMPLEMENTED_MESSAGE} for the
     * evidence that no persistence path exists.
     *
     * @param Request $request
     * @param array<string, string> $params Must contain 'name'.
     * @return Response
     */
    public function disableProvider(Request $request, array $params): Response
    {
        return $this->notImplemented($params['name'] ?? '');
    }

    /**
     * Build the 501 response shared by enable/disable.
     *
     * Returned unconditionally -- INCLUDING for a name that is not registered.
     * The capability does not exist for any provider, so answering 404
     * ("that provider is unknown") would imply a known provider could be
     * toggled. It cannot.
     *
     * @param string $name The requested provider name, echoed back for the UI.
     */
    private function notImplemented(string $name): Response
    {
        return (new Response())->status(501)->json([
            'error' => 'not_implemented',
            'name' => $name,
            'message' => self::NOT_IMPLEMENTED_MESSAGE,
        ]);
    }

    /**
     * Get the configuration JSON schema for a provider.
     *
     * @param Request $request
     * @param array<string, string> $params Must contain 'name'.
     * @return Response
     */
    public function getConfigSchema(Request $request, array $params): Response
    {
        $name = $params['name'] ?? '';

        if (!$this->registry->hasProvider($name)) {
            return (new Response())->status(404)->json([
                'error' => 'provider_not_found',
                'message' => "No auth provider registered with name '{$name}'.",
            ]);
        }

        $provider = $this->registry->getProvider($name);

        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => ucfirst($name) . ' Provider Configuration',
            'description' => "Configuration options for the {$provider->name()} auth provider.",
            'type' => 'object',
            'properties' => [
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether this provider is enabled.',
                ],
            ],
            'required' => [],
        ];

        return (new Response())->json(['schema' => $schema]);
    }
}
