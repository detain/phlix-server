<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\AuthMethodLockoutException;
use Phlix\Auth\AuthMethodPolicy;
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
     * F7 lock-out guard consulted by {@see self::disableProvider()} before the
     * persisted flag is turned off. Optional so hand-built call sites (unit
     * tests that only exercise list/enable/schema paths) keep working; the DI
     * binding names it explicitly — an unnamed policy would leave the
     * integrations-panel disable button as an unguarded write path, exactly
     * the hole this closes.
     *
     * @var AuthMethodPolicy|null
     */
    private ?AuthMethodPolicy $authPolicy;

    /**
     * @param AuthProviderRegistry     $registry     The auth provider registry.
     * @param AuthProviderBootstrapper $bootstrapper Enable-state store + (de)registration.
     * @param AuthMethodPolicy|null    $authPolicy   F7 R1/R2 transition guard.
     */
    public function __construct(
        AuthProviderRegistry $registry,
        AuthProviderBootstrapper $bootstrapper,
        ?AuthMethodPolicy $authPolicy = null,
    ) {
        $this->registry = $registry;
        $this->bootstrapper = $bootstrapper;
        $this->authPolicy = $authPolicy;
    }

    /**
     * List every toggleable auth provider with its badge signals (S252).
     *
     * Iterates {@see AuthProviderBootstrapper::TOGGLEABLE} — the fixed universe of
     * governable providers — NOT the registry's contents. A registered provider is
     * only ever one that is enabled AND configured, so iterating the registry made
     * `enabled: false` unrepresentable: a configured-but-disabled provider vanished
     * from the payload entirely, and the admin UI (S44-a, which reads `live`
     * strictly) could never render its Disabled state.
     *
     * Two independent signals per row, both real, never hardcoded:
     *  - `live`    — registered in THIS worker right now ({@see AuthProviderRegistry::hasProvider()}).
     *  - `enabled` — the persisted toggle flag ({@see AuthProviderBootstrapper::isEnabled()}).
     *
     * The legacy `supports_authentication` key keeps its exact old value: it was a
     * `method_exists()` probe over the registry, and every registry member is a
     * {@see \Phlix\Shared\Auth\ProviderInterface}, which DECLARES
     * `supportsAuthentication()` — so the probe was tautologically true for every
     * listed row and no row existed otherwise. `live` is therefore the honest,
     * equivalent encoding (true ⇔ a registered provider answers the call).
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function listProviders(Request $request, array $params): Response
    {
        $list = [];

        foreach (AuthProviderBootstrapper::TOGGLEABLE as $name) {
            $live = $this->registry->hasProvider($name);

            $list[] = [
                'name' => $name,
                'supports_authentication' => $live,
                'live' => $live,
                'enabled' => $this->bootstrapper->isEnabled($name),
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
     * F7: the write goes through {@see AuthMethodPolicy::assertSafeTransition()}
     * first — a provider that is some active administrator's ONLY usable factor
     * cannot be disabled here (R2-bis), and this route can never be the write
     * that empties the last enabled method (R1). A refused transition persists
     * NOTHING and answers 422 with the machine reason; the integrations panel
     * keeps its Enabled state untouched. With no policy wired (hand-built test
     * controllers) the guard is inert, which is why the DI binding names it.
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

        if ($this->authPolicy !== null) {
            try {
                $this->authPolicy->assertSafeTransition(
                    array_merge($this->authPolicy->currentState(), [$name => false]),
                );
            } catch (AuthMethodLockoutException $e) {
                return (new Response())->status(422)->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => [AuthProviderBootstrapper::flagKey($name) => $e->getMessage()],
                    'reason' => $e->reason(),
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                // Fail closed: an unreadable policy must not wave an
                // unvalidated disable through.
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error' => 'Auth-method policy check failed',
                    'message' => $e->getMessage(),
                ]);
            }
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
