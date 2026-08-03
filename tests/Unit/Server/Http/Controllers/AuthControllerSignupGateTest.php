<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\AccountInactiveException;
use Phlix\Auth\AuthManager;
use Phlix\Auth\SignupDisabledException;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the S1 signup-approval gate at the HTTP boundary in
 * {@see AuthController}:
 *   - register() pending result  → 202 { status: pending, message, user: null }
 *   - register() disabled signups → 403 { error, code: auth.signups_disabled }
 *   - login() inactive account    → 403 { error, code: auth.account_pending|disabled }
 *
 * Mirrors {@see AuthControllerTest}: createMock(AuthManager::class) and assert
 * on the JSON response status + body.
 */
final class AuthControllerSignupGateTest extends TestCase
{
    public function testRegisterPendingReturns202WithPendingPayload(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('register')
            ->with('nina', 'nina@example.com', 'topsecret123')
            ->willReturn([
                'status' => 'pending',
                'message' => 'Your account is awaiting administrator approval.',
                'user' => null,
            ]);

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = [
            'username' => 'nina',
            'email' => 'nina@example.com',
            'password' => 'topsecret123',
        ];

        $response = $controller->register($request, []);

        $this->assertSame(202, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('pending', $body['status']);
        $this->assertNull($body['user']);
        $this->assertArrayHasKey('message', $body);
        // No session cookies set on a pending registration (nothing to log in as).
        $this->assertSame([], $response->cookies);
    }

    public function testRegisterDisabledReturns403WithCode(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('register')
            ->willThrowException(new SignupDisabledException());

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = [
            'username' => 'nina',
            'email' => 'nina@example.com',
            'password' => 'topsecret123',
        ];

        $response = $controller->register($request, []);

        $this->assertSame(403, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('auth.signups_disabled', $body['code']);
        $this->assertArrayHasKey('error', $body);
    }

    public function testLoginPendingReturns403WithAccountPendingCode(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('login')
            ->willThrowException(AccountInactiveException::forStatus('pending'));

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'nina', 'password' => 'topsecret123'];
        $request->headers = [];

        $response = $controller->login($request, []);

        $this->assertSame(403, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('auth.account_pending', $body['code']);
        $this->assertArrayHasKey('error', $body);
    }

    public function testLoginDisabledReturns403WithAccountDisabledCode(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('login')
            ->willThrowException(AccountInactiveException::forStatus('disabled'));

        $controller = new AuthController($authManager);

        $request = new Request();
        $request->body = ['username' => 'nina', 'password' => 'topsecret123'];
        $request->headers = [];

        $response = $controller->login($request, []);

        $this->assertSame(403, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('auth.account_disabled', $body['code']);
    }
}
