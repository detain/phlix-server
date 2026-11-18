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
     * Names of integer knobs whose env value was set but not a whole number. Each such
     * knob falls back to its default. Keyed by name, so it holds at most one entry per
     * knob. Read it with {@see invalidIntegerNames()} where a logger is available: this
     * class never logs, because the config files load before the logger is initialised.
     *
     * @var array<string, true>
     */
    private static array $invalidIntegers = [];

    /**
     * Read an integer knob: unset/empty → $default; a whole number (optionally signed,
     * surrounding whitespace allowed) → that value, so `"0"` is an explicit 0. Anything
     * else, such as `"abc"` or `"12x"`, is NOT silently cast to 0 (which would disable
     * a cap). It yields $default and the name is recorded in {@see invalidIntegerNames()}.
     */
    public static function integer(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $value = filter_var(trim($raw), FILTER_VALIDATE_INT);
        if ($value === false) {
            self::$invalidIntegers[$name] = true;

            return $default;
        }

        return $value;
    }

    /**
     * The env names read by {@see integer()} whose value was not a whole number.
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
