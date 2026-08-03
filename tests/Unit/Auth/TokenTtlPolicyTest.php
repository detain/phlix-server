<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\TokenTtlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the policy object behind `auth.access_ttl` / `auth.refresh_ttl`.
 *
 * {@see TokenTtlEnforcementTest} is the companion file proving every real call
 * site consults this. This one proves the object itself is safe in isolation:
 * the clamps hold, a hostile or malformed `server_settings` row degrades to the
 * shipped default rather than to an unbounded lifetime, and the
 * refresh >= access invariant is enforced.
 */
final class TokenTtlPolicyTest extends TestCase
{
    /**
     * A policy whose store returns `$values` keyed by dotted setting key.
     * Keys absent from `$values` resolve to null, i.e. "no override, no
     * default" — the shape a missing config entry would produce.
     *
     * @param array<string, mixed> $values
     */
    private function policy(array $values): TokenTtlPolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->willReturnCallback(
                /** @return mixed */
                static fn (string $key) => $values[$key] ?? null
            );

        return new TokenTtlPolicy($settings);
    }

    // ─────────────────────────────────────────────────────────────────
    // no store wired — the historical lifetimes
    // ─────────────────────────────────────────────────────────────────

    public function test_without_a_settings_store_it_yields_the_shipped_defaults(): void
    {
        $policy = new TokenTtlPolicy();

        $this->assertSame(3600, $policy->accessTtl());
        $this->assertSame(604800, $policy->refreshTtl());
    }

    public function test_the_shipped_defaults_match_the_literals_this_class_replaced(): void
    {
        // These four numbers were previously duplicated across JwtHandler's
        // ctor, AuthServicesProvider, AuthManager::buildAuthResponse() and
        // AuthController::attachAuthCookies(). Pinning them here means a
        // change to the shipped lifetime is a deliberate edit, not a drift.
        $this->assertSame(3600, TokenTtlPolicy::DEFAULT_ACCESS_TTL);
        $this->assertSame(604800, TokenTtlPolicy::DEFAULT_REFRESH_TTL);
    }

    // ─────────────────────────────────────────────────────────────────
    // configured values pass through
    // ─────────────────────────────────────────────────────────────────

    public function test_an_in_range_override_is_honoured(): void
    {
        $policy = $this->policy([
            TokenTtlPolicy::ACCESS_TTL_KEY => 900,
            TokenTtlPolicy::REFRESH_TTL_KEY => 1209600,
        ]);

        $this->assertSame(900, $policy->accessTtl());
        $this->assertSame(1209600, $policy->refreshTtl());
    }

    public function test_a_numeric_string_override_is_coerced(): void
    {
        // `server_settings` values round-trip through JSON, and a value typed
        // into the admin UI can arrive as a string.
        $policy = $this->policy([
            TokenTtlPolicy::ACCESS_TTL_KEY => '1800',
            TokenTtlPolicy::REFRESH_TTL_KEY => '86400',
        ]);

        $this->assertSame(1800, $policy->accessTtl());
        $this->assertSame(86400, $policy->refreshTtl());
    }

    // ─────────────────────────────────────────────────────────────────
    // the clamps — both directions, unlike PasswordPolicy's one-sided floor
    // ─────────────────────────────────────────────────────────────────

    public function test_an_access_ttl_below_the_floor_is_clamped_up(): void
    {
        $policy = $this->policy([TokenTtlPolicy::ACCESS_TTL_KEY => 5]);

        $this->assertSame(TokenTtlPolicy::MIN_ACCESS_TTL, $policy->accessTtl());
    }

    public function test_an_access_ttl_above_the_ceiling_is_clamped_down(): void
    {
        // A year. The access token is a non-revocable bearer credential, so an
        // override must not be able to turn it into a permanent one.
        $policy = $this->policy([TokenTtlPolicy::ACCESS_TTL_KEY => 31536000]);

        $this->assertSame(TokenTtlPolicy::MAX_ACCESS_TTL, $policy->accessTtl());
    }

    public function test_a_refresh_ttl_below_the_floor_is_clamped_up(): void
    {
        $policy = $this->policy([TokenTtlPolicy::REFRESH_TTL_KEY => 30]);

        $this->assertSame(TokenTtlPolicy::MIN_REFRESH_TTL, $policy->refreshTtl());
    }

    public function test_a_refresh_ttl_above_the_ceiling_is_clamped_down(): void
    {
        $policy = $this->policy([TokenTtlPolicy::REFRESH_TTL_KEY => 315360000]);

        $this->assertSame(TokenTtlPolicy::MAX_REFRESH_TTL, $policy->refreshTtl());
    }

    public function test_a_negative_access_ttl_cannot_mint_an_already_expired_token(): void
    {
        // Without the floor this would produce `exp` in the past, so every
        // token would fail validation the instant it was issued — a total
        // lock-out driven from a settings field.
        $policy = $this->policy([TokenTtlPolicy::ACCESS_TTL_KEY => -1]);

        $this->assertSame(TokenTtlPolicy::MIN_ACCESS_TTL, $policy->accessTtl());
        $this->assertGreaterThan(0, $policy->accessTtl());
    }

    // ─────────────────────────────────────────────────────────────────
    // the cross-key invariant
    // ─────────────────────────────────────────────────────────────────

    public function test_refresh_ttl_is_raised_to_at_least_the_access_ttl(): void
    {
        // Both values are individually in range; only their RELATIONSHIP is
        // wrong. A refresh token expiring before the access token it renews
        // would end the session with the client still holding a valid access
        // token and no way to continue.
        $policy = $this->policy([
            TokenTtlPolicy::ACCESS_TTL_KEY => 86400,
            TokenTtlPolicy::REFRESH_TTL_KEY => 3600,
        ]);

        $this->assertSame(86400, $policy->accessTtl());
        $this->assertSame(86400, $policy->refreshTtl());
    }

    public function test_the_invariant_does_not_disturb_a_correctly_ordered_pair(): void
    {
        $policy = $this->policy([
            TokenTtlPolicy::ACCESS_TTL_KEY => 3600,
            TokenTtlPolicy::REFRESH_TTL_KEY => 7200,
        ]);

        $this->assertSame(7200, $policy->refreshTtl());
    }

    // ─────────────────────────────────────────────────────────────────
    // hostile / broken store
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonNumericOverrides(): array
    {
        return [
            'null (key absent)' => [null],
            'empty string' => [''],
            'non-numeric string' => ['forever'],
            'bool' => [true],
            'array' => [[3600]],
            'float-ish string' => ['not-a-number'],
        ];
    }

    /**
     * @dataProvider nonNumericOverrides
     */
    public function test_a_non_numeric_override_falls_back_to_the_default(mixed $value): void
    {
        $policy = $this->policy([
            TokenTtlPolicy::ACCESS_TTL_KEY => $value,
            TokenTtlPolicy::REFRESH_TTL_KEY => $value,
        ]);

        $this->assertSame(TokenTtlPolicy::DEFAULT_ACCESS_TTL, $policy->accessTtl());
        $this->assertSame(TokenTtlPolicy::DEFAULT_REFRESH_TTL, $policy->refreshTtl());
    }

    public function test_a_throwing_settings_store_falls_back_to_the_defaults(): void
    {
        // A settings-store failure must never lengthen a credential's life,
        // and must never yield 0 (which would expire tokens instantly).
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->willThrowException(new \RuntimeException('database is gone'));

        $policy = new TokenTtlPolicy($settings);

        $this->assertSame(TokenTtlPolicy::DEFAULT_ACCESS_TTL, $policy->accessTtl());
        $this->assertSame(TokenTtlPolicy::DEFAULT_REFRESH_TTL, $policy->refreshTtl());
    }

    // ─────────────────────────────────────────────────────────────────
    // deferred resolution — the path AuthServicesProvider actually uses
    // ─────────────────────────────────────────────────────────────────

    public function test_deferred_does_not_touch_the_resolver_until_a_ttl_is_read(): void
    {
        // This is the property that keeps JwtHandler resolvable without a
        // database. If the resolver ran at construction time, building the
        // container would require ConnectionPool::init() to have happened.
        $calls = 0;
        $policy = TokenTtlPolicy::deferred(function () use (&$calls): ?SettingsRepository {
            $calls++;
            return null;
        });

        $this->assertSame(0, $calls, 'constructing a deferred policy must not resolve the store');

        $policy->accessTtl();
        $this->assertSame(1, $calls);
    }

    public function test_deferred_resolves_at_most_once_across_many_reads(): void
    {
        // A resident Workerman worker mints tokens for the life of the
        // process; re-resolving per mint would be a per-login container hit.
        $calls = 0;
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->willReturnCallback(static fn (string $key): ?int => match ($key) {
                TokenTtlPolicy::ACCESS_TTL_KEY => 1200,
                default => null,
            });

        $policy = TokenTtlPolicy::deferred(function () use (&$calls, $settings): SettingsRepository {
            $calls++;
            return $settings;
        });

        $this->assertSame(1200, $policy->accessTtl());
        $this->assertSame(1200, $policy->accessTtl());
        $policy->refreshTtl();

        $this->assertSame(1, $calls);
    }

    public function test_a_throwing_deferred_resolver_degrades_to_defaults_and_is_not_retried(): void
    {
        // The container is unavailable (no DB yet). Minting must still work on
        // the shipped lifetimes rather than fataling, and a resident worker
        // must not re-attempt the failing resolve on every single login.
        $calls = 0;
        $policy = TokenTtlPolicy::deferred(function () use (&$calls): SettingsRepository {
            $calls++;
            throw new \RuntimeException('container not ready');
        });

        $this->assertSame(TokenTtlPolicy::DEFAULT_ACCESS_TTL, $policy->accessTtl());
        $this->assertSame(TokenTtlPolicy::DEFAULT_REFRESH_TTL, $policy->refreshTtl());
        $this->assertSame(1, $calls);
    }

    // ─────────────────────────────────────────────────────────────────
    // bounds sanity
    // ─────────────────────────────────────────────────────────────────

    public function test_the_shipped_defaults_sit_inside_their_own_clamps(): void
    {
        // If a default ever fell outside its bounds the "no override" path and
        // the "override set to the default" path would disagree.
        $this->assertGreaterThanOrEqual(TokenTtlPolicy::MIN_ACCESS_TTL, TokenTtlPolicy::DEFAULT_ACCESS_TTL);
        $this->assertLessThanOrEqual(TokenTtlPolicy::MAX_ACCESS_TTL, TokenTtlPolicy::DEFAULT_ACCESS_TTL);
        $this->assertGreaterThanOrEqual(TokenTtlPolicy::MIN_REFRESH_TTL, TokenTtlPolicy::DEFAULT_REFRESH_TTL);
        $this->assertLessThanOrEqual(TokenTtlPolicy::MAX_REFRESH_TTL, TokenTtlPolicy::DEFAULT_REFRESH_TTL);
        $this->assertGreaterThanOrEqual(TokenTtlPolicy::DEFAULT_ACCESS_TTL, TokenTtlPolicy::DEFAULT_REFRESH_TTL);
    }
}
