<?php

/**
 * Phlix media server test component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\FolderWatcher;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SplFileInfo;

/**
 * M-SERVER-39 (review server__srphp M3) — the directory checksum must
 * materialize the watched tree ONCE, and the array it does materialize must
 * be BOUNDED.
 *
 * The shipped defect: `calculateDirectoryChecksum()` collected every
 * `path:mtime` entry into an array AND concatenated the same entries into one
 * `$checksum` string before hashing — two full materializations per poll, an
 * O(library size) transient spike in the resident library-scan worker.
 *
 * Pinned here:
 * - P1 the digest VALUE is byte-preserved by the cure: md5 of the sorted
 *   entries, exactly as before (streaming a hash context over the same bytes
 *   is digest-identical, so no stored baseline changes meaning);
 * - P2 the method streams `hash_init`/`hash_update`/`hash_final` and contains
 *   no whole-tree accumulator (structural pin — behavior tests cannot see the
 *   absent second copy, the source can);
 * - P3/P4 the walk fails loud past the per-path watch cap and is exact at the
 *   cap boundary (kills off-by-one in both directions);
 * - P5 the shipped default ceiling stays at or above 2x the review's cited
 *   realistic large-library scale and the getter reports it.
 */
class FolderWatcherChecksumTest extends TestCase
{
    /** @var list<string> Temp directories to remove in tearDown. */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * P1 — byte-preservation contract: the checksum equals md5 over the
     * sorted `path:mtime` entries concatenated, the documented digest schema.
     * This passes BEFORE and AFTER the cure on purpose: it is the reason the
     * cure is in-place with no re-baseline, not a red-on-parent test.
     */
    public function testChecksumIsTheMd5OfTheSortedPathMtimeEntries(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/a.mkv', 'a');
        file_put_contents($dir . '/z.mkv', 'z');
        mkdir($dir . '/show');
        file_put_contents($dir . '/show/m.mkv', 'm');
        touch($dir . '/a.mkv', 1700000000);
        touch($dir . '/z.mkv', 1700000002);
        touch($dir . '/show/m.mkv', 1700000001);

        $expected = md5(implode('', $this->sortedEntriesOf($dir)));

        $this->assertSame($expected, $this->checksumOf($dir));
    }

    /**
     * P2 — structural pin: the method body streams the digest and never
     * materializes a concatenated copy. Red on the defective parent, which
     * accumulates `$checksum .= ...` and hashes the string with md5().
     */
    public function testTheChecksumStreamsInsteadOfConcatenatingTheWholeTree(): void
    {
        $body = $this->methodBodyOf('calculateDirectoryChecksum');

        $this->assertStringContainsString('hash_init(', $body);
        $this->assertStringContainsString('hash_update(', $body);
        $this->assertStringContainsString('hash_final(', $body);
        $this->assertStringNotContainsString(
            '.=',
            $body,
            'an accumulator assignment re-introduces the whole-tree string copy the cure removed'
        );
        $this->assertStringNotContainsString(
            'md5(',
            $body,
            'md5() takes one string; streaming must hash per entry instead'
        );
    }

    /**
     * P3 — past the cap the walk must fail loud, naming the path and the cap.
     * A silently truncated checksum would mis-detect changes forever (worse
     * than no checksum); the scheduler already contains this throw with
     * logging and registration backoff.
     */
    public function testWalkFailsLoudWhenTheDirectoryExceedsTheWatchCap(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/a.mkv', 'a');
        file_put_contents($dir . '/b.mkv', 'b');
        file_put_contents($dir . '/c.mkv', 'c');

        $watcher = new FolderWatcher(LoggerFactory::get(LogChannels::MEDIA));
        $watcher->setMaxWatchedFilesPerPath(2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($dir, '/') . '/');
        $this->expectExceptionMessageMatches('/more than 2 files/');

        $watcher->watch('lib-a', [$dir]);
    }

    /**
     * P4 — boundary exactness: exactly the cap walks clean with the
     * byte-preserved digest; the file past the cap throws. Kills the
     * off-by-one in both directions.
     */
    public function testCapAllowsExactlyTheCeilingAndRejectsTheFilePastIt(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/a.mkv', 'a');
        file_put_contents($dir . '/b.mkv', 'b');

        $watcher = new FolderWatcher(LoggerFactory::get(LogChannels::MEDIA));
        $watcher->setMaxWatchedFilesPerPath(2);

        $digest = (new ReflectionMethod(FolderWatcher::class, 'calculateDirectoryChecksum'))
            ->invoke($watcher, $dir);
        $this->assertSame(
            md5(implode('', $this->sortedEntriesOf($dir))),
            $digest,
            'exactly-at-cap must not throw and must keep the documented digest'
        );

        file_put_contents($dir . '/c.mkv', 'c');

        $this->expectException(RuntimeException::class);
        (new ReflectionMethod(FolderWatcher::class, 'calculateDirectoryChecksum'))
            ->invoke($watcher, $dir);
    }

    /**
     * P5 — the shipped ceiling is an int at least 2x the review's cited
     * 300-500k realistic large-library scale, and a fresh watcher reports it.
     */
    public function testTheShippedCapDefaultIsTheClassCeiling(): void
    {
        $ceiling = (new ReflectionClass(FolderWatcher::class))
            ->getConstant('MAX_WATCHED_FILES_PER_PATH');

        $this->assertIsInt($ceiling);
        $this->assertGreaterThanOrEqual(
            1000000,
            (int) $ceiling,
            'the shipped cap must keep headroom over the cited realistic scale (2x of 500k)'
        );

        $watcher = new FolderWatcher(LoggerFactory::get(LogChannels::MEDIA));
        $this->assertSame($ceiling, $watcher->getMaxWatchedFilesPerPath());
    }

    /**
     * Invoke the private checksum directly: the digest bytes are otherwise
     * unobservable through the public API (watch/check expose only change
     * verdicts). Precedent: ReflectionMethod::invoke pins elsewhere in tests/.
     */
    private function checksumOf(string $dir): string
    {
        $watcher = new FolderWatcher(LoggerFactory::get(LogChannels::MEDIA));

        return (new ReflectionMethod(FolderWatcher::class, 'calculateDirectoryChecksum'))
            ->invoke($watcher, $dir);
    }

    /**
     * Independently build the documented digest input: one `path:mtime` entry
     * per file under $dir, sorted.
     *
     * @return list<string>
     */
    private function sortedEntriesOf(string $dir): array
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $entries[] = $file->getPathname() . ':' . $file->getMTime();
            }
        }
        sort($entries);

        return $entries;
    }

    /**
     * Extract a method's full source (signature line through closing brace)
     * via reflection-provided line bounds.
     */
    private function methodBodyOf(string $methodName): string
    {
        $method = new ReflectionMethod(FolderWatcher::class, $methodName);
        $lines = file((string) $method->getFileName());
        $this->assertIsArray($lines);

        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix_folderwatcher_ckpt_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
