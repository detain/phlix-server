<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Admin\SettingsRepository;
use Phlix\Server\Http\Middleware\SecurityHeadersPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * W4 — decision table for the two configurable security-header values.
 *
 * Degradation direction is the pin that matters: every failure mode must
 * return the SHIPPED (strict, pre-key) constant, never a weaker value — a
 * settings outage must not silently downgrade HSTS or framing posture.
 */
final class SecurityHeadersPolicyTest extends TestCase
{
    public function test_null_store_emits_the_shipped_pair(): void
    {
        $policy = new SecurityHeadersPolicy();

        self::assertSame(31536000, $policy->hstsMaxAgeSeconds());
        self::assertSame('SAMEORIGIN', $policy->frameOptions());
    }

    public function test_unreadable_store_degrades_to_shipped_not_to_weak(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db down'));
        $policy = new SecurityHeadersPolicy($settings);

        self::assertSame(31536000, $policy->hstsMaxAgeSeconds());
        self::assertSame('SAMEORIGIN', $policy->frameOptions());
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function maxAgeProvider(): array
    {
        return [
            'int in range' => [60, 60],
            'zero honoured (clear-the-pin)' => [0, 0],
            'negative clamps to zero, not to default' => [-5, 0],
            'above a year clamps to a year' => [99999999, 31536000],
            'exact bound' => [31536000, 31536000],
            'float truncates toward zero' => [86400.9, 86400],
            'numeric string' => ['7200', 7200],
            'padded numeric string' => ['  7200  ', 7200],
            'scientific notation clamps high' => ['1e99', 31536000],
            'bool true is garbage -> shipped' => [true, 31536000],
            'null -> shipped' => [null, 31536000],
            'text -> shipped' => ['forever', 31536000],
            'array -> shipped' => [[1], 31536000],
            'NAN -> shipped (never cast to a bogus int)' => [NAN, 31536000],
            'INF clamps to the bound' => [INF, 31536000],
        ];
    }

    #[DataProvider('maxAgeProvider')]
    public function test_hsts_max_age_coercion_table(mixed $stored, int $expected): void
    {
        $policy = $this->policyWith(SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE, $stored);

        self::assertSame($expected, $policy->hstsMaxAgeSeconds());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function frameOptionsProvider(): array
    {
        return [
            'exact DENY' => ['DENY', 'DENY'],
            'exact SAMEORIGIN' => ['SAMEORIGIN', 'SAMEORIGIN'],
            'sentinel NONE' => ['NONE', 'NONE'],
            'lowercase normalised' => ['deny', 'DENY'],
            'padded normalised' => [" sameorigin\n", 'SAMEORIGIN'],
            'arbitrary string never reaches the header' => ['<script>', 'SAMEORIGIN'],
            'empty string -> shipped' => ['', 'SAMEORIGIN'],
            'bool -> shipped' => [false, 'SAMEORIGIN'],
            'null -> shipped' => [null, 'SAMEORIGIN'],
        ];
    }

    #[DataProvider('frameOptionsProvider')]
    public function test_frame_options_closed_set_only(mixed $stored, string $expected): void
    {
        $policy = $this->policyWith(SecurityHeadersPolicy::SETTING_KEY_FRAME_OPTIONS, $stored);

        self::assertSame($expected, $policy->frameOptions());
        self::assertContains($policy->frameOptions(), SecurityHeadersPolicy::FRAME_OPTIONS);
    }

    public function test_the_two_keys_resolve_against_config_security_php(): void
    {
        // Schema key -> config/security.php flat members (net-new file). If a
        // constant drifts off that shape the settings layer silently answers
        // the default forever.
        self::assertSame('security.hsts_max_age_seconds', SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE);
        self::assertSame('security.frame_options', SecurityHeadersPolicy::SETTING_KEY_FRAME_OPTIONS);

        $config = require dirname(__DIR__, 5) . '/config/security.php';

        self::assertIsArray($config);
        self::assertSame(SecurityHeadersPolicy::DEFAULT_HSTS_MAX_AGE, $config['hsts_max_age_seconds']);
        self::assertSame(SecurityHeadersPolicy::DEFAULT_FRAME_OPTIONS, $config['frame_options']);
        self::assertContains($config['frame_options'], SecurityHeadersPolicy::FRAME_OPTIONS);
    }

    private function policyWith(string $key, mixed $value): SecurityHeadersPolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings
            ->method('getEffective')
            ->willReturnCallback(static fn (string $queried): mixed => $queried === $key ? $value : null);

        return new SecurityHeadersPolicy($settings);
    }

    // ---- C3 (playback-lag cure, INV-A P6): the one-second in-process memo ----

    /**
     * Counting store double: every getEffective() increments $calls keyed by
     * the dotted settings key (by-reference array counter — typed clean for
     * phpstan, unlike an unparameterised ArrayObject).
     *
     * @param array<string, mixed>  $values
     * @param array<string, int>    $calls
     * @param callable():int        $clock
     */
    private function countingPolicy(array $values, array &$calls, callable $clock): SecurityHeadersPolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings
            ->method('getEffective')
            ->willReturnCallback(static function (string $key) use ($values, &$calls): mixed {
                $calls[$key] = (int) ($calls[$key] ?? 0) + 1;

                return $values[$key] ?? null;
            });

        return new SecurityHeadersPolicy($settings, $clock);
    }

    public function test_two_reads_within_one_second_hit_the_store_once(): void
    {
        // INV-A P6 defect: BOTH header values were a live DB point-SELECT per
        // response (SettingsRepository::getEffective covers config-cache for
        // FILE defaults only — SettingsRepository.php:112-135) on the hot
        // dispatch path. Cure: a one-second in-process memo per key, the
        // phlix-hub MaintenanceGate idiom. Red at parent: each call to
        // hstsMaxAgeSeconds() re-read the store, so the counter reached 3.
        $values = [SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE => 3600];
        $calls = [];
        $policy = $this->countingPolicy($values, $calls, static fn (): int => 100);

        for ($round = 0; $round < 3; $round++) {
            self::assertSame(3600, $policy->hstsMaxAgeSeconds());
        }

        self::assertSame([SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE => 1], $calls);
    }

    public function test_a_change_is_picked_up_once_the_one_second_bound_passes(): void
    {
        // The bound is EXACTLY one second: written at t, the memo serves for
        // t itself, and the first read at t+1 goes back to the store — an
        // admin PUT lands on a response within one second of the tick
        // boundary, never later (per-decision-bounded staleness BY DESIGN,
        // documented in the class docblock; resident-worker precedent
        // de573d6e).
        $tick = 100;
        $clock = static function () use (&$tick): int {
            return $tick;
        };
        $value = 3600;
        $calls = [];
        $settings = $this->createMock(SettingsRepository::class);
        $settings
            ->method('getEffective')
            ->willReturnCallback(static function (string $key) use (&$value, &$calls): mixed {
                $calls[$key] = (int) ($calls[$key] ?? 0) + 1;

                return $key === SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE ? $value : null;
            });
        $policy = new SecurityHeadersPolicy($settings, $clock);

        for ($round = 0; $round < 3; $round++) {
            self::assertSame(3600, $policy->hstsMaxAgeSeconds());
            if ($round === 1) {
                // Mid-window store change (the admin PUT shape): inside the
                // same second the memo serves the RESOLVED old value — the
                // one bound is the documented promise, not a bug.
                $value = 7200;
            }
        }

        self::assertSame([SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE => 1], $calls);

        // t + MEMO_TTL_SECONDS: strict-`<` expiry means the first read at
        // exactly t+1 goes back to the store and the new value reaches the
        // wire.
        $tick = 101;
        self::assertSame(7200, $policy->hstsMaxAgeSeconds());
        self::assertSame(2, $calls[SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE]);
    }

    public function test_each_key_is_memoised_independently_and_the_wire_pair_is_unchanged(): void
    {
        // Two memos, no cross-talk: each key pays at most one store read per
        // second, and a memoised read resolves through the SAME coercion as a
        // cold one (zero mutation of wire behavior — the header pair stays
        // byte-identical to the pre-memo path).
        $values = [
            SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE => 60,
            SecurityHeadersPolicy::SETTING_KEY_FRAME_OPTIONS => 'deny',
        ];
        $calls = [];
        $policy = $this->countingPolicy($values, $calls, static fn (): int => 500);

        for ($round = 0; $round < 5; $round++) {
            self::assertSame(60, $policy->hstsMaxAgeSeconds());
            self::assertSame('DENY', $policy->frameOptions());
        }

        self::assertSame([
            SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE => 1,
            SecurityHeadersPolicy::SETTING_KEY_FRAME_OPTIONS => 1,
        ], $calls);
    }

    public function test_the_memo_bound_is_the_one_second_constant(): void
    {
        // The bound is the law operators are promised in the class docblock;
        // rotating it must be a deliberate edit through this pin.
        $reflection = new \ReflectionClass(SecurityHeadersPolicy::class);
        $bound = $reflection->getConstant('MEMO_TTL_SECONDS');

        self::assertSame(1, $bound);
    }
}
