<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\SegmentCacheDirectory;
use RuntimeException;

/**
 * C5 (FIELD-hub-phlix): the boot-time usability verdict for the HLS segment
 * cache directory. A misprovisioned PHLIX_SEGMENT_DIR must abort boot with an
 * actionable message — never a silent fallback, because a broken cache is
 * invisible until every cold first-play pays the 3–6.6 s inline-transcode tax
 * the persistent-cache work exists to kill.
 *
 * The guard CHECKS and never creates: lazy creation stays exactly where it
 * already lives (`ensureHlsJob()` mkdir + `produceSegment()` @mkdir).
 *
 * Permission-shaped refusals (not-writable dir / not-writable ancestor) are
 * uid-dependent — root bypasses mode bits — so those cases skip under root
 * while every structural case (empty, relative, file-in-the-way, missing
 * chain) holds everywhere.
 */
final class SegmentCacheDirectoryTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/phlix_scd_' . uniqid('', true);
        mkdir($this->scratch, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);
    }

    private function removeTree(string $path): void
    {
        if (!str_starts_with($path, sys_get_temp_dir() . '/phlix_scd_')) {
            return; // paranoia guard: never delete outside the scratch prefix
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @chmod($path, 0644);
            @unlink($path);
        }
    }

    private function requireNonRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped(
                'mode-bit enforcement is bypassed by root; this case only bites for non-root processes'
            );
        }
    }

    public function testExistingWritableDirectoryIsUsable(): void
    {
        $this->assertTrue(SegmentCacheDirectory::isUsable($this->scratch));
        SegmentCacheDirectory::assertUsable($this->scratch); // must not throw
    }

    public function testShippedTempDefaultIsUsable(): void
    {
        // sys_get_temp_dir()/phlix_hls typically does not exist on a fresh
        // checkout — the verdict is "creatable", which is the historical
        // byte-identical default behavior C5 must not break.
        $this->assertTrue(SegmentCacheDirectory::isUsable(sys_get_temp_dir() . '/phlix_hls'));
    }

    public function testTrailingSlashIsTolerated(): void
    {
        $this->assertTrue(SegmentCacheDirectory::isUsable($this->scratch . '/'));
    }

    public function testEmptyValueIsRefused(): void
    {
        $this->assertFalse(SegmentCacheDirectory::isUsable(''));
        $this->assertRefusal('', 'is empty');
    }

    public function testRelativePathIsRefused(): void
    {
        // A relative override resolves against a per-process cwd — never what
        // the operator meant, and PrivateTmp would make it worse.
        $this->assertFalse(SegmentCacheDirectory::isUsable('var/phlix_hls'));
        $this->assertRefusal('var/phlix_hls', 'not an absolute path');
    }

    public function testExistingFileInPlaceOfDirectoryIsRefused(): void
    {
        $file = $this->scratch . '/not-a-dir';
        file_put_contents($file, 'x');
        $this->assertFalse(SegmentCacheDirectory::isUsable($file));
        $this->assertRefusal($file, 'exists and is not a directory');
    }

    public function testNotWritableDirectoryIsRefused(): void
    {
        $this->requireNonRoot();
        $dir = $this->scratch . '/locked';
        mkdir($dir, 0755, true);
        chmod($dir, 0555);
        try {
            $this->assertFalse(SegmentCacheDirectory::isUsable($dir));
            $this->assertRefusal($dir, 'not writable');
        } finally {
            chmod($dir, 0755);
        }
    }

    public function testMissingDirectoryUnderWritableParentIsUsable(): void
    {
        // Creation is lazy; the boot verdict is "this mkdir will work later".
        $this->assertTrue(SegmentCacheDirectory::isUsable($this->scratch . '/deep/er/still-deeper'));
    }

    public function testMissingDirectoryUnderNotWritableParentIsRefused(): void
    {
        $this->requireNonRoot();
        $parent = $this->scratch . '/locked-parent';
        mkdir($parent, 0755, true);
        chmod($parent, 0555);
        try {
            $target = $parent . '/segments';
            $this->assertFalse(SegmentCacheDirectory::isUsable($target));
            $message = $this->refusalMessage($target);
            $this->assertStringContainsString('is not writable', $message);
            $this->assertStringContainsString($parent, $message);
        } finally {
            chmod($parent, 0755);
        }
    }

    public function testMissingDirectoryWithFileAncestorIsRefused(): void
    {
        $file = $this->scratch . '/blocker';
        file_put_contents($file, 'x');
        $target = $file . '/segments';
        $this->assertFalse(SegmentCacheDirectory::isUsable($target));
        $this->assertStringContainsString(
            'is not a directory',
            $this->refusalMessage($target)
        );
    }

    public function testRefusalMessageNamesTheOperatorKnob(): void
    {
        $message = $this->refusalMessage('relative/path');
        $this->assertStringContainsString('PHLIX_SEGMENT_DIR', $message);
        $this->assertStringContainsString('config hls.segment_dir', $message);
        $this->assertStringContainsString('ReadWritePaths', $message);
        $this->assertStringContainsString('/var/phlix/hls', $message);
        $this->assertStringContainsString('relative/path', $message);
    }

    /**
     * @param string $needle substring the thrown message must carry
     */
    private function assertRefusal(string $dir, string $needle): void
    {
        $this->assertStringContainsString($needle, $this->refusalMessage($dir));
    }

    private function refusalMessage(string $dir): string
    {
        try {
            SegmentCacheDirectory::assertUsable($dir);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        $this->fail('assertUsable() accepted a path expected to be refused: ' . $dir);
    }
}
