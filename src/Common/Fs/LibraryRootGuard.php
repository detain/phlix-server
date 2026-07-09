<?php

/**
 * Phlix media server component: Fs.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Fs;

/**
 * Central path-jail guard for every file-serving controller.
 *
 * Media/cover/book paths stored in the database are treated as untrusted: a
 * poisoned `path` / `cover_path` (e.g. via a tampered scan record or a future
 * write path) must never be allowed to escape the configured library roots and
 * read arbitrary files such as `/etc/passwd`. This guard canonicalises the
 * candidate with {@see realpath()} (which resolves symlinks AND `../` segments
 * AND rejects non-existent paths) and verifies the resolved path is contained
 * within one of the allowed library roots.
 *
 * The allowed roots are resolved in priority order:
 *   1. An explicit set injected via {@see self::setRoots()} (e.g. derived from
 *      the configured libraries' `paths`), used when wired by the boot layer.
 *   2. The `PHLIX_LIBRARY_ROOTS` environment variable — a `PATH_SEPARATOR`
 *      (or comma) separated list of absolute directories.
 *   3. A hardcoded fallback allowlist of the well-known mount prefixes
 *      (`/media`, `/mnt`, `/data`, `/home`) — preserves the historical
 *      behaviour of {@see \Phlix\Server\Http\Controllers\AudiobookController}.
 *
 * Containment is checked with {@see str_starts_with()} against the canonical
 * real path plus a trailing slash on BOTH sides, so a path that merely
 * *contains* an allowed segment somewhere (e.g. `/var/www/home/secrets`) cannot
 * escape, and `/home/` can never match a sibling such as `/home-backup/`.
 *
 * This class performs only metadata syscalls ({@see realpath()}); it never
 * reads file contents, so it is safe to call inline on the Workerman event loop.
 *
 * @since 2.2.0
 */
final class LibraryRootGuard
{
    /**
     * Explicitly configured library roots (absolute, trailing-slash-normalised),
     * or null when not configured (fall back to env / hardcoded allowlist).
     *
     * @var list<string>|null
     */
    private static ?array $roots = null;

    /**
     * Well-known mount prefixes used when no roots are configured and the
     * `PHLIX_LIBRARY_ROOTS` env var is unset. Each entry is normalised to a
     * canonical, trailing-slash form by {@see self::resolveRoots()}.
     *
     * @var list<string>
     */
    private const FALLBACK_ROOTS = [
        '/media',
        '/mnt',
        '/data',
        '/home',
    ];

    /**
     * Configures the explicit set of allowed library roots, typically derived
     * from the `paths` of the configured libraries at boot.
     *
     * Each root is canonicalised with {@see realpath()}; entries that do not
     * resolve to a real directory are dropped. Passing an empty list (or only
     * non-resolving entries) results in no explicit roots being set, so the
     * env / hardcoded fallback applies.
     *
     * @param list<string> $roots Absolute directory paths.
     * @return void
     */
    public static function setRoots(array $roots): void
    {
        $normalised = self::normalise($roots);
        self::$roots = $normalised === [] ? null : $normalised;
    }

    /**
     * Clears any explicitly configured roots, restoring env / fallback
     * resolution. Primarily a test seam.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$roots = null;
    }

    /**
     * Asserts that the given candidate path resolves to a real file/directory
     * contained within one of the allowed library roots.
     *
     * Returns false (never throws) so callers can map a failure to a 404
     * response without leaking whether the path exists.
     *
     * @param string $path The untrusted candidate path.
     * @return bool True if the canonical path is within an allowed root.
     */
    public static function assertWithinLibraryRoots(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // realpath() returns false for non-existent paths, which also rejects
        // any traversal target that does not actually resolve to a real file.
        $realPath = realpath($path);
        if ($realPath === false) {
            return false;
        }

        // Append a trailing slash to the canonical path so prefix comparison is
        // boundary-safe: "/home/" must not match "/home-backup/...".
        $candidate = $realPath . '/';

        foreach (self::resolveRoots() as $root) {
            if (str_starts_with($candidate, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the effective set of allowed roots (trailing-slash-normalised).
     *
     * @return list<string>
     */
    private static function resolveRoots(): array
    {
        if (self::$roots !== null) {
            return self::$roots;
        }

        $env = getenv('PHLIX_LIBRARY_ROOTS');
        if (is_string($env) && trim($env) !== '') {
            $parts = preg_split('/[' . preg_quote(PATH_SEPARATOR, '/') . ',]/', $env);
            $candidates = $parts === false ? [] : $parts;
            $normalised = self::normalise($candidates);
            if ($normalised !== []) {
                return $normalised;
            }
        }

        // Hardcoded fallback. These are normalised by string only (no realpath)
        // so the well-known mount prefixes apply even on hosts/CI where e.g.
        // /media or /data do not physically exist.
        $roots = [];
        foreach (self::FALLBACK_ROOTS as $root) {
            $roots[] = rtrim($root, '/') . '/';
        }

        return $roots;
    }

    /**
     * Canonicalises and trailing-slash-normalises a list of candidate roots,
     * dropping any that do not resolve to a real directory.
     *
     * @param list<string> $roots
     * @return list<string>
     */
    private static function normalise(array $roots): array
    {
        $out = [];
        foreach ($roots as $root) {
            $trimmed = trim($root);
            if ($trimmed === '') {
                continue;
            }
            $real = realpath($trimmed);
            if ($real === false) {
                continue;
            }
            $normalised = rtrim($real, '/') . '/';
            if (!in_array($normalised, $out, true)) {
                $out[] = $normalised;
            }
        }

        return $out;
    }
}
