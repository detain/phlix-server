<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Fs;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Fs\LibraryRootGuard;

/**
 * Unit tests for the central library-root path jail.
 *
 * @covers \Phlix\Common\Fs\LibraryRootGuard
 * @since 2.2.0
 */
class LibraryRootGuardTest extends TestCase
{
    /** @var string|false */
    private $prevRoots = false;

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        $this->tempDir = sys_get_temp_dir() . '/phlix_guard_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        LibraryRootGuard::reset();
    }

    protected function tearDown(): void
    {
        LibraryRootGuard::reset();
        if ($this->prevRoots === false) {
            putenv('PHLIX_LIBRARY_ROOTS');
        } else {
            putenv('PHLIX_LIBRARY_ROOTS=' . $this->prevRoots);
        }

        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testAcceptsFileWithinConfiguredRoot(): void
    {
        putenv('PHLIX_LIBRARY_ROOTS=' . $this->tempDir);
        LibraryRootGuard::reset();

        $file = $this->tempDir . '/movie.mp4';
        file_put_contents($file, 'data');

        $this->assertTrue(LibraryRootGuard::assertWithinLibraryRoots($file));
    }

    public function testRejectsFileOutsideConfiguredRoot(): void
    {
        putenv('PHLIX_LIBRARY_ROOTS=' . $this->tempDir);
        LibraryRootGuard::reset();

        // /etc/passwd is a real, readable file but not under the configured root.
        $this->assertFalse(LibraryRootGuard::assertWithinLibraryRoots('/etc/passwd'));
    }

    public function testRejectsTraversalEscapingTheRoot(): void
    {
        putenv('PHLIX_LIBRARY_ROOTS=' . $this->tempDir);
        LibraryRootGuard::reset();

        // ../-traversal out of the root resolves (via realpath) to /etc/passwd,
        // which is outside the jail and must be rejected.
        $escape = $this->tempDir . '/../../../../etc/passwd';
        $this->assertFalse(LibraryRootGuard::assertWithinLibraryRoots($escape));
    }

    public function testRejectsNonExistentPath(): void
    {
        putenv('PHLIX_LIBRARY_ROOTS=' . $this->tempDir);
        LibraryRootGuard::reset();

        $this->assertFalse(
            LibraryRootGuard::assertWithinLibraryRoots($this->tempDir . '/does-not-exist.mp4')
        );
    }

    public function testRejectsEmptyPath(): void
    {
        $this->assertFalse(LibraryRootGuard::assertWithinLibraryRoots(''));
    }

    public function testSiblingPrefixDoesNotEscape(): void
    {
        // A sibling directory that shares a textual prefix with the root
        // (e.g. "<tmp>foo" vs root "<tmp>") must NOT be considered contained.
        $root = $this->tempDir . '/lib';
        mkdir($root, 0755, true);
        $sibling = $this->tempDir . '/lib-backup';
        mkdir($sibling, 0755, true);
        $siblingFile = $sibling . '/secret.txt';
        file_put_contents($siblingFile, 'secret');

        putenv('PHLIX_LIBRARY_ROOTS=' . $root);
        LibraryRootGuard::reset();

        $this->assertFalse(LibraryRootGuard::assertWithinLibraryRoots($siblingFile));

        unlink($siblingFile);
        rmdir($sibling);
        rmdir($root);
    }

    public function testExplicitRootsTakePrecedenceOverEnv(): void
    {
        putenv('PHLIX_LIBRARY_ROOTS=/nonexistent-root-xyz');
        LibraryRootGuard::setRoots([$this->tempDir]);

        $file = $this->tempDir . '/photo.jpg';
        file_put_contents($file, 'data');

        $this->assertTrue(LibraryRootGuard::assertWithinLibraryRoots($file));
    }

    public function testSetRootsWithOnlyNonResolvingEntriesFallsBack(): void
    {
        // Non-resolving explicit roots are dropped, so resolution falls through
        // to the env var (here set to our real temp dir).
        putenv('PHLIX_LIBRARY_ROOTS=' . $this->tempDir);
        LibraryRootGuard::setRoots(['/this/does/not/exist']);

        $file = $this->tempDir . '/x.mp4';
        file_put_contents($file, 'data');

        $this->assertTrue(LibraryRootGuard::assertWithinLibraryRoots($file));
    }
}
