<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Docker;

use PHPUnit\Framework\TestCase;

/**
 * SRV-12 / SRV-S9 — `scripts/install.sh --update` must hand over to the freshly
 * fetched installer (env-sentinel loop guard) so the previous release's install
 * logic never runs the update. Pins the structure and runs the shell self-test.
 */
final class InstallerSelfUpdateReexecTest extends TestCase
{
    private function installer(): string
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/scripts/install.sh');
        self::assertIsString($src);

        return $src;
    }

    public function testUpdateReexecsFromUpdatedCheckoutWithSentinel(): void
    {
        $src = $this->installer();
        self::assertStringContainsString('PHLIX_ORIG_ARGS=("$@")', $src);
        self::assertStringContainsString('export PHLIX_INSTALL_REEXEC=1', $src);
        self::assertStringContainsString('exec bash "$new_installer" "${PHLIX_ORIG_ARGS[@]}"', $src);
        self::assertStringContainsString('phlix_update_should_reexec "${PHLIX_INSTALL_REEXEC:-}"', $src);

        // The exec must come after the fetch/reset and before composer.
        $reset = strpos($src, 'reset --hard "origin/$BRANCH"');
        $exec = strpos($src, 'exec bash "$new_installer"');
        $composer = strpos($src, 'composer install --no-dev --optimize-autoloader', (int) $exec);
        self::assertNotFalse($reset);
        self::assertNotFalse($exec);
        self::assertNotFalse($composer);
        self::assertLessThan($exec, $reset);
        self::assertLessThan($composer, $exec);
    }

    public function testShellSelfTestPasses(): void
    {
        $script = dirname(__DIR__, 3) . '/scripts/install.sh';
        $out = [];
        $rc = 1;
        exec('bash ' . escapeshellarg($script) . ' --self-test 2>&1', $out, $rc);
        $joined = implode("\n", $out);
        self::assertSame(0, $rc, $joined);
        self::assertStringContainsString('reexec: sentinel set => no (loop guard)', $joined);
    }
}
