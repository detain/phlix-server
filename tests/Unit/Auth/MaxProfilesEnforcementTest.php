<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Controllers\Admin\AdminProfileController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * The "half-effective setting" guard for `auth.max_profiles`.
 *
 * ## Why this file exists
 *
 * There are TWO places that cap profile creation, and the obvious one is not
 * the one that fires. `AdminProfileController::createForUser()` counts existing
 * profiles and returns 400 BEFORE `UserProfileManager::create()` runs, so
 * `create()`'s own guard is unreachable through the only route that creates
 * profiles. Wiring `create()` alone — the natural choice, since it owns the
 * constant — would have produced a setting that passes a unit test of the
 * manager and still leaves the admin API pinned at 5.
 *
 * That is the same shape as the `auth.access_ttl` drift and the
 * `auth.password.min_length` triplicate: the failure is not "nothing is wired",
 * it is "the wired path is not the path anyone takes".
 *
 * Mutation-verified: restoring `UserProfileManager::MAX_PROFILES_PER_USER` at
 * either site turns exactly that site's test red.
 *
 */
final class MaxProfilesEnforcementTest extends TestCase
{
    /**
     * Deliberately differs from the shipped 5 in BOTH directions across the
     * tests below, so a site left on the constant produces a visibly wrong
     * answer rather than coincidentally agreeing.
     */
    private const RAISED_CAP = 12;

    private const LOWERED_CAP = 2;

    private function settings(int $cap): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with(UserProfileManager::MAX_PROFILES_SETTING_KEY)
            ->willReturn($cap);

        return $settings;
    }

    /**
     * A manager whose profile-count query returns `$existing` rows.
     */
    private function manager(int $cap, int $existing = 0): UserProfileManager
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @return array<int, array<string, mixed>> */
            static function (string $sql) use ($existing): array {
                if (stripos($sql, 'COUNT') !== false) {
                    return [['count' => $existing]];
                }

                // findByUserId() and friends: return `$existing` opaque rows.
                return array_fill(0, $existing, ['id' => 'p', 'user_id' => 'u']);
            }
        );

        return new UserProfileManager($db, $this->settings($cap));
    }

    /**
     * A repository that reports the target user exists, so `createForUser()`
     * gets past its 404 guard and reaches the profile-cap pre-check.
     */
    private function existingUser(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn(['id' => 'user-1', 'username' => 'u']);

        return $repo;
    }

    // ─────────────────────────────────────────────────────────────────
    // the policy method itself
    // ─────────────────────────────────────────────────────────────────

    public function test_max_profiles_honours_the_override(): void
    {
        $this->assertSame(self::RAISED_CAP, $this->manager(self::RAISED_CAP)->maxProfiles());
    }

    public function test_max_profiles_without_a_store_is_the_shipped_cap(): void
    {
        $manager = new UserProfileManager($this->createMock(Connection::class));

        $this->assertSame(UserProfileManager::MAX_PROFILES_PER_USER, $manager->maxProfiles());
    }

    public function test_a_zero_cap_is_clamped_up_so_profile_creation_stays_possible(): void
    {
        // Without the floor this would make every account unable to create even
        // its first profile — a settings field bricking the feature it configures.
        $this->assertSame(
            UserProfileManager::MIN_MAX_PROFILES,
            $this->manager(0)->maxProfiles()
        );
    }

    public function test_an_absurd_cap_is_clamped_down(): void
    {
        $this->assertSame(
            UserProfileManager::MAX_MAX_PROFILES,
            $this->manager(100000)->maxProfiles()
        );
    }

    public function test_a_throwing_store_falls_back_to_the_shipped_cap(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db gone'));

        $manager = new UserProfileManager($this->createMock(Connection::class), $settings);

        $this->assertSame(UserProfileManager::MAX_PROFILES_PER_USER, $manager->maxProfiles());
    }

    // ─────────────────────────────────────────────────────────────────
    // site 1 — UserProfileManager::create()
    // ─────────────────────────────────────────────────────────────────

    public function test_create_rejects_once_the_overridden_cap_is_reached(): void
    {
        // 2 existing profiles, cap lowered to 2 — legal under the shipped 5,
        // so a site still reading the constant would ALLOW this.
        $manager = $this->manager(self::LOWERED_CAP, self::LOWERED_CAP);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum number of profiles (2) reached');

        $manager->create('user-1', ['name' => 'Extra']);
    }

    public function test_create_allows_a_count_the_shipped_cap_would_have_refused(): void
    {
        // 6 existing profiles — over the shipped 5 — but the cap is raised to
        // 12, so this must NOT throw. A site still reading the constant refuses.
        $manager = $this->manager(self::RAISED_CAP, 6);

        try {
            $manager->create('user-1', ['name' => 'Seventh']);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString(
                'Maximum number of profiles',
                $e->getMessage(),
                'the raised cap must be honoured; failing on the profile limit means the constant is still in use'
            );
        }

        // Reaching here without a profile-limit rejection is the assertion.
        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────────
    // site 2 — the admin controller pre-check (the one that actually fires)
    // ─────────────────────────────────────────────────────────────────

    public function test_admin_create_pre_check_uses_the_overridden_cap(): void
    {
        // 2 existing profiles against a lowered cap of 2. Under the shipped 5
        // this request would sail past the pre-check, so a controller still
        // reading the constant does NOT return 400 here.
        $controller = new AdminProfileController(
            $this->manager(self::LOWERED_CAP, self::LOWERED_CAP),
            $this->existingUser(),
        );

        $request = new Request();
        $request->method = 'POST';
        $request->body = ['name' => 'Extra'];

        $response = $controller->createForUser($request, ['userId' => 'user-1']);

        $this->assertSame(400, $response->statusCode);
        $this->assertStringContainsString('Maximum profiles reached', $response->body);
    }

    public function test_admin_create_pre_check_permits_counts_above_the_shipped_cap(): void
    {
        // 6 existing profiles, cap raised to 12. A controller still reading
        // MAX_PROFILES_PER_USER returns 400 here; the wired one must not.
        $controller = new AdminProfileController($this->manager(self::RAISED_CAP, 6), $this->existingUser());

        $request = new Request();
        $request->method = 'POST';
        $request->body = ['name' => 'Seventh'];

        $response = $controller->createForUser($request, ['userId' => 'user-1']);

        $this->assertNotSame(
            400,
            $response->statusCode,
            'the raised cap must be honoured by the pre-check, which is the guard an operator actually hits'
        );
    }
}
