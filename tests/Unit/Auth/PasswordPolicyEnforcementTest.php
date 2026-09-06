<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\PasswordPolicy;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use Phlix\Server\Http\Request;

/**
 * The "half-effective setting" guard for `auth.password.min_length`.
 *
 * ## Why this file exists separately
 *
 * The minimum length was a bare `strlen($password) < 8` duplicated at THREE
 * sites: `AuthManager::register()`, `AdminUserController::create()` and
 * `AdminUserController::update()`. plan_settings.md is explicit that a setting
 * wired to only some of its duplicates is *half-effective* — and that is the
 * subtler failure, because the obvious path (self-service registration) works,
 * so the setting looks correct while an administrator can still set a password
 * that violates it.
 *
 * {@see PasswordPolicyTest} proves the policy object itself behaves. This file
 * proves every REAL call site consults it. A unit test of the policy alone
 * would stay green if a call site were left with its literal in place, which is
 * exactly the regression worth pinning.
 *
 * Each test drives the SAME override (minimum 20) through a different entry
 * point and requires a 12-character password — comfortably legal under the
 * shipped default of 8 — to be REJECTED.
 *
 * Mutation-verified: restoring `strlen($password) < 8` at any one of the three
 * sites turns exactly that site's test red while the others stay green, which
 * is the property that makes this file a real guard rather than a smoke test.
 */
class PasswordPolicyEnforcementTest extends TestCase
{
    /** @var list<string> log files minted by silentLogger(), removed in tearDown(). */
    private array $mintedLogPaths = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        // S439: sweep every stream path minted by silentLogger().
        foreach ($this->mintedLogPaths as $path) {
            @unlink($path);
        }
        $this->mintedLogPaths = [];
    }

    /**
     * A password that is fine under the shipped default (8) but violates the
     * raised minimum (20) these tests configure.
     */
    private const LEGAL_UNDER_DEFAULT = 'twelvechars!';

    private const RAISED_MINIMUM = 20;

    private function settings(): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with(PasswordPolicy::SETTING_KEY)
            ->willReturn(self::RAISED_MINIMUM);

        return $settings;
    }

    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_pwpolicy_' . uniqid('', true) . '.log';
        $this->mintedLogPaths[] = $path;

        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $path,
                    'level' => 'debug',
                ],
            ],
            'processors' => ['context' => true, 'request_id' => false, 'user_id' => false],
        ]);
    }

    private function authManager(?UserRepository $repo = null): AuthManager
    {
        return new AuthManager(
            $repo ?? $this->createMock(UserRepository::class),
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            null,
            null,
            null,
            $this->settings(),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): Request
    {
        $request = new Request();
        $request->body = $body;

        return $request;
    }

    /**
     * SITE 1 — self-service registration.
     *
     * CONSEQUENCE: register() rejects a password that is legal under the
     * shipped default once the minimum is raised.
     *
     * Mutation-verified: restoring the literal in AuthManager::register()
     * fails this test and only this test.
     */
    public function test_self_service_registration_honours_the_raised_minimum(): void
    {
        $repo = $this->createMock(UserRepository::class);
        // Must never reach creation — the policy rejects first.
        $repo->expects($this->never())->method('create');

        $manager = $this->authManager($repo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least 20 characters');

        $manager->register('newuser', 'new@example.test', self::LEGAL_UNDER_DEFAULT);
    }

    /**
     * SITE 2 — administrator creating a user.
     *
     * CONSEQUENCE: the admin create path rejects the same password.
     *
     * This is the half-effective case: before centralising, an admin could
     * create an 8-character password on a server configured to demand 20.
     *
     * Mutation-verified: restoring the literal at the create() site fails this
     * test and only this test.
     */
    public function test_admin_create_user_honours_the_raised_minimum(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->never())->method('create');

        $controller = new AdminUserController($repo, $this->authManager());

        $response = $controller->create($this->request([
            'username' => 'newuser',
            'email'    => 'new@example.test',
            'password' => self::LEGAL_UNDER_DEFAULT,
        ]));

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString(
            'at least 20 characters',
            (string) $response->body,
            'The admin create-user path must enforce the SAME effective minimum as '
            . 'self-service registration, or the setting is only half-effective.'
        );
    }

    /**
     * SITE 3 — administrator changing an existing password.
     *
     * CONSEQUENCE: the admin update path rejects the same password.
     *
     * Mutation-verified: restoring the literal at the update() site fails this
     * test and only this test.
     */
    public function test_admin_change_password_honours_the_raised_minimum(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn([
            'id'       => 'user-1',
            'username' => 'existing',
            'email'    => 'existing@example.test',
            'is_admin' => 0,
            'status'   => 'active',
        ]);
        $repo->expects($this->never())->method('update');

        $controller = new AdminUserController($repo, $this->authManager());

        $response = $controller->update(
            $this->request(['password' => self::LEGAL_UNDER_DEFAULT]),
            ['id' => 'user-1'],
        );

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('at least 20 characters', (string) $response->body);
    }

    /**
     * CONSEQUENCE: a compliant password still passes every site.
     *
     * A rejection-only suite would pass against an implementation that rejects
     * everything, so each site must also be shown to ACCEPT a legal password.
     * Here that means getting PAST the password check — the assertions
     * deliberately target the password field, not overall success, since these
     * mocked repositories do not model a full create.
     *
     * Mutation-verified: making validate() always return an error fails this.
     */
    public function test_a_compliant_password_passes_every_site(): void
    {
        $compliant = str_repeat('a', self::RAISED_MINIMUM);

        // Site 1: register() must not throw the password error. It may fail
        // later for unrelated mocked-repository reasons, which is fine.
        $manager = $this->authManager();
        try {
            $manager->register('newuser', 'new@example.test', $compliant);
        } catch (\Throwable $e) {
            self::assertStringNotContainsString(
                'Password must be at least',
                $e->getMessage(),
                'A compliant password must clear the policy check in register().'
            );
        }

        // Sites 2 and 3: no password field error.
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn([
            'id' => 'user-1', 'username' => 'existing',
            'email' => 'existing@example.test', 'is_admin' => 0, 'status' => 'active',
        ]);
        $controller = new AdminUserController($repo, $this->authManager());

        $created = $controller->create($this->request([
            'username' => 'newuser',
            'email'    => 'new@example.test',
            'password' => $compliant,
        ]));
        self::assertStringNotContainsString('Password must be at least', (string) $created->body);

        $updated = $controller->update(
            $this->request(['password' => $compliant]),
            ['id' => 'user-1'],
        );
        self::assertStringNotContainsString('Password must be at least', (string) $updated->body);
    }

    /**
     * CONSEQUENCE: the controller shares the manager's policy INSTANCE.
     *
     * `AdminUserController` gets its policy via `AuthManager::passwordPolicy()`
     * rather than its own optional constructor parameter, precisely because
     * PHP-DI skips optional parameters during autowiring — an unnamed
     * `?PasswordPolicy` param would silently arrive null and the controller
     * would enforce the floor while registration enforced the configured
     * value. This pins the sharing so a future refactor cannot reintroduce
     * that split.
     *
     * Mutation-verified: changing the controller's accessor to always return
     * `new PasswordPolicy()` fails this.
     */
    public function test_the_admin_controller_shares_the_managers_configured_policy(): void
    {
        $manager = $this->authManager();

        self::assertSame(
            self::RAISED_MINIMUM,
            $manager->passwordPolicy()->minLength(),
            'AuthManager must build its policy from the wired settings store.'
        );

        $controller = new AdminUserController($this->createMock(UserRepository::class), $manager);

        $accessor = new \ReflectionMethod($controller, 'passwordPolicy');
        $accessor->setAccessible(true);
        /** @var PasswordPolicy $policy */
        $policy = $accessor->invoke($controller);

        self::assertSame(
            $manager->passwordPolicy(),
            $policy,
            'The controller must reuse the manager\'s configured policy instance, not '
            . 'build an unconfigured one that silently falls back to the floor.'
        );
    }
}
