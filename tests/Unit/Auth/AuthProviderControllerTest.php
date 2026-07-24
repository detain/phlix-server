<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthProviderBootstrapper;
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
    /** @var AuthProviderBootstrapper&MockObject */
    private AuthProviderBootstrapper $bootstrapper;
    private AuthProviderController $controller;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(AuthProviderRegistry::class);
        $this->bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $this->controller = new AuthProviderController($this->registry, $this->bootstrapper);
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
     * S44: enabling a configured provider persists the flag, registers it in the
     * current worker, and honestly reports `enabled: true` + `live: true`.
     */
    public function test_enable_provider_configured_reports_enabled_and_live(): void
    {
        $this->bootstrapper->method('isToggleable')->with('oidc')->willReturn(true);
        $this->bootstrapper->method('isConfigured')->with('oidc')->willReturn(true);
        $this->bootstrapper->expects($this->once())->method('enable')->with('oidc')->willReturn(true);

        $request = $this->createMock(Request::class);

        $response = $this->controller->enableProvider($request, ['name' => 'oidc']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('oidc', $body['name']);
        $this->assertTrue($body['enabled']);
        $this->assertTrue($body['live']);
    }

    /**
     * S44: enabling a provider that has NOT been configured yet is rejected with
     * a 409 — the "Enabled" badge must mean "provider live", never a false claim.
     */
    public function test_enable_provider_not_configured_returns_409(): void
    {
        $this->bootstrapper->method('isToggleable')->with('oidc')->willReturn(true);
        $this->bootstrapper->method('isConfigured')->with('oidc')->willReturn(false);
        $this->bootstrapper->expects($this->never())->method('enable');

        $request = $this->createMock(Request::class);

        $response = $this->controller->enableProvider($request, ['name' => 'oidc']);

        $this->assertSame(409, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('not_configured', $body['error']);
        $this->assertSame('oidc', $body['name']);
    }

    /**
     * A provider name that is not one of the toggleable built-ins is a 404 —
     * distinct from the old blanket 501 that lied for every name.
     */
    public function test_enable_provider_unknown_name_returns_404(): void
    {
        $this->bootstrapper->method('isToggleable')->with('nonexistent')->willReturn(false);
        $this->bootstrapper->expects($this->never())->method('enable');

        $request = $this->createMock(Request::class);

        $response = $this->controller->enableProvider($request, ['name' => 'nonexistent']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('unknown_provider', $body['error']);
    }

    /**
     * S44: disabling persists the flag off and honestly reports `enabled: false`.
     */
    public function test_disable_provider_reports_disabled(): void
    {
        $this->bootstrapper->method('isToggleable')->with('ldap')->willReturn(true);
        $this->bootstrapper->expects($this->once())->method('disable')->with('ldap');

        $request = $this->createMock(Request::class);

        $response = $this->controller->disableProvider($request, ['name' => 'ldap']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('ldap', $body['name']);
        $this->assertFalse($body['enabled']);
    }

    public function test_disable_provider_unknown_name_returns_404(): void
    {
        $this->bootstrapper->method('isToggleable')->with('unknown')->willReturn(false);
        $this->bootstrapper->expects($this->never())->method('disable');

        $request = $this->createMock(Request::class);

        $response = $this->controller->disableProvider($request, ['name' => 'unknown']);

        $this->assertSame(404, $response->statusCode);
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
