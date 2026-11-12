<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Updates;

use Phlix\Common\Version;
use Phlix\Server\Updates\CoreUpdateCheckService;
use PHPUnit\Framework\TestCase;

/**
 * The repository's root `VERSION` file IS the update marker every deployed
 * phlix-server polls (S74): `config/updates.php`'s `marker_url` points at this
 * exact file on `master`.
 *
 * ## Why this suite exists
 *
 * S74 deliberately introduces a SECOND source of truth for the version — the
 * `VERSION` file alongside {@see Version::STRING}. That is a real hazard, not a
 * theoretical one:
 *
 *  - if `VERSION` drifts BEHIND the constant, every install believes it is
 *    ahead of master and no release ever announces itself;
 *  - if it drifts AHEAD, every install nags about an update that was never cut.
 *
 * Neither shows up as an exception, a failing request, or a static-analysis
 * finding — the two values simply disagree. This suite makes the drift a RED
 * TEST instead of a silent production defect, and pins that the file is
 * parseable by the very comparator that will parse it in production.
 *
 * @package Phlix\Tests\Unit\Server\Updates
 */
final class VersionMarkerFileTest extends TestCase
{
    private function markerPath(): string
    {
        return dirname(__DIR__, 4) . '/VERSION';
    }

    public function testTheRootVersionFileExists(): void
    {
        self::assertFileExists(
            $this->markerPath(),
            'config/updates.php advertises this file as the update marker; a server polling '
            . 'master would get a 404 page instead of a version',
        );
    }

    public function testTheMarkerMatchesTheCompiledVersionConstant(): void
    {
        $raw = file_get_contents($this->markerPath());
        self::assertIsString($raw);

        self::assertSame(
            Version::STRING,
            trim($raw),
            'VERSION and Phlix\Common\Version::STRING must be bumped together — the file is '
            . 'what other installs compare themselves against',
        );
    }

    public function testTheMarkerIsParseableByTheProductionComparator(): void
    {
        $raw = file_get_contents($this->markerPath());
        self::assertIsString($raw);

        self::assertSame(Version::STRING, CoreUpdateCheckService::normalise($raw));
        self::assertFalse(
            CoreUpdateCheckService::isNewer($raw, Version::STRING),
            'a server running this exact commit must not be told an update is available',
        );
    }

    /**
     * The marker URL must point at THIS repository's `VERSION`, not the hub's.
     *
     * A copy-paste of S75's config would have every phlix-server compare itself
     * against phlix-hub's `0.5.0` and permanently report "up to date".
     */
    public function testTheConfiguredMarkerUrlPointsAtThisRepositorysVersionFile(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 4) . '/config/updates.php';

        self::assertArrayHasKey('marker_url', $config);
        self::assertIsString($config['marker_url']);
        self::assertStringContainsString('phlix-server', $config['marker_url']);
        self::assertStringEndsWith('/VERSION', $config['marker_url']);
        self::assertStringNotContainsString('phlix-hub', $config['marker_url']);
    }

    /**
     * The copy-to-clipboard command must be a real, runnable one — and must
     * never become an APPLY action the server runs itself.
     */
    public function testTheUpdateCommandNamesAFlagTheInstallerActuallySupports(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 4) . '/config/updates.php';

        self::assertArrayHasKey('update_command', $config);
        self::assertIsString($config['update_command']);
        $command = $config['update_command'];

        self::assertStringContainsString('scripts/install.sh', $command);
        self::assertStringContainsString('--update', $command);

        $installer = (string) file_get_contents(dirname(__DIR__, 4) . '/scripts/install.sh');
        self::assertStringContainsString(
            '--update)',
            $installer,
            'The advertised command passes --update; scripts/install.sh must actually parse it.',
        );
    }

    /**
     * SRV-12: the banner's default command is the canonical LOCAL-CHECKOUT
     * updater — the installer already on disk in this checkout, run as a file —
     * never a `curl … master | sudo bash` pipe, which executes whatever
     * `master` holds at fetch time, as root, unpinned. The README's "Updating an
     * existing install" section quotes this exact form as the banner string.
     */
    public function testTheDefaultUpdateCommandRunsTheLocalCheckoutInstallerNotAMasterPipe(): void
    {
        $previous = getenv('PHLIX_UPDATES_COMMAND');
        putenv('PHLIX_UPDATES_COMMAND');

        try {
            /** @var array<string, mixed> $config */
            $config = require dirname(__DIR__, 4) . '/config/updates.php';
        } finally {
            if ($previous !== false) {
                putenv('PHLIX_UPDATES_COMMAND=' . $previous);
            }
        }

        $command = $config['update_command'];
        self::assertIsString($command);

        $installer = dirname(__DIR__, 4) . '/scripts/install.sh';
        self::assertFileExists($installer);
        $quoted = preg_match('#^[A-Za-z0-9_./-]+$#', $installer) === 1 ? $installer : escapeshellarg($installer);
        self::assertSame('sudo bash ' . $quoted . ' --update -y', $command);

        self::assertStringNotContainsString('curl', $command);
        self::assertStringNotContainsString('| sudo bash', $command);
        self::assertStringNotContainsString('/master/', $command);

        $readme = (string) file_get_contents(dirname(__DIR__, 4) . '/README.md');
        self::assertStringContainsString(
            'sudo bash /var/www/phlix/scripts/install.sh --update -y',
            $readme,
            'README must show the banner\'s local-checkout form (at the default install path).',
        );
    }
}
