<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\CookiePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SH-033: the insecure-cookie opt-in (PHLIX_COOKIE_INSECURE env or its stored
 * twin auth.cookie.insecure) is a DEV affordance; under PHLIX_ENV=production
 * it now speaks up. Exactly one loud E_USER_WARNING per process — the same
 * once-per-process latch idiom as the boot-time DB-credential guard in
 * config/database.php — naming the risk (auth cookies without Secure,
 * readable on plain HTTP) and the remedy (terminate TLS, turn the opt-in off).
 *
 * The opt-out still works (this is a warning, not a hard gate — same posture
 * as the DB-credential warning): dev/unset tiers stay silent, healthy
 * production posture (flag off) stays silent, and a degraded store stays
 * silent because nothing is insecure.
 */
final class CookiePolicyProductionInsecureWarnTest extends TestCase
{
    /** @var array<string, string|false> env values captured before the test */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The documented test seam: the latch lives in $GLOBALS so a suite can
        // re-arm the once-per-process warning per test (config/database.php idiom).
        unset($GLOBALS['__phlix_cookie_insecure_production_warn']);
        $this->env('PHLIX_COOKIE_INSECURE', null);
        $this->env('PHLIX_ENV', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->envBackup = [];
        unset($GLOBALS['__phlix_cookie_insecure_production_warn']);
        parent::tearDown();
    }

    private function env(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = getenv($name);
        }
        if ($value === null) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }

    /**
     * Call insecureOverride() N times, capturing any E_USER_WARNING it raises.
     *
     * @return array{0: bool, 1: list<string>} [last return value, warnings]
     */
    private function probe(CookiePolicy $policy, int $times = 1): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);
        try {
            $result = false;
            for ($i = 0; $i < $times; $i++) {
                $result = $policy->insecureOverride();
            }
        } finally {
            restore_error_handler();
        }

        return [$result, $warnings];
    }

    public function test_production_with_env_flag_warns_once_across_repeated_reads(): void
    {
        $this->env('PHLIX_ENV', 'production');
        $this->env('PHLIX_COOKIE_INSECURE', '1');

        [$result, $warnings] = $this->probe(new CookiePolicy(), 2);

        // The opt-out stays honored — this warns, it does not block.
        self::assertTrue($result);
        self::assertCount(1, $warnings, 'the latch must fire exactly once per process');
        self::assertStringContainsString('PHLIX_COOKIE_INSECURE', $warnings[0]);
        self::assertStringContainsString('production', $warnings[0]);
        self::assertStringContainsString('Secure', $warnings[0]);
    }

    public function test_production_with_stored_setting_warns(): void
    {
        $this->env('PHLIX_ENV', 'production');

        $policy = new CookiePolicy($this->store([
            CookiePolicy::COOKIE_INSECURE_KEY => true,
        ]));

        [$result, $warnings] = $this->probe($policy);

        self::assertTrue($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('auth.cookie.insecure', $warnings[0]);
    }

    public function test_dev_tier_with_env_flag_stays_silent(): void
    {
        $this->env('PHLIX_ENV', 'development');
        $this->env('PHLIX_COOKIE_INSECURE', '1');

        [$result, $warnings] = $this->probe(new CookiePolicy());

        self::assertTrue($result);
        self::assertSame([], $warnings);
    }

    public function test_untyped_env_tier_with_env_flag_stays_silent(): void
    {
        // PHLIX_ENV completely unset (manual `php start.php` dev boot) —
        // the same exact-'production' scope law as the DB-credential guard.
        $this->env('PHLIX_COOKIE_INSECURE', '1');

        [$result, $warnings] = $this->probe(new CookiePolicy());

        self::assertTrue($result);
        self::assertSame([], $warnings);
    }

    public function test_production_without_any_opt_in_stays_silent(): void
    {
        $this->env('PHLIX_ENV', 'production');

        [$result, $warnings] = $this->probe(new CookiePolicy($this->store([
            CookiePolicy::COOKIE_INSECURE_KEY => false,
        ])));

        self::assertFalse($result);
        self::assertSame([], $warnings);
    }

    public function test_production_with_throwing_store_stays_silent(): void
    {
        // Degraded store = shipped secure posture = nothing to warn about.
        $this->env('PHLIX_ENV', 'production');

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new RuntimeException('db down'));

        [$result, $warnings] = $this->probe(new CookiePolicy($settings));

        self::assertFalse($result);
        self::assertSame([], $warnings);
    }

    /** @param array<string, mixed> $values */
    private function store(array $values): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            static fn (string $key): mixed => $values[$key] ?? null
        );

        return $settings;
    }
}
