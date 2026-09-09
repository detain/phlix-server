<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\RelayStateStore;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\Admin\AdminHubController;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regression tests for {@see Application::resolveConfigDir()} and the
 * absolute-only guards added to its consumers (S211).
 *
 * ## The defect these pin
 *
 * Production never set `_config_dir`, so every former read site in
 * Application.php fell through `?? 'config'` to a RELATIVE path resolved
 * against the process CWD. A daemon started outside
 * `WorkingDirectory=/var/www/phlix` wrote the operator relay kill-switch
 * (`relay-control.json`) via {@see RelayStateStore::writeState()}'s silent
 * `@mkdir` into a stray `./config/` the relay fork never reads — a success
 * response for a dead lever.
 *
 * Every assertion here checks an OBSERVABLE consequence under a foreign CWD
 * (chdir to a fresh temp dir): resolution stays absolute and lands inside the
 * install root; writer (AdminHubController path) and reader (HubServicesProvider
 * fork path) agree on the SAME file; relative inputs fail loudly instead of
 * resolving CWD-wise; and the last test is a comment-stripped source guard
 * pinning that no relative `?? 'config'` fallback shape survives anywhere in
 * src/ (S345 lesson: match against `php_strip_whitespace()` so docblocks
 * cannot recreate the forbidden string), with a positive control proving the
 * pattern still bites.
 */
class ApplicationConfigDirResolutionTest extends TestCase
{
    public const string SURVIVAL_TOKEN = 'S211CFGDIRABSX7K7';

    /**
     * Build an Application without running the heavy route-loading constructor,
     * matching the convention in the sibling Application tests.
     *
     * @param array<string, mixed> $config
     */
    private function makeApp(array $config): Application
    {
        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();
        $p = $ref->getProperty('config');
        $p->setAccessible(true);
        $p->setValue($app, $config);

        return $app;
    }

    /**
     * PRIMARY proof: with the process CWD OUTSIDE the install root, resolving
     * the REAL production config (exactly what start.php includes) yields the
     * repo's own absolute `config/` — the identical string `HubServicesProvider`
     * binds the fork-side {@see RelayStateStore} on — never a CWD-relative path.
     *
     * Read-only: nothing is written into the repo tree here.
     */
    public function test_resolution_with_cwd_outside_the_install_root_yields_absolute_repo_config(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        /** @var array<string, mixed> $config */
        $config = require $repoRoot . '/config/server.php';
        // Production never sets the test seam; assert that fact, then honour it.
        self::assertArrayNotHasKey('_config_dir', $config, 'start.php must never set _config_dir.');
        unset($config['_config_dir']);

        self::assertIsArray($config['hub']);
        $hubDir = is_array($config['hub']) && is_string($config['hub']['config_dir'] ?? null)
            ? $config['hub']['config_dir']
            : '';
        self::assertNotSame('', $hubDir, 'config/server.php must compose an absolute hub.config_dir.');

        $repoConfig = realpath($repoRoot . '/config');
        self::assertNotFalse($repoConfig);

        $cwd0 = (string) getcwd();
        $tmp = sys_get_temp_dir() . '/s211-cwd-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);

        try {
            chdir($tmp);
            $resolved = $this->makeApp($config)->resolveConfigDir();

            // Absolute by construction.
            self::assertTrue(str_starts_with($resolved, '/'), "Resolved dir must be absolute, got '{$resolved}'.");
            // …and it is THE repo config dir, not a CWD ghost.
            self::assertSame($repoConfig, realpath($resolved));
            // Writer == fork-reader agreement: the resolver hands back the very
            // value HubServicesProvider passes to the fork-side RelayStateStore.
            self::assertSame(rtrim($hubDir, '/'), $resolved);
            // Positive control that the OLD bug is dead: had resolution stayed
            // relative 'config', the foreign CWD would have made it this ghost.
            self::assertNotSame($tmp . '/config', $resolved);
            self::assertNotSame(realpath($resolved), realpath($tmp . '/config'));
        } finally {
            chdir($cwd0);
            @rmdir($tmp);
        }
    }

    /**
     * End-to-end cross-process bridge proof against a fake install: with the CWD
     * somewhere else entirely, the writer store built from the resolver (the
     * AdminHubController path after S211) and the reader store built from the
     * raw absolute `hub.config_dir` (the HubServicesProvider fork path) land on
     * the SAME `relay-control.json`, and no stray `./config/` appears at the CWD.
     */
    public function test_writer_and_relay_fork_reader_agree_on_kill_switch_file_under_foreign_cwd(): void
    {
        $tmp = sys_get_temp_dir() . '/s211-cwd-' . bin2hex(random_bytes(6));
        $fakeCfg = $tmp . '/cfg';
        $elsewhere = $tmp . '/elsewhere';
        mkdir($fakeCfg, 0700, true);
        mkdir($elsewhere, 0700, true);

        $cwd0 = (string) getcwd();

        try {
            chdir($elsewhere);
            $app = $this->makeApp(['hub' => ['config_dir' => $fakeCfg]]);

            // Branch 2 returns the arbitrary absolute dir untouched — CWD cannot bend it.
            self::assertSame($fakeCfg, $app->resolveConfigDir());

            $writer = new RelayStateStore($app->resolveConfigDir());
            $reader = new RelayStateStore((string) $fakeCfg);

            self::assertTrue($writer->setRelayDisabled(true));
            self::assertTrue($reader->isRelayDisabled(), 'Fork-side reader must see the worker-side write.');

            // The write landed in the fake install's config dir, not a stray CWD dir.
            $controlFile = $fakeCfg . '/' . RelayStateStore::RELAY_CONTROL_FILE;
            self::assertFileExists($controlFile);
            self::assertSame(realpath($fakeCfg), realpath(dirname($controlFile)));
            self::assertDirectoryDoesNotExist($elsewhere . '/config');
        } finally {
            chdir($cwd0);
            @unlink($fakeCfg . '/' . RelayStateStore::RELAY_CONTROL_FILE);
            foreach (glob($fakeCfg . '/*') ?: [] as $stray) {
                @unlink($stray);
            }
            @rmdir($fakeCfg);
            @rmdir($elsewhere);
            @rmdir($tmp);
        }
    }

    /**
     * With neither the test seam nor `hub` configured, the resolver falls to its
     * DOCUMENTED ABSOLUTE default — the repo's own `config/`, derived from this
     * file's location — even when the CWD is a foreign temp dir. The old code
     * returned the relative string 'config' here.
     */
    public function test_documented_absolute_default_when_nothing_is_configured(): void
    {
        $cwd0 = (string) getcwd();
        $tmp = sys_get_temp_dir() . '/s211-cwd-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);

        try {
            chdir($tmp);
            $resolved = $this->makeApp([])->resolveConfigDir();

            self::assertTrue(str_starts_with($resolved, '/'));
            // Application.php lives at src/Server/Core; dirname(__DIR__, 3) from
            // there is the repo root, which from THIS test file is dirname(__DIR__, 4).
            self::assertSame(dirname(__DIR__, 4) . '/config', $resolved);
        } finally {
            chdir($cwd0);
            @rmdir($tmp);
        }
    }

    /**
     * The test seam still wins over every other branch and is trimmed of a
     * trailing slash (the resolver touches no filesystem).
     */
    public function test_test_seam_absolute_still_wins_and_is_trimmed(): void
    {
        $tmp = sys_get_temp_dir() . '/s211-cwd-' . bin2hex(random_bytes(6));

        $resolved = $this->makeApp(['_config_dir' => $tmp . '/cfg/'])->resolveConfigDir();

        self::assertSame($tmp . '/cfg', $resolved);
    }

    /**
     * A relative seam value is a test bug: it must throw, never resolve CWD-wise.
     */
    public function test_relative_test_seam_fails_loud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute/');

        $this->makeApp(['_config_dir' => 'config'])->resolveConfigDir();
    }

    /**
     * A relative `hub.config_dir` is a misconfiguration: loud-fail beats CWD
     * dependence.
     */
    public function test_relative_hub_config_dir_fails_loud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute/');

        $this->makeApp(['hub' => ['config_dir' => './config']])->resolveConfigDir();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function relativeOrEmptyDirProvider(): array
    {
        return [
            'empty' => [''],
            'relative' => ['config'],
            'relative-dot' => ['./config'],
        ];
    }

    /**
     * The store is the shared cross-process bridge; empty or relative dirs are
     * refused at construction so writer and reader can never diverge per-CWD.
     */
    #[DataProvider('relativeOrEmptyDirProvider')]
    public function test_relay_state_store_refuses_relative_and_empty_dirs(string $dir): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute/');

        new RelayStateStore($dir);
    }

    /**
     * The sole kill-switch writer defaults to an absolute repo `config/` derived
     * from its own file location, and refuses a relative injection outright.
     */
    public function test_admin_hub_controller_default_is_absolute_and_relative_refused(): void
    {
        $prop = new \ReflectionProperty(AdminHubController::class, 'configDir');
        $prop->setAccessible(true);
        $default = $prop->getValue(new AdminHubController());

        self::assertIsString($default);
        self::assertTrue(str_starts_with($default, '/'), "Default configDir must be absolute, got '{$default}'.");
        self::assertSame(realpath(dirname(__DIR__, 4) . '/config'), realpath($default));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute/');

        new AdminHubController(null, 'config');
    }

    /**
     * SECONDARY source guard: the relative fallback shapes this step removed may
     * not reappear anywhere under src/ or in start.php. Matched against
     * COMMENT-STRIPPED code (php_strip_whitespace) so explanatory docblocks that
     * QUOTE the old bug (including resolveConfigDir()'s own) cannot trip it, and
     * closed with a positive control proving the pattern still matches the bug
     * shape it forbids (S345 rule 3).
     */
    public function test_no_relative_config_dir_fallback_survives_in_source(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $paths = [$repoRoot . '/start.php'];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repoRoot . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        $seamFiles = [];
        $appPath = $repoRoot . '/src/Server/Core/Application.php';
        foreach ($paths as $path) {
            $code = (string) php_strip_whitespace($path);
            self::assertSame(
                0,
                preg_match("/_config_dir.{0,120}\?\?\s*'config'/s", $code),
                "Relative `_config_dir ?? 'config'` fallback resurrected in {$path}.",
            );
            if (str_contains($code, '_config_dir')) {
                $seamFiles[] = $path;
                if ($path === $appPath) {
                    self::assertSame(0, preg_match("/\?\?\s*'config'/", $code));
                    self::assertSame(0, preg_match("/: 'config'/", $code));
                }
            }
        }

        // The test seam may be read in exactly ONE first-party source file.
        self::assertSame([$appPath], $seamFiles);

        // Positive control: the guard's pattern bites the very shape it forbids.
        self::assertSame(
            1,
            preg_match("/_config_dir.{0,120}\?\?\s*'config'/s", "<?php\n\$d = \$c['_config_dir'] ?? 'config';\n"),
        );
    }
}
