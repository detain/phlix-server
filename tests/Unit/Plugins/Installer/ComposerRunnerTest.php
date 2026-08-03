<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Installer;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Installer\ComposerRunner;
use PHPUnit\Framework\TestCase;

final class ComposerRunnerTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix_composerrun_' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tmpDir)) {
            @system('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    public function test_install_throws_when_composer_json_missing(): void
    {
        $runner = new ComposerRunner();

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('missing composer.json');

        $runner->install($this->tmpDir);
    }

    public function test_install_throws_when_composer_binary_unavailable(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', '{}');

        // Point at a binary that surely does not exist on PATH so the
        // Process invocation fails immediately.
        $logger = $this->createMock(StructuredLogger::class);
        $runner = new ComposerRunner(
            timeoutSeconds: 5,
            composerBin: '/definitely/not/a/real/composer/binary',
            logger: $logger,
        );

        $this->expectException(PluginInstallException::class);
        $runner->install($this->tmpDir);
    }

    /**
     * @group integration
     */
    public function test_install_succeeds_on_minimal_composer_json(): void
    {
        if (trim((string) shell_exec('which composer 2>/dev/null')) === '') {
            $this->markTestSkipped('composer binary not available on PATH - run in docker-compose for integration testing');
        }
        file_put_contents(
            $this->tmpDir . '/composer.json',
            '{"name":"phlix/test-runner","autoload":{"psr-4":{"X\\\\":"src/"}}}',
        );
        mkdir($this->tmpDir . '/src', 0775, true);

        $logger = $this->createMock(StructuredLogger::class);
        $runner = new ComposerRunner(60, 'composer', $logger);
        $runner->install($this->tmpDir);

        $this->assertFileExists($this->tmpDir . '/vendor/autoload.php');
    }

    public function test_install_throws_and_logs_when_composer_exits_non_zero(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', '{}');

        // Build a tiny fake composer script that always exits 7 and
        // writes a known string to stderr — exercises the !isSuccessful()
        // branch in the runner without depending on a real composer.
        $fake = $this->tmpDir . '/fake-composer.sh';
        file_put_contents(
            $fake,
            "#!/usr/bin/env bash\n"
            . "echo 'composer-fake-stdout'\n"
            . "echo 'composer-fake-error' 1>&2\n"
            . "exit 7\n"
        );
        chmod($fake, 0755);

        // The fake exits 7 for BOTH `install` and the `dump-autoload` fallback,
        // so the runner exhausts the fallback and surfaces the install error.
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'composer install + dump-autoload both failed',
                $this->callback(static function ($ctx): bool {
                    return is_array($ctx)
                        && ($ctx['install_exit'] ?? null) === 7
                        && is_string($ctx['install_stderr'] ?? null)
                        && str_contains((string) $ctx['install_stderr'], 'composer-fake-error');
                })
            );

        $runner = new ComposerRunner(30, $fake, $logger);

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessageMatches('/composer install failed.*exit 7/');
        $runner->install($this->tmpDir);
    }

    public function test_install_falls_back_to_dump_autoload_when_install_fails(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', '{}');

        // A fake composer that FAILS `install` (exit 7) but SUCCEEDS for any
        // other subcommand (e.g. `dump-autoload`). Mirrors a token-less host
        // that can't fetch a vcs dep but can still generate the autoloader.
        $fake = $this->tmpDir . '/fallback-composer.sh';
        file_put_contents(
            $fake,
            "#!/usr/bin/env bash\n"
            . "if [ \"\$1\" = 'install' ]; then echo 'could not authenticate' 1>&2; exit 7; fi\n"
            . "exit 0\n"
        );
        chmod($fake, 0755);

        $logger = $this->createMock(StructuredLogger::class);
        // The install failure is a WARNING (not fatal); the fallback's success
        // is logged via info. No `error` is expected.
        $logger->expects($this->never())->method('error');

        $runner = new ComposerRunner(30, $fake, $logger);
        // No exception: the dump-autoload fallback installed the plugin.
        $runner->install($this->tmpDir);
        $this->assertFileExists($this->tmpDir . '/composer.json');
    }

    public function test_install_times_out_and_logs_when_timeout_exceeded(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', '{}');

        // Fake "composer" that just sleeps far longer than our timeout.
        $fake = $this->tmpDir . '/slow-composer.sh';
        file_put_contents(
            $fake,
            "#!/usr/bin/env bash\nsleep 30\n"
        );
        chmod($fake, 0755);

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'composer timed out',
                $this->callback(static function ($ctx): bool {
                    return is_array($ctx)
                        && ($ctx['timeout'] ?? null) === 1
                        && is_string($ctx['plugin_dir'] ?? null);
                })
            );

        $runner = new ComposerRunner(1, $fake, $logger);

        try {
            $runner->install($this->tmpDir);
            $this->fail('Expected PluginInstallException due to timeout');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function test_install_arg_vector_contains_no_scripts_and_no_plugins(): void
    {
        // SV-S1b (RCE kill-switch): the `composer install` invocation MUST pass
        // --no-scripts AND --no-plugins so a malicious plugin's composer.json
        // scripts/plugins never execute as the server user. We capture the real
        // argv via a fake composer that records its arguments to a file.
        file_put_contents($this->tmpDir . '/composer.json', '{}');
        $argLog = $this->tmpDir . '/install-args.txt';

        $fake = $this->tmpDir . '/capture-composer.sh';
        file_put_contents(
            $fake,
            "#!/usr/bin/env bash\n"
            . 'echo "$@" >> ' . escapeshellarg($argLog) . "\n"
            . "exit 0\n"
        );
        chmod($fake, 0755);

        $logger = $this->createMock(StructuredLogger::class);
        $runner = new ComposerRunner(30, $fake, $logger);
        $runner->install($this->tmpDir);

        $args = (string) file_get_contents($argLog);
        $this->assertStringContainsString('install', $args);
        $this->assertStringContainsString('--no-scripts', $args);
        $this->assertStringContainsString('--no-plugins', $args);
    }

    public function test_dump_autoload_fallback_arg_vector_contains_no_scripts_and_no_plugins(): void
    {
        // The dump-autoload fallback path (taken when `install` fails) must also
        // pass --no-scripts AND --no-plugins.
        file_put_contents($this->tmpDir . '/composer.json', '{}');
        $argLog = $this->tmpDir . '/dump-args.txt';

        // Fail `install` (exit 7), record + succeed for everything else (the
        // dump-autoload fallback), so we can inspect the fallback's argv.
        $fake = $this->tmpDir . '/capture-fallback-composer.sh';
        file_put_contents(
            $fake,
            "#!/usr/bin/env bash\n"
            . "if [ \"\$1\" = 'install' ]; then echo 'boom' 1>&2; exit 7; fi\n"
            . 'echo "$@" >> ' . escapeshellarg($argLog) . "\n"
            . "exit 0\n"
        );
        chmod($fake, 0755);

        $logger = $this->createMock(StructuredLogger::class);
        $runner = new ComposerRunner(30, $fake, $logger);
        $runner->install($this->tmpDir);

        $args = (string) file_get_contents($argLog);
        $this->assertStringContainsString('dump-autoload', $args);
        $this->assertStringContainsString('--no-scripts', $args);
        $this->assertStringContainsString('--no-plugins', $args);
    }

    public function test_install_logs_info_on_success_path(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', '{}');

        // Fake composer that succeeds quickly.
        $fake = $this->tmpDir . '/ok-composer.sh';
        file_put_contents(
            $fake,
            "#!/usr/bin/env bash\necho 'all-good'\nexit 0\n"
        );
        chmod($fake, 0755);

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('composer install completed', $this->isType('array'));

        $runner = new ComposerRunner(30, $fake, $logger);
        $runner->install($this->tmpDir);
        $this->assertFileExists($this->tmpDir . '/composer.json');
    }

    private function okComposer(): string
    {
        $fake = $this->tmpDir . '/ok-composer.sh';
        file_put_contents($fake, "#!/usr/bin/env bash\nexit 0\n");
        chmod($fake, 0755);
        return $fake;
    }

    public function test_install_forces_no_api_on_github_vcs_repositories(): void
    {
        // A GitHub vcs repo without no-api is the exact shape that makes a
        // token-less host hit the API rate limit + SSH fallback. The runner
        // must rewrite it to no-api:true before invoking composer.
        file_put_contents(
            $this->tmpDir . '/composer.json',
            (string) json_encode([
                'name' => 'phlix/test',
                'repositories' => [
                    ['type' => 'vcs', 'url' => 'https://github.com/detain/phlix-shared.git'],
                ],
            ], JSON_PRETTY_PRINT),
        );

        $runner = new ComposerRunner(30, $this->okComposer(), $this->createMock(StructuredLogger::class));
        $runner->install($this->tmpDir);

        /** @var array{repositories: array<int, array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($this->tmpDir . '/composer.json'), true);
        $this->assertTrue($data['repositories'][0]['no-api']);
    }

    public function test_install_leaves_non_github_and_non_vcs_repositories_untouched(): void
    {
        file_put_contents(
            $this->tmpDir . '/composer.json',
            (string) json_encode([
                'name' => 'phlix/test',
                'repositories' => [
                    ['type' => 'vcs', 'url' => 'https://gitlab.example.com/foo/bar.git'],
                    ['type' => 'path', 'url' => '../local-pkg'],
                ],
            ], JSON_PRETTY_PRINT),
        );

        $runner = new ComposerRunner(30, $this->okComposer(), $this->createMock(StructuredLogger::class));
        $runner->install($this->tmpDir);

        /** @var array{repositories: array<int, array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($this->tmpDir . '/composer.json'), true);
        $this->assertArrayNotHasKey('no-api', $data['repositories'][0]);
        $this->assertArrayNotHasKey('no-api', $data['repositories'][1]);
    }

    public function test_install_seeds_github_https_protocol_config(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', '{}');

        $runner = new ComposerRunner(30, $this->okComposer(), $this->createMock(StructuredLogger::class));
        $runner->install($this->tmpDir);

        $cfgFile = $this->tmpDir . '/.composer/config.json';
        $this->assertFileExists($cfgFile);
        /** @var array{config: array{github-protocols: array<int, string>}} $cfg */
        $cfg = json_decode((string) file_get_contents($cfgFile), true);
        $this->assertSame(['https'], $cfg['config']['github-protocols']);
    }

    public function test_install_writes_auth_json_when_github_token_present(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', '{}');

        putenv('PHLIX_GITHUB_TOKEN=ghp_testtoken123');
        try {
            $runner = new ComposerRunner(30, $this->okComposer(), $this->createMock(StructuredLogger::class));
            $runner->install($this->tmpDir);

            $authFile = $this->tmpDir . '/.composer/auth.json';
            $this->assertFileExists($authFile);
            /** @var array{github-oauth: array{'github.com': string}} $auth */
            $auth = json_decode((string) file_get_contents($authFile), true);
            $this->assertSame('ghp_testtoken123', $auth['github-oauth']['github.com']);
        } finally {
            putenv('PHLIX_GITHUB_TOKEN');
        }
    }
}
