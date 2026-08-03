<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthProviderNotFoundException;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;

final class AuthProviderRegistryTest extends TestCase
{
    public function test_register_and_authenticate(): void
    {
        $registry = new AuthProviderRegistry();
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $authResult = new AuthResult(
            success: true,
            userId: 'user-123',
            externalId: 'ext-456',
        );
        $provider->method('authenticate')->willReturn($authResult);

        $registry->registerProvider($provider);

        $this->assertTrue($registry->hasProvider('test'));
        $this->assertCount(1, $registry->getProviders());

        $result = $registry->authenticate('test', ['token' => 'abc']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('user-123', $result->userId);
    }

    public function test_no_provider_returns_null(): void
    {
        $registry = new AuthProviderRegistry();

        $this->assertFalse($registry->hasProvider('unknown'));
        $this->assertCount(0, $registry->getProviders());
    }

    public function test_unknown_provider_throws(): void
    {
        $registry = new AuthProviderRegistry();

        $this->expectException(AuthProviderNotFoundException::class);
        $this->expectExceptionMessage("No auth provider registered with name 'nonexistent'");

        $registry->getProvider('nonexistent');
    }

    public function test_duplicate_registration_throws(): void
    {
        $provider1 = $this->createMock(ProviderInterface::class);
        $provider1->method('name')->willReturn('dup');

        $provider2 = $this->createMock(ProviderInterface::class);
        $provider2->method('name')->willReturn('dup');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($provider1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Auth provider 'dup' is already registered");

        $registry->registerProvider($provider2);
    }

    public function test_get_provider_returns_correct_instance(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('myprovider');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($provider);

        $retrieved = $registry->getProvider('myprovider');

        $this->assertSame($provider, $retrieved);
    }

    // -----------------------------------------------------------------------
    // S47 — instance-addressable registration (multi-instance)
    // -----------------------------------------------------------------------

    public function test_instance_key_maps_default_to_family_and_names_others(): void
    {
        // Default instance ('') is the family name verbatim — the pre-S47 key,
        // which is what keeps every existing caller working unchanged.
        $this->assertSame('oidc', AuthProviderRegistry::instanceKey('oidc'));
        $this->assertSame('oidc', AuthProviderRegistry::instanceKey('oidc', ''));
        // A named instance is family:instance.
        $this->assertSame('oidc:okta', AuthProviderRegistry::instanceKey('oidc', 'okta'));
        $this->assertSame('oidc:azure', AuthProviderRegistry::instanceKey('oidc', 'azure'));
    }

    /**
     * The headline S47 capability: two providers of the SAME family
     * (both name()==='oidc') coexist without the dup-throw when registered under
     * distinct instance keys, and each resolves to its own instance.
     */
    public function test_two_instances_of_same_family_coexist(): void
    {
        $okta = $this->createMock(ProviderInterface::class);
        $okta->method('name')->willReturn('oidc');
        $azure = $this->createMock(ProviderInterface::class);
        $azure->method('name')->willReturn('oidc');
        $default = $this->createMock(ProviderInterface::class);
        $default->method('name')->willReturn('oidc');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($okta, 'okta');
        $registry->registerProvider($azure, 'azure');
        $registry->registerProvider($default); // default instance, key 'oidc'

        $this->assertCount(3, $registry->getProviders());
        $this->assertTrue($registry->hasProvider('oidc:okta'));
        $this->assertTrue($registry->hasProvider('oidc:azure'));
        $this->assertTrue($registry->hasProvider('oidc')); // default

        $this->assertSame($okta, $registry->getProvider('oidc:okta'));
        $this->assertSame($azure, $registry->getProvider('oidc:azure'));
        $this->assertSame($default, $registry->getProvider('oidc'));
    }

    public function test_get_providers_by_family_returns_every_instance(): void
    {
        $oidcDefault = $this->createMock(ProviderInterface::class);
        $oidcDefault->method('name')->willReturn('oidc');
        $oidcOkta = $this->createMock(ProviderInterface::class);
        $oidcOkta->method('name')->willReturn('oidc');
        $ldap = $this->createMock(ProviderInterface::class);
        $ldap->method('name')->willReturn('ldap');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($oidcDefault);
        $registry->registerProvider($oidcOkta, 'okta');
        $registry->registerProvider($ldap);

        $family = $registry->getProvidersByFamily('oidc');
        $this->assertCount(2, $family);
        $this->assertArrayHasKey('oidc', $family);
        $this->assertArrayHasKey('oidc:okta', $family);
        $this->assertArrayNotHasKey('ldap', $family);

        $this->assertCount(1, $registry->getProvidersByFamily('ldap'));
        $this->assertCount(0, $registry->getProvidersByFamily('github'));
    }

    public function test_duplicate_same_instance_throws_with_composite_key(): void
    {
        $a = $this->createMock(ProviderInterface::class);
        $a->method('name')->willReturn('oidc');
        $b = $this->createMock(ProviderInterface::class);
        $b->method('name')->willReturn('oidc');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($a, 'okta');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Auth provider 'oidc:okta' is already registered");
        $registry->registerProvider($b, 'okta');
    }

    public function test_unregister_targets_only_the_named_instance(): void
    {
        $okta = $this->createMock(ProviderInterface::class);
        $okta->method('name')->willReturn('oidc');
        $default = $this->createMock(ProviderInterface::class);
        $default->method('name')->willReturn('oidc');

        $registry = new AuthProviderRegistry();
        $registry->registerProvider($default);
        $registry->registerProvider($okta, 'okta');

        $registry->unregisterProvider('oidc:okta');

        $this->assertFalse($registry->hasProvider('oidc:okta'));
        // The default instance is untouched.
        $this->assertTrue($registry->hasProvider('oidc'));
    }
}
