<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\AuthProviderBootstrapper;
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
 * @see AuthProviderBootstrapper Where enable-state is persisted + applied.
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

    /** @var AuthProviderBootstrapper Persists enable-state and (de)registers providers. */
    private AuthProviderBootstrapper $bootstrapper;

    /**
     * @param AuthProviderRegistry     $registry     The auth provider registry.
     * @param AuthProviderBootstrapper $bootstrapper Enable-state store + (de)registration.
     */
    public function __construct(AuthProviderRegistry $registry, AuthProviderBootstrapper $bootstrapper)
    {
        $this->registry = $registry;
        $this->bootstrapper = $bootstrapper;
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
     * Enable an auth provider.
     *
     * Persists `auth.<name>.enabled = true` via {@see AuthProviderBootstrapper}
     * and registers the provider into the current worker's registry so the login
     * flow is live (the boot step re-registers it in every other worker on its
     * next start/reload). A provider that is not yet configured (no saved
     * settings) cannot be brought live, so enabling it is rejected with a clear
     * message rather than reporting a false "enabled" state.
     *
     * @param Request $request
     * @param array<string, string> $params Must contain 'name'.
     * @return Response
     */
    public function enableProvider(Request $request, array $params): Response
    {
        $name = strtolower($params['name'] ?? '');

        if (!$this->bootstrapper->isToggleable($name)) {
            return $this->unknownProvider($name);
        }

        if (!$this->bootstrapper->isConfigured($name)) {
            return (new Response())->status(409)->json([
                'error' => 'not_configured',
                'name' => $name,
                'message' => "Configure the '{$name}' provider before enabling it.",
            ]);
        }

        $live = $this->bootstrapper->enable($name);

        return (new Response())->json([
            'name' => $name,
            'enabled' => true,
            'live' => $live,
            'message' => "Provider '{$name}' is now enabled.",
        ]);
    }

    /**
     * Disable an auth provider.
     *
     * Persists `auth.<name>.enabled = false` and removes it from the current
     * worker's registry. Other workers stop offering it on their next boot pass.
     *
     * @param Request $request
     * @param array<string, string> $params Must contain 'name'.
     * @return Response
     */
    public function disableProvider(Request $request, array $params): Response
    {
        $name = strtolower($params['name'] ?? '');

        if (!$this->bootstrapper->isToggleable($name)) {
            return $this->unknownProvider($name);
        }

        $this->bootstrapper->disable($name);

        return (new Response())->json([
            'name' => $name,
            'enabled' => false,
            'message' => "Provider '{$name}' is now disabled.",
        ]);
    }

    /**
     * 404 for a provider name that is not one of the toggleable built-ins.
     *
     * @param string $name The requested provider name, echoed back for the UI.
     */
    private function unknownProvider(string $name): Response
    {
        return (new Response())->status(404)->json([
            'error' => 'unknown_provider',
            'name' => $name,
            'message' => "No toggleable auth provider named '{$name}'. "
                . 'Toggleable providers: ' . implode(', ', AuthProviderBootstrapper::TOGGLEABLE) . '.',
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
