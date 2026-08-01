<?php

/**
 * Folder-watch settings — periodic filesystem-change detection for libraries.
 *
 * Drives {@see \Phlix\Media\Library\FolderWatchScheduler}, which registers each
 * library's paths with {@see \Phlix\Media\Library\FolderWatcher} and then
 * re-checks them on a timer inside the `library-scan` managed worker. A detected
 * change dispatches {@see \Phlix\Playlists\LibraryUpdated}.
 *
 * ## What enabling this does, and what it does NOT do
 *
 * The ONLY subscriber to `LibraryUpdated` is
 * {@see \Phlix\Playlists\SmartPlaylistRefreshSubscriber}, so today the sole
 * effect of enabling folder watching is that a smart COLLECTION's stored
 * membership (`collection_items`) is refreshed when files under its library
 * change, without waiting for the next scan.
 *
 * It does NOT enqueue a rescan, does not add or remove `media_items`, and does
 * not update metadata. New files stay invisible to the library until a scan
 * runs. Nothing here changes that.
 *
 * ## Why it ships DISABLED
 *
 * Change detection is a recursive walk that `stat()`s every file under a
 * watched path (`FolderWatcher::calculateDirectoryChecksum()`), run
 * synchronously on the `library-scan` worker's event loop. Its cost scales with
 * the file count and with per-`stat()` latency, so it is cheap on a local SSD
 * and can be far worse on network storage (sshfs, SMB); it has not been
 * measured on the production vault. While a walk is in progress the worker
 * cannot claim scan jobs. So this is opt-in, and `interval_seconds` is
 * deliberately unhurried.
 *
 * The scheduler bounds the damage: it touches at most ONE library per tick. The
 * one exception is a tick whose registration walk threw — that tick also
 * re-checks one library, so that a library which can never be registered (an
 * unreadable directory throws every time) cannot starve the others.
 *
 * @since 0.14.0
 */

declare(strict_types=1);

return [
    /*
     * Master switch. When false the scheduler arms no timer at all and the
     * worker's behaviour is byte-for-byte what it was before this file existed.
     * Ships false: see "Why it ships DISABLED" above.
     */
    'enabled' => false,

    /*
     * Seconds between ticks. One tick does at most one of:
     *   - register one not-yet-watched library (baseline walk, dispatches
     *     nothing); or
     *   - re-check one already-registered library (walk + dispatch on change).
     *
     * The exception noted above: a tick whose registration walk THREW also does
     * the re-check.
     *
     * Consequences of that "one library per tick" bound, both deliberate:
     *   - with N libraries, the N registration ticks come first, so the FIRST
     *     library is not re-checked until about (N + 1) x interval_seconds
     *     after the worker starts and the LAST not until about
     *     2N x interval_seconds; and
     *   - thereafter a given library is re-checked about every
     *     N x interval_seconds, so that is the detection latency, not
     *     interval_seconds itself.
     *
     * Lower it only if the walk is known to be fast on your storage.
     */
    'interval_seconds' => 300,
];
