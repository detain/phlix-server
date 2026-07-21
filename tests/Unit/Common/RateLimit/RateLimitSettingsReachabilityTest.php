<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\RateLimit;

use Phlix\Common\RateLimit\RateLimitProfiles;
use Phlix\Config\EffectiveConfig;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Guard for the `server.rate_limit.*` block.
 *
 * ## What actually needed proving
 *
 * These keys are class (b) RESTART: nothing calls `getEffective()` for them.
 * They reach the limiters only if `EffectiveConfig`'s boot overlay writes them
 * into the `$appConfig` array that `AuthServicesProvider::registerRateLimiters()`
 * reads. That path is NOT obvious, and it very nearly does not work:
 *
 * `overlayAppConfig()` tries the full dotted path first, which for
 * `server.rate_limit.register.max` means `$config['server']['rate_limit']['register']['max']`.
 * `config/server.php` HAS a top-level `'server'` key, so that lookup gets one
 * level in and then finds no `rate_limit` child — and because the overlay
 * refuses to create keys, it silently no-ops. The value only lands because of
 * the SECOND candidate, which strips the leading `server.` segment and resolves
 * `$config['rate_limit']['register']['max']`.
 *
 * A key whose override silently no-ops is exactly the class of fake setting
 * this program exists to prevent, and it would pass every resolvability test.
 * So this file asserts the overlay reaches the real array shape, rather than
 * assuming it.
 *
 * @covers \Phlix\Config\EffectiveConfig
 * @covers \Phlix\Common\RateLimit\RateLimitProfiles
 */
final class RateLimitSettingsReachabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        parent::tearDown();
    }

    /**
     * The REAL boot config. Deliberately the shipped `config/server.php` rather
     * than a synthetic array: the whole question is whether the overlay reaches
     * the shape that file actually builds, and a hand-written stand-in could
     * agree with the test while disagreeing with production.
     *
     * @return array<string, mixed>
     */
    private function bootConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = include \dirname(__DIR__, 4) . '/config/server.php';

        // Preconditions this test depends on. If either changes, the overlay
        // analysis below is stale and must be redone, not patched.
        self::assertArrayHasKey('rate_limit', $config, 'config/server.php must expose a top-level rate_limit block');
        self::assertArrayHasKey('server', $config, 'the top-level `server` key is what makes the full-dotted candidate a near-miss');

        return $config;
    }

    /**
     * Settings store returning `$overrides` (dotted key => int value).
     *
     * @param array<string, int> $overrides
     */
    private function seed(array $overrides): void
    {
        $rows = [];
        foreach ($overrides as $key => $value) {
            $rows[$key] = [(string) $value, 'int'];
        }

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param mixed $query
             * @param mixed $params
             *
             * @return list<array<string, string>>
             */
            static function ($query = '', $params = null) use ($rows): array {
                $sql = is_string($query) ? $query : '';

                if (str_contains($sql, 'SELECT setting_key,')) {
                    $out = [];
                    foreach ($rows as $key => [$value, $type]) {
                        $out[] = [
                            'setting_key' => $key,
                            'setting_value' => $value,
                            'value_type' => $type,
                        ];
                    }

                    return $out;
                }

                if (str_contains($sql, 'SELECT setting_value,')) {
                    $key = is_array($params) && isset($params[0]) && is_string($params[0]) ? $params[0] : '';

                    return isset($rows[$key])
                        ? [['setting_value' => $rows[$key][0], 'value_type' => $rows[$key][1]]]
                        : [];
                }

                return [];
            }
        );

        EffectiveConfig::bootstrap($db);
    }

    public function test_an_override_lands_on_the_array_the_provider_reads(): void
    {
        $this->seed([
            'server.rate_limit.register.max' => 42,
            'server.rate_limit.register.window' => 900,
        ]);

        $overlaid = EffectiveConfig::overlayAppConfig($this->bootConfig());

        $this->assertIsArray($overlaid['rate_limit']);
        $this->assertSame(42, $overlaid['rate_limit']['register']['max']);
        $this->assertSame(900, $overlaid['rate_limit']['register']['window']);
    }

    public function test_the_overlay_does_not_invent_a_nested_server_rate_limit_block(): void
    {
        // The near-miss candidate. If this ever started creating keys, an
        // override would land somewhere nothing reads and the setting would
        // become a silent no-op while still appearing to save.
        $this->seed(['server.rate_limit.register.max' => 42]);

        $overlaid = EffectiveConfig::overlayAppConfig($this->bootConfig());

        $this->assertIsArray($overlaid['server']);
        $this->assertArrayNotHasKey('rate_limit', $overlaid['server']);
    }

    public function test_every_shipped_surface_is_overridable(): void
    {
        // Guards against exposing a surface in the schema whose key shape does
        // not match what config/server.php actually builds.
        $overrides = [];
        $expected = [];
        $i = 0;
        foreach (RateLimitProfiles::defaults() as $spec) {
            $max = 100 + $i;
            $window = 200 + $i;
            $overrides['server.rate_limit.' . $spec['key'] . '.max'] = $max;
            $overrides['server.rate_limit.' . $spec['key'] . '.window'] = $window;
            $expected[$spec['key']] = ['max' => $max, 'window' => $window];
            $i++;
        }

        $this->seed($overrides);
        $overlaid = EffectiveConfig::overlayAppConfig($this->bootConfig());

        $this->assertIsArray($overlaid['rate_limit']);
        foreach ($expected as $surface => $pair) {
            $this->assertSame($pair, $overlaid['rate_limit'][$surface], $surface . ' must be overridable');
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // the lock-out fail-safe
    // ─────────────────────────────────────────────────────────────────

    public function test_a_zero_max_is_clamped_up_so_a_surface_cannot_be_bricked(): void
    {
        // max=0 would reject EVERY request to the surface. On `refresh` that
        // signs out the entire install as access tokens expire.
        $this->assertSame(RateLimitProfiles::MIN_MAX, RateLimitProfiles::clampMax(0));
        $this->assertSame(RateLimitProfiles::MIN_MAX, RateLimitProfiles::clampMax(-5));
    }

    public function test_a_zero_window_is_clamped_up(): void
    {
        // A 0-second window puts every request in a fresh bucket, silently
        // disabling the limiter while the admin UI still displays a limit.
        $this->assertSame(RateLimitProfiles::MIN_WINDOW, RateLimitProfiles::clampWindow(0));
    }

    public function test_absurd_values_are_clamped_down(): void
    {
        $this->assertSame(RateLimitProfiles::MAX_MAX, RateLimitProfiles::clampMax(PHP_INT_MAX));
        $this->assertSame(RateLimitProfiles::MAX_WINDOW, RateLimitProfiles::clampWindow(PHP_INT_MAX));
    }

    public function test_in_range_values_pass_through_untouched(): void
    {
        $this->assertSame(42, RateLimitProfiles::clampMax(42));
        $this->assertSame(900, RateLimitProfiles::clampWindow(900));
    }

    public function test_every_shipped_default_survives_its_own_clamps(): void
    {
        // If a shipped default fell outside its bounds, the "no override" and
        // "override set to the default" paths would disagree.
        foreach (RateLimitProfiles::defaults() as $spec) {
            $this->assertSame(
                $spec['max'],
                RateLimitProfiles::clampMax($spec['max']),
                $spec['key'] . ' max default must sit inside its clamps'
            );
            $this->assertSame(
                $spec['window'],
                RateLimitProfiles::clampWindow($spec['window']),
                $spec['key'] . ' window default must sit inside its clamps'
            );
        }
    }
}
