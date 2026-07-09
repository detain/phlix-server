<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

/**
 * Derives the "sort title" of a media name by ignoring a leading article, so a
 * listing files e.g. "The Plot" under **P** (not T) while still DISPLAYING the
 * full "The Plot". Only the sort/group key changes — `media_items.name` (the
 * display title) is never modified.
 *
 * One source of truth ({@see self::ARTICLES}) is expressed two ways:
 *   - {@see self::from()} computes the key in PHP — used to expose the
 *     `sort_title` field on the API shape and by the unit tests; and
 *   - {@see self::sqlExpression()} / {@see self::letterSqlExpression()} build a
 *     portable SQL expression that {@see ItemRepository} drops into its
 *     `ORDER BY` and the A-Z letter-bucket `GROUP BY`, so the order the server
 *     returns matches the key it advertises.
 *
 * **Portability.** The SQL uses only `CASE` / `LOWER` / `LEFT` / `SUBSTRING` /
 * `TRIM` with a `COLLATE utf8mb4_bin` comparison — never `REGEXP_REPLACE`, whose
 * case-insensitive form differs between MySQL 8 and MariaDB 10.6 (both supported
 * per the README). `LOWER(...) COLLATE utf8mb4_bin` makes the prefix test
 * case-insensitive but accent-sensitive, exactly mirroring PHP's
 * {@see strncasecmp()} so the advertised key and the SQL order can never drift.
 * It needs no schema change and no backfill, so it is correct for every existing
 * and future row the instant it deploys.
 *
 * @author Phlix Development Team
 * @since 0.39.0
 */
final class SortTitle
{
    /**
     * Leading articles ignored when sorting/grouping — lowercased, WITHOUT the
     * trailing space. English (the/a/an) plus the common Romance and German
     * articles a multilingual library runs into. Each is stripped only when it
     * is a whole word — i.e. immediately followed by a space — so "Antman",
     * "Andes" or "Theory" keep their natural first letter.
     *
     * @var list<string>
     */
    public const ARTICLES = [
        'the', 'a', 'an',
        'el', 'la', 'le', 'les', 'los', 'las',
        'die', 'der', 'das',
    ];

    /**
     * Computes the sort key for a display name: strips a single leading article
     * (case-insensitive, whole word) and trims surrounding whitespace. Returns
     * the trimmed name unchanged when it carries no leading article.
     *
     * @param string $name The display name (e.g. "The Plot").
     * @return string The sort key (e.g. "Plot").
     */
    public static function from(string $name): string
    {
        foreach (self::ARTICLES as $article) {
            $prefix = $article . ' ';
            if (strncasecmp($name, $prefix, strlen($prefix)) === 0) {
                // trim(' ') — space-only — to mirror SQL TRIM() exactly (PHP's
                // default trim also strips \t\n\r\0\x0B; SQL TRIM strips only
                // U+0020), so this key never drifts from the SQL ORDER BY key.
                return trim(substr($name, strlen($prefix)), ' ');
            }
        }

        return trim($name, ' ');
    }

    /**
     * Builds the SQL expression that yields the article-stripped sort key for a
     * column. Mirrors {@see self::from()} branch-for-branch.
     *
     * The article list is a hardcoded `[a-z ]` allowlist, so interpolating it
     * into the SQL carries no injection risk; `$column` must likewise be a
     * trusted identifier (callers pass a literal column name).
     *
     * @param string $column Column holding the display name (default `name`).
     * @return string A SQL string expression (no surrounding parentheses).
     */
    public static function sqlExpression(string $column = 'name'): string
    {
        $cases = [];
        foreach (self::ARTICLES as $article) {
            $prefix = $article . ' ';
            // Byte length == character length here because every article is pure
            // ASCII, so it matches LEFT()/SUBSTRING()'s character indexing. A
            // future non-ASCII article (e.g. "l'") would break that and must be
            // added with care (and matching PHP/SQL slicing).
            $len = strlen($prefix);
            $start = $len + 1; // SUBSTRING() is 1-based: first char after the article + space.
            $cases[] = "WHEN LOWER(LEFT({$column}, {$len})) COLLATE utf8mb4_bin = '{$prefix}'"
                . " THEN SUBSTRING({$column}, {$start})";
        }

        return 'TRIM(CASE ' . implode(' ', $cases) . " ELSE {$column} END)";
    }

    /**
     * Builds the SQL expression for the uppercased first letter of the sort key
     * — the bucket key for the A-Z jump rail. Non-alphabetic results (digits,
     * symbols, empty) are folded to a single `#` bucket by the caller.
     *
     * @param string $column Column holding the display name (default `name`).
     * @return string A SQL string expression.
     */
    public static function letterSqlExpression(string $column = 'name'): string
    {
        return 'UPPER(LEFT(' . self::sqlExpression($column) . ', 1))';
    }
}
