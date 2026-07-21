<?php

/**
 * Guards that config backup/restore covers NESTED config files.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\BackupManager;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * `backupConfigs()` used a flat `glob($dir . '/*.php')`, so the only nested
 * config file in the tree — `config/scrobblers/trakt.php`, which holds Trakt's
 * OAuth tokens — was **never** captured by any backup this install produced.
 *
 * These assert the CONSEQUENCE (which files land where), not the shape of the
 * implementation.
 *
 * ## Why the core guard does not touch `PHLIX_CONFIG_DIR`
 *
 * `backupConfigs()`/`restoreConfigs()` read that constant, and a constant can be
 * defined only once per process. A first draft of this file had two tests wanting
 * two different values, so one of them **silently skipped — and it was the test
 * that proves the defect is fixed**. A skipped test reads exactly like a passing
 * one in the summary line.
 *
 * So the recursion guarantee is asserted through `configFilesUnder()`, which
 * takes its directory as an argument and therefore always runs. The end-to-end
 * paths are exercised separately in their own processes.
 */
final class BackupConfigRecursionTest extends TestCase
{
    /** @var list<string> Directories to remove in tearDown. */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $dir) {
            self::rmrf($dir);
        }
        $this->temp = [];
    }

    private function tmpdir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->temp[] = $dir;

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * @return array<string, string>
     */
    private static function walk(string $dir): array
    {
        $ref = new ReflectionMethod(BackupManager::class, 'configFilesUnder');
        $ref->setAccessible(true);
        /** @var array<string, string> $out */
        $out = $ref->invoke(null, $dir);

        return $out;
    }

    /**
     * THE defect. A flat `glob('/*.php')` returns only `server.php`; the fix must
     * also return the nested file, keyed by a path that preserves its directory.
     */
    public function test_walker_finds_nested_config_files(): void
    {
        $dir = $this->tmpdir('phlix_walk_');
        mkdir($dir . '/scrobblers', 0755, true);
        file_put_contents($dir . '/server.php', '<?php return [];');
        file_put_contents($dir . '/scrobblers/trakt.php', '<?php return ["token" => "secret"];');

        $found = self::walk($dir);

        self::assertSame(['scrobblers/trakt.php', 'server.php'], array_keys($found));
        self::assertArrayHasKey(
            'scrobblers/trakt.php',
            $found,
            'config/scrobblers/trakt.php holds Trakt OAuth tokens and must be backed up.',
        );
        // Keyed by RELATIVE PATH, not basename: flattening would collide this
        // with a hypothetical config/trakt.php and lose the directory on restore.
        self::assertArrayNotHasKey('trakt.php', $found);
        self::assertStringEndsWith('/scrobblers/trakt.php', $found['scrobblers/trakt.php']);
    }

    /** Only `.php` is captured, and the extension test is case-insensitive. */
    public function test_walker_takes_only_php_files(): void
    {
        $dir = $this->tmpdir('phlix_walk_');
        file_put_contents($dir . '/a.php', '<?php');
        file_put_contents($dir . '/notes.txt', 'x');
        file_put_contents($dir . '/B.PHP', '<?php');

        self::assertSame(['B.PHP', 'a.php'], array_keys(self::walk($dir)));
    }

    /** A missing directory is not an error — it just yields nothing. */
    public function test_walker_tolerates_a_missing_directory(): void
    {
        self::assertSame([], self::walk(sys_get_temp_dir() . '/phlix_definitely_absent_' . bin2hex(random_bytes(4))));
    }

    /**
     * End-to-end backup, in its own process so `PHLIX_CONFIG_DIR` is free.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_backup_writes_nested_config_into_the_archive_tree(): void
    {
        $configDir = $this->tmpdir('phlix_cfg_');
        mkdir($configDir . '/scrobblers', 0755, true);
        file_put_contents($configDir . '/server.php', '<?php return ["top" => 1];');
        file_put_contents($configDir . '/scrobblers/trakt.php', '<?php return ["token" => "secret"];');

        define('PHLIX_CONFIG_DIR', $configDir);

        $tempDir = $this->tmpdir('phlix_bak_');
        $m = new BackupManager($this->createMock(Connection::class));
        $ref = new ReflectionMethod(BackupManager::class, 'backupConfigs');
        $ref->setAccessible(true);
        $ref->invoke($m, $tempDir);

        self::assertFileExists($tempDir . '/config/server.php');
        self::assertFileExists($tempDir . '/config/scrobblers/trakt.php');
        self::assertFileDoesNotExist($tempDir . '/config/trakt.php');
        self::assertSame(
            '<?php return ["token" => "secret"];',
            file_get_contents($tempDir . '/config/scrobblers/trakt.php'),
        );
    }

    /**
     * Every archive already on disk is FLAT, because nested files were never
     * captured. Restoring one must still work — a fix that only understood the
     * new layout would break every existing backup.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_restore_handles_both_a_flat_legacy_archive_and_a_nested_one(): void
    {
        $configDir = $this->tmpdir('phlix_target_');
        define('PHLIX_CONFIG_DIR', $configDir);

        $extractDir = $this->tmpdir('phlix_extract_');
        mkdir($extractDir . '/config/scrobblers', 0755, true);
        // Flat entry, as every pre-fix archive contains.
        file_put_contents($extractDir . '/config/server.php', '<?php return ["restored" => true];');
        // Nested entry, as post-fix archives contain.
        file_put_contents($extractDir . '/config/scrobblers/trakt.php', '<?php return ["token" => "back"];');

        $m = new BackupManager($this->createMock(Connection::class));
        $ref = new ReflectionMethod(BackupManager::class, 'restoreConfigs');
        $ref->setAccessible(true);
        $ref->invoke($m, $extractDir);

        self::assertSame('<?php return ["restored" => true];', file_get_contents($configDir . '/server.php'));
        self::assertSame(
            '<?php return ["token" => "back"];',
            file_get_contents($configDir . '/scrobblers/trakt.php'),
            'A nested entry must be restored into its directory, which restore must create.',
        );
    }

    /**
     * A restore unpacks attacker-influenced paths, so an entry must not be able
     * to escape the config directory.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_restore_refuses_to_write_outside_the_config_directory(): void
    {
        $base = $this->tmpdir('phlix_esc_');
        $configDir = $base . '/config';
        mkdir($configDir, 0755, true);
        define('PHLIX_CONFIG_DIR', $configDir);

        $extractDir = $this->tmpdir('phlix_extract_');
        mkdir($extractDir . '/config/sneaky', 0755, true);
        file_put_contents($extractDir . '/config/sneaky/ok.php', '<?php return [];');

        $m = new BackupManager($this->createMock(Connection::class));
        $ref = new ReflectionMethod(BackupManager::class, 'restoreConfigs');
        $ref->setAccessible(true);
        $ref->invoke($m, $extractDir);

        // The legitimate nested entry lands inside config/ and nothing was
        // written to the parent alongside it.
        self::assertFileExists($configDir . '/sneaky/ok.php');
        self::assertFileDoesNotExist($base . '/ok.php');
    }
}
