<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use InvalidArgumentException;
use Phlix\Auth\AccountInactiveException;
use Phlix\Auth\AuthManager;
use Phlix\Auth\SignupDisabledException;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Pins the SRV-S12 redirect-carried auth envelope on every browser arm of
 * register()/login() that routes through messageRedirect(): the human text in
 * `error`/`notice`, plus `&code=` exactly where the JSON twin has a stable
 * machine code (and none where it does not).
 */
final class AuthControllerRedirectEnvelopeTest extends TestCase
{
    private function browserRequest(string $path, array $body): Request
    {
        $request = new Request();
        $request->path = $path;
        $request->body = $body;

        return $request;
    }

    private function location(\Phlix\Server\Http\Response $response): ?string
    {
        self::assertSame(302, $response->statusCode);

        return $response->headers['Location'] ?? null;
    }

    public function testRegisterMissingFieldsRedirectsWithErrorAndNoCode(): void
    {
        $controller = new AuthController($this->createMock(AuthManager::class));

        $response = $controller->register($this->browserRequest('/auth/register', ['username' => 'x']), []);

        self::assertSame(
            '/auth/register?error=' . rawurlencode('Missing required fields: username, email, password'),
            $this->location($response)
        );
    }

    public function testRegisterPendingRedirectsToLoginWithNoticeAndNoCode(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('register')->willReturn([
            'status' => 'pending',
            'message' => 'Your account is awaiting administrator approval.',
            'user' => null,
        ]);
        $controller = new AuthController($authManager);

        $response = $controller->register($this->browserRequest('/auth/register', [
            'username' => 'nina', 'email' => 'n@example.com', 'password' => 'topsecret123',
        ]), []);

        self::assertSame(
            '/login?notice=' . rawurlencode('Your account is awaiting administrator approval.'),
            $this->location($response)
        );
    }

    public function testRegisterSignupDisabledRedirectCarriesTheMachineCode(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('register')->willThrowException(new SignupDisabledException());
        $controller = new AuthController($authManager);

        $response = $controller->register($this->browserRequest('/auth/register', [
            'username' => 'nina', 'email' => 'n@example.com', 'password' => 'topsecret123',
        ]), []);

        self::assertSame(
            '/auth/register?error=' . rawurlencode((new SignupDisabledException())->getMessage())
                . '&code=auth.signups_disabled',
            $this->location($response)
        );
    }

    public function testRegisterInvalidArgumentRedirectsWithoutACode(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('register')->willThrowException(new InvalidArgumentException('Password too short & weak'));
        $controller = new AuthController($authManager);

        $response = $controller->register($this->browserRequest('/auth/register', [
            'username' => 'nina', 'email' => 'n@example.com', 'password' => 'x',
        ]), []);

        self::assertSame(
            '/auth/register?error=' . rawurlencode('Password too short & weak'),
            $this->location($response)
        );
    }

    public function testLoginMissingFieldsRedirectsWithErrorAndNoCode(): void
    {
        $controller = new AuthController($this->createMock(AuthManager::class));

        $response = $controller->login($this->browserRequest('/auth/login', ['username' => 'x']), []);

        self::assertSame(
            '/login?error=' . rawurlencode('Missing required fields: username, password'),
            $this->location($response)
        );
    }

    public function testLoginInactiveAccountRedirectCarriesItsCode(): void
    {
        $e = AccountInactiveException::forStatus('pending');
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('login')->willThrowException($e);
        $controller = new AuthController($authManager);

        $response = $controller->login($this->browserRequest('/auth/login', [
            'username' => 'nina', 'password' => 'topsecret123',
        ]), []);

        self::assertSame(
            '/login?error=' . rawurlencode($e->getMessage()) . '&code=auth.account_pending',
            $this->location($response)
        );
    }

    public function testLoginBadCredentialsRedirectCarriesInvalidCredentialsCode(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('login')->willThrowException(new InvalidArgumentException('Invalid credentials'));
        $controller = new AuthController($authManager);

        $response = $controller->login($this->browserRequest('/auth/login', [
            'username' => 'nina', 'password' => 'wrong',
        ]), []);

        self::assertSame(
            '/login?error=' . rawurlencode('Invalid credentials') . '&code=auth.invalid_credentials',
            $this->location($response)
        );
    }
}
