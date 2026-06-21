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

        // Composer needs a writable HOME for its config + cache. The server
        // user's real $HOME/.cache is read-only under the systemd sandbox
        // (ProtectSystem=strict / ProtectHome), so point COMPOSER_HOME +
        // COMPOSER_CACHE_DIR at a writable dir inside the plugin. (A token in
        // the inherited env — e.g. GITHUB_TOKEN — is still passed through.)
        $composerHome = $pluginDir . DIRECTORY_SEPARATOR . '.composer';
        @mkdir($composerHome, 0750, true);
        $env = [
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_CACHE_DIR' => $composerHome . DIRECTORY_SEPARATOR . 'cache',
            'COMPOSER_NO_INTERACTION' => '1',
        ];

        $install = $this->runComposer(
            ['install', '--no-dev', '--no-interaction', '--no-progress', '--no-ansi'],
            $pluginDir,
            $env,
        );

        if ($install->isSuccessful()) {
            $this->logger()->info('composer install completed', ['plugin_dir' => $pluginDir]);
            return;
        }

        // `composer install` failed — almost always because a required package
        // (e.g. detain/phlix-shared, declared via a github vcs repository) can't
        // be fetched: a token-less host hits the GitHub API rate limit ("Could
        // not authenticate against github.com"), or the box is offline. Those
        // shared packages are PROVIDED BY THE HOST at runtime, so fall back to
        // generating just the plugin's OWN autoloader — no network needed. The
        // plugin's classes load and host-provided deps (phlix-shared, PSR)
        // resolve against the already-registered host autoloader.
        $this->logger()->warning('composer install failed; falling back to dump-autoload', [
            'plugin_dir' => $pluginDir,
            'exit_code' => $install->getExitCode(),
            'stderr' => trim($install->getErrorOutput()),
        ]);

        $dump = $this->runComposer(
            ['dump-autoload', '--no-dev', '--no-interaction', '--no-ansi'],
            $pluginDir,
            $env,
        );

        if ($dump->isSuccessful()) {
            $this->logger()->info('composer dump-autoload completed (install fallback)', [
                'plugin_dir' => $pluginDir,
            ]);
            return;
        }

        // Both failed — surface the original install error (the meaningful one).
        $this->logger()->error('composer install + dump-autoload both failed', [
            'plugin_dir' => $pluginDir,
            'install_exit' => $install->getExitCode(),
            'install_stderr' => trim($install->getErrorOutput()),
            'dump_stderr' => trim($dump->getErrorOutput()),
        ]);
        throw new PluginInstallException(sprintf(
            'composer install failed for %s (exit %d): %s',
            $pluginDir,
            (int) $install->getExitCode(),
            trim($install->getErrorOutput()) ?: trim($install->getOutput()),
        ));
    }

    /**
     * Run composer with the given args in $pluginDir, returning the finished
     * Process (success or failure). Only a hard timeout throws.
     *
     * @param list<string>         $args Composer arguments (after the binary).
     * @param array<string,string> $env  Extra environment, merged over the parent.
     *
     * @throws PluginInstallException On timeout.
     */
    private function runComposer(array $args, string $pluginDir, array $env): Process
    {
        $process = new Process(
            array_merge([$this->composerBin], $args),
            $pluginDir,
            $env,
        );
        $process->setTimeout((float) $this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            $this->logger()->error('composer timed out', [
                'plugin_dir' => $pluginDir,
                'timeout' => $this->timeoutSeconds,
                'args' => $args,
            ]);
            throw new PluginInstallException(
                sprintf('composer timed out after %d seconds for %s.', $this->timeoutSeconds, $pluginDir),
                [],
                0,
                $e,
            );
        }

        return $process;
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
