<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Discovery\DiscoveryPolicy;
use Phlix\Media\Metadata\MatchConfidencePolicy;
use Phlix\Media\Metadata\MetadataCachePolicy;
use Phlix\Server\Http\Middleware\SecurityHeadersPolicy;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * W2+W4 config-side reachability for all six schema keys this wave declares
 * in phlix-shared (568655e + 5c4b59f): every key must resolve through
 * SettingsRepository::getDefault() over the REAL config directory to the
 * exact shipped constant its consuming policy duplicates — a dotted key with
 * no matching config subtree silently defaults to null, which is exactly the
 * 213fce9d near-miss class this pins against (AuthMethodFlagsReachabilityTest
 * is the shape this copies).
 *
 * The three policies are exercised THROUGH their public decision methods as
 * well, not just raw key lookup: "enforcement wiring reaches real config"
 * means DiscoveryPolicy/security pair/metadata pair all answer the shipped
 * values when constructed over the real directory — the same construction
 * PHP-DI performs at boot modulo the DB layer.
 */
final class PhaseW2W4SettingsReachabilityTest extends TestCase
{
    private function repositoryOverRealConfigDir(): SettingsRepository
    {
        // Empty-override-table stand-in: query() answers SELECTs with zero
        // rows, so getEffective() falls through getOverride()=null into the
        // REAL config-file default layer — the exact production shape for an
        // unset key. (The AuthMethodFlagsReachabilityTest reflection trick
        // stays valid for getDefault-only pins; here policies call
        // getEffective(), and an unconstructed Connection would detonate
        // connect() — undefined-array-key warnings + PDO attempts — inside
        // every policy call. Skipped parent ctor = no network, no noise.)
        $db = new class extends Connection {
            // no-op: never touch the real server
            public function __construct()
            {
            }

            #[\Override]
            public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
            {
                return [];
            }
        };

        return new SettingsRepository($db, dirname(__DIR__, 3) . '/config');
    }

    /**
     * @return array<string, array{0: string, 1: mixed}> key => expected default
     */
    public static function keyProvider(): array
    {
        return [
            'ssdp probe gate' => [DiscoveryPolicy::SETTING_KEY_SSDP, true],
            'mdns probe gate' => [DiscoveryPolicy::SETTING_KEY_MDNS, true],
            'hsts max age' => [SecurityHeadersPolicy::SETTING_KEY_HSTS_MAX_AGE, 31536000],
            'frame options' => [SecurityHeadersPolicy::SETTING_KEY_FRAME_OPTIONS, 'SAMEORIGIN'],
            'match confidence gate' => [MatchConfidencePolicy::SETTING_KEY, 0.0],
            'metadata cache ttl hours' => [MetadataCachePolicy::SETTING_KEY, 24],
        ];
    }

    /**
     * @dataProvider keyProvider
     */
    public function test_key_resolves_through_real_config(string $key, mixed $expected): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        $this->assertTrue($repo->hasDefault($key), "{$key} must resolve via config defaults");
        $this->assertSame($expected, $repo->getDefault($key));
    }

    public function test_policies_over_real_config_answer_their_shipped_constants(): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        // Defaults-true discovery gates (pure opt-out preserved end-to-end).
        $discovery = new DiscoveryPolicy($repo);
        $this->assertTrue($discovery->ssdpEnabled());
        $this->assertTrue($discovery->mdnsEnabled());

        // The header pair = the byte-identical pre-key values.
        $security = new SecurityHeadersPolicy($repo);
        $this->assertSame(SecurityHeadersPolicy::DEFAULT_HSTS_MAX_AGE, $security->hstsMaxAgeSeconds());
        $this->assertSame(SecurityHeadersPolicy::DEFAULT_FRAME_OPTIONS, $security->frameOptions());

        // The W3 duo = gate-off and 24 h (the former hardcodes).
        $this->assertSame(
            MatchConfidencePolicy::DEFAULT_MIN_CONFIDENCE,
            (new MatchConfidencePolicy($repo))->minConfidence(),
        );
        $this->assertSame(
            MetadataCachePolicy::DEFAULT_TTL_HOURS,
            (new MetadataCachePolicy($repo))->ttlHours(),
        );
    }

    public function test_server_php_carries_no_collision_trees_for_the_new_files(): void
    {
        $serverConfig = require dirname(__DIR__, 3) . '/config/server.php';

        $this->assertIsArray($serverConfig);

        // 'security' and 'discovery' are the NET-NEW-ish prefixes this wave
        // writes against (config/security.php authored here; config/discovery.php
        // finally loaded). None of the three may ALSO exist as a composed
        // top-level tree in server.php, or EffectiveConfig layering and
        // per-file resolution would disagree — the auth 'webauthn' near-miss
        // law, applied forward. (Measured: none exist — 'metadata' resolves
        // purely through config/metadata.php per-file, like the pre-existing
        // metadata.* schema family.)
        foreach (['security', 'discovery', 'metadata'] as $tree) {
            $this->assertArrayNotHasKey(
                $tree,
                $serverConfig,
                "config/server.php must not grow a top-level {$tree} tree — "
                . 'the per-file layer would then have two answers',
            );
        }
    }

    public function test_config_discovery_php_flag_shapes(): void
    {
        $discovery = require dirname(__DIR__, 3) . '/config/discovery.php';

        // The two flags the 0.28.0 audit found dead ("NO loader anywhere"):
        // DiscoveryPolicy now reads them via this file. Pin the nested shapes
        // the dotted keys address — a rename here silently un-plugs the gate.
        $this->assertTrue($discovery['ssdp']['enabled']);
        $this->assertTrue($discovery['mdns']['enabled']);
    }
}
