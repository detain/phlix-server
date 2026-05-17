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
}
