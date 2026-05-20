<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Util;

use Phlix\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Plugins\Util\RecursiveDelete
 */
final class RecursiveDeleteTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix_recdel_' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tmpDir)) {
            @system('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    public function test_remove_no_op_for_missing_path(): void
    {
        $this->expectNotToPerformAssertions();
        RecursiveDelete::remove($this->tmpDir . '/does-not-exist');
    }

    public function test_remove_deletes_single_file(): void
    {
        $file = $this->tmpDir . '/foo.txt';
        file_put_contents($file, 'hi');

        RecursiveDelete::remove($file);

        $this->assertFileDoesNotExist($file);
    }

    public function test_remove_deletes_directory_tree(): void
    {
        $root = $this->tmpDir . '/tree';
        mkdir($root . '/a/b/c', 0775, true);
        file_put_contents($root . '/top.txt', 'x');
        file_put_contents($root . '/a/b/c/leaf.txt', 'y');

        RecursiveDelete::remove($root);

        $this->assertDirectoryDoesNotExist($root);
    }
}
