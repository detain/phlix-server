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
}
