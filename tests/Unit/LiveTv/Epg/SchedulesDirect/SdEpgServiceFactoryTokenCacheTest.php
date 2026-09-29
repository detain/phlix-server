<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Epg\SchedulesDirect;

use Phlix\LiveTv\Epg\SchedulesDirect\SdEpgServiceFactory;
use PHPUnit\Framework\TestCase;

/**
 * F-6 regression suite: the cached Schedules Direct bearer token must never be
 * group- or world-visible, and must land atomically.
 *
 * Pre-fix, `file_put_contents($cachePath, ..., LOCK_EX)` inherited the default
 * 0666&~umask (0644 under the common 022 umask) inside a 0755 directory — any
 * local user could read a live API token. The repair is the house
 * atomic-private pattern (tempnam + chmod 0600 + rename, dir 0700).
 *
 * @since 2.3.0
 */
final class SdEpgServiceFactoryTokenCacheTest extends TestCase
{
    private string $dir;
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/sd-token-test-' . bin2hex(random_bytes(6));
        $this->dir = $base . '/cache';
        $this->cachePath = $this->dir . '/token.json';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        @rmdir(dirname($this->dir));
        parent::tearDown();
    }

    private function invokeSave(string $token): bool
    {
        $m = new \ReflectionMethod(SdEpgServiceFactory::class, 'saveCachedToken');
        $m->setAccessible(true);

        /** @var bool $ok */
        $ok = $m->invoke(null, $this->cachePath, $token);
        return $ok;
    }

    private function invokeLoad(): ?string
    {
        $m = new \ReflectionMethod(SdEpgServiceFactory::class, 'loadCachedToken');
        $m->setAccessible(true);

        /** @var string|null $token */
        $token = $m->invoke(null, $this->cachePath);
        return $token;
    }

    public function testSaveSucceedsAndRoundTripsThroughLoad(): void
    {
        $this->assertTrue($this->invokeSave('SDTOKEN-abc123'));
        $this->assertSame('SDTOKEN-abc123', $this->invokeLoad());
    }

    public function testTokenFileHasNoGroupOrOtherBits(): void
    {
        $this->invokeSave('SDTOKEN-perms');
        clearstatcache();

        $perms = fileperms($this->cachePath) & 0777;

        $this->assertSame(0600, $perms, sprintf(
            'SD token cache must be 0600, got %o',
            $perms
        ));
    }

    public function testCacheDirectoryIsOwnerOnly(): void
    {
        $this->invokeSave('SDTOKEN-dir');
        clearstatcache();

        $perms = fileperms($this->dir) & 0777;

        $this->assertSame(0700, $perms, sprintf(
            'SD token cache directory must be 0700, got %o',
            $perms
        ));
    }

    public function testPreExistingLoosePermissionsAreSelfHealedOnWrite(): void
    {
        // Upgrade path: the PRE-FIX deploy created the dir 0755 (and possibly a
        // 0644 token). A post-fix save must tighten BOTH even though mkdir is a
        // no-op on an existing directory.
        mkdir($this->dir, 0755, true);
        file_put_contents($this->cachePath, '{"token":"old","expires_at":9999999999}');
        chmod($this->cachePath, 0644);
        chmod($this->dir, 0755);
        clearstatcache();

        $this->invokeSave('SDTOKEN-healed');
        clearstatcache();

        $this->assertSame(0600, fileperms($this->cachePath) & 0777);
        $this->assertSame(0700, fileperms($this->dir) & 0777);
    }

    public function testNoTempFileStraysLeftBehind(): void
    {
        $this->invokeSave('SDTOKEN-clean');

        $strays = glob($this->dir . '/.sd-token-*') ?: [];

        $this->assertSame([], $strays, 'Atomic rename must consume the staged temp file');
    }
}
