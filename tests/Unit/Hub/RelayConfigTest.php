<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Container\Providers\HubServicesProvider;
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
            'PHLIX_RELAY_ALLOW_PLAINTEXT',
            'PHLIX_RELAY_REQUIRE_TLS',
            'PHLIX_RELAY_RECONNECT_DELAY',
            'PHLIX_RELAY_RECONNECT_MAX_DELAY',
            'PHLIX_RELAY_RECONNECT_JITTER_FACTOR',
            'PHLIX_RELAY_PING_INTERVAL',
            'PHLIX_RELAY_PING_TIMEOUT',
            'PHLIX_RELAY_TUNNEL_HOSTNAME',
            'PHLIX_RELAY_LOCAL_HTTP_ADDRESS',
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

    // ---- M5: plaintext-tunnel acknowledgment flag ----

    public function test_plaintext_opt_in_defaults_false(): void
    {
        $this->assertFalse(
            (new RelayConfig())->allowPlaintextTunnel,
            'the plaintext tunnel must stay LOUD by default (impersonation risk is opt-IN to silence)',
        );
    }

    public function test_from_env_reads_plaintext_opt_in(): void
    {
        putenv('PHLIX_RELAY_ALLOW_PLAINTEXT=1');

        $this->assertTrue(RelayConfig::fromEnv()->allowPlaintextTunnel);
    }

    public function test_from_env_plaintext_opt_in_override_wins(): void
    {
        putenv('PHLIX_RELAY_ALLOW_PLAINTEXT=1');

        $config = RelayConfig::fromEnv(['allow_plaintext_tunnel' => false]);

        $this->assertFalse($config->allowPlaintextTunnel, 'array override must win over env');
    }

    public function test_with_auto_enable_carries_plaintext_opt_in_forward(): void
    {
        $enabled = (new RelayConfig(allowPlaintextTunnel: true))->withAutoEnable('https://hub.example.com');

        $this->assertTrue($enabled->allowPlaintextTunnel);
        $this->assertSame('ws://hub.example.com:8802', $enabled->buildHubRelayWsUrl());
    }

    // ---- R17-01: fail-closed require-TLS knob ----

    public function test_require_tls_defaults_true(): void
    {
        $this->assertTrue(
            (new RelayConfig())->requireTls,
            'require_tls is secure-by-default (SRV-45 flip); opt OUT with PHLIX_RELAY_REQUIRE_TLS=0',
        );
        $this->assertTrue(RelayConfig::fromEnv()->requireTls, 'unset env must resolve to ON');
    }

    public function test_from_env_require_tls_opt_out(): void
    {
        putenv('PHLIX_RELAY_REQUIRE_TLS=0');

        $this->assertFalse(RelayConfig::fromEnv()->requireTls);
    }

    public function test_from_env_reads_require_tls(): void
    {
        putenv('PHLIX_RELAY_REQUIRE_TLS=1');

        $this->assertTrue(RelayConfig::fromEnv()->requireTls);
    }

    public function test_from_env_require_tls_override_wins(): void
    {
        putenv('PHLIX_RELAY_REQUIRE_TLS=1');

        $config = RelayConfig::fromEnv(['require_tls' => false]);

        $this->assertFalse($config->requireTls, 'array override must win over env');
    }

    public function test_with_auto_enable_carries_require_tls_forward(): void
    {
        $enabled = (new RelayConfig(requireTls: true))->withAutoEnable('https://hub.example.com');

        $this->assertTrue($enabled->requireTls);
        // Auto-derived plaintext URL must survive the copy untouched — the
        // refusal happens later at dial time, never by silently rewriting it.
        $this->assertSame('ws://hub.example.com:8802', $enabled->buildHubRelayWsUrl());
    }

    // ---- SRV-ENVBOOL: E-04 convergence — getEnvBool answers under EnvConfig::bool() ----

    public function test_from_env_padded_truthy_enables_under_the_trim_law(): void
    {
        // The hand-rolled copy did not trim: PHLIX_RELAY_ENABLED=" true" meant
        // "not enabled" here while the same padded shape answered ON under the
        // SSOT grammar. Disclosed flip class: padded TRUE words now enable.
        putenv('PHLIX_RELAY_ENABLED= true');

        $this->assertTrue(RelayConfig::fromEnv()->enabled, 'padded truthy must mean ON (SSOT trim law)');
    }

    public function test_from_env_junk_on_default_true_knobs_answers_securely(): void
    {
        // The two default-TRUE knobs used to read junk as a FALSE-spelling:
        // PHLIX_RELAY_REQUIRE_TLS=maybe and PHLIX_RELAY_TLS_VERIFY=maybe
        // resolved OFF at the bare truthy-word list. Under EnvConfig::bool()
        // junk is not-an-answer and the default — the SAFE answer — wins.
        // This is the second disclosed flip class (fail-closed direction);
        // no deploy artifact sets either knob to junk (install.sh ships bare
        // PHLIX_RELAY_ENABLED=1 only).
        putenv('PHLIX_RELAY_REQUIRE_TLS=maybe');
        $this->assertTrue(RelayConfig::fromEnv()->requireTls, 'junk must not fail-open require_tls');

        putenv('PHLIX_RELAY_TLS_VERIFY=maybe');
        $this->assertTrue(RelayConfig::fromEnv()->relayTlsVerify, 'junk must not fail-open cert verification');

        putenv('PHLIX_RELAY_TLS_VERIFY= ');
        $this->assertTrue(RelayConfig::fromEnv()->relayTlsVerify, 'whitespace reads not-provided, default ON');

        putenv('PHLIX_RELAY_REQUIRE_TLS=');
        $this->assertTrue(RelayConfig::fromEnv()->requireTls, 'empty reads not-provided, default ON');

        // Characterization legs — answers PRESERVED: an explicit falsy word
        // opts out, padded or not (the base reader also rejected both shapes
        // as non-truthy, so both eras answer FALSE here).
        putenv('PHLIX_RELAY_REQUIRE_TLS=0');
        $this->assertFalse(RelayConfig::fromEnv()->requireTls);

        putenv('PHLIX_RELAY_TLS_VERIFY= off');
        $this->assertFalse(RelayConfig::fromEnv()->relayTlsVerify, 'padded falsy word opts out (trim law)');
    }

    // ---- SRV-ENVBOOL-r2: config/relay.php converges on the same E-04 grammar ----
    //
    // The class-level pins above only governed the DIRECT env path. Production
    // wiring (config/server.php:97 'relay' => require config/relay.php ->
    // HubServicesProvider RelayConfig factory -> fromEnv($overrides)) answers
    // these knobs at the CONFIG LAYER first, whose three same-named reads used
    // the pre-E-04 filter_var/`?:` grammar (r1 review MAJOR). They now delegate
    // to EnvConfig, so every path speaks one grammar. Disclosed answer changes
    // on the live (config/DI) path — probe table reviews/envbool-r2-old.tsv:
    //   (a) PHLIX_RELAY_TLS_VERIFY='0'  : shadow `?:` swallowed the falsy
    //       string to 'true' -> ON despite the file's own comment promising
    //       the opt-out; now FALSE (opt-out respected).
    //   (b) PHLIX_RELAY_TLS_VERIFY junk ('maybe','-1'): filter_var invalid ->
    //       silent FALSE = verification fail-OPEN; now default TRUE (secure-on)
    //       and LOUD. Nothing shipped sets either value: deploy grep finds no
    //       artifact setting PHLIX_RELAY_TLS_VERIFY at all (.github/, docker,
    //       k8s, systemd, scripts/), so (a) can bite only hand-edited env.

    private const CONFIG_RELAY_FILE = __DIR__ . '/../../../config/relay.php';

    public function test_config_relay_file_tls_verify_follows_the_opt_out_grammar(): void
    {
        // Default secure-on (preserved on every path shape).
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_TLS_VERIFY', 'unset'));
        // Explicit opt-outs RESPECTED — the documented purpose of the knob.
        // '0' is disclosed flip class (a): the shadow answered TRUE.
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_TLS_VERIFY', '0'));
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_TLS_VERIFY', 'false'));
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_TLS_VERIFY', ' off'));
        // Truthy words keep verification (preserved).
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_TLS_VERIFY', '1'));
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_TLS_VERIFY', '  true  '));
        // Junk keeps the safe default TRUE — disclosed flip class (b): the
        // shadow failed OPEN to FALSE silently for these exact spellings.
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_TLS_VERIFY', 'maybe'));
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_TLS_VERIFY', '-1'));
        // Not-provided shapes take the default (preserved).
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_TLS_VERIFY', ''));
    }

    public function test_config_relay_file_relay_tls_and_require_tls_answers_are_preserved(): void
    {
        // relay_tls (default false): full answer space agrees OLD vs NEW — the
        // migration adds only the junk announcement, zero answer changes.
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_TLS', 'unset'));
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_TLS', ''));
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_TLS', '0'));
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_TLS', '1'));
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_TLS', ' yes'));
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_TLS', 'off'));
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_TLS', 'maybe'));
        // require_tls: the closure was already boolOptOut-shaped — every
        // spelling preserved ('0' opts out WITHOUT the :47 `?:` swallowing).
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_REQUIRE_TLS', 'unset'));
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_REQUIRE_TLS', ''));
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_REQUIRE_TLS', '0'));
        $this->assertTrue($this->configRelayValue('PHLIX_RELAY_REQUIRE_TLS', 'maybe'));
        $this->assertFalse($this->configRelayValue('PHLIX_RELAY_REQUIRE_TLS', 'no'));
    }

    public function test_config_relay_file_announces_junk_on_the_production_path(): void
    {
        // The config layer used to answer junk silently (relay_tls 'maybe' ->
        // FALSE no-words, tls_verify junk -> fail-open FALSE). With EnvConfig
        // the typo announces itself once per knob exactly where the operator
        // set it. Reader-level warning grammar is pinned exhaustively in
        // EnvConfigTest; these legs pin that the PRODUCTION parse path logs.
        $log = $this->capturedErrorLog(function (): void {
            $cfg = require self::CONFIG_RELAY_FILE;
            $this->assertTrue($cfg['relay_tls_verify']);
        }, 'PHLIX_RELAY_TLS_VERIFY=maybe');
        $this->assertStringContainsString('PHLIX_RELAY_TLS_VERIFY', $log);
    }

    public function test_di_final_relay_config_respects_the_tls_verify_opt_out_the_config_promise(): void
    {
        // Production truth: the DI factory ALWAYS overlays the parsed
        // config/relay.php, so this pins the effective container answer.
        // Pre-migration the shadow made PHLIX_RELAY_TLS_VERIFY=0 unreachable
        // (always ON via `?:`); it now opts out, matching the file's comment.
        $config = $this->relayConfigFromContainer('PHLIX_RELAY_TLS_VERIFY=0');
        $this->assertFalse($config->relayTlsVerify, '=0 must disable verification on the DI path');
        // The other bool knobs answer their defaults unaffected on this path.
        $this->assertFalse($config->relayTls);
        $this->assertTrue($config->requireTls);
    }

    public function test_di_final_junk_keeps_verification_secure_on_and_silence_is_gone(): void
    {
        $config = $this->relayConfigFromContainer('PHLIX_RELAY_TLS_VERIFY=maybe');
        // Fail-OPEN shadow (FALSE) is dead: junk keeps the safe ON.
        $this->assertTrue($config->relayTlsVerify);
        // require_tls opt-out survives end-to-end on the DI path (preserved).
        $requireOff = $this->relayConfigFromContainer('PHLIX_RELAY_REQUIRE_TLS=0');
        $this->assertFalse($requireOff->requireTls);
    }

    /**
     * Parse the REAL shipped config/relay.php with one env knob set.
     */
    private function configRelayValue(string $envName, string $value): bool
    {
        if ($value === 'unset') {
            putenv($envName);
        } else {
            putenv($envName . '=' . $value);
        }
        $cfg = require self::CONFIG_RELAY_FILE;
        $key = [
            'PHLIX_RELAY_TLS' => 'relay_tls',
            'PHLIX_RELAY_TLS_VERIFY' => 'relay_tls_verify',
            'PHLIX_RELAY_REQUIRE_TLS' => 'require_tls',
        ][$envName];
        $this->assertIsBool($cfg[$key]);
        return $cfg[$key];
    }

    /**
     * The container-built RelayConfig exactly as HubServicesProvider assembles
     * it (config/server.php passes require config/relay.php as $appConfig['relay']).
     */
    private function relayConfigFromContainer(string ...$envSets): RelayConfig
    {
        foreach ($envSets as $set) {
            putenv($set);
        }
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new HubServicesProvider())->register($builder, ['relay' => require self::CONFIG_RELAY_FILE]);
        $container = $builder->build();
        $config = $container->get(RelayConfig::class);
        $this->assertInstanceOf(RelayConfig::class, $config);
        return $config;
    }

    /**
     * EnvConfigTest idiom: route error_log to a temp file for one closure.
     */
    private function capturedErrorLog(callable $run, string $envSet): string
    {
        putenv($envSet);
        $path = tempnam(sys_get_temp_dir(), 'srvenvbool-r2-');
        $this->assertIsString($path);
        $previous = ini_get('error_log');
        ini_set('error_log', $path);
        $log = '';
        try {
            $run();
            $log = (string) @file_get_contents($path);
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '');
            unlink($path);
        }
        return $log;
    }

    // ---- R18-17: typed env parsing (empty = unset; old `?:` falsiness preserved) ----

    public function test_from_env_empty_string_means_unset_for_strings(): void
    {
        putenv('PHLIX_RELAY_HUB_URL=');
        putenv('PHLIX_RELAY_HUB_WS_URL=');
        putenv('PHLIX_RELAY_TUNNEL_HOSTNAME=');
        putenv('PHLIX_RELAY_LOCAL_HTTP_ADDRESS=');
        putenv('PHLIX_RELAY_TLS_CAFILE=');

        $config = RelayConfig::fromEnv();

        $this->assertSame('', $config->hubWssUrl);
        $this->assertSame('', $config->hubRelayWsUrl);
        $this->assertSame('', $config->tunnelHostname);
        $this->assertSame('127.0.0.1:8096', $config->localHttpAddress);
        $this->assertSame(RelayConfig::DEFAULT_TLS_CAFILE, $config->relayTlsCafile);
    }

    public function test_from_env_empty_string_means_unset_for_numerics(): void
    {
        putenv('PHLIX_RELAY_RECONNECT_DELAY=');
        putenv('PHLIX_RELAY_RECONNECT_MAX_DELAY=');
        putenv('PHLIX_RELAY_RECONNECT_JITTER_FACTOR=');
        putenv('PHLIX_RELAY_PING_INTERVAL=');
        putenv('PHLIX_RELAY_PING_TIMEOUT=');

        $config = RelayConfig::fromEnv();

        $this->assertSame(5, $config->reconnectDelay);
        $this->assertSame(300, $config->reconnectMaxDelay);
        $this->assertSame(0.3, $config->reconnectJitterFactor);
        $this->assertSame(30, $config->pingInterval);
        $this->assertSame(10, $config->pingTimeout);
    }

    public function test_from_env_zero_string_still_falls_back_like_the_old_falsy_chain(): void
    {
        // The retired idiom was (int)(getenv(X) ?: '5') — the STRING '0' is
        // falsy in PHP, so '0' never meant "delay 0"; pin that it still means
        // "default". Callers wanting a literal zero must not set the env at all.
        putenv('PHLIX_RELAY_RECONNECT_DELAY=0');
        putenv('PHLIX_RELAY_RECONNECT_MAX_DELAY=0');
        putenv('PHLIX_RELAY_RECONNECT_JITTER_FACTOR=0');
        putenv('PHLIX_RELAY_PING_INTERVAL=0');
        putenv('PHLIX_RELAY_PING_TIMEOUT=0');

        $config = RelayConfig::fromEnv();

        $this->assertSame(5, $config->reconnectDelay);
        $this->assertSame(300, $config->reconnectMaxDelay);
        $this->assertSame(0.3, $config->reconnectJitterFactor);
        $this->assertSame(30, $config->pingInterval);
        $this->assertSame(10, $config->pingTimeout);
    }

    public function test_from_env_reads_nonzero_numerics_verbatim(): void
    {
        putenv('PHLIX_RELAY_RECONNECT_DELAY=7');
        putenv('PHLIX_RELAY_RECONNECT_MAX_DELAY=45');
        putenv('PHLIX_RELAY_RECONNECT_JITTER_FACTOR=0.5');
        putenv('PHLIX_RELAY_PING_INTERVAL=15');
        putenv('PHLIX_RELAY_PING_TIMEOUT=4');

        $config = RelayConfig::fromEnv();

        $this->assertSame(7, $config->reconnectDelay);
        $this->assertSame(45, $config->reconnectMaxDelay);
        $this->assertSame(0.5, $config->reconnectJitterFactor);
        $this->assertSame(15, $config->pingInterval);
        $this->assertSame(4, $config->pingTimeout);
    }

    public function test_from_env_garbage_numerics_keep_the_cast_semantics(): void
    {
        // 'abc' was truthy for the old `?:`, then (int)'abc' === 0 — the typed
        // helpers must reproduce exactly that (fail-loud validation of these
        // knobs, if ever wanted, is a separate behavior change).
        putenv('PHLIX_RELAY_RECONNECT_DELAY=abc');
        putenv('PHLIX_RELAY_PING_INTERVAL=abc');
        putenv('PHLIX_RELAY_RECONNECT_JITTER_FACTOR=0.0');

        $config = RelayConfig::fromEnv();

        $this->assertSame(0, $config->reconnectDelay);
        $this->assertSame(0, $config->pingInterval);
        // '0.0' is a non-empty, non-'0' string → parses to float 0.0, same as
        // the old chain ((float)('0.0' ?: '0.3') would take the truthy '0.0').
        $this->assertSame(0.0, $config->reconnectJitterFactor);
    }
}
