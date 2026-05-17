<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Installer;

use Phlex\Common\Logger\StructuredLogger;
use Phlex\Plugins\Exception\PluginInstallException;
use Phlex\Plugins\Installer\ComposerRunner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Plugins\Installer\ComposerRunner
 */
final class ComposerRunnerTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlex_composerrun_' . uniqid('', true);
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

    public function test_install_succeeds_on_minimal_composer_json(): void
    {
        if (trim((string) shell_exec('which composer 2>/dev/null')) === '') {
            $this->markTestSkipped('composer binary not available on PATH');
        }
        file_put_contents(
            $this->tmpDir . '/composer.json',
            '{"name":"phlex/test-runner","autoload":{"psr-4":{"X\\\\":"src/"}}}',
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

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'composer install failed',
                $this->callback(static function ($ctx): bool {
                    return is_array($ctx)
                        && ($ctx['exit_code'] ?? null) === 7
                        && is_string($ctx['stderr'] ?? null)
                        && str_contains((string) $ctx['stderr'], 'composer-fake-error');
                })
            );

        $runner = new ComposerRunner(30, $fake, $logger);

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessageMatches('/composer install failed.*exit 7/');
        $runner->install($this->tmpDir);
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
                'composer install timed out',
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
        $this->assertTrue(true); // reached without exception
    }
}
