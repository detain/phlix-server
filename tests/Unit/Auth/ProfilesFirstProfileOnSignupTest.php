<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\ProviderManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * S81 blocker #5 — signup creates a first profile AUTOMATICALLY, on BOTH
 * entry points:
 *
 *  - {@see AuthManager::register()} (local credential signup), and
 *  - the external-provider create path behind
 *    {@see UserRepository::findOrCreateByExternalId()} (OIDC/GitHub/LDAP).
 *
 * Wiring `register()` alone would leave every external-provider signup
 * profile-less, so each path is pinned here — plus the two negative arms:
 * a 'pending' registration (no session yet; approval-mode signup) creates
 * NO profile, and an EXISTING external identity logging in again creates
 * NO second profile.
 */
final class ProfilesFirstProfileOnSignupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AuthManager::resetRateLimitStore();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        AuthManager::resetRateLimitStore();
        // S439: sweep every stream path minted by silentLogger().
        foreach ($this->mintedLogPaths as $path) {
            @unlink($path);
        }
        $this->mintedLogPaths = [];
    }

    /** @var list<string> log files minted by silentLogger(), removed in tearDown(). */
    private array $mintedLogPaths = [];

    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_s81_signup_' . uniqid() . '.log';
        $this->mintedLogPaths[] = $path;

        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $path,
                    'level' => 'debug',
                ],
            ],
        ]);
    }

    private function manager(
        UserRepository $repo,
        UserProfileManager $profiles,
        ?SettingsRepository $settings = null,
    ): AuthManager {
        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,   // eventDispatcher
            null,   // db — register() falls back to the non-transactional flow
            null,   // providerManager
            null,   // statsCollector
            $settings,
            null,   // loginRateLimitStore
            $profiles,
        );
    }

    private function signupRepo(string $newUserId = 'user-1'): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        $repo->method('countUsers')->willReturn(1); // not the first user — no admin bootstrap
        $repo->method('create')->willReturn($newUserId);
        $repo->method('findById')->willReturn([
            'id' => $newUserId,
            'username' => 'alice',
            'email' => 'alice@example.com',
            'display_name' => 'alice',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);

        return $repo;
    }

    public function test_register_creates_the_first_profile_named_main(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->once())
            ->method('create')
            ->with('user-1', ['name' => AuthManager::FIRST_PROFILE_NAME]);

        $result = $this->manager($this->signupRepo(), $profiles)
            ->register('alice', 'alice@example.com', 'topsecret123');

        $this->assertSame('user-1', $result['user']['id']);
    }

    public function test_pending_registration_creates_no_profile(): void
    {
        // Approval mode: the account is pending, no tokens are issued, and no
        // profile is created — resolveProfileIdForUser() heals on first
        // profile-scoped write after approval.
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('auth.signup_mode')->willReturn('approval');

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->never())->method('create');

        $result = $this->manager($this->signupRepo(), $profiles, $settings)
            ->register('alice', 'alice@example.com', 'topsecret123');

        $this->assertSame('pending', $result['status']);
    }

    // ---- external provider (OIDC/GitHub/LDAP) --------------------------------

    /**
     * A real ProviderManager (final — cannot be doubled) over a registry
     * holding a stub 'oidc' provider that authenticates successfully but does
     * NOT know the local user id, forcing the findOrCreateByExternalId path.
     *
     * @param array{created: bool, userId: string} $external
     */
    private function providerManagerReturningExternal(array $external, UserRepository $repo): ProviderManager
    {
        $registry = new AuthProviderRegistry();
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('oidc');
        $provider->method('authenticate')->willReturn(new AuthResult(
            success: true,
            userId: null, // provider does not know the local id → find-or-create runs
            externalId: 'oidc|ext-123',
            attributes: ['provider' => 'oidc'],
        ));
        $registry->registerProvider($provider);

        return new ProviderManager($registry, $repo);
    }

    private function externalRepo(array $external): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn([
            'id' => $external['userId'],
            'username' => 'oidc:ext-123',
            'email' => 'ext@example.com',
            'display_name' => 'Ext',
            'is_admin' => 0,
            'password_hash' => null,
        ]);
        // The real 5th parameter is BY REFERENCE (the S81 created out-param).
        $repo->method('findOrCreateByExternalId')->willReturnCallback(
            static function (
                string $provider,
                string $externalId,
                ?string $email = null,
                ?string $displayName = null,
                ?bool &$created = null
            ) use ($external): string {
                $created = $external['created'];

                return $external['userId'];
            },
        );

        return $repo;
    }

    public function test_external_signup_creates_the_first_profile(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->once())
            ->method('create')
            ->with('user-ext-new', ['name' => AuthManager::FIRST_PROFILE_NAME]);

        $repo = $this->externalRepo(['created' => true, 'userId' => 'user-ext-new']);
        $providerManager = $this->providerManagerReturningExternal(
            ['created' => true, 'userId' => 'user-ext-new'],
            $repo,
        );

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            null,
            $providerManager,
            null,
            null,
            null,
            $profiles,
        );

        $result = $manager->loginWithProvider('oidc:ext-123', ['id_token' => 'x'], 'device-1');

        $this->assertSame('user-ext-new', $result['user']['id']);
    }

    public function test_existing_external_identity_creates_no_second_profile(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->never())->method('create');

        $repo = $this->externalRepo(['created' => false, 'userId' => 'user-old']);
        $providerManager = $this->providerManagerReturningExternal(
            ['created' => false, 'userId' => 'user-old'],
            $repo,
        );

        $manager = new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            null,
            $providerManager,
            null,
            null,
            null,
            $profiles,
        );

        $result = $manager->loginWithProvider('oidc:ext-123', ['id_token' => 'x'], 'device-1');

        $this->assertSame('user-old', $result['user']['id']);
    }
}
