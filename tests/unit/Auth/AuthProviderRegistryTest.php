<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlex\Auth\AuthProviderNotFoundException;
use Phlex\Auth\AuthProviderRegistry;
use Phlex\Shared\Auth\AuthResult;
use Phlex\Shared\Auth\ProviderInterface;

/**
 * @covers \Phlex\Auth\AuthProviderRegistry
 */
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
}
