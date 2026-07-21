<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Shared\Auth\ProviderInterface;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \Phlix\Server\Http\Controllers\AuthProviderController
 */
final class AuthProviderControllerTest extends TestCase
{
    /** @var AuthProviderRegistry&MockObject */
    private AuthProviderRegistry $registry;
    private AuthProviderController $controller;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(AuthProviderRegistry::class);
        $this->controller = new AuthProviderController($this->registry);
    }

    public function test_list_providers(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('oidc');
        $provider->method('supportsAuthentication')->willReturn(true);

        $this->registry->method('getProviders')->willReturn([
            'oidc' => $provider,
        ]);

        $request = $this->createMock(Request::class);

        $response = $this->controller->listProviders($request, []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('providers', $body);
        $this->assertCount(1, $body['providers']);
        $this->assertSame('oidc', $body['providers'][0]['name']);
    }

    public function test_list_providers_empty(): void
    {
        $this->registry->method('getProviders')->willReturn([]);

        $request = $this->createMock(Request::class);

        $response = $this->controller->listProviders($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame([], $body['providers']);
    }

    /**
     * Enabling a provider must NOT claim success.
     *
     * There is no store for a provider's enabled state:
     * `AuthProviderRegistry` keeps providers in a private in-memory array with
     * no enabled flag and no unregister (`src/Auth/AuthProviderRegistry.php:37`),
     * its only writers are the OIDC/LDAP plugins' `onEnable()`
     * (`src/Plugins/Oidc/Plugin.php:95`, `src/Plugins/Ldap/Plugin.php:77`), and
     * those run solely through `PluginLoader::bootstrapEnabled()`
     * (`src/Plugins/PluginLoader.php:771`), which has ZERO callers.
     *
     * The handler used to answer 200 `{"enabled": true}` with the message
     * "Provider 'oidc' is now enabled." having persisted and mutated nothing.
     *
     * The CONSEQUENCE asserted here is that an operator is never told a state
     * change happened: the status is 501 and the body carries no `enabled`
     * claim at all.
     */
    public function test_enable_provider_reports_not_implemented(): void
    {
        $this->registry->method('hasProvider')->willReturn(true);

        $request = $this->createMock(Request::class);

        $response = $this->controller->enableProvider($request, ['name' => 'oidc']);

        $this->assertSame(501, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('not_implemented', $body['error']);
        $this->assertSame('oidc', $body['name']);
        $this->assertArrayNotHasKey('enabled', $body);
        $this->assertStringContainsString('not implemented', (string) $body['message']);
    }

    /**
     * An unregistered provider gets the SAME 501, not a 404.
     *
     * The capability is missing for every provider, so a 404 would wrongly
     * imply that a *registered* provider could be toggled.
     */
    public function test_enable_provider_unknown_name_also_not_implemented(): void
    {
        $this->registry->method('hasProvider')->willReturn(false);

        $request = $this->createMock(Request::class);

        $response = $this->controller->enableProvider($request, ['name' => 'nonexistent']);

        $this->assertSame(501, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('not_implemented', $body['error']);
    }

    /**
     * Disabling must not claim success either -- same evidence as
     * {@see test_enable_provider_reports_not_implemented()}. The old handler's
     * `enabled => false` was a *falsey* lie, so asserting the key is ABSENT
     * (rather than asserting it is not true) is what discriminates.
     */
    public function test_disable_provider_reports_not_implemented(): void
    {
        $this->registry->method('hasProvider')->willReturn(true);

        $request = $this->createMock(Request::class);

        $response = $this->controller->disableProvider($request, ['name' => 'ldap']);

        $this->assertSame(501, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('not_implemented', $body['error']);
        $this->assertSame('ldap', $body['name']);
        $this->assertArrayNotHasKey('enabled', $body);
    }

    public function test_disable_provider_unknown_name_also_not_implemented(): void
    {
        $this->registry->method('hasProvider')->willReturn(false);

        $request = $this->createMock(Request::class);

        $response = $this->controller->disableProvider($request, ['name' => 'unknown']);

        $this->assertSame(501, $response->statusCode);
    }

    public function test_config_schema_returns_json_schema(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('saml');

        $this->registry->method('hasProvider')->willReturn(true);
        $this->registry->method('getProvider')->willReturn($provider);

        $request = $this->createMock(Request::class);

        $response = $this->controller->getConfigSchema($request, ['name' => 'saml']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('schema', $body);
        $schema = $body['schema'];
        $this->assertIsArray($schema);
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('Saml Provider Configuration', $schema['title']);
    }

    public function test_config_schema_not_found(): void
    {
        $this->registry->method('hasProvider')->willReturn(false);

        $request = $this->createMock(Request::class);

        $response = $this->controller->getConfigSchema($request, ['name' => 'missing']);

        $this->assertSame(404, $response->statusCode);
    }
}
