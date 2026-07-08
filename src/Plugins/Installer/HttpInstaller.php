<?php

/**
 * Phlix media server component: Installer.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Plugins\Installer;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\Util\RecursiveDelete;

/**
 * Downloads a plugin source from a URL (or local `file://` path) into
 * `var/plugins/<name>/`.
 *
 * The incoming URL is first run through {@see SourceUrlResolver::normalize()},
 * which rewrites a git-host **repository** URL
 * (`https://github.com/owner/repo`) to its default-branch tarball, so the
 * admin UI and CLI can accept a plain repo URL. After normalisation the
 * source is one of these flavours:
 *
 *  - `*.zip`     — fetched, extracted with PHP's `ZipArchive`.
 *  - `*.tar.gz`  — fetched, extracted with `PharData`.
 *  - `*.json`    — treated as a "stub" `plugin.json` whose `source`
 *                  field points to a real tarball or zip; that URL is
 *                  itself normalised and then fetched recursively.
 *
 * Non-HTTPS URLs are refused unless the `PHLIX_PLUGINS_ALLOW_HTTP=1`
 * env var is set (default off — HTTPS-only). The `file://` scheme is
 * always allowed so unit and integration tests can stage local
 * fixtures.
 *
 * @package Phlix\Plugins\Installer
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
     * @param string      $sourceUrl     HTTPS URL, `file://` URL, or absolute path.
     * @param string|null $expectedSha256 When non-null, the lowercase 64-hex
     *        sha256 the downloaded artifact MUST hash to (SV-S1b). The artifact
     *        is verified after download and BEFORE extraction; a mismatch
     *        deletes the temp file and throws. `null` = legacy un-pinned path
     *        (governed by the loader's default-deny, see SV-S2b).
     * @param string|null $pinnedRef     When non-null, a GitHub repository URL is
     *        resolved to `/archive/<pinnedRef>.tar.gz` so the bytes hashed are
     *        the bytes pinned.
     *
     * @return array{0: Manifest, 1: string} Parsed manifest plus the
     *         absolute install directory.
     *
     * @throws PluginInstallException On any failure (download, validation,
     *         digest mismatch, signature mismatch, IO errors).
     *
     * @since 0.10.0
     */
    public function install(string $sourceUrl, ?string $expectedSha256 = null, ?string $pinnedRef = null): array
    {
        $sourceUrl = SourceUrlResolver::normalize($sourceUrl, $pinnedRef);
        $this->guardScheme($sourceUrl);

        $tempDir = $this->createTempDir();
        try {
            $this->fetchInto($sourceUrl, $tempDir, $expectedSha256);

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

            if (!$this->attemptAtomicMove($tempDir, $destination)) {
                // rename() cannot move across filesystems (EXDEV). The staging
                // dir lives under the system temp dir — a tmpfs under systemd
                // PrivateTmp — while the destination is on the install volume,
                // so a bare rename always fails there. Fall back to a recursive
                // copy + delete of the staging tree.
                try {
                    if (!is_dir($destination) && !@mkdir($destination, 0750, true) && !is_dir($destination)) {
                        throw new PluginInstallException(sprintf('Cannot create plugin directory %s.', $destination));
                    }
                    $this->copyDirectory($tempDir, $destination);
                    RecursiveDelete::remove($tempDir);
                } catch (\Throwable $e) {
                    throw new PluginInstallException(sprintf(
                        'Cannot move staged plugin from %s to %s: %s',
                        $tempDir,
                        $destination,
                        $e->getMessage(),
                    ));
                }
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
            // Exists but the daemon can't write it — the usual cause is a
            // sandboxed systemd unit (ProtectSystem=strict) whose ReadWritePaths
            // omits this directory, or wrong ownership after a manual change.
            if (!is_writable($this->pluginsBaseDir)) {
                throw new PluginInstallException(sprintf(
                    'Plugins base directory %s exists but is not writable by the '
                    . 'server user. Ensure it is owned by the service user; under a '
                    . 'sandboxed systemd unit it must also be listed in the unit\'s '
                    . 'ReadWritePaths. Re-running install.sh --update fixes both.',
                    $this->pluginsBaseDir,
                ));
            }
            return;
        }
        if (!@mkdir($this->pluginsBaseDir, 0750, true) && !is_dir($this->pluginsBaseDir)) {
            // Surface the OS-level reason (e.g. "Permission denied" / "Read-only
            // file system") plus the fix, instead of a bare "cannot create".
            $reason = error_get_last()['message'] ?? 'unknown error';
            throw new PluginInstallException(sprintf(
                'Cannot create plugins base directory %s (%s). It must exist and be '
                . 'writable by the server user; under a sandboxed systemd unit it must '
                . 'also be in the unit\'s ReadWritePaths. Re-running install.sh '
                . '--update creates and permits it.',
                $this->pluginsBaseDir,
                $reason,
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
            $envValue = getenv('PHLIX_PLUGINS_ALLOW_HTTP');
            $allowed = $envValue !== false
                && in_array(strtolower($envValue), ['1', 'true', 'yes', 'on'], true);
            if ($allowed) {
                return;
            }
            throw new PluginInstallException(
                'Plain HTTP plugin sources are forbidden. Set PHLIX_PLUGINS_ALLOW_HTTP=1 to override.',
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
        if (!preg_match('/^phlix-plugin-[a-z0-9][a-z0-9-]*$/', $name)) {
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
     * @param string|null $expectedSha256 Optional pinned artifact digest
     *        (SV-S1b); verified after download, before extraction.
     *
     * @throws PluginInstallException
     */
    private function fetchInto(string $sourceUrl, string $tempDir, ?string $expectedSha256 = null): void
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
            // SECURITY (SV-S1b): a `.json` stub is an indirection, not the final
            // artifact — the pinned digest must be checked against the real
            // archive the stub points at, so defer verification to the recursive
            // fetch. Every other (terminal) artifact is verified here, BEFORE
            // any extraction touches the filesystem.
            if ($extension !== '.json') {
                $this->verifyArtifactDigest($localFile, $expectedSha256);
            }

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
                $stubSource = SourceUrlResolver::normalize($decoded['source']);
                $this->guardScheme($stubSource);
                $this->fetchInto($stubSource, $tempDir, $expectedSha256);
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
        $localFile = tempnam(sys_get_temp_dir(), 'phlix_plugin_');
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
     * Verify a freshly-downloaded artifact against its pinned sha256 (SV-S1b).
     *
     * Called BEFORE extraction so a tampered/substituted archive never touches
     * the install tree. A mismatch deletes the temp file and throws; a `null`
     * pin is a no-op (the loader's default-deny in {@see PluginLoader} decides
     * whether an un-pinned install is allowed at all).
     *
     * @param string      $localFile      Absolute path to the downloaded archive.
     * @param string|null $expectedSha256 Lowercase 64-hex pin, or `null`.
     *
     * @throws PluginInstallException On digest mismatch.
     */
    private function verifyArtifactDigest(string $localFile, ?string $expectedSha256): void
    {
        if ($expectedSha256 === null || $expectedSha256 === '') {
            return;
        }

        $expected = strtolower(trim($expectedSha256));
        $actual = hash_file('sha256', $localFile);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            @unlink($localFile);
            throw new PluginInstallException(sprintf(
                'Plugin artifact digest mismatch — refusing install (expected sha256 %s, got %s).',
                $expected,
                is_string($actual) ? strtolower($actual) : '<unreadable>',
            ));
        }
    }

    /**
     * Extract a zip archive into the target directory.
     *
     * SECURITY (S9, zip-slip): `ZipArchive::extractTo($dir)` honours the
     * literal entry names inside the archive, so an entry like `../evil`
     * or `/etc/cron.d/x` would be written OUTSIDE `$targetDir`. Unlike the
     * tar path (which uses PharData strict mode), the zip API has no such
     * guard, so we validate every entry name first and only extract the
     * explicit list of validated names. A single malicious entry aborts the
     * whole install, having written nothing.
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

        try {
            $names = $this->validateZipEntries($zip, $zipPath);
            if (!$zip->extractTo($targetDir, $names)) {
                throw new PluginInstallException(sprintf('Failed to extract zip archive %s.', $zipPath));
            }
        } finally {
            $zip->close();
        }

        $this->flattenSingleRoot($targetDir);
    }

    /**
     * Validate every entry name in an open zip archive against zip-slip
     * (S9) and return the explicit list of safe names to extract.
     *
     * An entry is rejected when its name is absolute, starts with a slash
     * or backslash, contains a backslash separator, or — once URL-decoded
     * to defeat encoded traversal — resolves (purely lexically, without
     * touching the filesystem) to a path that escapes `$targetDir` via
     * `..` segments. The first offending entry aborts the install.
     *
     * @return list<string> The validated entry names, in archive order.
     *
     * @throws PluginInstallException On any unsafe entry.
     */
    private function validateZipEntries(\ZipArchive $zip, string $zipPath): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                throw new PluginInstallException(sprintf(
                    'Cannot read entry %d of zip archive %s.',
                    $i,
                    $zipPath,
                ));
            }
            $this->assertSafeArchiveEntry($name, $zipPath);
            $names[] = $name;
        }
        return $names;
    }

    /**
     * Reject a single archive entry name that would escape the extraction
     * root (zip-slip / path-traversal). Pure lexical check — never touches
     * the filesystem — so it is safe to call before anything is written.
     *
     * @throws PluginInstallException
     */
    private function assertSafeArchiveEntry(string $name, string $zipPath): void
    {
        // Decode once so a single layer of percent-encoded traversal
        // (e.g. `%2e%2e%2fevil`) is caught.
        $decoded = rawurldecode($name);

        foreach ([$name, $decoded] as $candidate) {
            // Normalise separators so a backslash cannot smuggle traversal
            // past a forward-slash-only check.
            $normalised = str_replace('\\', '/', $candidate);

            // Absolute paths (POSIX `/foo`, Windows `C:\foo` / `C:/foo`).
            if (str_starts_with($normalised, '/') || preg_match('#^[A-Za-z]:#', $normalised) === 1) {
                throw new PluginInstallException(sprintf(
                    'Refusing zip archive %s: entry "%s" uses an absolute path.',
                    $zipPath,
                    $name,
                ));
            }

            // Lexically resolve `.`/`..` segments and ensure we never climb
            // above the (virtual) extraction root.
            $depth = 0;
            foreach (explode('/', $normalised) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    $depth--;
                    if ($depth < 0) {
                        throw new PluginInstallException(sprintf(
                            'Refusing zip archive %s: entry "%s" escapes the extraction directory.',
                            $zipPath,
                            $name,
                        ));
                    }
                    continue;
                }
                $depth++;
            }
        }
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

        // PharData::decompress() strips the .gz suffix and writes a sibling
        // file. For `.tar.gz` -> `.tar`; for `.tgz` -> `.tar`. Pre-compute the
        // sibling path so the `finally` can clean it up even when decompress(),
        // `new PharData($tarPath)`, or extractTo() throws (B6: the intermediate
        // `.tar` must never be left behind in the system temp dir).
        $tarPath = preg_replace('/\.(tar\.gz|tgz)$/i', '.tar', $tarballPath) ?? ($tarballPath . '.tar');
        $phar = null;
        $tar = null;
        try {
            $phar = new \PharData($tarballPath);
            $phar->decompress('.tar');
            $tar = new \PharData($tarPath);
            $tar->extractTo($targetDir, null, true);
        } catch (\Throwable $e) {
            throw new PluginInstallException(
                sprintf('Failed to extract %s: %s', $tarballPath, $e->getMessage()),
                [],
                0,
                $e,
            );
        } finally {
            // Release the Phar handles before unlinking: PHP caches PharData by
            // filename, which can otherwise pin the `.tar` open and defeat the
            // unlink on some platforms (B6).
            unset($phar, $tar);
            @unlink($tarPath);
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
    /**
     * Attempt an atomic, same-filesystem move of the staged plugin into place.
     *
     * Returns false (rather than throwing) when `rename()` cannot complete —
     * most commonly EXDEV, because the staging dir is on a different filesystem
     * (a tmpfs under systemd `PrivateTmp`) than the install volume — so the
     * caller can fall back to a recursive copy. Extracted as a seam so the
     * cross-device copy fallback is unit-testable.
     *
     * @return bool True on a successful atomic move; false to trigger the copy fallback.
     */
    protected function attemptAtomicMove(string $tempDir, string $destination): bool
    {
        return @rename($tempDir, $destination);
    }

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
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phlix_plugin_' . bin2hex(random_bytes(8));
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
