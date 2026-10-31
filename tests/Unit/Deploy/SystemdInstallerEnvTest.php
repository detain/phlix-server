<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Deploy;

use Phlix\Common\Container\Providers\AuthServicesProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review server__devops M11 — the minimal installer must produce a BOOTING
 * service, not a crash-looping one.
 *
 * The pre-M11 /etc/phlix/env template in install/systemd.sh (a) omitted
 * `JWT_SECRET` entirely, so start.php's boot guard exit(1)s and
 * `Restart=on-failure` spins forever; (b) wrote only the `PHLIX_DATABASE_*`
 * aliases for the DB, which config/database.php never reads; (c) carried
 * `PHLIX_SECRET_KEY` (no reader on this carrier — docker-only input) and
 * `PHLIX_DATABASE_PORT`; (d) its header comment claimed the live keys were
 * "placeholders for the uninstaller and forward-compat".
 *
 * These tests parse the REAL heredoc template and the REAL helper functions
 * out of install/systemd.sh (CON-53-style pins — nothing here is a fixture
 * that can drift from the shipped script) and exercise the generator and the
 * legacy backfill in real bash.
 */
final class SystemdInstallerEnvTest extends TestCase
{
    /**
     * config/database.php reads the DB_* keys and start.php's boot guard
     * hard-requires JWT_SECRET — without these the service cannot come up.
     */
    private const BOOT_REQUIRED_KEYS = ['JWT_SECRET', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];

    /** Claims the pre-M11 header made that the code contradicts. */
    private const LIE_PHRASES = [
        'Only DB_PASSWORD',
        'placeholders for',
        'forward-compat',
        'fill in DB_PASSWORD and PHLIX_SECRET_KEY',
    ];

    /**
     * Replica of what the pre-M11 template produced (an operator who filled in
     * the DB password but could not know about the missing JWT_SECRET). Used to
     * prove the backfill heals legacy files.
     */
    private const LEGACY_ENV_REPLICA = "# Phlix Media Server environment.\n"
        . "DB_PASSWORD=operator-set-value\n"
        . "PHLIX_DATABASE_HOST=127.0.0.1\n"
        . "PHLIX_DATABASE_PORT=3306\n"
        . "PHLIX_DATABASE_NAME=phlix\n"
        . "PHLIX_DATABASE_USER=phlix\n"
        . "# 32-byte hex secret (openssl rand -hex 32)\n"
        . "PHLIX_SECRET_KEY=\n"
        . "PHLIX_LOG_LEVEL=info\n"
        . "PHLIX_ENV=production\n";

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function installerScript(): string
    {
        $text = @file_get_contents(self::repoRoot() . '/install/systemd.sh');
        self::assertIsString($text, 'install/systemd.sh must exist');
        return $text;
    }

    /**
     * The first-install template, parsed from the script itself.
     *
     * @return array<string,string> key => raw template value (generated
     *                              values appear as their `${...}` expansion)
     */
    private static function templateEntries(): array
    {
        $script = self::installerScript();
        self::assertTrue(
            (bool) preg_match('/cat\s*>\s*\/etc\/phlix\/env\s+<<EOF\n(.*?)\nEOF(?=\n|$)/s', $script, $m),
            'install/systemd.sh must write /etc/phlix/env through an UNQUOTED heredoc — '
            . "the pre-M11 quoted form ('EOF') could never expand a generated JWT_SECRET."
        );

        $entries = [];
        foreach (preg_split('/\n/', $m[1]) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $kv) === 1) {
                $entries[$kv[1]] = $kv[2];
            }
        }
        self::assertNotEmpty($entries, 'the env heredoc must assign at least one key');
        return $entries;
    }

    public function testTemplateCarriesEveryBootRequiredKey(): void
    {
        $missing = array_values(array_diff(self::BOOT_REQUIRED_KEYS, array_keys(self::templateEntries())));
        self::assertSame(
            [],
            $missing,
            'the generated env file must carry every key the service needs to boot '
            . '(config/database.php reads DB_*; the boot guard requires JWT_SECRET)'
        );
    }

    public function testTemplateCarriesEveryUninstallerReaderKey(): void
    {
        $installer = @file_get_contents(self::repoRoot() . '/scripts/install.sh');
        self::assertIsString($installer, 'scripts/install.sh must exist');
        self::assertTrue(
            (bool) preg_match('/^do_uninstall\(\)\s*\{(.*)^\}/msU', $installer, $m),
            'do_uninstall() must exist in scripts/install.sh (parser updated with the script?)'
        );

        $matches = preg_match_all('/grep\b[^\n]*[\x27"]\^([A-Z][A-Z0-9_]+)=/', $m[1], $g);
        self::assertGreaterThan(
            0,
            $matches,
            'do_uninstall must locate the install via env greps — parser teeth '
            . '(the canonical set below keeps that honest)'
        );
        $uninstallerKeys = array_values(array_unique($g[1]));
        sort($uninstallerKeys);
        self::assertSame(
            ['PHLIX_DATABASE_HOST', 'PHLIX_DATABASE_NAME', 'PHLIX_DATABASE_USER', 'PHLIX_DOMAIN'],
            $uninstallerKeys,
            'the uninstaller locates the install through these env keys — if it grew a new '
            . 'one, the systemd template must record it too (this pin doubles as parser teeth)'
        );

        $templateKeys = array_keys(self::templateEntries());
        $missing = array_values(array_diff($uninstallerKeys, $templateKeys));
        self::assertSame(
            [],
            $missing,
            'the systemd env file must preserve every key the uninstaller greps back out'
        );
    }

    public function testEveryTemplateKeyHasARealReaderOnThisCarrier(): void
    {
        $readers = self::phpEnvReaders();
        foreach (['scripts/install.sh'] as $shellFile) {
            $text = (string) @file_get_contents(self::repoRoot() . '/' . $shellFile);
            if (preg_match_all('/grep\b[^\n]*[\x27"]\^([A-Z][A-Z0-9_]+)=/', $text, $g) > 0) {
                $readers = array_merge($readers, $g[1]);
            }
        }
        foreach (glob(self::repoRoot() . '/systemd/*.service') ?: [] as $unit) {
            if (preg_match_all('/Environment=["\']?([A-Z][A-Z0-9_]+)=/', (string) file_get_contents($unit), $g) > 0) {
                $readers = array_merge($readers, $g[1]);
            }
        }
        $readers = array_unique($readers);

        foreach (array_keys(self::templateEntries()) as $key) {
            self::assertContains(
                $key,
                $readers,
                "template key {$key} is read by nothing on the systemd carrier — "
                . 'write honest keys only (the pre-M11 template shipped PHLIX_SECRET_KEY '
                . 'and PHLIX_DATABASE_PORT, which no code on this carrier reads)'
            );
        }
    }

    public function testScriptNoLongerTellsThePreM11Lies(): void
    {
        $script = self::installerScript();
        foreach (self::LIE_PHRASES as $lie) {
            self::assertStringNotContainsString(
                $lie,
                $script,
                "the installer must not claim '{$lie}' — that prose is what M11 disproved"
            );
        }
        self::assertStringNotContainsString(
            'PHLIX_SECRET_KEY',
            $script,
            'PHLIX_SECRET_KEY is a docker-entrypoint input; the systemd carrier reads '
            . 'nothing from it, so it must not appear in this installer at all'
        );
    }

    public function testJwtSecretIsGeneratedAndSatisfiesTheBootGuard(): void
    {
        $script = self::installerScript();
        self::assertTrue(
            (bool) preg_match('/^phlix_generate_jwt_secret\(\)\s*\{(.*)^\}/msU', $script, $m),
            'install/systemd.sh must define phlix_generate_jwt_secret()'
        );

        $run = self::runBashScript($m[0] . "\nphlix_generate_jwt_secret\nphlix_generate_jwt_secret\n");
        self::assertFalse($run['timedOut'], 'the generator must not hang: ' . $run['stderr']);
        self::assertSame(0, $run['code'], 'generator must exit 0: ' . $run['stderr']);

        $generated = preg_split('/\n/', $run['stdout']) ?: [];
        $lines = array_values(array_filter(
            $generated,
            static fn (string $l): bool => $l !== ''
        ));
        self::assertCount(2, $lines, 'generator must print exactly one line per call');
        foreach ($lines as $secret) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/',
                $secret,
                'generated secret must be openssl rand -hex 32 material (64 hex chars)'
            );
            // The installer's own output must satisfy the lifted boot guard.
            self::assertNull(
                AuthServicesProvider::secretViolation($secret),
                'a freshly generated systemd JWT_SECRET must pass the boot policy'
            );
        }
        self::assertNotSame($lines[0], $lines[1], 'the generator must not return a cached value');
    }

    public function testBackfillHealsLegacyEnvFilesIdempotently(): void
    {
        $script = self::installerScript();
        $fns = [];
        foreach (['phlix_generate_jwt_secret', 'phlix_backfill_jwt_secret'] as $fn) {
            self::assertTrue(
                (bool) preg_match('/^' . $fn . '\(\)\s*\{(.*)^\}/msU', $script, $m),
                "install/systemd.sh must define {$fn}()"
            );
            $fns[] = $m[0];
        }

        $dir = self::scratchDir();
        try {
            $envPath = $dir . '/env';
            self::assertNotFalse(file_put_contents($envPath, self::LEGACY_ENV_REPLICA));

            $call = implode("\n", $fns) . "\nphlix_backfill_jwt_secret \"$envPath\"\n";

            $first = self::runBashScript($call);
            self::assertSame(0, $first['code'], 'first backfill must succeed: ' . $first['stderr']);
            $healed = (string) file_get_contents($envPath);

            self::assertSame(
                1,
                preg_match('/^JWT_SECRET=([0-9a-f]{64})$/m', $healed),
                'legacy files must gain exactly one real generated JWT_SECRET line'
            );
            foreach (explode("\n", trim(self::LEGACY_ENV_REPLICA)) as $legacyLine) {
                self::assertStringContainsString(
                    $legacyLine,
                    $healed,
                    'backfill must not disturb any pre-existing line: ' . $legacyLine
                );
            }

            $second = self::runBashScript($call);
            self::assertSame(0, $second['code']);
            self::assertStringNotContainsString(
                'Backfilled',
                $second['stdout'],
                'the second call must be a no-op (secret already present)'
            );
            self::assertSame(
                $healed,
                (string) file_get_contents($envPath),
                'backfill must be idempotent — byte-for-byte'
            );
        } finally {
            self::removeDir($dir);
        }
    }

    public function testInstallerScriptKeepsBashSyntax(): void
    {
        $run = self::runProcess(['bash', '-n', self::repoRoot() . '/install/systemd.sh'], []);
        self::assertFalse($run['timedOut'], 'bash -n must not hang');
        self::assertSame(0, $run['code'], 'install/systemd.sh must parse cleanly: ' . $run['stderr']);
    }

    /**
     * Rider R-SERVER-22: scripts/install.sh threads --http-port into the
     * HAProxy backend and its healthcheck, but the env heredoc never wrote
     * PHLIX_HTTP_PORT — so the daemon (ListenEndpoints.php) bound the built-in
     * 8096 behind a proxy dialing the custom port. The row must pin the SAME
     * shell variable the proxy block uses, not a second literal.
     */
    public function testFullInstallerEnvHeredocPinsTheHttpPort(): void
    {
        $installer = (string) @file_get_contents(self::repoRoot() . '/scripts/install.sh');
        self::assertNotSame('', $installer, 'scripts/install.sh must exist');
        self::assertSame(
            1,
            preg_match('/cat >"\$ENV_FILE" <<EOF\n(.*?)\nEOF\n/s', $installer, $m),
            'parser teeth: the installer must still write the env file through an unquoted heredoc'
        );
        self::assertSame(
            1,
            preg_match('/^PHLIX_HTTP_PORT=\$\{HTTP_PORT\}$/m', $m[1]),
            'the generated env file must carry PHLIX_HTTP_PORT=\${HTTP_PORT} — one source of truth with the proxy backend (rider R-SERVER-22)'
        );
    }

    /**
     * The finding in one line: pre-M11, this value BOOTED on systemd (31 bytes
     * only tripped the length floor outside dev, and dev never refused it).
     * Post-M11 the carrier-agnostic boot guard in start.php itself must refuse
     * it with the CRITICAL envelope before any worker or port exists.
     *
     * The bogus command argument is deliberate safety, not noise: if a child
     * ever got PAST the guard (a regression), Workerman's runAll validates the
     * command before forking or binding any listen socket — so the child prints
     * usage and exits instead of daemonizing onto the venue's ports.
     */
    public function testSystemdCarrierRefusesThePublicPlaceholderAtBoot(): void
    {
        $run = self::runProcess([PHP_BINARY, 'start.php', 'm11-refusal-verify'], [
            'PHLIX_ENV' => 'production',
            'JWT_SECRET' => 'change_me_generate_with_openssl',
        ], self::repoRoot());

        self::assertFalse(
            $run['timedOut'],
            "start.php served (or hung) on a public placeholder key — it must refuse: stderr={$run['stderr']}"
        );
        self::assertSame(1, $run['code'], 'the boot guard exits 1 via start.php');
        self::assertStringContainsString('CRITICAL', $run['stderr']);
        self::assertStringContainsString('JWT_SECRET', $run['stderr']);
        self::assertStringContainsString('placeholder', $run['stderr']);
    }

    /**
     * @return list<string> every env name PHP code reads via getenv()/$_ENV
     */
    private static function phpEnvReaders(): array
    {
        $files = [self::repoRoot() . '/start.php'];
        foreach (['config', 'src', 'scripts', 'public'] as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::repoRoot() . '/' . $root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $readers = [];
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            if (preg_match_all("/getenv\(\s*['\"]([A-Z][A-Z0-9_]*)['\"]/", $text, $g) > 0) {
                $readers = array_merge($readers, $g[1]);
            }
            // The typed env reader (M-SERVER-34) is a read too: an EnvConfig::
            // call naming the key literal is how config/ now consumes the env.
            if (preg_match_all("/EnvConfig::(?:integer|text|nullableText)\(\s*['\"]([A-Z][A-Z0-9_]*)['\"]/", $text, $g) > 0) {
                $readers = array_merge($readers, $g[1]);
            }
            if (preg_match_all('/\$_ENV\[\s*[\x27"]([A-Z][A-Z0-9_]*)[\x27"]/', $text, $g) > 0) {
                $readers = array_merge($readers, $g[1]);
            }
            // House convention: an `*_ENV` class constant IS an env var name
            // (read via getenv(LogChannels::LOG_LEVEL_ENV)-style indirection).
            if (preg_match_all('/const\s+[A-Z0-9_]*_ENV\b\s*=\s*[\x27"]([A-Z][A-Z0-9_]*)[\x27"]/', $text, $g) > 0) {
                $readers = array_merge($readers, $g[1]);
            }
        }
        return array_values(array_unique($readers));
    }

    /**
     * @return array<string,string> base environment plus overrides, always with PATH
     */
    private static function childEnv(array $overrides): array
    {
        $env = $_ENV;
        $env['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        foreach ($overrides as $key => $value) {
            $env[$key] = $value;
        }
        return $env;
    }

    /**
     * Runs a bash script body (function definitions + calls) in a child.
     *
     * @return array{code:int|null,stdout:string,stderr:string,timedOut:bool}
     */
    private static function runBashScript(string $body): array
    {
        $dir = self::scratchDir();
        try {
            $path = $dir . '/run.sh';
            self::assertNotFalse(file_put_contents($path, "#!/bin/bash\nset -e\n" . $body));
            return self::runProcess(['bash', $path], []);
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * Bounded child-process runner: never trusts the child to terminate.
     *
     * @param list<string>        $argv
     * @param array<string,string> $env
     * @return array{code:int|null,stdout:string,stderr:string,timedOut:bool}
     */
    private static function runProcess(array $argv, array $env, ?string $cwd = null): array
    {
        if ($env === []) {
            $env = self::childEnv([]);
        } else {
            $env = self::childEnv($env);
        }

        $pipes = [];
        $proc = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        self::assertIsResource($proc, 'child process must spawn: ' . implode(' ', $argv));

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = microtime(true) + 20.0;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
            usleep(20000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $timedOut ? null : $code, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => $timedOut];
    }

    private static function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix_s11_' . bin2hex(random_bytes(6));
        self::assertNotFalse(mkdir($dir, 0700, true));
        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
