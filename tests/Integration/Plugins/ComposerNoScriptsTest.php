<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Installer\ComposerRunner;
use PHPUnit\Framework\TestCase;

/**
 * SV-S1b (RCE kill-switch) end-to-end proof: a fixture plugin whose
 * `composer.json` declares a `post-install-cmd` that writes a sentinel file
 * MUST NOT have that command executed, because {@see ComposerRunner} invokes
 * composer with `--no-scripts --no-plugins`.
 *
 * This runs the REAL composer binary (skipped if not on PATH), so it is the
 * definitive regression guard for the kill-switch.
 *
 * @covers \Phlix\Plugins\Installer\ComposerRunner
 */
final class ComposerNoScriptsTest extends TestCase
{
    private string $pluginDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginDir = sys_get_temp_dir() . '/phlix_noscripts_' . uniqid('', true);
        mkdir($this->pluginDir, 0775, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->pluginDir)) {
            @system('rm -rf ' . escapeshellarg($this->pluginDir));
        }
    }

    public function test_post_install_script_is_not_executed(): void
    {
        if (trim((string) shell_exec('which composer 2>/dev/null')) === '') {
            $this->markTestSkipped('composer binary not available on PATH');
        }

        $sentinel = $this->pluginDir . '/PWNED.txt';

        // A malicious plugin whose composer scripts try to run arbitrary code
        // as the server user. `--no-scripts` (kill-switch) must prevent this.
        $composerJson = [
            'name' => 'phlix/evil-plugin',
            'description' => 'attempts RCE via composer scripts',
            'autoload' => ['psr-4' => ['Evil\\' => 'src/']],
            'scripts' => [
                'post-install-cmd' => 'touch ' . escapeshellarg($sentinel),
                'post-autoload-dump' => 'touch ' . escapeshellarg($sentinel),
            ],
        ];
        file_put_contents(
            $this->pluginDir . '/composer.json',
            (string) json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        mkdir($this->pluginDir . '/src', 0775, true);

        $logger = $this->createMock(StructuredLogger::class);
        $runner = new ComposerRunner(60, 'composer', $logger);
        $runner->install($this->pluginDir);

        // The RCE sentinel must NOT exist — composer scripts were suppressed.
        $this->assertFileDoesNotExist(
            $sentinel,
            'composer post-install-cmd executed — --no-scripts kill-switch is broken (RCE).',
        );
    }
}
