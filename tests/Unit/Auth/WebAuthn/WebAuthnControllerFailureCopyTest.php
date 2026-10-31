<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\WebAuthn;

use Phlix\Auth\AuthManager;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Server\Http\Controllers\WebAuthnController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * minors M-SERVER-06 (W-min108): the four `catch (\InvalidArgumentException)`
 * arms in WebAuthnController used to render the manager's terse parser/ceremony
 * diagnostics verbatim on the user's wire ('rpId hash mismatch', 'Malformed
 * COSE public key', 'User not found', 'No credentials registered for user').
 * The cure keeps the envelope shape byte-identical (`{"error": "..."}` only —
 * no fields added, no statuses moved) and swaps the VALUE to a phase-shaped
 * generic sentence, with the raw detail routed to the server error log.
 *
 * Side effect pinned here implicitly: the finishAuthentication/startAuthentication
 * arms no longer leak account-existence through 'User not found'/'No credentials
 * registered for user'.
 *
 * Deliberately OUT of scope (disclosed): the RESULT-object failure path
 * `$result->error ?? 'Authentication failed'` is not an exception arm; the
 * missing-field guard copy is pre-existing shaped text.
 */
final class WebAuthnControllerFailureCopyTest extends TestCase
{
    /** A real diagnostic string the manager throws (parser jargon class). */
    private const JARGON = 'rpId hash mismatch';

    /** @var WebAuthnManager&MockObject */
    private WebAuthnManager $webauthnManager;

    /** @var AuthManager&MockObject */
    private AuthManager $authManager;

    private WebAuthnController $controller;

    private ?string $logFile = null;

    protected function setUp(): void
    {
        $this->webauthnManager = $this->createMock(WebAuthnManager::class);
        $this->authManager = $this->createMock(AuthManager::class);
        $this->controller = new WebAuthnController($this->webauthnManager, $this->authManager);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }

        $this->logFile = null;
    }

    public function testStartRegistrationFailureRendersGenericCopyNotJargon(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [];

        $this->authManager->method('getUser')->willReturn(['username' => 'testuser']);
        $this->webauthnManager->method('startRegistration')
            ->willThrowException(new \InvalidArgumentException(self::JARGON));

        $response = $this->controller->startRegistration($request, []);

        self::assertSame(400, $response->statusCode);
        self::assertSame(
            'The security key registration could not be started. Try again, or use another sign-in method.',
            $this->errorOf($response->body),
        );
        self::assertStringNotContainsString(self::JARGON, $response->body);
    }

    public function testFinishRegistrationFailureRendersGenericCopyNotJargon(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [
            'credential' => [
                'attestationObject' => base64_encode(random_bytes(100)),
                'clientDataJSON' => base64_encode(json_encode(['type' => 'webauthn.create'], JSON_THROW_ON_ERROR)),
            ],
            'challenge' => random_bytes(32),
        ];

        $this->authManager->method('getUser')->willReturn(['username' => 'testuser']);
        $this->webauthnManager->method('finishRegistration')
            ->willThrowException(new \InvalidArgumentException('Malformed COSE public key'));

        $response = $this->controller->finishRegistration($request, []);

        self::assertSame(400, $response->statusCode);
        self::assertSame(
            'The security key registration could not be completed. Try again, or use another sign-in method.',
            $this->errorOf($response->body),
        );
        self::assertStringNotContainsString('Malformed COSE public key', $response->body);
    }

    public function testStartAuthenticationFailureRendersGenericCopyNotJargon(): void
    {
        $request = new Request();
        $request->body = ['username' => 'testuser'];

        $this->webauthnManager->method('startAuthentication')
            ->willThrowException(new \InvalidArgumentException('No credentials registered for user'));

        $response = $this->controller->startAuthentication($request, []);

        self::assertSame(400, $response->statusCode);
        self::assertSame(
            'The security key request could not be started. Try again, or use another sign-in method.',
            $this->errorOf($response->body),
        );
        self::assertStringNotContainsString('No credentials registered for user', $response->body);
    }

    public function testFinishAuthenticationFailureRendersReviewerCopyNotJargon(): void
    {
        $request = new Request();
        $request->body = [
            'username' => 'testuser',
            'credential' => ['id' => 'cred-1'],
            'challenge' => random_bytes(32),
        ];

        $this->webauthnManager->method('finishAuthentication')
            ->willThrowException(new \InvalidArgumentException(self::JARGON));

        $response = $this->controller->finishAuthentication($request, []);

        self::assertSame(401, $response->statusCode);
        self::assertSame(
            'The security key\'s response could not be verified. Try again, or use another key.',
            $this->errorOf($response->body),
        );
        self::assertStringNotContainsString(self::JARGON, $response->body);
    }

    /**
     * The raw diagnostic must still reach the operator: the arms error_log the
     * manager's message. Captured by pointing error_log() at a scratch file.
     */
    public function testRawDetailIsSentToTheErrorLog(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'phlix-wa06-log-');
        ini_set('error_log', $this->logFile);

        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [];

        $this->authManager->method('getUser')->willReturn(['username' => 'testuser']);
        $this->webauthnManager->method('startRegistration')
            ->willThrowException(new \InvalidArgumentException(self::JARGON));

        $this->controller->startRegistration($request, []);

        $log = (string) file_get_contents((string) $this->logFile);
        self::assertStringContainsString(self::JARGON, $log);
        self::assertStringContainsString('webauthn:', $log);
    }

    /** @return string the decoded error member of the bare {error: ...} envelope */
    private function errorOf(string $body): string
    {
        /** @var array{error?: mixed} $data */
        $data = json_decode($body, true);
        self::assertArrayHasKey('error', $data);

        return (string) $data['error'];
    }
}
