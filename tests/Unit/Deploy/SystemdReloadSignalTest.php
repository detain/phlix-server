<?php

/**
 * Phlix media server component: Deploy.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the reload signal on every deploy carrier (M15).
 *
 * Workerman's master treats SIGUSR2 as the GRACEFUL reload (children drain
 * in-flight requests before exiting) and SIGUSR1 as the NON-graceful one
 * (children stop immediately and a SIGKILL arms after stopTimeout) —
 * `vendor/workerman/workerman/src/Worker.php` signal handler:
 * `static::$gracefulStop = $signal === SIGUSR2`. This repo's own authority
 * (`src/Server/Http/Controllers/Admin/AdminRestartController.php`) states the
 * same and records that an earlier revision which sent SIGUSR1 "was backwards
 * and is fixed" — but the fix reached only the admin-UI path. The committed
 * systemd unit and the unit heredoc in scripts/install.sh were still wiring
 * `systemctl reload` to SIGUSR1, so the DOCUMENTED graceful operator path was
 * the hard-cut one: every bare-metal reload severed the :8097 SyncPlay WS
 * workers, in-flight HLS/transcode segment delivery and the hub relay tunnel,
 * while the admin Restart button drained them.
 *
 * These tests parse the REAL deploy artifacts (CON-53-style pins — nothing
 * here is a fixture that can drift from what ships), exercise the installer's
 * legacy-unit repair in real bash, and stay coupled to the controller's
 * authority prose so the two cannot silently diverge again.
 */
final class SystemdReloadSignalTest extends TestCase
{
    /** The canonical sentence in AdminRestartController's docblock. */
    private const AUTHORITY_PHRASE = '**SIGUSR2 is the GRACEFUL reload and SIGUSR1 is the NON-graceful one.**';

    /** Replica of the unit scripts/install.sh (and the committed unit) wrote before M15. */
    private const LEGACY_UNIT_REPLICA = "[Service]\n"
        . "Type=simple\n"
        . "ExecStart=/usr/bin/php /var/www/phlix/start.php start\n"
        . "ExecReload=/bin/kill -SIGUSR1 \$MAINPID\n"
        . "ExecStop=/bin/kill -SIGTERM \$MAINPID\n"
        . "Restart=on-failure\n";

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testCommittedUnitReloadsGracefully(): void
    {
        $unit = self::read('systemd/phlix-server.service');

        self::assertSame(
            1,
            preg_match_all('/^ExecReload=/m', $unit),
            'the unit must carry exactly one ExecReload= line (parser teeth for the pins below)'
        );
        self::assertSame(
            1,
            preg_match('/^ExecReload=\/bin\/kill -SIGUSR2 \$MAINPID$/m', $unit),
            'systemd/phlix-server.service must reload with SIGUSR2 (Workerman\'s GRACEFUL reload). '
            . 'SIGUSR1 hard-cuts the :8097 WS workers, in-flight HLS segments and the hub relay tunnel — '
            . 'the admin restart endpoint drains them; systemctl reload must not be the worse path.'
        );
        // The explanatory comment MAY name SIGUSR1 (it explains why it is
        // wrong); no active (non-comment) line may wire it.
        $active = array_filter(
            explode("\n", $unit),
            static fn (string $l): bool => !str_starts_with(ltrim($l), '#')
        );
        self::assertStringNotContainsString(
            'SIGUSR1',
            implode("\n", $active),
            'the unit must not reference the non-graceful reload signal outside its warning comment'
        );
        self::assertMatchesRegularExpression(
            '/graceful/i',
            $unit,
            'the unit must carry the comment explaining WHY ExecReload is SIGUSR2, '
            . 'so a future editor does not "clean up" the distinction'
        );
    }

    public function testMinimalInstallerCarriesNoIndependentReloadLine(): void
    {
        $installer = self::read('install/systemd.sh');

        // The minimal installer ships the committed unit verbatim — that copy
        // is the vector by which this fix reaches it. Pin both halves.
        self::assertStringNotContainsString(
            'ExecReload',
            $installer,
            'install/systemd.sh must not grow its own unit body; it installs the committed file'
        );
        self::assertMatchesRegularExpression(
            '/install -m 644 systemd\/phlix-server\.service/',
            $installer,
            'install/systemd.sh must keep installing systemd/phlix-server.service verbatim '
            . '(if it starts generating its own unit, the ExecReload pins must move there too)'
        );
    }

    public function testFullInstallerHeredocEmitsTheGracefulSignal(): void
    {
        $install = self::read('scripts/install.sh');

        self::assertSame(
            1,
            preg_match_all('/^ExecReload=/m', $install),
            'install.sh must carry exactly one unit-template ExecReload= line '
            . '(the heredoc + the repair helper are matched by their own anchors below)'
        );
        self::assertSame(
            1,
            substr_count($install, 'ExecReload=/bin/kill -SIGUSR2 ' . '\\$MAINPID'),
            'the generated unit must use SIGUSR2 — and the backslash-escaped \$MAINPID must stay '
            . 'escaped in the unquoted heredoc so systemd (not install.sh) receives the literal $MAINPID'
        );
    }

    public function testInstallerRepairsLegacyUnitsOffTheNonGracefulSignal(): void
    {
        $install = self::read('scripts/install.sh');
        self::assertSame(
            1,
            preg_match('/^phlix_repair_exec_reload\(\)\s*\{.*^\}/msU', $install),
            'scripts/install.sh must define phlix_repair_exec_reload() — new units alone leave '
            . 'already-deployed installs (including the prod update_server.sh reload path) on SIGUSR1'
        );
        $fn = self::extractFunction($install, 'phlix_repair_exec_reload');
        self::assertMatchesRegularExpression(
            '/^[ \t]*phlix_repair_exec_reload "\$SERVICE_FILE"$/m',
            $install,
            'the repair must actually be CALLED in the existing-install migration section — '
            . 'a defined-but-never-invoked helper repairs nothing'
        );

        $dir = self::scratchDir();
        try {
            // (1) Legacy generated unit: repaired, neighbours byte-untouched, log fires.
            $unit = $dir . '/legacy.service';
            self::assertNotFalse(file_put_contents($unit, self::LEGACY_UNIT_REPLICA));
            $first = self::runBash($fn . "\nphlix_repair_exec_reload \"$unit\"\nphlix_repair_exec_reload \"$unit\"\n");
            self::assertSame(0, $first['code'], 'first repair must exit 0: ' . $first['stderr']);
            $repaired = (string) file_get_contents($unit);
            self::assertSame(
                1,
                preg_match('/^ExecReload=\/bin\/kill -SIGUSR2 \$MAINPID$/m', $repaired),
                'the legacy ExecReload line must flip to SIGUSR2'
            );
            self::assertStringNotContainsString('SIGUSR1', $repaired);
            foreach (explode("\n", trim(self::LEGACY_UNIT_REPLICA)) as $legacyLine) {
                if (str_starts_with($legacyLine, 'ExecReload=')) {
                    continue;
                }
                self::assertStringContainsString(
                    "\n" . $legacyLine . "\n",
                    "\n" . $repaired . "\n",
                    'the repair must not disturb any other line: ' . $legacyLine
                );
            }
            self::assertSame(
                1,
                substr_count($first['stdout'], 'Retrofitting ExecReload'),
                'exactly one repair must be announced — the second call must be a no-op'
            );
            self::assertSame(
                1,
                preg_match_all('/^ExecReload=/m', $repaired),
                'the repair must rewrite in place, never append a second line'
            );

            // (2) Operator-customised ExecReload stays untouched (preserve customisation law).
            $custom = $dir . '/custom.service';
            self::assertNotFalse(file_put_contents(
                $custom,
                "[Service]\nExecReload=/bin/kill -HUP \$MAINPID\n"
            ));
            $second = self::runBash($fn . "\nphlix_repair_exec_reload \"$custom\"\n");
            self::assertSame(0, $second['code']);
            self::assertStringNotContainsString('Retrofitting', $second['stdout']);
            self::assertStringContainsString(
                'ExecReload=/bin/kill -HUP $MAINPID',
                (string) file_get_contents($custom),
                'only the exact known-generated SIGUSR1 value may be rewritten'
            );

            // (3) Missing unit file (fresh host) is not an error.
            $third = self::runBash($fn . "\nphlix_repair_exec_reload \"$dir/nope.service\"\n");
            self::assertSame(0, $third['code'], 'a missing unit must be a silent no-op');
            self::assertStringNotContainsString('Retrofitting', $third['stdout']);
        } finally {
            self::removeDir($dir);
        }
    }

    public function testFullInstallerKeepsBashSyntax(): void
    {
        $run = self::runProcess(['bash', '-n', self::repoRoot() . '/scripts/install.sh']);
        self::assertFalse($run['timedOut'], 'bash -n must not hang');
        self::assertSame(0, $run['code'], 'scripts/install.sh must parse cleanly: ' . $run['stderr']);
    }

    public function testRepoAuthorityStillNamesSigusr2TheGracefulReload(): void
    {
        $controller = self::read('src/Server/Http/Controllers/Admin/AdminRestartController.php');
        self::assertStringContainsString(
            self::AUTHORITY_PHRASE,
            $controller,
            'the deploy pins above are justified by AdminRestartController\'s authority sentence; '
            . 'if the authority prose moved, reconcile BOTH sides — do not just flip this pin'
        );
    }

    /**
     * @return string the full text of `name() { ... }` extracted from the script
     */
    private static function extractFunction(string $script, string $name): string
    {
        self::assertSame(
            1,
            preg_match('/^' . $name . '\(\)\s*\{.*^\}/msU', $script, $m),
            "function {$name}() must be extractable (parser teeth)"
        );
        return $m[0];
    }

    private static function read(string $relative): string
    {
        $text = @file_get_contents(self::repoRoot() . '/' . $relative);
        self::assertIsString($text, "{$relative} must exist");
        return $text;
    }

    /**
     * Runs a bash body (function definitions + calls) with the helpers the
     * extracted function expects (log, systemctl).
     *
     * @return array{code:int|null,stdout:string,stderr:string,timedOut:bool}
     */
    private static function runBash(string $body): array
    {
        $dir = self::scratchDir();
        try {
            $path = $dir . '/run.sh';
            self::assertNotFalse(file_put_contents(
                $path,
                "#!/bin/bash\nset -e\nlog() { printf '%s\\n' \"\$*\"; }\nsystemctl() { return 0; }\n" . $body
            ));
            return self::runProcess(['bash', $path]);
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * Bounded child-process runner: never trusts the child to terminate.
     *
     * @param list<string> $argv
     * @return array{code:int|null,stdout:string,stderr:string,timedOut:bool}
     */
    private static function runProcess(array $argv): array
    {
        $env = $_ENV;
        $env['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

        $pipes = [];
        $proc = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
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
        $dir = sys_get_temp_dir() . '/phlix_m15_' . bin2hex(random_bytes(6));
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
