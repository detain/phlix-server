<?php

/**
 * Phlix media server component: Deploy.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the artwork-cache sandbox path.
 *
 * The scanner writes downloaded TMDB posters under the local artwork cache
 * root (config/artwork.php's ARTWORK_STORAGE_PATH, historic default
 * /var/artwork). The systemd unit runs with `ProtectSystem=strict`, which makes
 * the whole filesystem read-only EXCEPT the paths listed in `ReadWritePaths`.
 *
 * When the artwork root is missing from `ReadWritePaths`, mkdir fails with
 * "Read-only file system"; and because nothing ever caches, every scan
 * re-downloads every poster via blocking cURL across the concurrent matcher,
 * eventually tripping Swoole's "all coroutines are asleep - deadlock" abort that
 * kills the server. These tests pin the deploy artifacts so that regression
 * cannot silently return.
 */
final class ArtworkReadWritePathTest extends TestCase
{
    /** @var string Repository root (tests/Unit/Deploy → repo root). */
    private string $repoRoot;

    /** @var string The historic artwork-cache default, from config/artwork.php. */
    private string $defaultArtworkPath;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 3);

        // Resolve the config default with no env override so the test asserts
        // against the same value install.sh bakes into the unit.
        $prev = getenv('ARTWORK_STORAGE_PATH');
        putenv('ARTWORK_STORAGE_PATH');
        /** @var array{storage_path: string} $config */
        $config = require $this->repoRoot . '/config/artwork.php';
        $this->defaultArtworkPath = $config['storage_path'];
        if ($prev !== false) {
            putenv('ARTWORK_STORAGE_PATH=' . $prev);
        }

        self::assertSame(
            '/var/artwork',
            $this->defaultArtworkPath,
            'The artwork default moved; update the systemd unit + install.sh ReadWritePaths to match.',
        );
    }

    public function testSystemdUnitReadWritePathsIncludesArtworkRoot(): void
    {
        $unit = (string) file_get_contents($this->repoRoot . '/systemd/phlix-server.service');
        $paths = $this->readWritePaths($unit);

        self::assertContains(
            $this->defaultArtworkPath,
            $paths,
            'systemd/phlix-server.service ReadWritePaths must include the artwork cache root '
            . 'or ProtectSystem=strict makes it read-only (scanner mkdir fails, then deadlock).',
        );
    }

    public function testSystemdUnitStillProtectsSystem(): void
    {
        // The whole reason the artwork path must be enumerated is that the unit
        // hardens the filesystem. If this ever relaxes, the guard above is moot;
        // pin it so the two assumptions stay coupled.
        $unit = (string) file_get_contents($this->repoRoot . '/systemd/phlix-server.service');
        self::assertMatchesRegularExpression(
            '/^ProtectSystem=strict$/m',
            $unit,
            'ProtectSystem=strict is the invariant that makes ReadWritePaths load-bearing.',
        );
    }

    public function testInstallScriptWiresArtworkDirIntoReadWritePaths(): void
    {
        $install = (string) file_get_contents($this->repoRoot . '/scripts/install.sh');

        // The freshly-generated unit heredoc must add the artwork dir.
        self::assertMatchesRegularExpression(
            '/^ReadWritePaths=.*\$\{ARTWORK_DIR\}/m',
            $install,
            'install.sh unit heredoc must append ${ARTWORK_DIR} to ReadWritePaths.',
        );

        // ARTWORK_DIR must default to the same historic path config uses.
        self::assertStringContainsString(
            'ARTWORK_DIR="/var/artwork"',
            $install,
            'install.sh must fall back to /var/artwork when ARTWORK_STORAGE_PATH is unset.',
        );

        // The in-place migration for existing installs must exist so
        // `install.sh --update` (via /root/update_server.sh) backfills the path.
        self::assertMatchesRegularExpression(
            '/Adding \$ARTWORK_DIR to systemd ReadWritePaths/',
            $install,
            'install.sh must carry the 4g one-off migration that appends the artwork '
            . 'root to an existing unit and creates the directory before restart.',
        );
    }

    /**
     * Extract the space-separated entries of the unit's ReadWritePaths= line.
     *
     * @return list<string>
     */
    private function readWritePaths(string $unit): array
    {
        self::assertMatchesRegularExpression(
            '/^ReadWritePaths=.+$/m',
            $unit,
            'systemd/phlix-server.service is missing a ReadWritePaths= line.',
        );

        $matched = preg_match('/^ReadWritePaths=(.+)$/m', $unit, $m);
        self::assertSame(1, $matched);

        return array_values(array_filter(preg_split('/\s+/', trim($m[1])) ?: []));
    }
}
