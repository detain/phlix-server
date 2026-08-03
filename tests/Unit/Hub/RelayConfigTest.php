<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\RelayConfig;

/**
 * Unit coverage for {@see RelayConfig} — the S38 relay-TLS-vs-scheme fix.
 *
 * Relay TLS is INDEPENDENT of the hub's HTTP TLS: the derived relay scheme is
 * decided by PHLIX_RELAY_TLS (default off, mirroring the hub's HUB_RELAY_TLS),
 * NOT by whether the hub is https. An explicit hub_relay_ws_url is highest
 * precedence.
 */
final class RelayConfigTest extends TestCase
{
    /** @var array<string, string|false> Saved env values to restore in tearDown. */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (
            [
            'PHLIX_RELAY_TLS',
            'PHLIX_RELAY_TLS_VERIFY',
            'PHLIX_RELAY_TLS_CAFILE',
            'PHLIX_RELAY_ENABLED',
            'PHLIX_RELAY_HUB_URL',
            'PHLIX_RELAY_HUB_WS_URL',
            ] as $key
        ) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }

    public function test_defaults_are_secure_and_plaintext_relay(): void
    {
        $config = new RelayConfig();

        $this->assertFalse($config->relayTls, 'relay TLS must default OFF (hub relay port is plaintext by default)');
        $this->assertTrue($config->relayTlsVerify, 'cert verification must default ON (secure)');
        $this->assertSame(RelayConfig::DEFAULT_TLS_CAFILE, $config->relayTlsCafile);
    }

    public function test_build_hub_relay_ws_url_is_plaintext_by_default(): void
    {
        $config = new RelayConfig(hubWssUrl: 'wss://hub.example.com/api/v1/servers/{id}/relay');
        $this->assertSame('ws://hub.example.com:8802', $config->buildHubRelayWsUrl());
    }

    public function test_build_hub_relay_ws_url_is_wss_when_relay_tls_on(): void
    {
        $config = new RelayConfig(
            hubWssUrl: 'wss://hub.example.com/api/v1/servers/{id}/relay',
            relayTls: true,
        );
        $this->assertSame('wss://hub.example.com:8802', $config->buildHubRelayWsUrl());
    }

    public function test_explicit_hub_relay_ws_url_wins_over_derivation(): void
    {
        // relayTls on would derive wss://, but the explicit override is verbatim.
        $config = new RelayConfig(
            hubWssUrl: 'wss://hub.example.com/api/v1/servers/{id}/relay',
            hubRelayWsUrl: 'ws://custom-hub:12000',
            relayTls: true,
        );
        $this->assertSame('ws://custom-hub:12000', $config->buildHubRelayWsUrl());
    }

    public function test_explicit_wss_hub_relay_ws_url_wins_over_plaintext_derivation(): void
    {
        // relayTls off would derive ws://, but the explicit wss:// override is
        // returned verbatim — proving precedence holds in BOTH scheme directions
        // (the ws-over-wss direction is covered above).
        $config = new RelayConfig(
            hubWssUrl: 'https://hub.example.com/api/v1/servers/{id}/relay',
            hubRelayWsUrl: 'wss://custom-hub:12000',
            relayTls: false,
        );
        $this->assertSame('wss://custom-hub:12000', $config->buildHubRelayWsUrl());
    }

    public function test_build_hub_relay_ws_url_empty_when_unconfigured(): void
    {
        $this->assertSame('', (new RelayConfig())->buildHubRelayWsUrl());
    }

    public function test_with_auto_enable_derives_plaintext_by_default(): void
    {
        $enabled = (new RelayConfig())->withAutoEnable('https://hub.example.com');

        $this->assertTrue($enabled->enabled);
        $this->assertSame('ws://hub.example.com:8802', $enabled->buildHubRelayWsUrl());
    }

    public function test_with_auto_enable_derives_wss_when_relay_tls_on(): void
    {
        $enabled = (new RelayConfig(relayTls: true))->withAutoEnable('https://hub.example.com');
        $this->assertSame('wss://hub.example.com:8802', $enabled->buildHubRelayWsUrl());
    }

    public function test_with_auto_enable_preserves_explicit_override(): void
    {
        $enabled = (new RelayConfig(hubRelayWsUrl: 'ws://explicit:9999'))
            ->withAutoEnable('https://hub.example.com');
        $this->assertSame('ws://explicit:9999', $enabled->buildHubRelayWsUrl());
    }

    public function test_with_auto_enable_carries_tls_settings_forward(): void
    {
        $config = new RelayConfig(relayTls: true, relayTlsVerify: false, relayTlsCafile: '/tmp/ca.pem');
        $enabled = $config->withAutoEnable('https://hub.example.com');

        $this->assertTrue($enabled->relayTls);
        $this->assertFalse($enabled->relayTlsVerify);
        $this->assertSame('/tmp/ca.pem', $enabled->relayTlsCafile);
    }

    public function test_from_env_reads_relay_tls_flags(): void
    {
        putenv('PHLIX_RELAY_TLS=1');
        putenv('PHLIX_RELAY_TLS_VERIFY=0');
        putenv('PHLIX_RELAY_TLS_CAFILE=/etc/custom/bundle.pem');

        $config = RelayConfig::fromEnv();

        $this->assertTrue($config->relayTls);
        $this->assertFalse($config->relayTlsVerify);
        $this->assertSame('/etc/custom/bundle.pem', $config->relayTlsCafile);
    }

    public function test_from_env_defaults_when_unset(): void
    {
        $config = RelayConfig::fromEnv();

        $this->assertFalse($config->relayTls);
        $this->assertTrue($config->relayTlsVerify);
        $this->assertSame(RelayConfig::DEFAULT_TLS_CAFILE, $config->relayTlsCafile);
    }

    public function test_from_env_overrides_win_over_env(): void
    {
        putenv('PHLIX_RELAY_TLS=1');

        $config = RelayConfig::fromEnv([
            'relay_tls' => false,
            'relay_tls_verify' => false,
            'relay_tls_cafile' => '/override/ca.pem',
        ]);

        $this->assertFalse($config->relayTls, 'array override must win over env');
        $this->assertFalse($config->relayTlsVerify);
        $this->assertSame('/override/ca.pem', $config->relayTlsCafile);
    }
}
