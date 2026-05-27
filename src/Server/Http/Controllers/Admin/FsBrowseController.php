<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Throwable;

/**
 * Admin JSON API for browsing the filesystem when picking a library path
 * (Step 0.6).
 *
 *   - `GET /api/v1/admin/fs/browse?path=…` → the immediate SUBDIRECTORIES of
 *     `path`, restricted to the configured allowed roots. With no/empty `path`
 *     it returns the configured roots themselves as the top-level entry list,
 *     giving the picker a starting point.
 *
 * This is intentionally NOT a general file manager: it lists directories only
 * (never files), and supports no read/write/delete. Files within a directory
 * are excluded from the result.
 *
 * Path-traversal safety: every candidate path is canonicalised with
 * {@see realpath()} (which collapses `..` segments AND resolves symlinks) and
 * then checked against each allowed root with a trailing-slash prefix test —
 * `$real === $root || str_starts_with($real . '/', $root . '/')` — NEVER
 * `str_contains()`. Because realpath() canonicalises both `..` and symlinks,
 * this single check rejects `../` escapes and symlinks that point outside the
 * jail alike (both yield 403). This mirrors the canonical jail pattern in
 * {@see \Phlix\Server\Http\Controllers\AudiobookController::validateMediaPath()}.
 *
 * Route group is gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}); non-admin
 * callers receive a JSON 401/403 from the middleware, so this controller
 * assumes it only runs for authenticated admins.
 *
 * Resident-memory rules: no `exit`/`die`, no blocking `sleep()`, no request
 * data parked in `static`/`global`. The allowed-roots list is shared/immutable
 * config data captured at construction, not request state, so it is safe. The
 * `realpath`/`scandir`/`is_dir` calls are synchronous filesystem syscalls,
 * consistent with the existing {@see \Phlix\Server\Http\Controllers\AudiobookController}
 * precedent; there is no async filesystem API in this stack.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since   0.6 (Filesystem-browse endpoint)
 */
final class FsBrowseController
{
    /**
     * Canonicalised allowed roots (no trailing slash). Each entry is the
     * {@see realpath()} of a configured `browse_roots` path; roots that do not
     * resolve are dropped at construction.
     *
     * @var list<string>
     */
    private array $allowedRoots;

    /**
     * @param list<string> $allowedRoots Configured browse roots; each is
     *        canonicalised via {@see realpath()} and non-resolving entries are
     *        dropped. Survivors are stored without a trailing slash.
     *
     * @since 0.6
     */
    public function __construct(array $allowedRoots)
    {
        $resolved = [];
        foreach ($allowedRoots as $root) {
            $real = realpath($root);
            if ($real === false) {
                continue;
            }
            $resolved[] = rtrim($real, '/');
        }

        $this->allowedRoots = $resolved;
    }

    /**
     * List the immediate subdirectories of `?path=`, jailed to the allowed
     * roots.
     *
     * GET /api/v1/admin/fs/browse?path=…
     *
     * Behaviour:
     *   - empty/absent `path` → 200 with the configured roots as the entry list
     *     (`data.path` and `data.parent` are `null`);
     *   - `realpath()` fails (non-existent / unresolvable) → 404;
     *   - resolves but is not a directory → 400;
     *   - resolves to a directory NOT under any allowed root (covers `../` and
     *     symlink escapes) → 403;
     *   - valid directory under a root → 200 with its immediate subdirectories.
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON `{ success, data: { path, parent, entries } }` on
     *         success, or `{ success:false, error }` with the mapped status.
     *
     * @since 0.6
     */
    public function browse(Request $request, array $params): Response
    {
        try {
            $path = $request->queryString('path', '');

            if ($path === '' || $path === null) {
                return (new Response())->json([
                    'success' => true,
                    'data'    => [
                        'path'    => null,
                        'parent'  => null,
                        'entries' => $this->rootsAsEntries(),
                    ],
                ]);
            }

            $real = realpath($path);
            if ($real === false) {
                return (new Response())->status(404)->json([
                    'success' => false,
                    'error'   => 'Path not found',
                ]);
            }

            if (!is_dir($real)) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error'   => 'Not a directory',
                ]);
            }

            if (!$this->isAllowed($real)) {
                return (new Response())->status(403)->json([
                    'success' => false,
                    'error'   => 'Path is outside the allowed roots',
                ]);
            }

            $entries = $this->listSubdirectories($real);

            $parentPath = dirname($real);
            $parent     = ($parentPath !== $real && $this->isAllowed($parentPath))
                ? $parentPath
                : null;

            return (new Response())->json([
                'success' => true,
                'data'    => [
                    'path'    => $real,
                    'parent'  => $parent,
                    'entries' => $entries,
                ],
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error'   => 'Failed to browse filesystem',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the entry list for the configured roots (the empty-path response).
     *
     * @return list<array{name: string, path: string}>
     */
    private function rootsAsEntries(): array
    {
        $entries = [];
        foreach ($this->allowedRoots as $root) {
            $entries[] = [
                'name' => basename($root),
                'path' => $root,
            ];
        }

        return $entries;
    }

    /**
     * List the immediate subdirectories of a (already validated) directory,
     * sorted by name. Files and the `.`/`..` entries are excluded.
     *
     * @param string $dir Canonical directory path.
     *
     * @return list<array{name: string, path: string}>
     */
    private function listSubdirectories(string $dir): array
    {
        $names = scandir($dir);
        if ($names === false) {
            return [];
        }

        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $child = $dir . '/' . $name;
            if (!is_dir($child)) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'path' => $child,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $entries;
    }

    /**
     * Whether a canonical path is equal to or nested under an allowed root.
     *
     * Uses the trailing-slash prefix form so that a sibling such as
     * `/home-backup` cannot match the `/home` root.
     *
     * @param string $real A canonical (realpath'd) absolute path.
     *
     * @return bool True when the path is within the jail.
     */
    private function isAllowed(string $real): bool
    {
        foreach ($this->allowedRoots as $root) {
            if ($real === $root || str_starts_with($real . '/', $root . '/')) {
                return true;
            }
        }

        return false;
    }
}
