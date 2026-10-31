<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Boot-time DB-credential law of `config/database.php` (minors M-SERVER-38,
 * W-min107 part 3): production-tier boots with no DB_PASSWORD get exactly one
 * loud E_USER_WARNING, and the opt-in PHLIX_REQUIRE_DB_CREDENTIALS knob turns
 * missing-OR-empty credentials into a hard boot refusal. Dev/test tiers stay
 * silent — the silent empty default remains a deliberate LOCAL choice.
 *
 * The suite drives the guard by re-including the config file with controlled
 * env and resetting the once-per-process latch
 * ($GLOBALS['__phlix_db_credentials_guard']) — the documented test seam in
 * the config file's own comment.
 */
final class DatabaseConfigCredentialGuardTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../config/database.php';

    /** @var array<string, string|false> env values captured before the test */
    private array $envBackup = [];

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
        unset($GLOBALS['__phlix_db_credentials_guard']);
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
     * Include the config with a fresh latch and capture any user warning.
     *
     * @return array{0: array<string,mixed>|null, 1: list<string>, 2: string|null}
     *               [config-or-null, warnings, thrown-message-or-null]
     */
    private function boot(): array
    {
        unset($GLOBALS['__phlix_db_credentials_guard']);
        $warnings = [];
        $thrown = null;
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);
        try {
            $config = include self::CONFIG;
        } catch (RuntimeException $e) {
            $thrown = $e->getMessage();
            $config = null;
        } finally {
            restore_error_handler();
        }

        return [$config, $warnings, $thrown];
    }

    public function test_production_with_unset_password_warns_once_and_still_boots(): void
    {
        $this->env('PHLIX_ENV', 'production');
        $this->env('DB_PASSWORD', null);
        $this->env('PHLIX_REQUIRE_DB_CREDENTIALS', null);

        [$config, $warnings, $thrown] = $this->boot();

        self::assertIsArray($config);
        self::assertNull($thrown);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('DB_PASSWORD is unset', $warnings[0]);
        self::assertStringContainsString('PHLIX_REQUIRE_DB_CREDENTIALS', $warnings[0]);
        self::assertSame('', $config['connections']['mysql']['password']);

        // The latch: a second include in the same process stays quiet (direct
        // runtime consumers re-include this file; the warning is boot-time).
        $again = include self::CONFIG;
        self::assertIsArray($again);
    }

    public function test_production_with_password_set_is_silent(): void
    {
        $this->env('PHLIX_ENV', 'production');
        $this->env('DB_PASSWORD', 's3cret');
        $this->env('PHLIX_REQUIRE_DB_CREDENTIALS', null);

        [$config, $warnings, $thrown] = $this->boot();

        self::assertIsArray($config);
        self::assertSame([], $warnings);
        self::assertNull($thrown);
        self::assertSame('s3cret', $config['connections']['mysql']['password']);
    }

    public function test_dev_tier_without_password_stays_silent(): void
    {
        $this->env('PHLIX_ENV', 'development');
        $this->env('DB_PASSWORD', null);
        $this->env('PHLIX_REQUIRE_DB_CREDENTIALS', null);

        [$config, $warnings, $thrown] = $this->boot();

        self::assertIsArray($config);
        self::assertSame([], $warnings);
        self::assertNull($thrown);
    }

    public function test_untyped_env_tier_without_password_stays_silent(): void
    {
        // PHLIX_ENV completely unset (manual `php start.php` dev boot).
        $this->env('PHLIX_ENV', null);
        $this->env('DB_PASSWORD', null);
        $this->env('PHLIX_REQUIRE_DB_CREDENTIALS', null);

        [$config, $warnings, $thrown] = $this->boot();

        self::assertIsArray($config);
        self::assertSame([], $warnings);
        self::assertNull($thrown);
    }

    public function test_production_strict_knob_refuses_missing_password(): void
    {
        $this->env('PHLIX_ENV', 'production');
        $this->env('DB_PASSWORD', null);
        $this->env('PHLIX_REQUIRE_DB_CREDENTIALS', '1');

        [, $warnings, $thrown] = $this->boot();

        self::assertNotNull($thrown, 'the opt-in gate must refuse the boot');
        self::assertStringContainsString('PHLIX_REQUIRE_DB_CREDENTIALS', $thrown ?? '');
        self::assertSame([], $warnings, 'a refused boot never also warns');
    }

    public function test_production_strict_knob_refuses_explicitly_empty_password(): void
    {
        $this->env('PHLIX_ENV', 'production');
        $this->env('DB_PASSWORD', '');
        $this->env('PHLIX_REQUIRE_DB_CREDENTIALS', 'on');

        [, $warnings, $thrown] = $this->boot();

        self::assertNotNull($thrown);
        self::assertStringContainsString('unset or empty', $thrown ?? '');
    }

    public function test_strict_knob_zero_does_not_enable_the_gate(): void
    {
        // The `=== false ? '0' : ...` idiom (same law as DB_POOL_ENABLED, but
        // inverted default): an explicit '0' must not arm the refusal.
        $this->env('PHLIX_ENV', 'production');
        $this->env('DB_PASSWORD', null);
        $this->env('PHLIX_REQUIRE_DB_CREDENTIALS', '0');

        [$config, $warnings, $thrown] = $this->boot();

        self::assertIsArray($config);
        self::assertNull($thrown);
        self::assertCount(1, $warnings, 'warn arm still fires for the unset case');
    }

    public function test_strict_knob_outside_production_does_not_refuse(): void
    {
        // The guard's own scope law: production-context only, warn and gate.
        $this->env('PHLIX_ENV', 'development');
        $this->env('DB_PASSWORD', null);
        $this->env('PHLIX_REQUIRE_DB_CREDENTIALS', '1');

        [$config, $warnings, $thrown] = $this->boot();

        self::assertIsArray($config);
        self::assertSame([], $warnings);
        self::assertNull($thrown);
    }
}
