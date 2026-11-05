<?php

/**
 * Phlix media server component: Media\Transcoding.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use RuntimeException;

/**
 * Boot-time usability guard for the HLS segment cache directory (C5).
 *
 * The cache lives at `config['hls']['segment_dir']`, historically
 * `sys_get_temp_dir() . '/phlix_hls'`. Under the shipped systemd unit
 * (`PrivateTmp=true`) that path is a per-start scratch namespace, so EVERY
 * deploy/restart silently wiped the whole cache: the FIELD-hub-phlix trace
 * showed 6-second segments building 3.0–6.6 s inline on the cold cache —
 * exactly the playback lag the persistent-cache work (PHLIX_SEGMENT_DIR)
 * targets. A typo'd or unprovisioned override must NOT reproduce that
 * failure mode invisibly: an unusable directory is a boot-time
 * misconfiguration, so start.php aborts here with a clear message instead
 * of letting every first-play pay the inline-transcode tax forever.
 *
 * The rule is deliberately narrow:
 *   - the value must be a non-empty ABSOLUTE path (a relative override
 *     resolves against a per-process cwd — never what the operator meant);
 *   - an existing directory must be writable;
 *   - an existing path that is not a directory is refused;
 *   - a MISSING directory is fine only when creation is actually possible:
 *     every existing ancestor component must be a directory and the nearest
 *     existing parent must be writable (TranscodeManager creates lazily —
 *     `ensureHlsJob()` mkdirs per job, `produceSegment()` @mkdirs — this
 *     guard checks, it never creates).
 *
 * Budget semantics are untouched: `sweepSegmentCache()` (8 GiB LRU + 3 h
 * idle TTL) and `ensureDiskSpace()` (free-space floor) are keyed off the
 * SAME resolved directory, so an env-overridden persistent cache is governed
 * exactly like the temp default. Law-pinned by
 * SegmentCacheDirectoryTest + the budget tests in TranscodeManagerTest.
 *
 * @package Phlix\Media\Transcoding
 * @since   1.4.0
 */
final class SegmentCacheDirectory
{
    /**
     * Throw unless `$dir` names a directory the process can write into (or
     * would be able to create), with an operator-actionable message.
     *
     * @throws RuntimeException on any unusable shape (empty, relative,
     *                          not-writable, exists-but-not-a-dir, or a
     *                          creation path blocked by a non-directory /
     *                          non-writable ancestor).
     */
    public static function assertUsable(string $dir): void
    {
        $problem = self::describeUnusable($dir);
        if ($problem !== null) {
            throw new RuntimeException(self::message($dir, $problem));
        }
    }

    /**
     * True when {@see assertUsable()} would pass. Never throws.
     */
    public static function isUsable(string $dir): bool
    {
        return self::describeUnusable($dir) === null;
    }

    /**
     * @return string|null the refusal reason, or null when the path is usable
     */
    private static function describeUnusable(string $dir): ?string
    {
        if ($dir === '') {
            return 'is empty';
        }
        if ($dir[0] !== '/') {
            return 'is not an absolute path';
        }

        $probe = rtrim($dir, '/');
        if ($probe === '') {
            // The path was "/" itself — writable-only verdict applies.
            $probe = '/';
        }

        if (is_dir($probe)) {
            return is_writable($probe) ? null : 'exists but is not writable by this process';
        }
        if (file_exists($probe)) {
            return 'exists and is not a directory';
        }

        // Missing directory: creation is lazy (per-job mkdir in
        // TranscodeManager), so boot passes only when that mkdir can succeed
        // later. Walk to the nearest existing ancestor; any existing
        // intermediate that is NOT a directory dooms the chain, and the
        // nearest existing parent must be writable.
        $parent = dirname($probe);
        while ($parent !== '/' && !is_dir($parent)) {
            if (file_exists($parent)) {
                return 'cannot be created: ancestor "' . $parent . '" exists and is not a directory';
            }
            $next = dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }
        if (!is_dir($parent)) {
            return 'cannot be created: no existing ancestor directory';
        }
        return is_writable($parent)
            ? null
            : 'does not exist and cannot be created: parent "' . $parent . '" is not writable by this process';
    }

    private static function message(string $dir, string $problem): string
    {
        return 'HLS segment cache directory "' . $dir . '" ' . $problem
            . '. Fix PHLIX_SEGMENT_DIR (or config hls.segment_dir): it must be an absolute path'
            . ' the phlix-server process can write, or that it can create.'
            . ' Under the shipped systemd unit (PrivateTmp=true, ProtectSystem=strict) the path'
            . ' must live inside a ReadWritePaths directory — /var/phlix/hls is the provisioned'
            . ' persistent home (see scripts/install.sh). Unsetting the variable restores the'
            . ' default, sys_get_temp_dir()/phlix_hls, which works out of the box but is wiped'
            . ' on every restart.';
    }
}
