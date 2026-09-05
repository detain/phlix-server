<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Version;

/**
 * Executes `scripts/release.sh` for real, in a throwaway git sandbox.
 *
 * ## Why the script is executed rather than grepped
 *
 * The version of this script that shipped until 2026-08-05 *looked* fine. It
 * read the current version with
 * `grep '"version"' composer.json`, but `f5375f7a` had deleted that key from
 * composer.json on 2026-07-18 (it makes `composer validate --strict` fail), so
 * the expression evaluated to the empty string. Running it produced:
 *
 *     Current version:
 *     New version: ..1
 *     [master dae1607] Release v..1
 *     fatal: 'v..1' is not a valid tag name.
 *
 * — a committed, corrupted `Chart.yaml` and a `set -e` abort *after* the
 * commit. It also never wrote `src/Common/Version.php` or `VERSION` at all. No
 * amount of reading the source proved that; running it did.
 *
 * Every test here copies only the version-carrying files into a temporary
 * directory and runs the script there. The real repository is never touched,
 * and no test ever creates a commit or a tag outside the sandbox.
 *
 * @package Phlix\Tests\Unit\Scripts
 */
final class ReleaseScriptTest extends TestCase
{
    /**
     * Files copied verbatim into the sandbox, relative to the repository root.
     *
     * @var list<string>
     */
    private const SANDBOX_FILES = [
        'scripts/release.sh',
        'src/Common/Version.php',
        'VERSION',
        'k8s/helm/phlix/Chart.yaml',
        'composer.json',
    ];

    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 3);
        $this->sandbox = sys_get_temp_dir() . '/phlix-release-' . bin2hex(random_bytes(8));

        foreach (self::SANDBOX_FILES as $relative) {
            $target = $this->sandbox . '/' . $relative;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
                self::fail('could not create ' . $dir);
            }
            self::assertTrue(copy($root . '/' . $relative, $target), 'copy ' . $relative);
        }

        chmod($this->sandbox . '/scripts/release.sh', 0o755);

        $this->git('init', '-q', '-b', 'master', '.');
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', 'sandbox base');
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }

        parent::tearDown();
    }

    /**
     * A hermetic environment: no global/system git config (so no gpg signing,
     * no hooksPath, no user identity leaking in from the developer's box).
     *
     * @return array<string, string>
     */
    private function env(): array
    {
        return [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => $this->sandbox,
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
            'GIT_AUTHOR_NAME' => 'Release Test',
            'GIT_AUTHOR_EMAIL' => 'release-test@example.invalid',
            'GIT_COMMITTER_NAME' => 'Release Test',
            'GIT_COMMITTER_EMAIL' => 'release-test@example.invalid',
        ];
    }

    /**
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runCommand(string $command, string $cwd): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $cwd, $this->env());
        self::assertIsResource($process, 'proc_open failed for: ' . $command);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($process)];
    }

    private function git(string ...$args): string
    {
        $command = 'git ' . implode(' ', array_map('escapeshellarg', $args));
        $result = $this->runCommand($command . ' 2>&1', $this->sandbox);

        return $result['stdout'];
    }

    /**
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function release(string $args = '', ?string $cwd = null): array
    {
        return $this->runCommand(
            'bash ' . escapeshellarg($this->sandbox . '/scripts/release.sh') . ' ' . $args,
            $cwd ?? $this->sandbox,
        );
    }

    /**
     * Every version the sandbox currently carries, as the release script and
     * the Helm tooling would read them.
     *
     * @return array<string, string>
     */
    private function sandboxVersions(): array
    {
        $php = (string) file_get_contents($this->sandbox . '/src/Common/Version.php');
        preg_match("/public const STRING = '([^']*)';/", $php, $constant);

        $chart = (string) file_get_contents($this->sandbox . '/k8s/helm/phlix/Chart.yaml');
        preg_match('/^version:\s*(\S+)$/m', $chart, $chartVersion);
        preg_match('/^appVersion:\s*"([^"]*)"$/m', $chart, $chartAppVersion);

        return [
            'src/Common/Version.php::STRING' => $constant[1] ?? '',
            'VERSION' => trim((string) file_get_contents($this->sandbox . '/VERSION')),
            'Chart.yaml version' => $chartVersion[1] ?? '',
            'Chart.yaml appVersion' => $chartAppVersion[1] ?? '',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function bumpProvider(): array
    {
        [$major, $minor, $patch] = array_map('intval', explode('.', Version::STRING));

        return [
            'patch' => ['patch', sprintf('%d.%d.%d', $major, $minor, $patch + 1)],
            'minor' => ['minor', sprintf('%d.%d.0', $major, $minor + 1)],
            'major' => ['major', sprintf('%d.0.0', $major + 1)],
        ];
    }

    /**
     * The whole point: ONE invocation moves ALL FOUR version sources.
     *
     * @param string $type     the bump argument
     * @param string $expected the version every source must end up holding
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bumpProvider')]
    public function testABumpRewritesEveryVersionSource(string $type, string $expected): void
    {
        $before = $this->sandboxVersions();
        self::assertSame(
            [Version::STRING, Version::STRING, Version::STRING, Version::STRING],
            array_values($before),
            'the sandbox must start reconciled: ' . json_encode($before),
        );

        $result = $this->release($type);
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);

        foreach ($this->sandboxVersions() as $label => $actual) {
            self::assertSame($expected, $actual, $label . ' was not bumped by scripts/release.sh');
        }
    }

    public function testABumpCommitsAndTagsEveryRewrittenFile(): void
    {
        $expected = self::bumpProvider()['patch'][1];

        $result = $this->release('patch');
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);

        self::assertStringContainsString('Release v' . $expected, $this->git('log', '-1', '--format=%s'));
        self::assertSame('v' . $expected, trim($this->git('tag', '--list')));

        $committed = $this->git('show', '--name-only', '--format=', 'HEAD');
        foreach (['src/Common/Version.php', 'VERSION', 'k8s/helm/phlix/Chart.yaml'] as $file) {
            self::assertStringContainsString($file, $committed, $file . ' is missing from the release commit');
        }

        self::assertSame('', trim($this->git('status', '--porcelain')), 'the tree must be clean afterwards');
    }

    /**
     * composer.json must come out byte-identical. Re-adding a `version` key is
     * what turns the `composer-validate` CI job red.
     */
    public function testABumpLeavesComposerJsonByteIdentical(): void
    {
        $before = md5_file(dirname(__DIR__, 3) . '/composer.json');

        $result = $this->release('patch');
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);

        self::assertSame($before, md5_file($this->sandbox . '/composer.json'));

        // The forbidden thing is the ROOT `"version"` key (what composer
        // validate --strict rejects) — not the literal substring anywhere:
        // S228's inline composer `repositories` block legitimately carries a
        // nested `"version"` for the pinned phlix-tokens package definition.
        $decoded = json_decode((string) file_get_contents($this->sandbox . '/composer.json'), true);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey(
            'version',
            $decoded,
            'composer.json must not declare a root version key — the md5 pin above already proves the '
            . 'whole file survived the bump byte-for-byte; this names WHY the key is forbidden.',
        );
    }

    /**
     * A drifted source aborts the release BEFORE anything is written.
     *
     * This is the check that would have stopped `c17bb9ec` from publishing a
     * chart at 1.2.3 against a constant at 1.2.2.
     *
     * @param string $file    sandbox-relative file to corrupt
     * @param string $search  text to replace
     * @param string $replace replacement text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('driftProvider')]
    public function testDriftInAnySourceAbortsTheReleaseWithoutWriting(
        string $file,
        string $search,
        string $replace,
    ): void {
        $path = $this->sandbox . '/' . $file;
        $contents = (string) file_get_contents($path);
        self::assertStringContainsString($search, $contents, 'fixture is stale: ' . $file);
        file_put_contents($path, str_replace($search, $replace, $contents));

        $before = $this->sandboxVersions();
        $result = $this->release('patch');

        self::assertNotSame(0, $result['exit'], 'a drifted tree must not release');
        self::assertStringContainsString('DRIFT', $result['stderr'] . $result['stdout']);
        self::assertSame($before, $this->sandboxVersions(), 'nothing may be written on abort');
        self::assertSame('', trim($this->git('tag', '--list')), 'no tag may be created on abort');
        self::assertStringContainsString('sandbox base', $this->git('log', '-1', '--format=%s'));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function driftProvider(): array
    {
        return [
            'VERSION marker behind' => ['VERSION', Version::STRING, '9.9.9'],
            'Chart.yaml version ahead' => [
                'k8s/helm/phlix/Chart.yaml',
                'version: ' . Version::STRING,
                'version: 9.9.9',
            ],
            'Chart.yaml appVersion ahead' => [
                'k8s/helm/phlix/Chart.yaml',
                'appVersion: "' . Version::STRING . '"',
                'appVersion: "9.9.9"',
            ],
            'composer.json regrew a version field' => [
                'composer.json',
                '"type": "project",',
                '"type": "project",' . "\n" . '    "version": "9.9.9",',
            ],
        ];
    }

    /**
     * `--dry-run` as the ONLY argument. The old script parsed it as a bump type
     * and died with `Invalid type: --dry-run`, even though its own usage line
     * documented `[patch|minor|major] [--dry-run]`.
     */
    public function testDryRunAloneIsAcceptedAndWritesNothing(): void
    {
        $before = $this->sandboxVersions();
        $head = $this->git('rev-parse', 'HEAD');

        $result = $this->release('--dry-run');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('[DRY-RUN]', $result['stdout']);
        self::assertSame($before, $this->sandboxVersions());
        self::assertSame($head, $this->git('rev-parse', 'HEAD'));
        self::assertSame('', trim($this->git('tag', '--list')));
    }

    public function testDryRunNamesEverySourceItWouldWrite(): void
    {
        $result = $this->release('minor --dry-run');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        foreach (['src/Common/Version.php', 'VERSION', 'k8s/helm/phlix/Chart.yaml'] as $file) {
            self::assertStringContainsString($file, $result['stdout']);
        }
        self::assertStringContainsString(self::bumpProvider()['minor'][1], $result['stdout']);
    }

    /**
     * The script must resolve the repository from its own location, not from
     * the caller's cwd. The old one ran bare `grep composer.json` / `sed -i` on
     * relative paths, so invoking it from anywhere else silently did nothing or
     * edited the wrong files.
     */
    public function testItWorksWhenInvokedFromAnotherDirectory(): void
    {
        $expected = self::bumpProvider()['patch'][1];

        $result = $this->release('patch', sys_get_temp_dir());

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        foreach ($this->sandboxVersions() as $label => $actual) {
            self::assertSame($expected, $actual, $label);
        }
    }

    /**
     * An existing tag must abort BEFORE any file is written. The old script
     * committed first and only then ran `git tag`, so a collision left a
     * dangling release commit behind.
     */
    public function testAnExistingTagAbortsBeforeAnythingIsWritten(): void
    {
        $expected = self::bumpProvider()['patch'][1];
        $this->git('tag', 'v' . $expected);

        $before = $this->sandboxVersions();
        $result = $this->release('patch');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('already exists', $result['stderr']);
        self::assertSame($before, $this->sandboxVersions());
        self::assertStringContainsString('sandbox base', $this->git('log', '-1', '--format=%s'));
    }

    /**
     * `git add <paths>` followed by a bare `git commit` also commits whatever
     * was already staged. A release commit must contain the release and
     * nothing else.
     */
    public function testAlreadyStagedChangesAbortTheReleaseInsteadOfBeingSweptIn(): void
    {
        file_put_contents($this->sandbox . '/unrelated.txt', "secret\n");
        $this->git('add', 'unrelated.txt');

        $result = $this->release('patch');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('staged changes', $result['stderr']);
        self::assertStringContainsString('sandbox base', $this->git('log', '-1', '--format=%s'));
    }

    public function testAnUnknownArgumentIsRejected(): void
    {
        $result = $this->release('sideways');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('unknown argument', $result['stderr']);
        self::assertStringContainsString('sandbox base', $this->git('log', '-1', '--format=%s'));
    }

    /**
     * With no bump type at all the documented default is `patch`.
     */
    public function testTheDefaultBumpTypeIsPatch(): void
    {
        $result = $this->release('');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame(
            self::bumpProvider()['patch'][1],
            $this->sandboxVersions()['src/Common/Version.php::STRING'],
        );
    }

    /**
     * An unparseable authoritative constant must stop the release rather than
     * produce a nonsense version. `..1` is not a hypothetical: it is what the
     * previous script actually computed.
     */
    public function testAnUnparseableAuthoritativeConstantAborts(): void
    {
        $path = $this->sandbox . '/src/Common/Version.php';
        file_put_contents(
            $path,
            str_replace(
                "public const STRING = '" . Version::STRING . "';",
                "public const STRING = '';",
                (string) file_get_contents($path),
            ),
        );

        $result = $this->release('patch');

        self::assertNotSame(0, $result['exit']);
        self::assertStringNotContainsString('..1', $result['stdout']);
        self::assertStringContainsString('sandbox base', $this->git('log', '-1', '--format=%s'));
    }
}
