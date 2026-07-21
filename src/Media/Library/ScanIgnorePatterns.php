<?php

/**
 * Phlix media server component: Media\Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Admin\SettingsRepository;

/**
 * Single enforcement point for `scanner.ignore_patterns`.
 *
 * ## Why this class exists
 *
 * {@see MediaScanner::shouldSkipFile()} carried a bare
 * `['.part', '.tmp', '_unpack', '.download', '.!ut']` literal. It is a genuine
 * single choke point — seven in-file call sites, covering the flat walk, the
 * series-per-directory walk, its season/specials sub-walks and the progress
 * pre-count — so gating it here reaches every scan path.
 *
 * ## The dotfile rule is NOT part of this
 *
 * `shouldSkipFile()` keeps its own hardcoded `str_starts_with($filename, '.')`
 * check, which runs BEFORE this list is consulted and which no configured value
 * can weaken. An empty configured list is legal and means "skip nothing extra";
 * it does not make the scanner start indexing `.DS_Store` or `.@__thumb`.
 * Whether to import dotfiles is not an operator decision.
 *
 * ## Matching
 *
 * Case-insensitive, with two shapes distinguished by the pattern itself:
 *
 *  - a pattern containing any non-alphanumeric character is a plain SUBSTRING
 *    match — the shape all five original literals use, and preserved exactly;
 *  - a purely-alphanumeric pattern (the shipped `sample`) matches only as a
 *    DELIMITED TOKEN, so "Sample People (2000).mkv" is the only residual false
 *    positive rather than every title containing the letters s-a-m-p-l-e.
 *
 * The full rationale, including why the original case-SENSITIVE comparison was
 * a latent bug (SABnzbd writes `_UNPACK_`, uppercase), is in
 * `config/scanner.php`.
 *
 * ## Read path
 *
 * Class (a) LIVE, memoised per scan. {@see SettingsRepository::getEffective()}
 * is consulted lazily on first use and the resolved list is then cached until
 * {@see self::refresh()} is called, which {@see MediaScanner::scan()} and
 * {@see MediaScanner::countFiles()} do at the top of each walk.
 *
 * The memo is not an optimisation nicety: `shouldSkipFile()` runs once per
 * directory entry and does no other I/O, so an unmemoised `getEffective()`
 * would put a `server_settings` SELECT in front of every file in the library
 * and dominate the scan. Per-scan refresh is also the only granularity at which
 * a change is meaningful — swapping the ignore list halfway through a walk
 * would produce a half-filtered library. A change therefore applies to the next
 * scan, with no restart.
 *
 * ## Resident-memory note
 *
 * The memo is a single per-instance list bounded by the admin allow-list, and
 * it is REPLACED (never appended to) on every refresh. It is not static and
 * holds no request state.
 *
 * @package Phlix\Media\Library
 * @since 1.6.0
 */
final class ScanIgnorePatterns
{
    /**
     * The dotted settings key backing {@see self::patterns()}.
     */
    public const SETTING_KEY = 'scanner.ignore_patterns';

    /**
     * Shipped defaults, mirroring `config/scanner.php`.
     *
     * The first five are the literals previously hardcoded in
     * {@see MediaScanner::shouldSkipFile()}; `sample` is new.
     *
     * @var list<string>
     */
    public const DEFAULT_PATTERNS = [
        '.part',
        '.tmp',
        '.download',
        '.!ut',
        '_unpack',
        // NOT 'sample' — see config/scanner.php. Token matching makes it
        // safe to offer, not safe to impose: it would still skip a title
        // where the word stands alone, and a silently-missing movie is
        // undiagnosable. Opt-in via the admin setting.
    ];

    /**
     * Resolved, validated, lowercased pattern list; null until first use or
     * after {@see self::refresh()}.
     *
     * @var list<string>|null
     */
    private ?array $resolved = null;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::DEFAULT_PATTERNS}.
     *
     *        NOTE for DI: PHP-DI SKIPS optional constructor parameters during
     *        autowiring, so any binding that needs a configured instance must
     *        name this parameter explicitly. Left unnamed, the setting is inert
     *        by construction.
     */
    public function __construct(
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * Drop the memo so the next {@see self::patterns()} re-reads the store.
     *
     * Called at the top of each scan/count walk; see the class docblock for why
     * per-scan is the right granularity.
     *
     * @since 1.6.0
     */
    public function refresh(): void
    {
        $this->resolved = null;
    }

    /**
     * The effective ignore patterns, lowercased.
     *
     * @return list<string> Possibly empty (a configured empty list is legal).
     *
     * @since 1.6.0
     */
    public function patterns(): array
    {
        return $this->resolved ??= $this->resolve();
    }

    /**
     * Does `$filename` match any effective ignore pattern?
     *
     * Does NOT implement the dotfile rule — that stays in
     * {@see MediaScanner::shouldSkipFile()} and is deliberately unreachable
     * from configuration.
     *
     * @param string $filename Bare file or directory name (no path).
     *
     * @since 1.6.0
     */
    public function matches(string $filename): bool
    {
        $haystack = strtolower($filename);

        foreach ($this->patterns() as $pattern) {
            if (self::isWordPattern($pattern)) {
                if (self::containsToken($haystack, $pattern)) {
                    return true;
                }
                continue;
            }

            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read + validate the configured list, degrading to the shipped defaults.
     *
     * @return list<string>
     */
    private function resolve(): array
    {
        if ($this->settings === null) {
            return self::normalise(self::DEFAULT_PATTERNS);
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::SETTING_KEY);
        } catch (\Throwable) {
            // A settings-store failure must not blank the skip list and start
            // importing half-downloaded `.part` files as though they were media.
            return self::normalise(self::DEFAULT_PATTERNS);
        }

        // Not an array at all (a scalar written by hand, a `json` row that
        // decoded to null): fall back rather than guess.
        if (!is_array($configured)) {
            return self::normalise(self::DEFAULT_PATTERNS);
        }

        // An explicitly empty list is LEGAL and means "skip nothing extra".
        // It must survive normalisation as [] rather than being mistaken for
        // "unset" and replaced by the defaults.
        return self::normalise($configured);
    }

    /**
     * Coerce an arbitrary configured value into a clean lowercased list.
     *
     * Non-string entries (ints, nulls, nested arrays, objects — anything a
     * hand-written or restored `server_settings` JSON row can carry) are
     * DROPPED rather than stringified, so nothing unexpected ever reaches
     * {@see str_contains()}. Whitespace-only and empty strings are dropped too:
     * an empty pattern is a substring of every filename and would skip the
     * entire library.
     *
     * @param array<array-key, mixed> $values Raw configured entries.
     *
     * @return list<string> De-duplicated, lowercased, non-empty patterns.
     */
    private static function normalise(array $values): array
    {
        $out = [];

        /** @var mixed $value */
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $clean = strtolower(trim($value));
            if ($clean === '') {
                continue;
            }

            $out[$clean] = true;
        }

        return array_keys($out);
    }

    /**
     * Is `$pattern` purely alphanumeric, and therefore token-matched?
     *
     * Patterns carrying punctuation (`.part`, `_unpack`, `.!ut`) are
     * self-delimiting and stay plain substrings.
     */
    private static function isWordPattern(string $pattern): bool
    {
        return preg_match('/^[a-z0-9]+$/', $pattern) === 1;
    }

    /**
     * Does `$haystack` contain `$pattern` bounded by non-alphanumerics or by
     * the string edges?
     *
     * Hand-rolled rather than a `preg_match('/\b…/')` because the pattern comes
     * from operator-supplied configuration and must never be interpreted as a
     * regular expression. {@see self::isWordPattern()} already guarantees it is
     * alphanumeric, so no quoting question arises here either.
     *
     * @param string $haystack Lowercased filename.
     * @param string $pattern  Lowercased alphanumeric pattern.
     */
    private static function containsToken(string $haystack, string $pattern): bool
    {
        $length = strlen($pattern);
        $offset = 0;

        while (($pos = strpos($haystack, $pattern, $offset)) !== false) {
            $beforeOk = $pos === 0 || !self::isAlnumAt($haystack, $pos - 1);
            $afterPos = $pos + $length;
            $afterOk  = $afterPos >= strlen($haystack) || !self::isAlnumAt($haystack, $afterPos);

            if ($beforeOk && $afterOk) {
                return true;
            }

            $offset = $pos + 1;
        }

        return false;
    }

    /**
     * Is the byte at `$index` an ASCII letter or digit?
     */
    private static function isAlnumAt(string $subject, int $index): bool
    {
        $char = $subject[$index];

        return ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || ($char >= '0' && $char <= '9');
    }
}
