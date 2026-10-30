<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * Review server__copywriter M-SERVER-01: the installer's closing "Next:"
 * summary sent every fresh self-hoster to "complete the first-run setup
 * wizard" — a UI that exists nowhere in the product (the word "wizard"
 * appeared exactly once repo-wide: on this promise line). The shipped
 * first-run reality is the CLI pair documented in the README ("Bad days",
 * "No admin exists yet"): create an account with bin/phlix user:create,
 * promote it with user:promote, then sign in via the SPA.
 *
 * This guard keeps the installer prose truthful: the phantom-wizard promise
 * may never return, and the Next block must carry the real commands.
 *
 * @package Phlix\Tests\Unit\Deploy
 */
final class InstallerFirstRunProseTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function installer(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/scripts/install.sh');
    }

    public static function testThePhantomWizardPromiseIsGone(): void
    {
        $install = self::installer();

        // The only lawful mention of a wizard is the plain disclosure that
        // none exists; strip every disclosure and no token may remain, so a
        // re-worded promise ("complete the setup wizard…") cannot sneak back.
        $withoutDisclosures = str_replace('no setup wizard', '', strtolower($install));

        self::assertStringNotContainsString(
            'wizard',
            $withoutDisclosures,
            'scripts/install.sh must not advertise a setup wizard — none exists in src/, openapi.yaml, or the vendored SPA.'
        );
    }

    public static function testTheNextBlockCarriesTheRealFirstRunCommands(): void
    {
        $install = self::installer();

        self::assertStringContainsString(
            'user:create',
            $install,
            'the installer summary must name the shipped first step: bin/phlix user:create.'
        );
        self::assertStringContainsString(
            'user:promote',
            $install,
            'the installer summary must name the shipped second step: bin/phlix user:promote.'
        );
        self::assertStringContainsString(
            'no setup wizard',
            $install,
            'the installer summary must say plainly that no setup wizard exists.'
        );
    }

    public static function testTheInstallerStillParsesAsBash(): void
    {
        $output = [];
        $code = 0;
        exec('bash -n ' . escapeshellarg(self::repoRoot() . '/scripts/install.sh') . ' 2>&1', $output, $code);

        self::assertSame(0, $code, 'scripts/install.sh must parse cleanly: ' . implode("\n", $output));
    }
}
