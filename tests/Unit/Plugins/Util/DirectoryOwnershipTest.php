<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Util;

use Phlix\Plugins\Util\DirectoryOwnership;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Plugins\Util\DirectoryOwnership
 */
final class DirectoryOwnershipTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix_ownership_' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tmpDir)) {
            @system('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    public function test_returns_false_for_missing_directory(): void
    {
        $this->assertFalse(DirectoryOwnership::matchToParent($this->tmpDir . '/nope'));
    }

    public function test_returns_false_for_a_regular_file(): void
    {
        $file = $this->tmpDir . '/plain.txt';
        file_put_contents($file, 'x');

        $this->assertFalse(DirectoryOwnership::matchToParent($file));
    }

    /**
     * A freshly created child of the plugins base dir is written by the SAME
     * user that owns the base dir, so ownership already matches — the common
     * in-worker install path — and matchToParent is a successful no-op. This is
     * the case that must succeed on ordinary (unprivileged) CI where chowning
     * to a different user would be impossible.
     */
    public function test_already_matching_owner_is_a_noop_success(): void
    {
        $base = $this->tmpDir . '/plugins';
        mkdir($base, 0755, true);
        $plugin = $base . '/phlix-plugin-example';
        mkdir($plugin . '/vendor', 0750, true);
        file_put_contents($plugin . '/vendor/autoload.php', '<?php');

        // The test process created both $base and $plugin, so they share an
        // owner — matchToParent should confirm the match without needing any
        // privileged chown.
        $this->assertTrue(DirectoryOwnership::matchToParent($plugin));
    }
}
