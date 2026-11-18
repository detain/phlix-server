<?php

declare(strict_types=1);

namespace Phlix\Common\Env;

/**
 * The env-read idiom for config defaults, in one place.
 *
 * `getenv('X') ?: $default` is the estate's oldest config bug: PHP's `?:`
 * treats the STRING `"0"` as falsy, so an operator who explicitly sets
 * `X=0` silently gets the DEFAULT instead (canonical statement of the trap:
 * the note at the head of `config/database.php`'s pool block, which this
 * class finally generalizes — `M-SERVER-34`). This helper keeps the two
 * conventions the config files actually rely on, minus the trap:
 *
 * - unset, or set-but-empty (`""`), means "not configured" → the default;
 * - `"0"` is an EXPLICIT value and survives.
 *
 * The helpers never throw and never warn: a config read cannot take the
 * boot path down. Callers own any further validation (bounds, enums).
 *
 * @package Phlix\Common\Env
 * @since 1.2.4
 */
final class EnvConfig
{
    /**
     * Names of integer knobs whose env value was set but is not an integer. Each such knob
     * falls back to its default. Keyed by name, so it holds at most one entry per knob (the
     * knob names are fixed config literals, so the map is bounded). This class never logs
     * itself: the config files load before the logger is initialised. Read the list with
     * {@see invalidIntegerNames()} once a logger exists.
     *
     * @var array<string, true>
     */
    private static array $invalidIntegers = [];

    /**
     * A whole integer, optionally signed, with optional surrounding whitespace, optionally
     * followed by a `#` comment. The comment form exists because systemd EnvironmentFile and
     * docker env_file do not strip inline comments, so `PHLIX_X=0 # off` must still read 0,
     * exactly as the old `(int)` cast read it. Group 1 is the integer token.
     */
    private const INTEGER_PATTERN = '/^\s*([+-]?\d+)(?:\s*#.*)?\s*$/s';

    /**
     * Read an integer knob. Unset or empty gives $default. A whole integer gives that value,
     * with `"0"` an explicit 0, and `"010"` 10 and `"+5"` 5 as the old `(int)` cast read
     * them. The value may be followed by whitespace and a `#` comment, which reads the
     * integer before the comment. Anything else, such as `"auto"`, `"8G"`, `"1e3"` or
     * `"12x"`, gives $default and records the name for {@see invalidIntegerNames()}. A
     * non-integer is never cast to a prefix, and never to 0, which would disable a cap.
     */
    public static function integer(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $default;
        }

        if (preg_match(self::INTEGER_PATTERN, $raw, $matches) === 1) {
            return (int) $matches[1];
        }

        self::$invalidIntegers[$name] = true;

        return $default;
    }

    /**
     * The env names read by {@see integer()} whose value was not an integer.
     *
     * @return list<string>
     */
    public static function invalidIntegerNames(): array
    {
        return array_keys(self::$invalidIntegers);
    }


    /**
     * Read a string knob: unset/empty → $default, otherwise the raw value —
     * including `"0"`, which `?:` used to swallow.
     */
    public static function text(string $name, string $default): string
    {
        $raw = getenv($name);

        return $raw === false || $raw === '' ? $default : $raw;
    }

    /**
     * Read an optional string knob whose "not configured" marker is `null`
     * (secrets/tokens a later gate validates): unset/empty → null.
     */
    public static function nullableText(string $name): ?string
    {
        $raw = getenv($name);

        return $raw === false || $raw === '' ? null : $raw;
    }

    /**
     * Memoized answer of {@see cpuCountProbe()} — the count is a property of
     * the machine, and a process that asks once must not shell out again.
     */
    private static ?int $cpuCount = null;

    /**
     * Number of CPUs usable by this process, portable across venues.
     *
     * PHP has no core-count API without an extension, so the chain is:
     *  1. `nproc` (coreutils; affinity-aware — reflects taskset/cgroup limits
     *     on every distro that ships it, which is every Linux venue here);
     *  2. `/proc/cpuinfo` `processor:` lines (minimal images without nproc —
     *     busybox/alpine cuts — still mount procfs);
     *  3. `1` — the honest floor when nothing answers.
     *
     * Never throws, never warns (same contract as every other helper here).
     *
     * @since playback-lag wave C (see `config/ffmpeg.php`'s
     *        `segment_encode_threads` default, its first consumer).
     */
    public static function availableCpuCount(): int
    {
        if (self::$cpuCount !== null) {
            return self::$cpuCount;
        }

        return self::$cpuCount = self::cpuCountProbe();
    }

    private static function cpuCountProbe(): int
    {
        $out = @shell_exec('nproc 2>/dev/null');
        if (is_string($out) && preg_match('/^\s*(\d+)\s*$/', $out, $m) === 1) {
            $count = (int) $m[1];
            if ($count > 0) {
                return $count;
            }
        }

        if (is_readable('/proc/cpuinfo')) {
            $info = @file_get_contents('/proc/cpuinfo');
            if (is_string($info)) {
                $count = preg_match_all('/^processor\s*:/m', $info);
                if (is_int($count) && $count > 0) {
                    return $count;
                }
            }
        }

        return 1;
    }
}
