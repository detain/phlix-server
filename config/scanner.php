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

    /*
     * How many file reads the MUSIC scan may have in flight against the media
     * mount at once, INCLUDING the scanner's own. **S122(b).**
     *
     * 🛑 THE CEILING IS 4 AND IT WAS MEASURED. A value above 4 is CLAMPED to 4
     * by {@see \Phlix\Media\Music\MusicScanPrefetcher::clampReaders()} — this
     * file cannot raise the cap, only lower it.
     *
     * Parallel cold `open()`s on the production vault mount, 16 files per run
     * (`steps/vault-sshfs-read-perf-diagnostic.worklog.md`):
     *
     *   threads |  ms/file | files/s | speedup
     *   --------+----------+---------+--------
     *      1    |   117.0  |   8.5   |  1.00x
     *    **4**  |  **67.7**|**14.8** |**1.73x**
     *      8    |   197.8  |   5.1   |  0.59x  <- WORSE THAN SERIAL
     *     16    |   115.9  |   8.6   |  1.01x
     *
     * The backing store is a single rotational spindle (`r_await` 10.58 ms,
     * `%util` 12.45 at queue depth 0.14 — idle but latency-bound), so a few
     * concurrent requests fill the seek pipeline and more than that thrashes it.
     * "More parallelism is better" is measurably FALSE here.
     *
     * WHAT THE VALUE MEANS
     *   1 — no read-ahead pool AND no read-ahead walk: this knob's entire effect is
     *       removed and the scan touches the mount exactly as the pre-S122 scanner
     *       did. Set this if the media mount uses `direct_io` (which defeats the page
     *       cache the pool warms, so the bytes would be paid twice) or to isolate
     *       the pool while diagnosing something else.
     *       ⚠ "no read-ahead WALK" is part of the promise as of review r1
     *       (non-blocking 1). At 1 the pool has no children so every submit() was
     *       already a no-op, but the scanner still performed a SECOND
     *       RecursiveIteratorIterator pass over the tree — one readdir/getattr per
     *       entry, on the very escape valve that exists for a mount where those are
     *       most expensive. {@see \Phlix\Media\Music\MusicLibraryScanner::scanDirectory()}
     *       now creates that second walk only when the pool actually has readers.
     *       ⚠ This value does NOT switch off the S122(a) unchanged-file skip, which
     *       is an independent mechanism with its own gates
     *       ({@see \Phlix\Media\Music\MusicScanSkipIndex}). "Pre-S122" here means the
     *       READ PATTERN of a file the scan does open.
     *   2-4 — the scanner plus (value - 1) reader processes running a few files
     *       ahead of the walk. 4 is the measured optimum and the default.
     *
     * The pool cannot affect WHAT gets indexed — it reads bytes and discards
     * them — so this knob trades scan speed against mount pressure and nothing
     * else. See {@see \Phlix\Media\Music\MusicScanPrefetcher}.
     */
    'music_read_concurrency' => 4,
];
