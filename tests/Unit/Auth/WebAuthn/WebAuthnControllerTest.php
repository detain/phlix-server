<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\WebAuthn;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthManager;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Server\Http\Controllers\WebAuthnController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Auth\WebAuthn\WebAuthnCredential;
use Phlix\Shared\Auth\AuthResult;
use Workerman\MySQL\Connection;

final class WebAuthnControllerTest extends TestCase
{
    private WebAuthnController $controller;
    private WebAuthnManager $webauthnManager;
    private AuthManager $authManager;

    protected function setUp(): void
    {
        $this->webauthnManager = $this->createMock(WebAuthnManager::class);
        $this->authManager = $this->createMock(AuthManager::class);
        $this->controller = new WebAuthnController($this->webauthnManager, $this->authManager);
    }

    public function test_start_registration_returns_options(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [];

        $options = [
            'challenge' => base64_encode(random_bytes(32)),
            'rp' => ['id' => 'localhost', 'name' => 'Test'],
            'user' => ['id' => 'user-123', 'name' => 'testuser', 'displayName' => 'Test User'],
            'pubKeyCredParams' => [],
            'timeout' => 60000,
        ];

        $this->webauthnManager
            ->method('startRegistration')
            ->with('user-123', 'testuser')
            ->willReturn($options);

        $this->authManager->method('getUser')->willReturn(['username' => 'testuser']);

        $response = $this->controller->startRegistration($request, []);

        $this->assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertSame($options['challenge'], $data['challenge']);
    }

    public function test_start_registration_requires_auth(): void
    {
        $request = new Request();
        $request->userId = null;

        $response = $this->controller->startRegistration($request, []);

        $this->assertSame(401, $response->statusCode);
    }

    public function test_finish_registration_success(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $credentialData = [
            'credential' => [
                'attestationObject' => base64_encode(random_bytes(100)),
                'clientDataJSON' => base64_encode(json_encode([
                    'type' => 'webauthn.create',
                    'challenge' => base64_encode(random_bytes(32)),
                ])),
            ],
            'challenge' => random_bytes(32),
        ];
        $request->body = $credentialData;

        $this->authManager->method('getUser')->willReturn(['username' => 'testuser']);

        $this->webauthnManager
            ->method('finishRegistration')
            ->willReturn(base64_encode(random_bytes(32)));

        $response = $this->controller->finishRegistration($request, []);

        $this->assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('credential_id', $data);
    }

    public function test_finish_registration_missing_fields(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [];

        $response = $this->controller->finishRegistration($request, []);

        $this->assertSame(400, $response->statusCode);
    }

    public function test_list_credentials(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $credentials = [
            new WebAuthnCredential(
                credentialId: random_bytes(32),
                userId: 'user-123',
                publicKey: random_bytes(65),
                counter: '10',
                type: 'public-key',
                deviceType: 'platform',
                aaguid: null,
                registeredAt: time()
            )
        ];

        $this->webauthnManager
            ->method('listCredentials')
            ->with('user-123')
            ->willReturn($credentials);

        $response = $this->controller->listCredentials($request, []);

        $this->assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('credentials', $data);
        $this->assertCount(1, $data['credentials']);
    }

    public function test_list_credentials_requires_auth(): void
    {
        $request = new Request();
        $request->userId = null;

        $response = $this->controller->listCredentials($request, []);

        $this->assertSame(401, $response->statusCode);
    }

    public function test_delete_credential_success(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $credentialId = base64_encode(random_bytes(32));

        $this->webauthnManager
            ->method('deleteCredential')
            ->with('user-123', $credentialId)
            ->willReturn(true);

        $response = $this->controller->deleteCredential($request, ['id' => $credentialId]);

        $this->assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertSame('Credential deleted successfully', $data['message']);
    }

    public function test_delete_credential_not_found(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $credentialId = base64_encode(random_bytes(32));

        $this->webauthnManager
            ->method('deleteCredential')
            ->willReturn(false);

        $response = $this->controller->deleteCredential($request, ['id' => $credentialId]);

        $this->assertSame(404, $response->statusCode);
    }

    public function test_delete_credential_requires_auth(): void
    {
        $request = new Request();
        $request->userId = null;

        $response = $this->controller->deleteCredential($request, ['id' => 'some-id']);

        $this->assertSame(401, $response->statusCode);
    }
}
