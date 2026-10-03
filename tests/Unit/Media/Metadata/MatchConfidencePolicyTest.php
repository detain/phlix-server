<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Metadata\MatchConfidencePolicy;
use RuntimeException;

/**
 * W3/F3 — MatchConfidencePolicy: the `metadata.min_match_confidence` gate
 * ships at 0.0 (= blind first result, today's behaviour byte-preserved) and
 * every outage/ambiguity path degrades to that SAME default, because a
 * settings failure that started REJECTING matches would present as rescans
 * mysteriously matching nothing behind a dead admin control.
 */
final class MatchConfidencePolicyTest extends TestCase
{
    public function test_no_store_at_all_ships_the_gate_off(): void
    {
        $this->assertSame(0.0, (new MatchConfidencePolicy())->minConfidence());
    }

    public function test_the_shipped_default_constant_is_zero(): void
    {
        // The BEHAVIOUR-PRESERVATION promise is this constant; making it
        // nonzero would start rejecting first-results on every install that
        // never touched the setting. Read through constant() so the pin holds
        // at RUNTIME (the analyser pre-computes the direct form).
        $this->assertSame(0.0, (float) constant(MatchConfidencePolicy::class . '::DEFAULT_MIN_CONFIDENCE'));
    }

    public function test_the_domain_bounds_are_zero_to_one(): void
    {
        $this->assertSame(0.0, (float) constant(MatchConfidencePolicy::class . '::MIN_BOUND'));
        $this->assertSame(1.0, (float) constant(MatchConfidencePolicy::class . '::MAX_BOUND'));
    }

    public function test_a_settings_store_outage_degrades_to_gate_off(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new RuntimeException('store down'));

        $this->assertSame(0.0, (new MatchConfidencePolicy($settings))->minConfidence());
    }

    public function test_an_absent_effective_value_degrades_to_gate_off(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn(null);

        $this->assertSame(0.0, (new MatchConfidencePolicy($settings))->minConfidence());
    }

    /**
     * @return array<string, array{mixed, float}>
     */
    public static function recognizedValues(): array
    {
        return [
            'float 0.9'            => [0.9, 0.9],
            'float 0.0'            => [0.0, 0.0],
            'float 1.0'            => [1.0, 1.0],
            'int 1 (strictest)'    => [1, 1.0],
            'int 0 (gate off)'     => [0, 0.0],
            'string "0.5"'         => ['0.5', 0.5],
            'padded " 0.75 "'      => [" 0.75\n", 0.75],
            'string "1"'           => ['1', 1.0],
        ];
    }

    /**
     * @dataProvider recognizedValues
     */
    public function test_recognized_values_are_read_back(mixed $stored, float $expected): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn($stored);

        $this->assertEqualsWithDelta($expected, (new MatchConfidencePolicy($settings))->minConfidence(), 1e-12);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unrecognizedValues(): array
    {
        return [
            'bool true'   => [true],
            'bool false'  => [false],
            'blank'       => [''],
            'garbage'     => ['tight-ish'],
            'array'       => [[0.5]],
            'INF float'   => [INF],
            'NAN float'   => [NAN],
        ];
    }

    /**
     * @dataProvider unrecognizedValues
     */
    public function test_unrecognized_values_fall_back_to_the_shipped_default(mixed $stored): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn($stored);

        $this->assertSame(0.0, (new MatchConfidencePolicy($settings))->minConfidence());
    }

    /**
     * @return array<string, array{mixed, float}>
     */
    public static function outOfDomainValues(): array
    {
        return [
            // Out-of-range numbers are CLAMPED into the 0..1 score domain
            // rather than discarded — a fat-fingered 7 means "as strict as
            // possible", a negative means "gate off".
            'float 7.0 clamps to 1.0'  => [7.0, 1.0],
            'int 5 clamps to 1.0'      => [5, 1.0],
            'string "3" clamps to 1.0' => ['3', 1.0],
            'float -2 clamps to 0.0'   => [-2.0, 0.0],
        ];
    }

    /**
     * @dataProvider outOfDomainValues
     */
    public function test_out_of_domain_numbers_are_clamped_into_the_score_domain(mixed $stored, float $expected): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn($stored);

        $this->assertSame($expected, (new MatchConfidencePolicy($settings))->minConfidence());
    }

    public function test_the_value_is_read_live_on_every_call_never_cached(): void
    {
        // Resident-process law: an override must take effect on the very next
        // resolve WITHOUT a restart, so the store is consulted per decision —
        // pinning the call count kills any future "cache it at construction"
        // refactor that would silently freeze admin changes in a worker.
        $read = 0;
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->willReturnCallback(function (string $key) use (&$read): float {
                $read++;

                return $read === 1 ? 0.4 : 0.8;
            });

        $policy = new MatchConfidencePolicy($settings);
        $this->assertSame(0.4, $policy->minConfidence());
        $this->assertSame(0.8, $policy->minConfidence());
        $this->assertSame(2, $read);
    }

    public function test_the_policy_reads_its_own_key_only(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->expects($this->once())
            ->method('getEffective')
            ->with(MatchConfidencePolicy::SETTING_KEY)
            ->willReturn(0.6);

        $this->assertSame(0.6, (new MatchConfidencePolicy($settings))->minConfidence());
    }

    public function test_the_setting_key_is_the_contracted_dotted_name(): void
    {
        // The key name is the wire contract with the settings store (and, once
        // declared, with phlix-shared's server-settings.schema.json). Drift
        // here silently detaches the reader from any override row.
        $this->assertSame(
            'metadata.min_match_confidence',
            (string) constant(MatchConfidencePolicy::class . '::SETTING_KEY')
        );
    }

    public function test_the_config_file_default_matches_the_shipped_constant(): void
    {
        // config/metadata.php is the store's fallback default; it MUST equal
        // the in-code constant or the two "defaults" disagree depending on
        // which read path answers (schema mirror precedent: the file's own
        // docblock demands byte-for-byte mirroring).
        $config = require __DIR__ . '/../../../../config/metadata.php';
        $this->assertIsArray($config);
        $this->assertArrayHasKey('min_match_confidence', $config);
        $this->assertSame(
            MatchConfidencePolicy::DEFAULT_MIN_CONFIDENCE,
            (float) $config['min_match_confidence']
        );
    }
}
