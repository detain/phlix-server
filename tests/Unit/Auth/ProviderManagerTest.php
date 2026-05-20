<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;
use Phlix\Auth\ProviderManager;
use Phlix\Auth\UserRepository;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Auth\ProviderManager
 */
final class ProviderManagerTest extends TestCase
{
    private AuthProviderRegistry $registry;
    private UserRepository $userRepo;

    protected function setUp(): void
    {
        $db = $this->createMock(Connection::class);
        $this->userRepo = new UserRepository($db);
        $this->registry = new AuthProviderRegistry($this->createMock(\Psr\Container\ContainerInterface::class));
    }

    public function test_parse_provider_prefix(): void
    {
        $manager = new ProviderManager($this->registry, $this->userRepo);

        $result = $manager->parseProviderPrefix('oidc:alice@example.com');
        $this->assertSame(['oidc', 'alice@example.com'], $result);

        $result = $manager->parseProviderPrefix('ldap:bob');
        $this->assertSame(['ldap', 'bob'], $result);

        $result = $manager->parseProviderPrefix('saml:https://idp.example.com/user/123');
        $this->assertSame(['saml', 'https://idp.example.com/user/123'], $result);
    }

    public function test_parse_provider_prefix_returns_null_for_plain_username(): void
    {
        $manager = new ProviderManager($this->registry, $this->userRepo);

        $this->assertNull($manager->parseProviderPrefix('alice'));
        $this->assertNull($manager->parseProviderPrefix('alice@example.com'));
    }

    public function test_parse_provider_prefix_with_multiple_colons(): void
    {
        $manager = new ProviderManager($this->registry, $this->userRepo);

        $result = $manager->parseProviderPrefix('user:with:colons');
        $this->assertSame(['user', 'with:colons'], $result);
    }

    public function test_parse_provider_prefix_case_insensitive(): void
    {
        $manager = new ProviderManager($this->registry, $this->userRepo);

        $result = $manager->parseProviderPrefix('OIDC:alice@example.com');
        $this->assertSame(['oidc', 'alice@example.com'], $result);

        $result = $manager->parseProviderPrefix('Ldap:bob');
        $this->assertSame(['ldap', 'bob'], $result);
    }

    public function test_authenticate_with_provider(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('oidc');
        $provider->method('authenticate')
            ->with(['id_token' => 'token123'])
            ->willReturn(new AuthResult(
                success: true,
                userId: 'user-oidc-123',
                externalId: 'https://accounts.google.com/12345',
            ));

        $this->registry->registerProvider($provider);

        $manager = new ProviderManager($this->registry, $this->userRepo);

        $result = $manager->authenticate('oidc:alice@example.com', ['id_token' => 'token123']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('user-oidc-123', $result->userId);
    }

    public function test_authenticate_with_unknown_provider_throws(): void
    {
        $manager = new ProviderManager($this->registry, $this->userRepo);

        $this->expectException(\Phlix\Auth\AuthProviderNotFoundException::class);
        $this->expectExceptionMessage("Provider 'unknown' is not registered");

        $manager->authenticate('unknown:alice', []);
    }

    public function test_fallback_to_password_auth(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $params) {
                if (strpos($sql, 'username = ?') !== false) {
                    return [[
                        'id' => 'user-password-123',
                        'username' => 'alice',
                        'email' => 'alice@example.com',
                        'password_hash' => password_hash('secret123', PASSWORD_ARGON2ID),
                        'display_name' => 'Alice',
                    ]];
                }
                if (strpos($sql, 'id = ?') !== false) {
                    return [[
                        'id' => 'user-password-123',
                        'username' => 'alice',
                        'email' => 'alice@example.com',
                        'password_hash' => password_hash('secret123', PASSWORD_ARGON2ID),
                        'display_name' => 'Alice',
                    ]];
                }
                return [];
            });

        $repo = new UserRepository($db);
        $registry = new AuthProviderRegistry($this->createMock(\Psr\Container\ContainerInterface::class));
        $manager = new ProviderManager($registry, $repo);

        $result = $manager->authenticate('alice', ['password' => 'secret123']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('user-password-123', $result->userId);
    }

    public function test_fallback_password_auth_fails_for_unknown_user(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        $registry = new AuthProviderRegistry($this->createMock(\Psr\Container\ContainerInterface::class));
        $manager = new ProviderManager($registry, $repo);

        $result = $manager->authenticate('nobody', ['password' => 'secret']);

        $this->assertTrue($result->isFailure());
        $this->assertSame('user_not_found', $result->error);
    }

    public function test_fallback_password_auth_fails_for_wrong_password(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $params) {
                if (strpos($sql, 'username = ?') !== false) {
                    return [[
                        'id' => 'user-123',
                        'username' => 'alice',
                        'password_hash' => password_hash('correct-password', PASSWORD_ARGON2ID),
                        'display_name' => 'Alice',
                    ]];
                }
                if (strpos($sql, 'id = ?') !== false) {
                    return [[
                        'id' => 'user-123',
                        'username' => 'alice',
                        'password_hash' => password_hash('correct-password', PASSWORD_ARGON2ID),
                        'display_name' => 'Alice',
                    ]];
                }
                return [];
            });

        $repo = new UserRepository($db);
        $registry = new AuthProviderRegistry($this->createMock(\Psr\Container\ContainerInterface::class));
        $manager = new ProviderManager($registry, $repo);

        $result = $manager->authenticate('alice', ['password' => 'wrong-password']);

        $this->assertTrue($result->isFailure());
        $this->assertSame('invalid_credentials', $result->error);
    }
}
