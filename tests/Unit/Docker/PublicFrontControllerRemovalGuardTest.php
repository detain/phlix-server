<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Docker;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * S171 — permanent removal guard for the unserved CGI front controller.
 *
 * `public/index.php` (376 lines) was a one-shot CGI/FPM front controller that
 * NOTHING on this project's deployment artifacts executed: the Dockerfiles run
 * php-cli only, under supervisord's `start.php` program; the systemd unit's
 * ExecStart is start.php; the compose files publish the daemon's ports;
 * `scripts/install.sh` actively REWRITES legacy `public/index.php start` units
 * onto start.php; and the docker boot gate asserts the ABSENCE of any
 * CGI/FPM/nginx process inside the image. The file nevertheless kept compiling
 * and kept reading like a supported path — enough that an audit (S99) reached
 * for it as a live entry point and had to qualify its own finding as
 * Workerman-path-only afterwards.
 *
 * "Unserved" is measured on THIS deployment's published artifacts; this guard
 * does not claim fronting it were impossible on a third-party box that invents
 * an fpm stack around the docroot.
 *
 * The deletion is pinned by the assertions below: the file stays absent, and
 * every deployment artifact the evidence pass measured — supervisord, the four
 * Dockerfiles, every compose file, the shipped reverse-proxy configs, the helm
 * charts, the systemd unit — keeps fronting nothing, while the installer's
 * legacy-unit migration and public/'s surviving payloads (the SPA bundle, the
 * email/UI templates) stay in place. Recreating the file reddens the first
 * assertion; mutating any of the pinned properties reddens its own.
 *
 * Pure unit test: it reads files from the repository tree and touches no DB,
 * no network and no process. tests/Unit/Docker/DockerEntrypointTest.php holds
 * sibling negative pins; this file re-pins them independently so the invariant
 * survives edits over there.
 */
final class PublicFrontControllerRemovalGuardTest extends TestCase
{
    /** Merge-ritual survival token for S171; also a structural pin that this guard exists. */
    public const string SURVIVAL_TOKEN = 'S171UNSERVEDFRONTX2J5';

    public function testTheUnservedFrontControllerFileIsAbsent(): void
    {
        self::assertFileDoesNotExist(
            self::repoPath('public/index.php'),
            'S171 deleted public/index.php because, measured on THIS deployment\'s artifacts '
            . '(Dockerfiles, supervisord, compose files, systemd unit, installer, CI boot gate), '
            . 'nothing executes it — not because fronting it were impossible on other hardware. '
            . 'Recreating the file without a serving path re-opens the dead-code illusion; if a '
            . 'real serving path is ever added, re-derive the S171 evidence first.'
        );
    }

    public function testSupervisordRunsTheDaemonAndStartsNothingCgi(): void
    {
        $body = self::readDirectives(self::repoPath('docker/supervisord.conf'), ';');

        // Positive control: an empty or wholly-commented file must not pass the
        // negative assertions below vacuously.
        self::assertNotSame(
            '',
            trim($body),
            'docker/supervisord.conf carries no directives once comments are stripped'
        );
        self::assertStringContainsString(
            'command=php /var/www/html/start.php start',
            $body,
            'the active [program:phlix] must run the Workerman daemon'
        );

        foreach (['index.php', '[program:nginx]', '[program:php-fpm]'] as $foreign) {
            self::assertStringNotContainsString(
                $foreign,
                $body,
                'no supervisord directive may mention ' . $foreign
                . ' — the image runs the daemon only. The S163 incident block keeps the dead '
                . 'public/index.php command line as history; whole-line comments are stripped '
                . 'before this assertion, exactly like DockerEntrypointTest\'s sibling pin.'
            );
        }
    }

    public function testDockerfilesFrontNothing(): void
    {
        foreach (
            [
                'docker/Dockerfile',
                'docker/Dockerfile.base',
                'docker/Dockerfile.intel',
                'docker/Dockerfile.nvidia',
            ] as $relative
        ) {
            $path = self::repoPath($relative);
            self::assertFileExists(
                $path,
                $relative . ' is part of the build matrix this guard measures — its absence '
                . 'would silently shrink the denominator'
            );

            $body = self::readDirectives($path, '#');
            self::assertNotSame(
                '',
                trim($body),
                $relative . ' carries no directives once comments are stripped'
            );
            self::assertStringNotContainsString(
                'public/index.php',
                $body,
                $relative . ' must not copy, reference or run the removed front controller'
            );

            foreach (explode("\n", $body) as $line) {
                if (preg_match('/^\s*(CMD|ENTRYPOINT)\b/', $line) !== 1) {
                    continue;
                }

                self::assertStringNotContainsString(
                    'index.php',
                    $line,
                    'a launch instruction must not name the removed front controller: ' . $line
                );
            }
        }
    }

    public function testComposeFilesPublishOnlyDaemonPorts(): void
    {
        $paths = [self::repoPath('docker-compose.yml')];
        $paths = array_merge(
            $paths,
            glob(self::repoPath('docker/examples/*/docker-compose.yml')) ?: []
        );
        // Fail fast on an empty denominator: a suite that measures nothing
        // must not report success.
        self::assertNotEmpty(
            array_filter($paths, 'file_exists'),
            'no compose file was found to check — the denominator (docker-compose.yml plus '
            . 'docker/examples/*/docker-compose.yml) is empty'
        );
        self::assertGreaterThanOrEqual(
            2,
            count($paths),
            'expected docker-compose.yml AND at least one docker/examples/* compose file'
        );

        foreach ($paths as $path) {
            $body = self::readDirectives($path, '#');
            self::assertNotSame(
                '',
                trim($body),
                $path . ' carries no directives once comments are stripped'
            );
            self::assertStringNotContainsString(
                'index.php',
                $body,
                'no compose file may run or mount the removed front controller; the published '
                . 'ports belong to the start.php daemon'
            );
        }
    }

    public function testReverseProxyConfigsFrontNothing(): void
    {
        self::assertNoFrontingReferenceUnder('reverse-proxy', 5);
    }

    public function testHelmChartsShipNoPhpFronting(): void
    {
        self::assertNoFrontingReferenceUnder('k8s', 20);
    }

    public function testSystemdUnitStartsTheDaemon(): void
    {
        $body = self::readDirectives(self::repoPath('systemd/phlix-server.service'), '#');

        self::assertMatchesRegularExpression(
            '/^ExecStart=.*start\.php\b/m',
            $body,
            'systemd/phlix-server.service must ExecStart the Workerman daemon (start.php)'
        );
        self::assertDoesNotMatchRegularExpression(
            '/^ExecStart=.*index\.php/m',
            $body,
            'no ExecStart of the shipped unit may point at the removed front controller'
        );
    }

    public function testInstallerStillMigratesLegacyUnitsAwayFromTheFrontController(): void
    {
        $installer = file_get_contents(self::repoPath('scripts/install.sh'));
        self::assertNotFalse(
            $installer,
            'scripts/install.sh must be readable — the migration pin may not pass vacuously'
        );

        // Step 4c: existing installs still carry units written before start.php
        // existed. The migration must outlive the file it migrates away from;
        // deleting it would strand those units on a dead ExecStart path.
        self::assertStringContainsString(
            's|public/index\.php start|start.php start|',
            (string) $installer,
            'install.sh must keep rewriting legacy `public/index.php start` ExecStart units to '
            . 'start.php even after S171 deleted the file'
        );
    }

    public function testPublicDirectoryStillCarriesItsSurvivingPayloads(): void
    {
        // public/ is NOT part of the removal: the Vite-built SPA bundle ships
        // under public/assets/app and the newsletter email template under
        // public/templates/emails. "Finishing the job" by nuking public/ would
        // take the SPA and the email rendering down with the front controller.
        self::assertDirectoryExists(
            self::repoPath('public/assets'),
            'public/assets carries the SPA bundle the daemon serves via HttpHandler::serveStatic()'
        );
        self::assertDirectoryExists(
            self::repoPath('public/templates'),
            'public/templates carries the email/UI templates still rendered by the application'
        );
    }

    /**
     * Absolute path of a repository file, resolved from tests/Unit/Docker.
     */
    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    /**
     * Assert that no file under $relativeDir — raw, comments included — names the
     * removed front controller or the directive that could front it.
     *
     * Unlike docker/supervisord.conf, the reverse-proxy and k8s trees carry no
     * historical comment naming public/index.php today, so their RAW contents are
     * scanned: a re-fronting attempt (a fastcgi_pass added to a shipped nginx
     * config, an fpm container added to the charts) cannot hide in a comment.
     * $minFiles is the denominator floor — a moved or emptied tree fails loudly
     * instead of scanning nothing and passing vacuously (S345 law 3).
     */
    private static function assertNoFrontingReferenceUnder(string $relativeDir, int $minFiles): void
    {
        $dir = self::repoPath($relativeDir);
        self::assertDirectoryExists(
            $dir,
            $relativeDir . '/ is part of the deployment matrix this guard measures — its '
            . 'absence would silently shrink the denominator'
        );

        $files = self::filesUnder($dir);
        self::assertGreaterThanOrEqual(
            $minFiles,
            count($files),
            'the ' . $relativeDir . '/ denominator fell below ' . $minFiles . ' files — '
            . 'the scan would be vacuous (tree moved? emptied?)'
        );

        foreach ($files as $path) {
            $raw = file_get_contents($path);
            self::assertNotFalse(
                $raw,
                'cannot read ' . $path . ' — the removal guard must not pass vacuously'
            );
            self::assertStringNotContainsString(
                'index.php',
                (string) $raw,
                $path . ' must not name the removed front controller'
            );
            self::assertStringNotContainsString(
                'fastcgi_pass',
                (string) $raw,
                $path . ' must not configure FastCGI — there is no CGI front controller to serve'
            );
        }
    }

    /**
     * Every regular file under $dir, sorted for deterministic failure output.
     *
     * @return list<string>
     */
    private static function filesUnder(string $dir): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * Read a deployment artifact and return it with WHOLE-LINE comments removed.
     *
     * A line is a comment when, after trimming leading whitespace, it starts
     * with $commentPrefix — ';' for supervisord/INI files, '#' for Dockerfiles,
     * compose files and systemd units. Historical commentary may name the
     * removed front controller; only live directives must not.
     *
     * Fails loud: an unreadable or absent file aborts the test here instead of
     * letting the downstream negative assertions pass vacuously.
     */
    private static function readDirectives(string $path, string $commentPrefix): string
    {
        $raw = file_get_contents($path);
        self::assertNotFalse(
            $raw,
            'cannot read ' . $path . ' — the removal guard must not pass vacuously'
        );
        $contents = (string) $raw;

        $kept = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (!str_starts_with(ltrim($line), $commentPrefix)) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }
}
