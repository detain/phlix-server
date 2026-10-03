<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery;

use Phlix\Admin\SettingsRepository;
use Phlix\Discovery\DiscoveryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * W2 — decision table for the background multicast-probe gates.
 *
 * `DiscoveryServer` consults this policy at timer registration (boot) and on
 * every tick; the gates must be pure opt-out (default true — the probes ran
 * unconditionally before the keys existed) and must degrade to ON, never to
 * "silenced by an outage". Mirrors the ArtworkDownloadPolicyTest idiom.
 */
final class DiscoveryPolicyTest extends TestCase
{
    public function test_null_store_keeps_both_probes_on_the_pre_key_behaviour(): void
    {
        $policy = new DiscoveryPolicy();

        self::assertTrue($policy->ssdpEnabled());
        self::assertTrue($policy->mdnsEnabled());
    }

    public function test_unreadable_store_degrades_to_on_not_to_silenced(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db down'));
        $policy = new DiscoveryPolicy($settings);

        self::assertTrue($policy->ssdpEnabled(), 'A settings outage must not silence the SSDP probe.');
        self::assertTrue($policy->mdnsEnabled(), 'A settings outage must not silence the mDNS probe.');
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function valueProvider(): array
    {
        return [
            'bool true' => [true, true],
            'bool false' => [false, false],
            'int 1' => [1, true],
            'int 0' => [0, false],
            'string "true"' => ['true', true],
            'string "false"' => ['false', false],
            'string "on"' => ['on', true],
            'string "off"' => ['off', false],
            'string "1"' => ['1', true],
            'string "0"' => ['0', false],
            'empty string is an explicit off' => ['', false],
            'unparseable falls back to default-on' => ['maybe', true],
            'null falls back to default-on' => [null, true],
            'array falls back to default-on' => [['x'], true],
        ];
    }

    #[DataProvider('valueProvider')]
    public function test_persisted_values_coerce_to_the_documented_verdict(mixed $stored, bool $expected): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings
            ->method('getEffective')
            ->willReturn($stored);

        $policy = new DiscoveryPolicy($settings);

        self::assertSame($expected, $policy->ssdpEnabled());
        self::assertSame($expected, $policy->mdnsEnabled());
    }

    public function test_the_two_keys_are_read_independently(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings
            ->method('getEffective')
            ->willReturnCallback(
                static fn (string $key): mixed => match ($key) {
                    DiscoveryPolicy::SETTING_KEY_SSDP => false,
                    DiscoveryPolicy::SETTING_KEY_MDNS => true,
                    default => null,
                }
            );

        $policy = new DiscoveryPolicy($settings);

        self::assertFalse($policy->ssdpEnabled());
        self::assertTrue($policy->mdnsEnabled());
    }

    public function test_key_constants_address_the_config_discovery_php_flags_they_replaces(): void
    {
        // Discovery key -> config/discovery.php -> ['ssdp']['enabled'] and
        // ['mdns']['enabled']: the flags the file has always carried and that
        // nothing loaded. If either constant drifts off that path the gate
        // silently stops seeing the admin-saved value.
        self::assertSame('discovery.ssdp.enabled', DiscoveryPolicy::SETTING_KEY_SSDP);
        self::assertSame('discovery.mdns.enabled', DiscoveryPolicy::SETTING_KEY_MDNS);
    }
}
