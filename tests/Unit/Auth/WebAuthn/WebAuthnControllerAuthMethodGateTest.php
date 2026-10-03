<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\WebAuthn;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Server\Http\Controllers\WebAuthnController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * F7 gates on the WebAuthn routes. Login ceremonies refuse with the fixed
 * disabled-method 401; ENROLMENT refuses with 403 while the method is off;
 * credential MANAGEMENT (list/delete) stays reachable unconditionally — the
 * gate-the-route-never-delete-the-config escape-hatch law: users must be able
 * to see (and later re-enrol) their passkeys to get OUT of a lock-out shape.
 *
 * Real AuthMethodPolicy over mocked collaborators (the class is final; the
 * settings rows below are the whole truth the gate sees).
 */
final class WebAuthnControllerAuthMethodGateTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function policy(array $overrides): AuthMethodPolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getAllOverrides')->willReturn($overrides);
        $settings->method('getDefault')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'auth.password.enabled', 'auth.webauthn.enabled' => true,
                default => false,
            },
        );

        $waDb = $this->createMock(Connection::class);
        $waDb->method('query')->willReturn([]);

        return new AuthMethodPolicy(
            $settings,
            $this->createMock(UserRepository::class),
            new WebAuthnCredentialRepository($waDb),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );
    }


    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body, ?string $userId = null): Request
    {
        $request = new Request();
        $request->body = $body;
        $request->userId = $userId;

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function managerRefusingCeremony(array $body): WebAuthnController
    {
        $manager = $this->createMock(WebAuthnManager::class);
        $manager->expects($this->never())->method('startAuthentication');
        $manager->expects($this->never())->method('finishAuthentication');
        $manager->expects($this->never())->method('startRegistration');
        $manager->expects($this->never())->method('finishRegistration');

        return new WebAuthnController(
            $manager,
            $this->createMock(AuthManager::class),
            null,
            null,
            $this->policy($body),
        );
    }

    public function test_start_authentication_off_returns_401_generic_body(): void
    {
        $response = $this->managerRefusingCeremony(['auth.webauthn.enabled' => false])
            ->startAuthentication($this->request(['username' => 'nina']), []);

        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $data */
        $data = json_decode($response->body, true);
        $this->assertSame('Invalid credentials', $data['error']);
        $this->assertArrayNotHasKey('code', $data);
    }

    public function test_finish_authentication_off_returns_401_generic_body(): void
    {
        $response = $this->managerRefusingCeremony(['auth.webauthn.enabled' => false])
            ->finishAuthentication($this->request([
                'username' => 'nina',
                'credential' => [],
                'challenge' => 'c',
            ]), []);

        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $data */
        $data = json_decode($response->body, true);
        $this->assertSame('Invalid credentials', $data['error']);
        $this->assertArrayNotHasKey('code', $data);
    }

    public function test_start_registration_off_returns_403_enrolment_refused(): void
    {
        $response = $this->managerRefusingCeremony(['auth.webauthn.enabled' => false])
            ->startRegistration($this->request([], 'user-123'), []);

        $this->assertSame(403, $response->statusCode);
        /** @var array<string, mixed> $data */
        $data = json_decode($response->body, true);
        $this->assertSame('Passkey enrolment is disabled on this server', $data['error']);
        $this->assertArrayNotHasKey('code', $data);
    }

    public function test_finish_registration_off_returns_403_enrolment_refused(): void
    {
        $response = $this->managerRefusingCeremony(['auth.webauthn.enabled' => false])
            ->finishRegistration($this->request([
                'credential' => ['x' => 'y'],
                'challenge' => 'c',
            ], 'user-123'), []);

        $this->assertSame(403, $response->statusCode);
    }

    public function test_management_routes_stay_live_while_method_off(): void
    {
        $manager = $this->createMock(WebAuthnManager::class);
        $manager->method('listCredentials')->willReturn([]);
        $manager->method('deleteCredential')->willReturn(true);
        $controller = new WebAuthnController(
            $manager,
            $this->createMock(AuthManager::class),
            null,
            null,
            $this->policy(['auth.webauthn.enabled' => false]),
        );

        $list = $controller->listCredentials($this->request([], 'user-123'), []);
        $this->assertSame(200, $list->statusCode);

        $delete = $controller->deleteCredential(
            $this->request([], 'user-123'),
            ['id' => 'cred-1'],
        );
        $this->assertSame(200, $delete->statusCode);
    }

    public function test_login_ceremony_runs_when_method_enabled_or_unwired(): void
    {
        // Enabled via absence (default-true) → the ceremony is reached.
        $manager = $this->createMock(WebAuthnManager::class);
        // Both the wired controller and the null-policy legacy controller below
        // run the ceremony — the expectation counts calls, not controllers.
        $manager->expects($this->exactly(2))
            ->method('startAuthentication')
            ->with('nina')
            ->willReturn(['challenge' => 'abc']);
        $controller = new WebAuthnController(
            $manager,
            $this->createMock(AuthManager::class),
            null,
            null,
            $this->policy([]),
        );
        $response = $controller->startAuthentication($this->request(['username' => 'nina']), []);
        $this->assertSame(200, $response->statusCode);

        // Null policy (legacy hand-built controller) → untouched behaviour.
        $legacy = new WebAuthnController($manager, $this->createMock(AuthManager::class));
        $this->assertSame(200, $legacy->startAuthentication($this->request(['username' => 'nina']), [])->statusCode);
    }
}
