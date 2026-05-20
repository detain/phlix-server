<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\WebAuthn;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\WebAuthnProvider;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Shared\Auth\AuthResult;

final class WebAuthnProviderTest extends TestCase
{
    private WebAuthnProvider $provider;
    private WebAuthnManager $webauthnManager;

    protected function setUp(): void
    {
        $this->webauthnManager = $this->createMock(WebAuthnManager::class);
        $this->provider = new WebAuthnProvider($this->webauthnManager);
    }

    public function test_name_returns_webauthn(): void
    {
        $this->assertSame('webauthn', $this->provider->name());
    }

    public function test_supportsAuthentication_returns_true_when_credentials_present(): void
    {
        $credentials = [
            'username' => 'testuser',
            'challenge' => random_bytes(32),
            'credential' => ['id' => 'cred-id'],
        ];

        $this->assertTrue($this->provider->supportsAuthentication($credentials));
    }

    public function test_supportsAuthentication_returns_false_when_missing_fields(): void
    {
        $this->assertFalse($this->provider->supportsAuthentication(['username' => 'test']));
        $this->assertFalse($this->provider->supportsAuthentication(['challenge' => 'abc']));
        $this->assertFalse($this->provider->supportsAuthentication([]));
    }

    public function test_authenticate_success(): void
    {
        $credentials = [
            'username' => 'testuser',
            'challenge' => random_bytes(32),
            'credential' => [
                'id' => base64_encode(random_bytes(32)),
                'clientDataJSON' => base64_encode(json_encode(['type' => 'webauthn.get', 'challenge' => ''])),
                'authenticatorData' => base64_encode(random_bytes(37)),
                'signature' => base64_encode(random_bytes(64)),
            ],
        ];

        $authResult = new AuthResult(
            success: true,
            userId: 'user-123',
            externalId: 'webauthn:some-cred-id',
            attributes: ['username' => 'testuser']
        );

        $this->webauthnManager
            ->method('finishAuthentication')
            ->willReturn($authResult);

        $result = $this->provider->authenticate($credentials);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('user-123', $result->userId);
    }

    public function test_authenticate_no_credentials(): void
    {
        $credentials = [
            'username' => 'testuser',
            'challenge' => random_bytes(32),
            'credential' => [
                'id' => base64_encode(random_bytes(32)),
                'clientDataJSON' => base64_encode(json_encode(['type' => 'webauthn.get', 'challenge' => ''])),
                'authenticatorData' => base64_encode(random_bytes(37)),
                'signature' => base64_encode(random_bytes(64)),
            ],
        ];

        $this->webauthnManager
            ->method('finishAuthentication')
            ->willThrowException(new \InvalidArgumentException('Credential not found'));

        $result = $this->provider->authenticate($credentials);

        $this->assertTrue($result->isFailure());
    }

    public function test_authenticate_missing_fields_returns_failure(): void
    {
        $credentials = ['username' => 'testuser'];

        $result = $this->provider->authenticate($credentials);

        $this->assertTrue($result->isFailure());
        $this->assertSame('missing_required_fields', $result->error);
    }

    public function test_authenticate_generic_exception_returns_failure(): void
    {
        $credentials = [
            'username' => 'testuser',
            'challenge' => random_bytes(32),
            'credential' => ['id' => base64_encode(random_bytes(32))],
        ];

        $this->webauthnManager
            ->method('finishAuthentication')
            ->willThrowException(new \RuntimeException('Unexpected error'));

        $result = $this->provider->authenticate($credentials);

        $this->assertTrue($result->isFailure());
        $this->assertSame('webauthn_authentication_failed', $result->error);
    }
}
