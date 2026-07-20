<?php

/**
 * Phlix media server component: Common.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Http;

/**
 * The ONE server-side pagination policy.
 *
 * Every list endpoint's `?limit=` (and `?offset=`) must pass through here
 * before it reaches a `LIMIT ?` binding. This exists because the server runs
 * under **Workerman (resident memory)**: an unclamped `limit` is not a "big
 * page", it is a memory-exhaustion vector against a long-lived worker process
 * that is concurrently serving every other user. One
 * `GET /api/v1/libraries/{id}/items?limit=100000000` could OOM a worker.
 *
 * {@see self::MAX} is a **hard compile-time ceiling**, deliberately NOT a
 * configurable default a client may exceed. Any future `media.max_page_size`
 * setting may only *lower* the effective page size — it can never raise it
 * above {@see self::MAX}.
 *
 * The bounds and the coercion rules are lifted verbatim from
 * {@see \Phlix\Media\Library\ItemRepository::normalizeLimit()} — the one place
 * in the codebase that already clamped correctly — and that method now
 * delegates here, so there is exactly one policy rather than two that can
 * drift. `100` is also the cap the SPA already documents and assumes
 * (`phlix-ui`: `CHILDREN_LIMIT = 100 // the browse API caps at 100 per page`).
 *
 * @package Phlix\Common\Http
 * @since   1.3.0
 */
final class PageLimit
{
    /** Smallest page size a caller may request. */
    public const MIN = 1;

    /**
     * Hard server-side maximum page size.
     *
     * NOT configurable upward. Matches
     * {@see \Phlix\Media\Library\ItemRepository::normalizeLimit()}'s existing
     * cap and the browse-API cap the SPA already assumes.
     */
    public const MAX = 100;

    /** Page size used when the caller supplied nothing usable and gave no default. */
    public const DEFAULT = 50;

    /**
     * Clamp a raw, caller-supplied page size into `[MIN, MAX]`.
     *
     * A missing / non-numeric value falls back to `$default`; the default is
     * itself clamped, so a controller cannot accidentally opt out of the
     * ceiling by declaring an oversized default.
     *
     * @param mixed $limit   Raw request value (string, int, null, anything).
     * @param int   $default Fallback when `$limit` is not numeric.
     *
     * @return int A page size guaranteed to satisfy `MIN <= n <= MAX`.
     *
     * @since 1.3.0
     */
    public static function clamp(mixed $limit, int $default = self::DEFAULT): int
    {
        $value = is_numeric($limit) ? (int) $limit : $default;

        if ($value < self::MIN) {
            return self::MIN;
        }
        if ($value > self::MAX) {
            return self::MAX;
        }

        return $value;
    }

    /**
     * Clamp a raw, caller-supplied offset to a non-negative integer.
     *
     * There is no upper bound on the offset: a large offset costs the database
     * a scan but never materialises an unbounded result set in worker memory,
     * which is the hazard {@see self::clamp()} exists to prevent.
     *
     * @param mixed $offset  Raw request value.
     * @param int   $default Fallback when `$offset` is not numeric.
     *
     * @return int A non-negative row offset.
     *
     * @since 1.3.0
     */
    public static function clampOffset(mixed $offset, int $default = 0): int
    {
        $value = is_numeric($offset) ? (int) $offset : $default;

        return $value < 0 ? 0 : $value;
    }
}
