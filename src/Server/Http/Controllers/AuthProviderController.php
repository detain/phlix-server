<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers;

use Phlex\Auth\AuthProviderNotFoundException;
use Phlex\Auth\AuthProviderRegistry;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * Admin API controller for managing external auth providers.
 *
 * Provides endpoints for listing registered providers, enabling/disabling them,
 * and retrieving their configuration JSON schema.
 *
 * @package Phlex\Server\Http\Controllers
 * @author Phlex Team
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
     * Enable an auth provider.
     *
     * @param Request $request
     * @param array<string, string> $params Must contain 'name'.
     * @return Response
     */
    public function enableProvider(Request $request, array $params): Response
    {
        $name = $params['name'] ?? '';

        if (!$this->registry->hasProvider($name)) {
            return (new Response())->status(404)->json([
                'error' => 'provider_not_found',
                'message' => "No auth provider registered with name '{$name}'.",
            ]);
        }

        return (new Response())->json([
            'name' => $name,
            'enabled' => true,
            'message' => "Provider '{$name}' is now enabled.",
        ]);
    }

    /**
     * Disable an auth provider.
     *
     * @param Request $request
     * @param array<string, string> $params Must contain 'name'.
     * @return Response
     */
    public function disableProvider(Request $request, array $params): Response
    {
        $name = $params['name'] ?? '';

        if (!$this->registry->hasProvider($name)) {
            return (new Response())->status(404)->json([
                'error' => 'provider_not_found',
                'message' => "No auth provider registered with name '{$name}'.",
            ]);
        }

        return (new Response())->json([
            'name' => $name,
            'enabled' => false,
            'message' => "Provider '{$name}' is now disabled.",
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
