<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Media\Library\Dto\LibraryRow;
use Workerman\MySQL\Connection;

/**
 * Answers one question: is this absolute path inside a configured library root?
 *
 * ## Why an authless route needs this
 *
 * {@see \Phlix\Server\Http\Controllers\Dlna\DlnaStreamController} serves file
 * bytes with **no credentials at all** — DLNA/UPnP has no concept of a user, so
 * the only gate is {@see \Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware}'s
 * inbound IP allowlist. The path it serves comes from `media_items.path`, never
 * from the request, so there is no *direct* traversal vector; this class is the
 * second layer. If a `media_items` row were ever poisoned — a buggy importer, a
 * plugin writing rows, an injection elsewhere in the stack — the DLNA surface
 * would otherwise be an unauthenticated arbitrary-file-read primitive for
 * anything the worker can open. Jailing to the library roots the operator
 * actually configured removes that class of consequence entirely.
 *
 * ## Fails CLOSED
 *
 * An empty root set denies everything. That is deliberate and safe: the roots
 * come from the same `libraries` rows the scanner walks, so "no resolvable
 * roots" means either nothing has ever been scanned (there are no items to
 * serve) or the database/mount is unavailable (in which case the item lookup
 * fails too). It never degrades to "allow anything".
 *
 * ## Resident-memory notes
 *
 * The root list is cached for {@see self::CACHE_TTL_SECONDS} on the INSTANCE
 * (not statically), and is a bounded list of library paths — never per-request
 * data — so it is safe in a long-lived Workerman worker while still picking up a
 * newly-added library within a minute. `libraries` is a tiny table and the query
 * is unparameterised and index-free by design (full scan of a handful of rows).
 *
 * @package Phlix\Media\Library
 * @since 1.7.0
 */
final class LibraryRootJail
{
    /** How long a resolved root list is reused before being re-read. */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Canonicalised roots, each guaranteed to end in a directory separator.
     *
     * @var list<string>|null
     */
    private ?array $cachedRoots = null;

    /** Unix timestamp {@see $cachedRoots} was populated (0 = never). */
    private int $cachedAt = 0;

    /**
     * @param Connection $db The MySQL connection used to read `libraries`.
     */
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Whether `$path` resolves to a location inside a configured library root.
     *
     * `$path` is canonicalised with {@see realpath()} first, so `..` segments and
     * symlinks are resolved BEFORE the prefix comparison — a symlink inside a
     * library that points outside it is rejected, which a naive string check on
     * the raw path would have allowed.
     *
     * @param string $path A filesystem path (absolute or relative).
     *
     * @return bool True only when the canonical path is a root itself or lies
     *              beneath one. False for a non-existent path, and false for
     *              every path when no root can be resolved (fail closed).
     */
    public function allows(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        foreach ($this->roots() as $root) {
            // `$root` carries a trailing separator, so /media/movies never
            // matches /media/movies-private.
            if (str_starts_with($real . DIRECTORY_SEPARATOR, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The canonicalised library roots, each with a trailing directory separator.
     *
     * Public so the decision can be explained (and asserted) without inferring
     * it from {@see self::allows()} alone.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        $now = time();
        if ($this->cachedRoots !== null && ($now - $this->cachedAt) < self::CACHE_TTL_SECONDS) {
            return $this->cachedRoots;
        }

        $roots = $this->readRoots();
        $this->cachedRoots = $roots;
        $this->cachedAt = $now;

        return $roots;
    }

    /**
     * Read and canonicalise every configured library path.
     *
     * A database failure yields an EMPTY list, which denies everything — see the
     * class docblock on failing closed.
     *
     * @return list<string>
     */
    private function readRoots(): array
    {
        try {
            /** @var mixed $rows */
            $rows = $this->db->query('SELECT paths FROM libraries');
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $roots = [];
        /** @var mixed $row */
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Reuse the ONE `paths` JSON decoder rather than a second copy.
            foreach (LibraryRow::fromRow(['paths' => $row['paths'] ?? null])->paths as $configured) {
                $real = realpath($configured);
                if ($real === false || !is_dir($real)) {
                    continue;
                }
                // Trailing separator makes the prefix test in allows() exact.
                // Deduped as a LIST, not via array keys: PHP silently coerces a
                // numeric-looking string key to an int, which would break the
                // list<string> contract for an (admittedly odd) numeric root.
                $normalized = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if (!in_array($normalized, $roots, true)) {
                    $roots[] = $normalized;
                }
            }
        }

        return $roots;
    }
}
