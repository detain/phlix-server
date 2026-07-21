<?php

/**
 * Library-scanner tunables.
 *
 * NOTE: this file is deliberately NOT composed into `config/server.php`, so it
 * is absent from the boot `$appConfig` array. Consumers must therefore read it
 * through {@see \Phlix\Config\EffectiveConfig::file('scanner')} or
 * {@see \Phlix\Admin\SettingsRepository::getEffective()} — a plain
 * `$appConfig['scanner']` lookup resolves to nothing and an override would
 * never arrive.
 *
 * @since 1.6.0
 */

declare(strict_types=1);

return [
    /*
     * Filename fragments that mark a file (or directory) as not-real-media, so
     * the scanner skips it. Backs the `scanner.ignore_patterns` admin setting
     * and is enforced at the single choke point
     * {@see \Phlix\Media\Library\MediaScanner::shouldSkipFile()}, which every
     * scan/count walk in that class routes through.
     *
     * MATCHING RULES
     *   - Case-INSENSITIVE. Both the filename and the pattern are lowercased
     *     before comparison. (The literal list this replaced was case-SENSITIVE,
     *     which meant SABnzbd's real marker directory `_UNPACK_` was never
     *     skipped despite `_unpack` being in the list, and `movie.TMP` slipped
     *     past `.tmp`. Making it insensitive fixes both.)
     *   - A pattern containing ANY non-alphanumeric character (`.part`, `.tmp`,
     *     `_unpack`, `.download`, `.!ut`) matches as a plain SUBSTRING, exactly
     *     as before. Such patterns are self-delimiting — the leading `.` or `_`
     *     is what makes them unambiguous — so substring matching is safe.
     *   - A pattern that is PURELY alphanumeric (`sample`) matches only as a
     *     DELIMITED TOKEN: it must be bounded on both sides by a non-alphanumeric
     *     character or by the start/end of the name. This is the deliberate
     *     narrowing described below.
     *
     * WHY `sample` IS TOKEN-MATCHED
     *   Sample files (`Movie.2020.1080p-GRP-sample.mkv`, `sample.mkv`,
     *   `Movie-Sample.mkv`) are a well-known library annoyance and had NO
     *   handling here at all. But a bare substring match on `sample` would also
     *   silently skip legitimate titles that merely contain the letters —
     *   "Sample People (2000).mkv" is a real film, and "Free Samples (2012)"
     *   would go too. Silently not importing a user's movie is a support ticket
     *   nobody can diagnose.
     *
     *   The token rule keeps every real sample-file naming convention (they all
     *   delimit the word with `.`, `-`, `_` or a space) while letting
     *   "Samples" and "Resample" through untouched. The residual false-positive
     *   is a title where `sample` stands alone as a whole word, e.g.
     *   "Sample People (2000).mkv" — that one IS still skipped. That is the
     *   accepted tradeoff, and it is now reversible: an operator hitting it
     *   removes `sample` from this list in the admin UI, which is precisely
     *   what this setting exists for. Before this setting there was no remedy
     *   for any of the five patterns at all.
     *
     * NOT CONFIGURABLE
     *   Dotfiles (a name starting with `.`) are skipped by a separate,
     *   hardcoded rule in `shouldSkipFile()` that this list cannot reach or
     *   disable. Setting this to `[]` is legal and means "skip nothing extra";
     *   it does NOT re-enable scanning of `.DS_Store`, `.@__thumb` and friends.
     *
     * VALIDATION
     *   The effective value must be a list of non-empty strings. Non-string
     *   entries are dropped rather than reaching `str_contains()`, and a value
     *   that is not an array at all falls back to these defaults. See
     *   {@see \Phlix\Media\Library\ScanIgnorePatterns}.
     */
    'ignore_patterns' => [
        // In-progress downloads (Firefox/aria2/generic `.part`, uTorrent
        // `.!ut`, browser `.download`) and generic temporary files.
        '.part',
        '.tmp',
        '.download',
        '.!ut',
        // SABnzbd/NZB post-processing staging directory (`_UNPACK_<name>`).
        '_unpack',
        //
        // DELIBERATELY NOT SHIPPED BY DEFAULT: 'sample'.
        //
        // Token matching (above) makes `sample` safe enough to OFFER, but not
        // safe enough to impose. It would still skip a title where the word
        // stands alone — "Sample People (2000).mkv" is a real film — and the
        // failure mode is a movie that silently never appears, which is exactly
        // the kind of thing an operator cannot diagnose. Turning that on for
        // every existing install at upgrade time is not ours to decide.
        //
        // So the shipped list stays byte-identical in EFFECT to the literal it
        // replaced, and `sample` is the first thing an operator should add if
        // release-group sample clips bother them. The admin UI help text says
        // so. (The one behaviour change at defaults is case-insensitivity,
        // which is an unambiguous bug fix: `_UNPACK_` and `movie.TMP` were
        // never matched before and unquestionably should be.)
    ],
];
