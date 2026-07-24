<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthManager;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use RuntimeException;

/**
 * S44 Path A: an `ldap:`-prefixed identifier is routed through
 * {@see AuthManager::loginWithProvider()} instead of the local password store.
 *
 * @covers \Phlix\Server\Http\Controllers\AuthController
 */
final class AuthControllerLdapLoginTest extends TestCase
{
    public function test_ldap_prefixed_login_delegates_to_provider_flow(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('loginWithProvider')
            ->with(
                'ldap:alice',
                ['username' => 'alice', 'password' => 'secretpw'],
                'unknown',
            )
            ->willReturn([
                'access_token' => 'ldap-access',
                'refresh_token' => 'ldap-refresh',
                'user' => ['id' => 'u-ldap', 'username' => 'alice'],
            ]);
        // The normal password path must NOT be taken.
        $authManager->expects($this->never())->method('login');

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'ldap:alice', 'password' => 'secretpw'];

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('ldap-access', $body['access_token']);
    }

    public function test_ldap_login_invalid_credentials_returns_401(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('loginWithProvider')
            ->willThrowException(new InvalidArgumentException('invalid_credentials'));

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'ldap:bob', 'password' => 'wrong'];

        $response = $controller->login($request, []);

        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('invalid_credentials', $body['error']);
    }

    public function test_ldap_login_provider_unavailable_returns_503(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        // AuthProviderNotFoundException / "ProviderManager not configured" both
        // extend RuntimeException.
        $authManager->method('loginWithProvider')
            ->willThrowException(new RuntimeException("Provider 'ldap' is not registered."));

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'ldap:carol', 'password' => 'pw'];

        $response = $controller->login($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('provider_unavailable', $body['code']);
    }

    public function test_non_ldap_login_still_uses_password_flow(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('login')
            ->with('bob', 'pw', 'unknown')
            ->willReturn([
                'access_token' => 'pw-access',
                'refresh_token' => 'pw-refresh',
                'user' => ['id' => 'u-bob', 'username' => 'bob'],
            ]);
        $authManager->expects($this->never())->method('loginWithProvider');

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'bob', 'password' => 'pw'];

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('pw-access', $body['access_token']);
    }
}
