<?php

declare(strict_types=1);

namespace Phlix\Plugins\Installer;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Exception\PluginInstallException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Wraps the per-plugin `composer install --no-dev --no-interaction`
 * shell-out used by {@see HttpInstaller} after the source has been
 * extracted into `var/plugins/<name>/`.
 *
 * Every plugin MUST ship a `composer.json` — that is how each plugin
 * gets its own isolated `vendor/` tree (no global vendor pollution).
 * The runner refuses to proceed when `composer.json` is missing.
 *
 * Composer is invoked via {@see Process}; stdout/stderr is captured
 * and logged on the `plugins` channel for postmortem. The default
 * timeout is 120 seconds, override via the
 * `PHLIX_PLUGINS_COMPOSER_TIMEOUT` env var or constructor argument.
 *
 * @package Phlix\Plugins\Installer
 * @since 0.10.0
 */
class ComposerRunner
{
    /**
     * Default timeout in seconds applied to the composer subprocess.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    /**
     * Default composer binary path. The runner looks for this exact
     * filename on `$PATH` via {@see Process}; if the operator has
     * composer aliased or installed as a phar, set the `composer_bin`
     * constructor argument.
     */
    public const DEFAULT_COMPOSER_BIN = 'composer';

    /**
     * @param int          $timeoutSeconds Hard cap on composer execution time.
     * @param string       $composerBin    Composer binary name or absolute path.
     * @param StructuredLogger|null $logger Optional logger; lazy-loaded on first use.
     */
    public function __construct(
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly string $composerBin = self::DEFAULT_COMPOSER_BIN,
        private ?StructuredLogger $logger = null,
    ) {
    }

    /**
     * Run `composer install --no-dev --no-interaction --no-progress`
     * inside the given plugin directory.
     *
     * @param string $pluginDir Absolute path to a plugin source root.
     *
     * @throws PluginInstallException When `composer.json` is missing or
     *         composer exits non-zero / times out.
     *
     * @since 0.10.0
     */
    public function install(string $pluginDir): void
    {
        $composerJson = $pluginDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerJson)) {
            throw new PluginInstallException(sprintf(
                'Plugin at %s is missing composer.json — every plugin must be a Composer project.',
                $pluginDir,
            ));
        }

        $process = new Process(
            [
                $this->composerBin,
                'install',
                '--no-dev',
                '--no-interaction',
                '--no-progress',
                '--no-ansi',
            ],
            $pluginDir,
        );
        $process->setTimeout((float) $this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            $this->logger()->error('composer install timed out', [
                'plugin_dir' => $pluginDir,
                'timeout' => $this->timeoutSeconds,
            ]);
            throw new PluginInstallException(
                sprintf('composer install timed out after %d seconds for %s.', $this->timeoutSeconds, $pluginDir),
                [],
                0,
                $e,
            );
        }

        if (!$process->isSuccessful()) {
            $this->logger()->error('composer install failed', [
                'plugin_dir' => $pluginDir,
                'exit_code' => $process->getExitCode(),
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
            ]);
            throw new PluginInstallException(sprintf(
                'composer install failed for %s (exit %d): %s',
                $pluginDir,
                (int) $process->getExitCode(),
                trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ));
        }

        $this->logger()->info('composer install completed', [
            'plugin_dir' => $pluginDir,
        ]);
    }

    /**
     * Lazy-load the plugins-channel logger.
     */
    private function logger(): StructuredLogger
    {
        if ($this->logger === null) {
            $this->logger = LoggerFactory::get(LogChannels::PLUGINS);
        }
        return $this->logger;
    }
}
