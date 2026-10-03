<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Admin\SettingsRepository;
use Phlix\Server\Http\Middleware\SecurityHeaders;
use Phlix\Server\Http\Middleware\SecurityHeadersPolicy;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * W4 — the END-TO-END header emission: policy values → wire-visible headers.
 *
 * SecurityHeadersPolicyTest pins the decision table; this file pins that
 * `decorate()` actually CONSUMES the policy — the pre-W4 class emitted both
 * values from literals, so a policy threaded but ignored would pass every
 * other test estate-wide. Includes the legacy caller-wins guards and the
 * NONE-sentinel omission.
 */
final class SecurityHeadersEmissionTest extends TestCase
{
    public function test_default_construction_reproduces_the_pre_key_headers_byte_identically(): void
    {
        $headers = $this->decorate(new SecurityHeaders());

        self::assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        self::assertSame('max-age=31536000; includeSubDomains', $headers['Strict-Transport-Security']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function test_configured_values_reach_the_wire_per_request(): void
    {
        $decorator = new SecurityHeaders($this->policy([
            SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE => 3600,
            SecurityHeadersPolicy::SETTING_KEY_FRAME_OPTIONS => 'DENY',
        ]));

        // LIVE read law: a second decorate on a FRESH response reflects the
        // same values without rebuilding anything (the decorator instance is
        // memoised per worker by HttpHandler — correctness rests on live reads).
        foreach ([$decorator, $decorator] as $d) {
            $headers = $this->decorate($d);
            self::assertSame('max-age=3600; includeSubDomains', $headers['Strict-Transport-Security']);
            self::assertSame('DENY', $headers['X-Frame-Options']);
        }
    }

    public function test_zero_max_age_emits_the_clear_the_pin_signal(): void
    {
        $headers = $this->decorate($this->decoratorWith([
            SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE => 0,
        ]));

        self::assertSame('max-age=0; includeSubDomains', $headers['Strict-Transport-Security']);
    }

    public function test_none_sentinel_omits_the_legacy_frame_header_only(): void
    {
        $headers = $this->decorate($this->decoratorWith([
            SecurityHeadersPolicy::SETTING_KEY_FRAME_OPTIONS => 'NONE',
        ]));

        self::assertArrayNotHasKey('X-Frame-Options', $headers, 'NONE must omit the legacy header.');
        self::assertStringContainsString(
            "frame-ancestors 'self'",
            $headers['Content-Security-Policy'],
            'NONE governs ONLY X-Frame-Options; the always-on CSP frame-ancestors must remain.'
        );
    }

    public function test_caller_supplied_headers_still_win_over_configured_values(): void
    {
        // The hasHeader guards predate W4 and must keep beating BOTH the
        // defaults and the configured overrides (case-insensitively).
        $response = (new Response())
            ->header('x-frame-options', 'deny')
            ->header('strict-transport-security', 'max-age=7');

        $decorated = $this->decoratorWith([
            SecurityHeadersPolicy::SETTING_KEY_FRAME_OPTIONS => 'SAMEORIGIN',
            SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE => 3600,
        ])->decorate($response);

        self::assertSame('deny', $decorated->headers['x-frame-options']);
        self::assertSame('max-age=7', $decorated->headers['strict-transport-security']);
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * @param array<string, mixed> $values keyed by settings key
     */
    private function decoratorWith(array $values): SecurityHeaders
    {
        return new SecurityHeaders($this->policy($values));
    }

    /**
     * @param array<string, mixed> $values keyed by settings key
     */
    private function policy(array $values): SecurityHeadersPolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings
            ->method('getEffective')
            ->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? null);

        return new SecurityHeadersPolicy($settings);
    }

    /**
     * @return array<string, string>
     */
    private function decorate(SecurityHeaders $decorator): array
    {
        return $decorator->decorate(new Response())->headers;
    }
}
