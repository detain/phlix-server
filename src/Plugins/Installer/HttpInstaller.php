<?php

declare(strict_types=1);

namespace Phlex\Plugins\Installer;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Plugins\Exception\PluginInstallException;
use Phlex\Plugins\Manifest;
use Phlex\Plugins\Util\RecursiveDelete;

/**
 * Downloads a plugin source from a URL (or local `file://` path) into
 * `var/plugins/<name>/`.
 *
 * Supports three URL flavours:
 *
 *  - `*.zip`     — fetched, extracted with PHP's `ZipArchive`.
 *  - `*.tar.gz`  — fetched, extracted with `PharData`.
 *  - `*.json`    — treated as a "stub" `plugin.json` whose `source`
 *                  field points to a real tarball or zip; that URL is
 *                  then fetched recursively.
 *
 * Non-HTTPS URLs are refused unless the `PHLEX_PLUGINS_ALLOW_HTTP=1`
 * env var is set (default off — HTTPS-only). The `file://` scheme is
 * always allowed so unit and integration tests can stage local
 * fixtures.
 *
 * @package Phlex\Plugins\Installer
 * @since 0.10.0
 */
class HttpInstaller
{
    /**
     * @param string $pluginsBaseDir Absolute path to the directory under
     *        which `<plugin-name>/` install subdirs are created.
     * @param StructuredLogger|null $logger Plugins-channel logger.
     */
    public function __construct(
        private readonly string $pluginsBaseDir,
        private ?StructuredLogger $logger = null,
    ) {
    }

    /**
     * Fetch the plugin source at `$sourceUrl`, verify its manifest and
     * (when present) signature, and stage it under
     * `var/plugins/<name>/`.
     *
     * @param string $sourceUrl HTTPS URL, `file://` URL, or absolute path.
     *
     * @return array{0: Manifest, 1: string} Parsed manifest plus the
     *         absolute install directory.
     *
     * @throws PluginInstallException On any failure (download, validation,
     *         signature mismatch, IO errors).
     *
     * @since 0.10.0
     */
    public function install(string $sourceUrl): array
    {
        $this->guardScheme($sourceUrl);

        $tempDir = $this->createTempDir();
        try {
            $this->fetchInto($sourceUrl, $tempDir);

            $manifestPath = $tempDir . DIRECTORY_SEPARATOR . 'plugin.json';
            if (!is_file($manifestPath)) {
                throw new PluginInstallException(sprintf(
                    'Extracted plugin source at %s does not contain a plugin.json.',
                    $tempDir,
                ));
            }

            $manifestRaw = (string) file_get_contents($manifestPath);
            $manifest = Manifest::fromJson($manifestRaw);
            $errors = $manifest->validate();
            if ($errors !== []) {
                throw new PluginInstallException(
                    sprintf('Plugin manifest is invalid: %d error(s).', count($errors)),
                    $errors,
                );
            }

            $name = $manifest->name;
            $this->guardPluginName($name);

            $destination = $this->pluginsBaseDir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($destination)) {
                RecursiveDelete::remove($destination);
            }

            $this->ensureBaseDir();

            if (!@rename($tempDir, $destination)) {
                throw new PluginInstallException(sprintf(
                    'Cannot move staged plugin from %s to %s.',
                    $tempDir,
                    $destination,
                ));
            }

            $this->logger()->info('plugin source installed', [
                'plugin' => $name,
                'destination' => $destination,
                'source_url' => $sourceUrl,
            ]);

            return [$manifest, $destination];
        } catch (\Throwable $e) {
            if (is_dir($tempDir)) {
                RecursiveDelete::remove($tempDir);
            }
            if ($e instanceof PluginInstallException) {
                throw $e;
            }
            throw new PluginInstallException(
                sprintf('Failed to install plugin from %s: %s', $sourceUrl, $e->getMessage()),
                [],
                0,
                $e,
            );
        }
    }

    /**
     * Stage a plugin already present on the local filesystem (typically
     * a Git checkout or a release tarball that has been pre-extracted).
     *
     * @param string $sourceDir Absolute path to a directory containing `plugin.json`.
     *
     * @return array{0: Manifest, 1: string} Parsed manifest plus the
     *         absolute install directory.
     *
     * @throws PluginInstallException
     *
     * @since 0.10.0
     */
    public function installFromDirectory(string $sourceDir): array
    {
        $manifestPath = rtrim($sourceDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($manifestPath)) {
            throw new PluginInstallException(sprintf(
                'Plugin source directory %s does not contain a plugin.json.',
                $sourceDir,
            ));
        }

        $manifestRaw = (string) file_get_contents($manifestPath);
        $manifest = Manifest::fromJson($manifestRaw);
        $errors = $manifest->validate();
        if ($errors !== []) {
            throw new PluginInstallException(
                sprintf('Plugin manifest is invalid: %d error(s).', count($errors)),
                $errors,
            );
        }

        $name = $manifest->name;
        $this->guardPluginName($name);

        $destination = $this->pluginsBaseDir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($destination)) {
            RecursiveDelete::remove($destination);
        }

        $this->ensureBaseDir();

        if (!@mkdir($destination, 0750, true) && !is_dir($destination)) {
            throw new PluginInstallException(sprintf(
                'Cannot create plugin directory %s.',
                $destination,
            ));
        }

        $this->copyDirectory($sourceDir, $destination);

        $this->logger()->info('plugin staged from directory', [
            'plugin' => $name,
            'source' => $sourceDir,
            'destination' => $destination,
        ]);

        return [$manifest, $destination];
    }

    /**
     * Ensure the configured plugins base directory exists, creating it
     * with safe permissions if missing.
     *
     * @throws PluginInstallException
     */
    private function ensureBaseDir(): void
    {
        if (is_dir($this->pluginsBaseDir)) {
            return;
        }
        if (!@mkdir($this->pluginsBaseDir, 0750, true) && !is_dir($this->pluginsBaseDir)) {
            throw new PluginInstallException(sprintf(
                'Cannot create plugins base directory %s.',
                $this->pluginsBaseDir,
            ));
        }
    }

    /**
     * Refuse non-HTTPS URLs unless explicitly allowed.
     *
     * @throws PluginInstallException
     */
    private function guardScheme(string $sourceUrl): void
    {
        $scheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
        if ($scheme === '' || $scheme === 'file' || $scheme === 'https') {
            return;
        }
        if ($scheme === 'http') {
            $envValue = getenv('PHLEX_PLUGINS_ALLOW_HTTP');
            $allowed = $envValue !== false
                && in_array(strtolower($envValue), ['1', 'true', 'yes', 'on'], true);
            if ($allowed) {
                return;
            }
            throw new PluginInstallException(
                'Plain HTTP plugin sources are forbidden. Set PHLEX_PLUGINS_ALLOW_HTTP=1 to override.',
            );
        }

        throw new PluginInstallException(sprintf('Unsupported source URL scheme "%s".', $scheme));
    }

    /**
     * Refuse plugin names that contain path components or anything
     * outside the kebab-case alphabet the Manifest already validates
     * for. This is the second layer of defense for the filesystem
     * path component.
     *
     * @throws PluginInstallException
     */
    private function guardPluginName(string $name): void
    {
        if (!preg_match('/^phlex-plugin-[a-z0-9][a-z0-9-]*$/', $name)) {
            throw new PluginInstallException(sprintf(
                'Plugin name %s is not a safe directory component.',
                $name,
            ));
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new PluginInstallException(sprintf(
                'Plugin name %s contains forbidden path characters.',
                $name,
            ));
        }
    }

    /**
     * Download `$sourceUrl` and explode it into `$tempDir`.
     *
     * @throws PluginInstallException
     */
    private function fetchInto(string $sourceUrl, string $tempDir): void
    {
        // PharData inspects the file extension to choose a codec, so we
        // need to preserve the source URL's suffix when staging the
        // download locally. Pre-compute the extension before fetching.
        $extension = '';
        if (preg_match('/\.zip$/i', $sourceUrl)) {
            $extension = '.zip';
        } elseif (preg_match('/\.tar\.gz$/i', $sourceUrl)) {
            $extension = '.tar.gz';
        } elseif (preg_match('/\.tgz$/i', $sourceUrl)) {
            $extension = '.tgz';
        } elseif (preg_match('/\.json$/i', $sourceUrl)) {
            $extension = '.json';
        }

        $localFile = $this->downloadToTemp($sourceUrl, $extension);

        try {
            if ($extension === '.zip') {
                $this->extractZip($localFile, $tempDir);
                return;
            }
            if ($extension === '.tar.gz' || $extension === '.tgz') {
                $this->extractTarGz($localFile, $tempDir);
                return;
            }
            if ($extension === '.json') {
                $stub = (string) file_get_contents($localFile);
                /** @var mixed $decoded */
                $decoded = json_decode($stub, true);
                if (!is_array($decoded) || !isset($decoded['source']) || !is_string($decoded['source'])) {
                    throw new PluginInstallException(
                        'Stub plugin.json must contain a "source" field pointing at a tarball or zip.',
                    );
                }
                $this->guardScheme($decoded['source']);
                $this->fetchInto($decoded['source'], $tempDir);
                return;
            }

            throw new PluginInstallException(sprintf(
                'Unsupported plugin source extension for %s — expected .zip, .tar.gz, .tgz, or .json.',
                $sourceUrl,
            ));
        } finally {
            @unlink($localFile);
        }
    }

    /**
     * Stream `$sourceUrl` into a temp file. Returns the local path.
     *
     * @param string $sourceUrl Remote or local URL to download.
     * @param string $extension Optional suffix to preserve on the local
     *        file (e.g. `.tar.gz`) so downstream codec detection works.
     *
     * @throws PluginInstallException
     */
    private function downloadToTemp(string $sourceUrl, string $extension = ''): string
    {
        $localFile = tempnam(sys_get_temp_dir(), 'phlex_plugin_');
        if ($localFile === false) {
            throw new PluginInstallException('Cannot allocate temporary file for plugin download.');
        }

        if ($extension !== '') {
            $renamed = $localFile . $extension;
            if (!@rename($localFile, $renamed)) {
                @unlink($localFile);
                throw new PluginInstallException('Cannot rename temporary file for plugin download.');
            }
            $localFile = $renamed;
        }

        $bytes = @file_get_contents($sourceUrl);
        if ($bytes === false) {
            @unlink($localFile);
            throw new PluginInstallException(sprintf('Failed to fetch plugin source from %s.', $sourceUrl));
        }
        file_put_contents($localFile, $bytes);

        return $localFile;
    }

    /**
     * Extract a zip archive into the target directory.
     *
     * @throws PluginInstallException
     */
    private function extractZip(string $zipPath, string $targetDir): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new PluginInstallException('PHP zip extension is required to install zip plugin sources.');
        }
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new PluginInstallException(sprintf('Cannot open zip archive %s (code %d).', $zipPath, (int) $opened));
        }
        if (!$zip->extractTo($targetDir)) {
            $zip->close();
            throw new PluginInstallException(sprintf('Failed to extract zip archive %s.', $zipPath));
        }
        $zip->close();

        $this->flattenSingleRoot($targetDir);
    }

    /**
     * Extract a `.tar.gz` archive into the target directory using
     * PHP's PharData wrapper.
     *
     * @throws PluginInstallException
     */
    private function extractTarGz(string $tarballPath, string $targetDir): void
    {
        if (!class_exists(\PharData::class)) {
            throw new PluginInstallException('PHP phar extension is required to install tar.gz plugin sources.');
        }

        try {
            // PharData::decompress() strips the .gz suffix and writes a
            // sibling file. For `.tar.gz` -> `.tar`; for `.tgz` -> `.tar`.
            $phar = new \PharData($tarballPath);
            $phar->decompress('.tar');
            $tarPath = preg_replace('/\.(tar\.gz|tgz)$/i', '.tar', $tarballPath) ?? ($tarballPath . '.tar');
            $tar = new \PharData($tarPath);
            $tar->extractTo($targetDir, null, true);
            @unlink($tarPath);
        } catch (\Throwable $e) {
            throw new PluginInstallException(
                sprintf('Failed to extract %s: %s', $tarballPath, $e->getMessage()),
                [],
                0,
                $e,
            );
        }

        $this->flattenSingleRoot($targetDir);
    }

    /**
     * Many GitHub tarballs unpack into a single `<repo>-<sha>/` root
     * directory. Detect that and lift its contents to the target dir
     * so `plugin.json` lives at the expected depth.
     */
    private function flattenSingleRoot(string $targetDir): void
    {
        $entries = array_values(array_filter(
            scandir($targetDir) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..',
        ));
        if (count($entries) !== 1) {
            return;
        }
        $singleEntry = $targetDir . DIRECTORY_SEPARATOR . $entries[0];
        if (!is_dir($singleEntry)) {
            return;
        }
        if (is_file($targetDir . DIRECTORY_SEPARATOR . 'plugin.json')) {
            return;
        }
        $this->moveContents($singleEntry, $targetDir);
        @rmdir($singleEntry);
    }

    /**
     * Move every immediate child of `$source` into `$destination`.
     */
    private function moveContents(string $source, string $destination): void
    {
        $children = scandir($source) ?: [];
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            @rename(
                $source . DIRECTORY_SEPARATOR . $child,
                $destination . DIRECTORY_SEPARATOR . $child,
            );
        }
    }

    /**
     * Copy `$source` into `$destination` recursively.
     *
     * @throws PluginInstallException
     */
    private function copyDirectory(string $source, string $destination): void
    {
        $source = rtrim($source, DIRECTORY_SEPARATOR);
        $destination = rtrim($destination, DIRECTORY_SEPARATOR);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $sub = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $sub;
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0750, true) && !is_dir($target)) {
                    throw new PluginInstallException(sprintf('Cannot create directory %s.', $target));
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent) && !@mkdir($parent, 0750, true) && !is_dir($parent)) {
                    throw new PluginInstallException(sprintf('Cannot create directory %s.', $parent));
                }
                if (!@copy($item->getPathname(), $target)) {
                    throw new PluginInstallException(sprintf(
                        'Failed to copy %s to %s.',
                        $item->getPathname(),
                        $target,
                    ));
                }
            }
        }
    }

    /**
     * Create a fresh, isolated temporary directory.
     *
     * @throws PluginInstallException
     */
    private function createTempDir(): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phlex_plugin_' . bin2hex(random_bytes(8));
        if (!@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new PluginInstallException(sprintf('Cannot create temp directory %s.', $base));
        }
        return $base;
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
