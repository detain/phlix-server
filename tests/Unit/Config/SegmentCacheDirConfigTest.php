<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * C5 (FIELD-hub-phlix): `hls.segment_dir` resolution in config/server.php.
 *
 * The shipped systemd unit runs with PrivateTmp=true, so the historical
 * temp-dir default is a per-start scratch namespace — every deploy/restart
 * wiped the segment cache and each cold first-play re-paid 3–6.6 s of inline
 * transcode (the FIELD-hub-phlix playback trace). PHLIX_SEGMENT_DIR lets the
 * cache live on a persistent, provisioned path (install.sh seeds
 * /var/phlix/hls). Two laws, both pinned here:
 *
 *  1. ENV HONORED: a non-empty PHLIX_SEGMENT_DIR becomes the resolved
 *     segment_dir verbatim (the whole point of the knob).
 *  2. DEFAULT UNCHANGED: unset/empty resolves byte-identically to the
 *     pre-C5 `sys_get_temp_dir() . '/phlix_hls'` — an install that never
 *     hears about the knob changes nothing.
 *
 * Uses the same require-the-real-config-file approach as
 * PhaseW2W4SettingsReachabilityTest; env is backed up and restored per test
 * exactly like JobQueuePathsTest.
 */
final class SegmentCacheDirConfigTest extends TestCase
{
    private const TOUCHED_ENV = ['PHLIX_SEGMENT_DIR'];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (self::TOUCHED_ENV as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        $this->envBackup = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function serverConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/config/server.php';
        $this->assertIsArray($config['hls'] ?? null);
        /** @var array<string, mixed> $hls */
        $hls = $config['hls'];
        return $hls;
    }

    public function testSegmentDirHonorsEnvOverride(): void
    {
        putenv('PHLIX_SEGMENT_DIR=/var/phlix/hls');
        $hls = $this->serverConfig();
        $this->assertSame('/var/phlix/hls', $hls['segment_dir'] ?? null);
    }

    public function testSegmentDirPassesThroughAnyNonEmptyValueVerbatim(): void
    {
        // The config layer resolves; the SHAPE verdict belongs to
        // SegmentCacheDirectory at boot (fail-loud), not to a silent filter here.
        putenv('PHLIX_SEGMENT_DIR=/srv/custom-segments/');
        $hls = $this->serverConfig();
        $this->assertSame('/srv/custom-segments/', $hls['segment_dir'] ?? null);
    }

    public function testSegmentDirDefaultIsByteIdenticalTempPathWhenUnset(): void
    {
        $hls = $this->serverConfig();
        $this->assertSame(sys_get_temp_dir() . '/phlix_hls', $hls['segment_dir'] ?? null);
    }

    public function testSegmentDirDefaultAppliesWhenEnvIsEmptyString(): void
    {
        // Container runtimes set `-e FOO=` more often than anyone admits —
        // the estate-wide EnvConfig law treats "" as unset.
        putenv('PHLIX_SEGMENT_DIR=');
        $hls = $this->serverConfig();
        $this->assertSame(sys_get_temp_dir() . '/phlix_hls', $hls['segment_dir'] ?? null);
    }
}
