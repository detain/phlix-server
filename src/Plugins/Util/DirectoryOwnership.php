<?php

/**
 * Phlix media server component: Util.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Util;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Best-effort recursive ownership normalization for an installed plugin tree.
 *
 * Why this exists — a production incident: a plugin install/update executed as
 * **root** (a deploy-time catalog refresh, or an out-of-band maintenance
 * script such as `PluginUpdateService::updateAll()` run from a root shell)
 * leaves the freshly written plugin directory — and the `vendor/` tree
 * `ComposerRunner` creates inside it — owned by `root:root` at mode `0750`.
 * The resident server runs as a NON-root service user (systemd `User=phlix`),
 * which then cannot even traverse those directories. Autoload silently fails
 * and {@see \Phlix\Plugins\PluginLoader::wire()} reports the entry class
 * "does not exist (forgot composer install?)" for every enabled plugin — the
 * class is perfectly fine, the worker simply cannot read it.
 *
 * {@see self::matchToParent()} re-owns the tree to whoever owns the plugins
 * *base* directory (the parent of the install dir) — i.e. the service user
 * that provisioned `var/plugins` — so the worker can always read it regardless
 * of which user ran the install. When the installer already runs as that user
 * (the normal admin-UI install path, inside the `phlix` worker) it is a no-op.
 *
 * Best-effort by design: it NEVER throws. A wrong owner is a soft, now
 * self-diagnosing problem (see wire()'s permission-aware error), not a reason
 * to fail an otherwise-successful install. On platforms without POSIX
 * ownership, or when the process lacks the privilege to chown, it returns
 * false and leaves the tree untouched.
 *
 * @internal Phlix-internal utility.
 *
 * @package Phlix\Plugins\Util
 * @since 0.11.0
 */
final class DirectoryOwnership
{
    /**
     * Prevent instantiation — purely static utility.
     */
    private function __construct()
    {
    }

    /**
     * Recursively re-own `$target` (the directory itself and every descendant)
     * to match the owner and group of its parent directory.
     *
     * @param string $target Absolute path to the installed plugin directory.
     *
     * @return bool True when the tree matches the parent owner afterwards
     *              (including the fast path where it already did, and the
     *              "nothing to do because there is no POSIX ownership" case);
     *              false when a re-own was needed but could not be completed
     *              (e.g. running unprivileged against a root-owned tree).
     *
     * @since 0.11.0
     */
    public static function matchToParent(string $target): bool
    {
        if (!is_dir($target)) {
            return false;
        }
        // No POSIX ownership model (e.g. Windows) — nothing to normalize.
        if (!function_exists('chown') || !function_exists('fileowner')) {
            return true;
        }

        $parent = \dirname($target);
        $uid = @fileowner($parent);
        $gid = @filegroup($parent);
        if ($uid === false || $gid === false) {
            return false;
        }

        // Fast path: the installer wrote the whole tree as one user in one
        // pass, so a matching root owner means the tree is already consistent.
        if (@fileowner($target) === $uid && @filegroup($target) === $gid) {
            return true;
        }

        $ok = self::apply($target, $uid, $gid);
        /** @var SplFileInfo $entry */
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            ) as $entry
        ) {
            $ok = self::apply($entry->getPathname(), $uid, $gid) && $ok;
        }

        return $ok;
    }

    /**
     * chgrp + chown a single path, swallowing EPERM (unprivileged) failures.
     */
    private static function apply(string $path, int $uid, int $gid): bool
    {
        // Symlinks are re-owned with lchown/lchgrp so the link node itself
        // (not its target, which may live outside the tree) is touched.
        if (is_link($path)) {
            $g = function_exists('lchgrp') ? @lchgrp($path, $gid) : true;
            $o = function_exists('lchown') ? @lchown($path, $uid) : true;
            return $g && $o;
        }
        $g = @chgrp($path, $gid);
        $o = @chown($path, $uid);
        return $g && $o;
    }
}
