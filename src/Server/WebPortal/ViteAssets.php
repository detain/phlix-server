<?php

/**
 * Phlix media server component: WebPortal.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
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
                . 'Run `cd web-ui && npm install && npm run build` first.'
            );
        }

        $content = file_get_contents($this->manifestPath);
        if ($content === false) {
            throw new RuntimeException("Could not read Vite manifest at {$this->manifestPath}.");
        }

        /** @var array<string, array{file: string, isEntry?: bool}> $manifest */
        $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

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
