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
     * Item 2(b): a directory replaced by a new one at the same path, with the same mode and
     * owner, is caught by the device and inode check. The cache stays off and warns once.
     */
    public function testAReplacedDirectoryIsRefusedAndWarnedOnce(): void
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

        // Move the trusted directory away and put a new one, same name, mode and owner, in its place.
        rename($dir, $this->root . '/swapped-away');
        mkdir($dir, 0700);

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
}
