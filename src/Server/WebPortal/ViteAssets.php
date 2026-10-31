<?php

/**
 * Phlix media server component: WebPortal.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\WebPortal;

use RuntimeException;

/**
 * Reads Vite's manifest.json to provide asset paths for the built SPA.
 *
 * @package Phlix\Server\WebPortal
 * @since   0.11.0 (Phase C)
 */
final class ViteAssets
{
    private readonly string $manifestPath;

    /**
     * minors M-SERVER-33 (W-min109): /app boot used to stat, read and
     * json_decode the manifest on EVERY request. Within a resident worker the
     * parsed map is now memoized against the file's (mtime:size) fingerprint —
     * a deploy replacing the manifest changes the fingerprint and the next boot
     * re-parses (busting is inherent; no signal needed). The file_exists gate
     * still runs per call, so the first-boot ordering law and the
     * manifest-removed failure shape are untouched. Seam for tests:
     * resetParsedForTesting().
     *
     * @var array<string, array{fingerprint: string, manifest: mixed}>
     */
    private static array $parsed = [];

    /** Clears the per-worker manifest memo (test seam, minors M-SERVER-33). */
    public static function resetParsedForTesting(): void
    {
        self::$parsed = [];
    }

    /**
     * @param string $publicRoot Absolute path to the server's public/ directory.
     */
    public function __construct(private readonly string $publicRoot)
    {
        $this->manifestPath = $this->publicRoot . '/assets/app/.vite/manifest.json';
    }

    /**
     * Returns the path to the entry JavaScript file from the Vite manifest.
     *
     * @return string Absolute path to the entry JS asset.
     *
     * @throws RuntimeException If the manifest file does not exist.
     */
    public function getEntryJsPath(): string
    {
        if (! file_exists($this->manifestPath)) {
            throw new RuntimeException(
                "Vite manifest not found at {$this->manifestPath}. "
                . 'Run `cd web-ui && npm ci && npm run build` first.'
            );
        }

        // PHP caches stat() results per path and the test's touch()/a deploy's
        // rename do NOT invalidate that cache — without this the fingerprint
        // would read stale forever (same law as SubtitleController's cache
        // path check). clearstatcache(true, $path) clears ONLY this file.
        clearstatcache(true, $this->manifestPath);
        $mtime = filemtime($this->manifestPath);
        $size = filesize($this->manifestPath);
        $fingerprint = ($mtime === false ? 'x' : (string) $mtime)
            . ':' . ($size === false ? 'x' : (string) $size);
        $memo = self::$parsed[$this->manifestPath] ?? null;
        if (is_array($memo) && ($memo['fingerprint'] ?? null) === $fingerprint) {
            /** @var array<string, array{file: string, isEntry?: bool}> $manifest */
            $manifest = $memo['manifest'];
        } else {
            $content = file_get_contents($this->manifestPath);
            if ($content === false) {
                throw new RuntimeException("Could not read Vite manifest at {$this->manifestPath}.");
            }

            /** @var array<string, array{file: string, isEntry?: bool}> $manifest */
            $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            self::$parsed[$this->manifestPath] = ['fingerprint' => $fingerprint, 'manifest' => $manifest];
        }

        foreach ($manifest as $entry) {
            if (($entry['isEntry'] ?? false) === true && isset($entry['file'])) {
                return '/' . $entry['file'];
            }
        }

        throw new RuntimeException(
            "No entry point found in Vite manifest at {$this->manifestPath}."
        );
    }

    /**
     * Returns the absolute path to the public root directory.
     */
    public function getPublicRoot(): string
    {
        return $this->publicRoot;
    }
}
