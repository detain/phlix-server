<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Metadata\MetadataCachePolicy;
use RuntimeException;

/**
 * W3 — MetadataCachePolicy: the `metadata.cache_ttl_hours` window ships at the
 * exact 24h/86400s the manager hardcoded before the setting existed, every
 * outage/ambiguity path degrades to that SAME window (neither a re-fetch
 * storm nor a permanent freeze), and `isFresh()` owns the ONE comparison —
 * with the clock injected so the ttl±1s boundaries are table-testable.
 */
final class MetadataCachePolicyTest extends TestCase
{
    /**
     * Format an epoch as an EXPLICIT-UTC timestamp string. `gmdate()` alone
     * would round-trip through `strtotime()` in the RUNTIME's default timezone
     * and shift the epoch by the UTC offset (the host TZ here is
     * America/New_York) — the '+0000' suffix makes every boundary test exact
     * no matter where the suite runs.
     */
    private function ts(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', $epoch) . ' +0000';
    }
    public function test_no_store_at_all_ships_the_historical_24h_window(): void
    {
        $policy = new MetadataCachePolicy();
        $this->assertSame(24, $policy->ttlHours());
        $this->assertSame(86400, $policy->ttlSeconds());
    }

    public function test_the_shipped_default_constant_is_24_hours(): void
    {
        // BEHAVIOUR-PRESERVATION pin (via constant() so the analyser cannot
        // pre-compute the assertion into a tautology).
        $this->assertSame(24, (int) constant(MetadataCachePolicy::class . '::DEFAULT_TTL_HOURS'));
    }

    public function test_the_defended_domain_is_one_hour_to_one_year(): void
    {
        $this->assertSame(1, (int) constant(MetadataCachePolicy::class . '::MIN_TTL_HOURS'));
        $this->assertSame(8760, (int) constant(MetadataCachePolicy::class . '::MAX_TTL_HOURS'));
    }

    public function test_the_setting_key_is_the_contracted_dotted_name(): void
    {
        $this->assertSame(
            'metadata.cache_ttl_hours',
            (string) constant(MetadataCachePolicy::class . '::SETTING_KEY')
        );
    }

    public function test_a_settings_store_outage_keeps_the_historical_cadence(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new RuntimeException('store down'));

        $this->assertSame(24, (new MetadataCachePolicy($settings))->ttlHours());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function recognizedValues(): array
    {
        return [
            'int 12'          => [12, 12],
            'string "6"'      => ['6', 6],
            'padded " 6 "'    => [" 6\n", 6],
            'float 12.0'      => [12.0, 12],
            'float 12.6 rounds up'   => [12.6, 13],
            'string "6.4" rounds down' => ['6.4', 6],
            'numeric string "1.5" rounds' => ['1.5', 2],
        ];
    }

    /**
     * @dataProvider recognizedValues
     */
    public function test_recognized_values_are_read_back_as_hours(mixed $stored, int $expected): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn($stored);

        $this->assertSame($expected, (new MetadataCachePolicy($settings))->ttlHours());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unrecognizedValues(): array
    {
        return [
            'null'    => [null],
            'bool'    => [true],
            'blank'   => [''],
            'garbage' => ['yesterday'],
            'array'   => [[12]],
            'INF'     => [INF],
            'NAN'     => [NAN],
        ];
    }

    /**
     * @dataProvider unrecognizedValues
     */
    public function test_unrecognized_values_fall_back_to_the_shipped_24h(mixed $stored): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn($stored);

        $this->assertSame(24, (new MetadataCachePolicy($settings))->ttlHours());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function outOfDomainValues(): array
    {
        return [
            // The clamps cover rows written by direct SQL / restored backups,
            // exactly as TokenTtlPolicy's do for auth — schema minimum/maximum
            // is the ADMIN-API-side twin of this defence.
            '0 clamps up to 1h (no re-fetch storm)'  => [0, 1],
            'negative clamps up to 1h'               => [-5, 1],
            'string "0" clamps up to 1h'             => ['0', 1],
            '100000 clamps down to 8760h'            => [100000, 8760],
            'string "999999" clamps down to 8760h'   => ['999999', 8760],
        ];
    }

    /**
     * @dataProvider outOfDomainValues
     */
    public function test_out_of_domain_values_are_clamped_into_the_defended_domain(mixed $stored, int $expected): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn($stored);

        $this->assertSame($expected, (new MetadataCachePolicy($settings))->ttlHours());
    }

    public function test_ttl_seconds_is_the_clamped_hours_times_3600(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn(2);

        $this->assertSame(7200, (new MetadataCachePolicy($settings))->ttlSeconds());
    }

    public function test_the_value_is_read_live_on_every_call_never_cached(): void
    {
        // Resident-process law (same kill-switch pin as the confidence policy):
        // a TTL change must apply to the NEXT freshness check, so the store is
        // consulted per decision.
        $read = 0;
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->willReturnCallback(function (string $key) use (&$read): int {
                $read++;

                return $read === 1 ? 48 : 6;
            });

        $policy = new MetadataCachePolicy($settings);
        $this->assertSame(48, $policy->ttlHours());
        $this->assertSame(6, $policy->ttlHours());
        $this->assertSame(2, $read);
    }

    // ------------------------------------------------------------------
    // isFresh() — the ONE comparison, clock injected
    // ------------------------------------------------------------------

    public function test_is_fresh_boundary_at_ttl_minus_one_second_with_injected_clock(): void
    {
        $now = 1_700_000_000; // fixed wall clock, store-less policy = 86400s window
        $policy = new MetadataCachePolicy();

        $this->assertTrue($policy->isFresh($this->ts($now - 86399), $now));
    }

    public function test_is_fresh_boundary_at_ttl_plus_one_second_with_injected_clock(): void
    {
        $now = 1_700_000_000;
        $policy = new MetadataCachePolicy();

        $this->assertFalse($policy->isFresh($this->ts($now - 86401), $now));
    }

    public function test_exactly_ttl_seconds_old_is_NOT_fresh_strict_less_than_preserved(): void
    {
        // Byte-identity pin: the old code was `(time() - $refreshedAt) < 86400`
        // — strict. At exactly the TTL the entry is STALE. An accidental `<=`
        // here would silently extend every cache entry's life by one tick.
        $now = 1_700_000_000;
        $policy = new MetadataCachePolicy();

        $this->assertFalse($policy->isFresh($this->ts($now - 86400), $now));
    }

    public function test_future_timestamp_counts_fresh_the_historical_leniency_is_preserved(): void
    {
        // The old `< 86400` comparison had NO lower bound, so clock skew into
        // the future read as fresh. Preserved byte-for-byte; changing it would
        // be a behavior change smuggled in as a refactor.
        $now = 1_700_000_000;
        $policy = new MetadataCachePolicy();

        $this->assertTrue($policy->isFresh($this->ts($now + 3600), $now));
    }

    public function test_override_changes_the_boundary_live(): void
    {
        $now = 1_700_000_000;
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn(2); // 2h = 7200s
        $policy = new MetadataCachePolicy($settings);

        $this->assertTrue($policy->isFresh($this->ts($now - 7199), $now));
        $this->assertFalse($policy->isFresh($this->ts($now - 7201), $now));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function nonFreshTimestamps(): array
    {
        return [
            'null (never refreshed)' => [null],
            'empty string'           => [''],
            'garbage'                => ['not-a-date'],
        ];
    }

    /**
     * @dataProvider nonFreshTimestamps
     */
    public function test_absent_or_unparseable_timestamps_are_never_fresh(?string $raw): void
    {
        // Same answers the pre-policy hasRecentMetadata() gave for these rows.
        $this->assertFalse((new MetadataCachePolicy())->isFresh($raw, 1_700_000_000));
    }
}
