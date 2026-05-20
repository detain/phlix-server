<?php

declare(strict_types=1);

namespace Phlix\Plugins\Util;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Recursive directory removal helper used by
 * {@see \Phlix\Plugins\PluginLoader::uninstall()}.
 *
 * The plan calls for a hand-rolled helper rather than pulling in
 * `symfony/filesystem` just to delete a tree. The implementation
 * walks the tree with PHP SPL iterators in child-before-parent order,
 * so directories empty out before their `rmdir()` call.
 *
 * @internal Phlix-internal utility.
 *
 * @package Phlix\Plugins\Util
 * @since 0.10.0
 */
final class RecursiveDelete
{
    /**
     * Prevent instantiation — purely static utility.
     */
    private function __construct()
    {
    }

    /**
     * Delete `$path` recursively. A non-existent path is treated as a
     * successful no-op so callers don't need to guard with
     * {@see file_exists()} first.
     *
     * @param string $path Absolute or relative path to remove.
     *
     * @throws RuntimeException When a file or directory cannot be deleted.
     *
     * @since 0.10.0
     */
    public static function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException(sprintf('Failed to delete file %s.', $path));
            }
            return;
        }

        /** @var SplFileInfo $entry */
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            ) as $entry
        ) {
            $entryPath = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                if (!@rmdir($entryPath)) {
                    throw new RuntimeException(sprintf('Failed to remove directory %s.', $entryPath));
                }
            } else {
                if (!@unlink($entryPath)) {
                    throw new RuntimeException(sprintf('Failed to delete file %s.', $entryPath));
                }
            }
        }

        if (!@rmdir($path)) {
            throw new RuntimeException(sprintf('Failed to remove directory %s.', $path));
        }
    }
}
