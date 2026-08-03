<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthManager;
use Phlix\Auth\RateLimitException;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use RuntimeException;

/**
 * S44 Path A: an `ldap:`-prefixed identifier is routed through
 * {@see AuthManager::loginWithProvider()} instead of the local password store.
 *
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
        // S44 Finding 5 — the raw provider error code is NOT echoed back; a
        // single generic credential-rejection message is returned.
        $this->assertSame('Invalid credentials', $body['error']);
    }

    /**
     * S44 Finding 5 (LOW) — LdapProvider distinguishes `user_not_found` from
     * `invalid_credentials`. Echoing either verbatim is a user-enumeration
     * oracle. Both must produce the SAME generic 401 response so an attacker
     * cannot tell a real account from a bad password.
     */
    public function test_ldap_login_does_not_leak_user_enumeration_oracle(): void
    {
        foreach (['user_not_found', 'invalid_credentials'] as $providerError) {
            $authManager = $this->createMock(AuthManager::class);
            $authManager->method('loginWithProvider')
                ->willThrowException(new InvalidArgumentException($providerError));

            $controller = new AuthController($authManager);

            $request = new Request();
            $request->body = ['username' => 'ldap:probe', 'password' => 'x'];

            $response = $controller->login($request, []);

            $this->assertSame(401, $response->statusCode, "for provider error: {$providerError}");
            /** @var array<string, mixed> $body */
            $body = json_decode($response->body, true);
            $this->assertSame('Invalid credentials', $body['error'], "for provider error: {$providerError}");
            // The raw provider code must never be reflected to the client.
            $this->assertStringNotContainsString($providerError, $response->body);
        }
    }

    /**
     * S44 Finding 5 — a genuine LDAP config/connection failure (LdapProvider
     * prefixes these `ldap_error:`) is a 500-class problem, NOT a bad password.
     * It must surface as a 503 "unavailable", not be masked as a 401.
     */
    public function test_ldap_login_config_error_returns_503_not_401(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('loginWithProvider')
            ->willThrowException(new InvalidArgumentException("ldap_error: Can't contact LDAP server"));

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'ldap:dave', 'password' => 'pw'];

        $response = $controller->login($request, []);

        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('provider_unavailable', $body['code']);
        // The raw error detail must not leak to the client.
        $this->assertStringNotContainsString('contact LDAP server', $response->body);
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

    /**
     * S44 Finding 2 (controller level) — the brute-force throttle inside
     * loginWithProvider() throws RateLimitException, which extends
     * RuntimeException. The `ldap:` login path MUST re-throw it (so it reaches
     * the central 429 + Retry-After mapper) BEFORE its RuntimeException branch
     * turns generic provider errors into a 503. This proves the catch ordering:
     * a rate-limit trip is NOT swallowed as "provider unavailable".
     */
    public function test_ldap_login_rate_limit_exception_propagates_not_503(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('loginWithProvider')
            ->willThrowException(new RateLimitException(time() + 60, 0));

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'ldap:eve', 'password' => 'pw'];

        // The exception must escape the controller unchanged — not be caught and
        // turned into a 503 provider_unavailable response.
        $this->expectException(RateLimitException::class);

        $controller->login($request, []);
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
