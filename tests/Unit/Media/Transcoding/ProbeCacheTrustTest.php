<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\ProbeCache;

/**
 * security (finding 3): the ffprobe disk cache is used only from a directory that is a
 * real directory, owned by the process uid, with no group or other write bit. Probe
 * results drive copy/transcode decisions, so a directory another local user can write
 * must disable the cache, not be trusted. Ownership is injected where a foreign-owned
 * directory cannot be created by the test.
 */
final class ProbeCacheTrustTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (!function_exists('posix_getuid')) {
            self::markTestSkipped('The posix extension is needed to check directory ownership.');
        }
        $this->root = sys_get_temp_dir() . '/phlix-probetrust-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $this->root = '';
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . '/*') ?: [] as $entry) {
            $this->removeTree($entry);
        }
        foreach (glob($path . '/.*') ?: [] as $entry) {
            if (basename($entry) !== '.' && basename($entry) !== '..') {
                $this->removeTree($entry);
            }
        }
        @rmdir($path);
    }

    private function uid(): int
    {
        return posix_geteuid();
    }

    /**
     * @return array{streams: array<int, array<string, mixed>>, format: array<string, mixed>}
     */
    private function sampleProbe(): array
    {
        return [
            'streams' => [['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264']],
            'format' => ['duration' => '60.000000', 'format_name' => 'mp4'],
        ];
    }

    public function testAPrivateDirectoryOwnedByThisUserIsAccepted(): void
    {
        $dir = $this->root . '/cache';
        mkdir($dir, 0700);

        self::assertInstanceOf(ProbeCache::class, ProbeCache::createIfSecure($dir));
    }

    public function testAMissingDirectoryIsCreatedPrivateAndAccepted(): void
    {
        $dir = $this->root . '/fresh';

        self::assertInstanceOf(ProbeCache::class, ProbeCache::createIfSecure($dir));
        self::assertSame(0700, fileperms($dir) & 0777);
    }

    public function testASymlinkedDirectoryDisablesTheCache(): void
    {
        $target = $this->root . '/real';
        mkdir($target, 0700);
        $link = $this->root . '/link';
        symlink($target, $link);

        self::assertNull(ProbeCache::createIfSecure($link), 'a symlink is never trusted');
    }

    public function testAGroupWritableDirectoryDisablesTheCache(): void
    {
        $dir = $this->root . '/group';
        mkdir($dir, 0700);
        chmod($dir, 0770);

        self::assertNull(ProbeCache::createIfSecure($dir));
    }

    public function testAWorldWritableDirectoryDisablesTheCache(): void
    {
        $dir = $this->root . '/world';
        mkdir($dir, 0700);
        chmod($dir, 0707);

        self::assertNull(ProbeCache::createIfSecure($dir));
    }

    public function testADirectoryOwnedByAnotherUidDisablesTheCache(): void
    {
        $dir = $this->root . '/foreign';
        mkdir($dir, 0700);

        self::assertNull(ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, null, $this->uid() + 1));
    }

    public function testARegularFileAtThePathDisablesTheCache(): void
    {
        $file = $this->root . '/not-a-dir';
        file_put_contents($file, 'x');

        self::assertNull(ProbeCache::createIfSecure($file));
    }

    public function testAnUntrustedDirectoryNeverReceivesAnEntry(): void
    {
        $dir = $this->root . '/untrusted';
        mkdir($dir, 0700);

        // Constructed directly so the refusal is the per-call check, not the factory.
        $cache = new ProbeCache($dir, ProbeCache::MAX_ENTRIES, null, $this->uid() + 1);
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());

        self::assertSame([], glob($dir . '/*') ?: [], 'nothing is written to an untrusted directory');
        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9));
    }

    public function testADirectoryWidenedAfterBuildIsRefusedOnTheNextCall(): void
    {
        $dir = $this->root . '/widened';
        mkdir($dir, 0700);
        $cache = ProbeCache::createIfSecure($dir);
        self::assertInstanceOf(ProbeCache::class, $cache);
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertNotNull($cache->read('/media/a.mkv', 1, 1, 7, 9), 'trusted while private');

        // Another local user widens the directory after build: the per-call check refuses it.
        chmod($dir, 0777);

        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9), 'a widened directory is never read');
        $cache->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertFileDoesNotExist($dir . '/' . sha1('/media/b.mkv') . '.json');
    }

    /**
     * Finding 1: an in-place widening (same inode, group-write added) is refused, latched and warned once.
     * Restoring the mode does not re-enable the cache, so the refusal is not a silent skip that undoes itself.
     */
    public function testAnInPlaceWidenedDirectoryWarnsOnceAndStaysOff(): void
    {
        $dir = $this->root . '/inplace';
        mkdir($dir, 0700);
        $logger = new ProbeCacheRecordingLogger();
        $cache = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger);
        self::assertInstanceOf(ProbeCache::class, $cache);

        chmod($dir, 0770);
        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9), 'a widened directory is never read');
        $cache->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertNull($cache->read('/media/b.mkv', 1, 1, 7, 9));

        chmod($dir, 0700);
        $cache->write('/media/c.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertNull($cache->read('/media/c.mkv', 1, 1, 7, 9), 'still off after the mode is restored');
        self::assertSame([], glob($dir . '/*') ?: [], 'nothing is written while the cache is off');

        $warnings = $logger->messages('warning');
        self::assertCount(1, $warnings, 'the in-place widening is warned exactly once');
        self::assertStringContainsString('writable by group or other', $warnings[0]);
    }

    /** Item 2(a): a group-writable ancestor without the sticky bit refuses the cache. */
    public function testAGroupWritableAncestorDisablesTheCache(): void
    {
        $parent = $this->root . '/shared';
        mkdir($parent, 0700);
        chmod($parent, 0770);

        self::assertNull(ProbeCache::createIfSecure($parent . '/cache'));
    }

    /** Item 2(a): a sticky ancestor (like /tmp) is not a reason to refuse. */
    public function testAStickyAncestorDoesNotDisableTheCache(): void
    {
        $parent = $this->root . '/sticky';
        mkdir($parent, 0700);
        chmod($parent, 01777);

        self::assertInstanceOf(ProbeCache::class, ProbeCache::createIfSecure($parent . '/cache'));
    }

    /**
     * Item 2(b): a directory replaced by a new one at the same path is caught by the device and
     * inode check. A replacement that fails the checks (here group-writable) is not adopted: the
     * cache stays off and warns once. A replacement that passes them is adopted, see
     * testAReplacedDirectoryThatPassesTheChecksIsAdopted.
     */
    public function testAReplacedDirectoryThatFailsTheChecksLatchesAndWarnsOnce(): void
    {
        $dir = $this->root . '/swapped';
        mkdir($dir, 0700);
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $cache = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger);
        self::assertInstanceOf(ProbeCache::class, $cache);
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertNotNull($cache->read('/media/a.mkv', 1, 1, 7, 9), 'trusted before the swap');

        // Move the trusted directory away and put a new one, same name, in its place. It is
        // group-writable, so it fails the checks and is not adopted.
        rename($dir, $this->root . '/swapped-away');
        mkdir($dir, 0700);
        chmod($dir, 0770);

        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9), 'a replaced directory is never read');
        $cache->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertNull($cache->read('/media/b.mkv', 1, 1, 7, 9));
        self::assertSame([], glob($dir . '/*') ?: [], 'nothing is written into the replacement');
        $replaced = array_filter($logger->warnings, static fn (string $m): bool => str_contains($m, 'replaced'));
        self::assertCount(1, $replaced, 'the replacement is reported once');
    }

    /** Item 4: the refusal names its real cause (owner, symlink), not a generic one. */
    public function testTheRefusalNamesItsCause(): void
    {
        $dir = $this->root . '/named';
        mkdir($dir, 0700);
        $link = $this->root . '/named-link';
        symlink($dir, $link);
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        self::assertNull(ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger, $this->uid() + 1));
        self::assertNull(ProbeCache::createIfSecure($link, ProbeCache::MAX_ENTRIES, $logger));
        self::assertStringContainsString('not owned by this user', $logger->warnings[0] ?? '');
        self::assertStringContainsString('symlink', $logger->warnings[1] ?? '');
    }

    /** Prune runs the same trust check, so an untrusted directory is never pruned. */
    public function testPruneRefusesAnUntrustedDirectory(): void
    {
        $dir = $this->root . '/prune-widened';
        mkdir($dir, 0700);
        $cache = new ProbeCache($dir, 1);
        for ($i = 0; $i < 3; $i++) {
            $cache->write('/media/' . $i . '.mkv', 1, 1, 7, 9, $this->sampleProbe());
        }
        chmod($dir, 0777);

        self::assertSame(0, $cache->prune());
        self::assertCount(3, glob($dir . '/*.json') ?: []);
    }

    /**
     * Finding 3(a): a relative path has no absolute ancestor chain (dirname() stops at "."), so
     * it is refused and logged once, and nothing is created under the working directory. The
     * constructor path refuses writes for the same reason.
     */
    public function testARelativeDirectoryPathIsRefusedAndLoggedOnce(): void
    {
        $name = 'phlix-relative-' . bin2hex(random_bytes(6));
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        self::assertNull(ProbeCache::createIfSecure($name, ProbeCache::MAX_ENTRIES, $logger));
        self::assertDirectoryDoesNotExist(getcwd() . '/' . $name);
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('absolute', $logger->warnings[0]);

        $cache = new ProbeCache($name);
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertDirectoryDoesNotExist(getcwd() . '/' . $name);
        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9));
    }

    /**
     * Finding 3(c): a trailing slash makes lstat follow the link, which would skip the
     * symlink refusal. The slash is dropped first, so the symlink is still refused.
     */
    public function testATrailingSlashOnASymlinkStillDisablesTheCache(): void
    {
        $target = $this->root . '/slash-target';
        mkdir($target, 0700);
        $link = $this->root . '/slash-link';
        symlink($target, $link);
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        self::assertNull(ProbeCache::createIfSecure($link . '/', ProbeCache::MAX_ENTRIES, $logger));
        self::assertStringContainsString('symlink', $logger->warnings[0] ?? '');
    }

    /** Finding 3(b): a symlinked ancestor whose target is group-writable is refused. */
    public function testASymlinkedAncestorWhoseTargetIsGroupWritableIsRefused(): void
    {
        $target = $this->root . '/gw-target';
        mkdir($target, 0700);
        chmod($target, 0770);
        $link = $this->root . '/gw-link';
        symlink($target, $link);

        self::assertNull(ProbeCache::createIfSecure($link . '/cache'));
        self::assertDirectoryDoesNotExist($target . '/cache', 'nothing is created through the link');
    }

    /**
     * Finding 3(b): the walk follows a symlinked ancestor to its target and checks the target's
     * own parents, so a group-writable parent of the target is refused as well.
     */
    public function testASymlinkedAncestorWhoseTargetsParentIsGroupWritableIsRefused(): void
    {
        $shared = $this->root . '/shared-parent';
        mkdir($shared, 0700);
        chmod($shared, 0770);
        $target = $shared . '/target';
        mkdir($target, 0700);
        $link = $this->root . '/to-shared';
        symlink($target, $link);

        self::assertNull(ProbeCache::createIfSecure($link . '/cache'));
        self::assertDirectoryDoesNotExist($target . '/cache', 'nothing is created under the shared parent');
    }

    /**
     * Finding 4: a directory removed while the worker runs is a miss, logged as removed and not
     * as replaced. The next write recreates it, runs the location check again, and records the
     * new identity, so the cache keeps working.
     */
    public function testARemovedDirectoryIsRecreatedAndTrustedAgain(): void
    {
        $dir = $this->root . '/removed';
        mkdir($dir, 0700);
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $cache = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger);
        self::assertInstanceOf(ProbeCache::class, $cache);
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());

        $this->removeTree($dir);
        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9), 'a removed directory is a miss');

        $cache->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertDirectoryExists($dir);
        self::assertSame(0700, fileperms($dir) & 0777, 'recreated private');
        self::assertNotNull($cache->read('/media/b.mkv', 1, 1, 7, 9), 'the recreated directory is used');
        $cache->write('/media/c.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertNotNull($cache->read('/media/c.mkv', 1, 1, 7, 9), 'and it stays trusted');

        $replaced = array_filter($logger->warnings, static fn (string $m): bool => str_contains($m, 'replaced'));
        $removed = array_filter($logger->warnings, static fn (string $m): bool => str_contains($m, 'removed'));
        self::assertCount(0, $replaced, 'a removal is not reported as a replacement');
        self::assertCount(1, $removed, 'the recreation is reported once, as a removal');
    }

    /**
     * Finding 4: when a removed directory's location is no longer trusted (its parent became
     * group-writable), the recreation is refused, nothing is created, and the refusal is logged once.
     */
    public function testARemovedDirectoryWhoseLocationIsNoLongerTrustedStaysOff(): void
    {
        $parent = $this->root . '/loc';
        mkdir($parent, 0700);
        $dir = $parent . '/cache';
        mkdir($dir, 0700);
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $cache = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger);
        self::assertInstanceOf(ProbeCache::class, $cache);

        $this->removeTree($dir);
        chmod($parent, 0770);
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());
        $cache->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());

        self::assertDirectoryDoesNotExist($dir, 'nothing is recreated under an untrusted location');
        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9));
        self::assertCount(1, $logger->warnings, 'the refusal is logged once');
        self::assertStringContainsString('sticky bit', $logger->warnings[0] ?? '');
    }

    /**
     * Item 1: two instances over one directory. The first removes the directory and recreates it,
     * so it has a new inode. The second still holds the old identity, so its next check must adopt
     * the new inode, log that once at info, and keep working, instead of latching until restart.
     */
    public function testASecondInstanceAdoptsADirectoryRecreatedByTheFirst(): void
    {
        $dir = $this->root . '/shared';
        mkdir($dir, 0700);
        $loggerA = new ProbeCacheRecordingLogger();
        $loggerB = new ProbeCacheRecordingLogger();
        $first = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $loggerA);
        $second = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $loggerB);
        self::assertInstanceOf(ProbeCache::class, $first);
        self::assertInstanceOf(ProbeCache::class, $second);
        $second->write('/media/before.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertNotNull($second->read('/media/before.mkv', 1, 1, 7, 9), 'trusted before the removal');

        // The old directory is renamed aside, not just removed: a freed inode number can be reused
        // at once, which would make the new directory look identical. Renaming keeps the inode alive
        // until the new directory exists, so the new inode is certainly different.
        rename($dir, $this->root . '/shared-away');
        $first->write('/media/after.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertDirectoryExists($dir);
        $this->removeTree($this->root . '/shared-away');

        // The second instance sees a new inode on its next check and adopts it.
        $second->write('/media/second.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertNotNull($second->read('/media/after.mkv', 1, 1, 7, 9), 'the second reads the first\'s entry');
        self::assertNotNull($second->read('/media/second.mkv', 1, 1, 7, 9), 'the second writes into the new directory');
        self::assertNotNull($first->read('/media/second.mkv', 1, 1, 7, 9), 'and the first reads it back');
        self::assertSame([], $loggerB->messages('warning'), 'adoption is not a warning');
        self::assertCount(1, $loggerB->messages('info'), 'adoption is logged once at info');
    }

    /**
     * Item 1: the same recreate, but the new directory is group-writable. The second instance
     * runs the checks on the new inode, so it latches, reads nothing, writes nothing and warns once.
     */
    public function testARecreatedGroupWritableDirectoryLatchesTheSecondInstance(): void
    {
        $dir = $this->root . '/shared-bad';
        mkdir($dir, 0700);
        $logger = new ProbeCacheRecordingLogger();
        $second = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger);
        self::assertInstanceOf(ProbeCache::class, $second);

        // Renamed aside first, so the new directory has a new inode (see the test above).
        rename($dir, $this->root . '/shared-bad-away');
        mkdir($dir, 0700);
        chmod($dir, 0770);

        self::assertNull($second->read('/media/a.mkv', 1, 1, 7, 9));
        $second->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertSame([], glob($dir . '/*') ?: [], 'nothing is written into the group-writable directory');
        $warnings = $logger->messages('warning');
        self::assertCount(1, $warnings, 'the refusal is warned once');
        self::assertStringContainsString('replaced after the trust check', $warnings[0]);
        self::assertStringContainsString('writable by group or other', $warnings[0]);
    }

    /**
     * Item 1: a replacement that passes the same checks a fresh build runs is adopted by the
     * cache that already holds the old identity: it is read, written, and logged at info.
     */
    public function testAReplacedDirectoryThatPassesTheChecksIsAdopted(): void
    {
        $dir = $this->root . '/adopt';
        mkdir($dir, 0700);
        $logger = new ProbeCacheRecordingLogger();
        $cache = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger);
        self::assertInstanceOf(ProbeCache::class, $cache);
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());

        rename($dir, $this->root . '/adopt-away');
        mkdir($dir, 0700);

        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9), 'the empty replacement holds no entry');
        $cache->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertNotNull($cache->read('/media/b.mkv', 1, 1, 7, 9), 'the adopted replacement is used');
        self::assertSame([], $logger->messages('warning'));
        self::assertCount(1, $logger->messages('info'));
    }

    /**
     * Item 3: a sticky ancestor is accepted only when root or the expected uid owns it, because the
     * owner of a sticky directory can rename or unlink the entries in it. The expected uid is injected,
     * since the test user cannot create a root-owned directory. (A root-owned sticky /tmp is accepted by
     * the other tests, which run under it.)
     */
    public function testAStickyAncestorOwnedByAnotherUidIsRefused(): void
    {
        $parent = $this->root . '/sticky-other';
        mkdir($parent, 0700);
        chmod($parent, 01777);
        $logger = new ProbeCacheRecordingLogger();

        self::assertNull(
            ProbeCache::createIfSecure($parent . '/cache', ProbeCache::MAX_ENTRIES, $logger, $this->uid() + 1)
        );
        self::assertDirectoryDoesNotExist($parent . '/cache', 'nothing is created under the refused ancestor');
        $warnings = $logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('sticky bit', $warnings[0]);
        self::assertStringContainsString('sticky-other', $warnings[0], 'the refusal names the ancestor');
    }

    /**
     * Item 4: a "." or ".." segment is refused, not normalised. lstat follows a link through "."
     * (for example /x/link/.), so the symlink refusal would otherwise be skipped.
     */
    public function testADotSegmentInTheConfiguredPathIsRefused(): void
    {
        $target = $this->root . '/dot-target';
        mkdir($target, 0700);
        $link = $this->root . '/dot-link';
        symlink($target, $link);
        $logger = new ProbeCacheRecordingLogger();

        self::assertNull(ProbeCache::createIfSecure($link . '/.', ProbeCache::MAX_ENTRIES, $logger));
        self::assertNull(
            ProbeCache::createIfSecure($target . '/../cache', ProbeCache::MAX_ENTRIES, $logger)
        );
        self::assertDirectoryDoesNotExist($target . '/cache', 'nothing is created through the dot segment');
        self::assertDirectoryDoesNotExist($this->root . '/cache', 'nothing is created at the resolved location');
        $warnings = $logger->messages('warning');
        self::assertCount(2, $warnings, 'each refusal is warned once');
        self::assertStringContainsString('segment', $warnings[0]);

        // A constructed instance refuses on every call, so nothing reaches the target through the link.
        $cache = new ProbeCache($link . '/.');
        $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());
        self::assertSame([], glob($target . '/*') ?: [], 'nothing is written through the dot segment');
        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9));
    }

    /**
     * Item 6: a read on a removed directory is a miss, and it is logged at debug on that read. It
     * is not silent until the next write.
     */
    public function testAReadOnARemovedDirectoryIsLoggedAtDebug(): void
    {
        $dir = $this->root . '/silent';
        mkdir($dir, 0700);
        $logger = new ProbeCacheRecordingLogger();
        $cache = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger);
        self::assertInstanceOf(ProbeCache::class, $cache);

        $this->removeTree($dir);
        self::assertNull($cache->read('/media/a.mkv', 1, 1, 7, 9));

        $debug = $logger->messages('debug');
        self::assertCount(1, $debug, 'the removal read is logged at debug');
        self::assertStringContainsString('missing', $debug[0]);
        self::assertSame([], $logger->messages('warning'), 'a read is not a warning');
    }

    /**
     * Item 9 and finding 4: a recreate whose mkdir fails (here the parent is not writable) is not retried
     * on every probe miss. It is held back for RECREATE_BACKOFF_SECONDS. The first failure of the instance
     * logs one warning. Failures in later backoff windows log at debug, so the warning count stays at one
     * across two windows. The window is expired through its monotonic-clock field, so the test does not wait.
     */
    public function testAFailedRecreateBacksOffAndWarnsOnceForTheInstance(): void
    {
        $parent = $this->root . '/backoff';
        mkdir($parent, 0700);
        $dir = $parent . '/cache';
        mkdir($dir, 0700);
        $logger = new ProbeCacheRecordingLogger();
        $cache = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger);
        self::assertInstanceOf(ProbeCache::class, $cache);
        $until = new \ReflectionProperty(ProbeCache::class, 'recreateBackoffUntil');

        $this->removeTree($dir);
        chmod($parent, 0500);
        try {
            $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());
            $cache->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());
            self::assertCount(
                1,
                $logger->messages('warning'),
                'one warning for the failed recreate, none inside the window'
            );

            // The parent is writable again, but the window is still open: no recreate is attempted.
            chmod($parent, 0700);
            $cache->write('/media/c.mkv', 1, 1, 7, 9, $this->sampleProbe());
            self::assertDirectoryDoesNotExist($dir, 'still backing off, so the recreate is not attempted');

            // Second window, the parent still not writable: the retry fails again. It is held back again
            // and logged at debug, so the warning count does not grow.
            chmod($parent, 0500);
            $until->setValue($cache, 0);
            $cache->write('/media/d.mkv', 1, 1, 7, 9, $this->sampleProbe());
            self::assertDirectoryDoesNotExist($dir);
            self::assertCount(1, $logger->messages('warning'), 'a second failed window does not warn again');
            $stillMissing = array_filter(
                $logger->messages('debug'),
                static fn (string $m): bool => str_contains($m, 'still missing'),
            );
            self::assertCount(1, $stillMissing, 'the second failed attempt is logged at debug');

            // The parent is writable again and the window expires: the recreate succeeds.
            chmod($parent, 0700);
            $until->setValue($cache, 0);
            $cache->write('/media/e.mkv', 1, 1, 7, 9, $this->sampleProbe());
        } finally {
            chmod($parent, 0700);
        }

        self::assertDirectoryExists($dir);
        self::assertNotNull($cache->read('/media/e.mkv', 1, 1, 7, 9));
    }

    /**
     * Finding 2: a recreate that succeeds re-arms the warn-once latch. The first outage (the parent is not
     * writable, so mkdir fails) warns once. A later recovery recreates the directory. A second outage must
     * warn again, not log at debug only, so a later permanent failure stays visible for the life of the worker.
     * The backoff window is expired through its monotonic-clock field, so the test does not wait.
     */
    public function testASecondOutageAfterARecoveryWarnsOnceAgain(): void
    {
        $parent = $this->root . '/rearm';
        mkdir($parent, 0700);
        $dir = $parent . '/cache';
        mkdir($dir, 0700);
        $logger = new ProbeCacheRecordingLogger();
        $cache = ProbeCache::createIfSecure($dir, ProbeCache::MAX_ENTRIES, $logger);
        self::assertInstanceOf(ProbeCache::class, $cache);
        $until = new \ReflectionProperty(ProbeCache::class, 'recreateBackoffUntil');
        $failedRecreates = static fn (ProbeCacheRecordingLogger $l): array => array_values(array_filter(
            $l->messages('warning'),
            static fn (string $m): bool => str_contains($m, 'could not be recreated'),
        ));

        try {
            // First outage: the parent is not writable, so the recreate fails and warns once.
            $this->removeTree($dir);
            chmod($parent, 0500);
            $cache->write('/media/a.mkv', 1, 1, 7, 9, $this->sampleProbe());
            self::assertCount(1, $failedRecreates($logger), 'the first outage warns once');

            // Recovery: the parent is writable again and the window expires, so the recreate succeeds.
            chmod($parent, 0700);
            $until->setValue($cache, 0);
            $cache->write('/media/b.mkv', 1, 1, 7, 9, $this->sampleProbe());
            self::assertDirectoryExists($dir, 'the recovery recreates the directory');
            self::assertNotNull($cache->read('/media/b.mkv', 1, 1, 7, 9), 'and it is used again');

            // Second outage: the directory is removed again and the parent is not writable again.
            $this->removeTree($dir);
            chmod($parent, 0500);
            $cache->write('/media/c.mkv', 1, 1, 7, 9, $this->sampleProbe());
            self::assertCount(
                2,
                $failedRecreates($logger),
                'the second outage warns again, because the recovery re-armed the warning'
            );
        } finally {
            chmod($parent, 0700);
        }
    }
}
