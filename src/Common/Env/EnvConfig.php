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
 * The helpers never throw: a config read cannot take the boot path down.
 * Callers own any further validation (bounds, enums). The numeric/text
 * readers are also silent; the bool family adds ONE soft exception — a
 * value that is neither a true word nor a false word is answered by the
 * default AND logged as a one-line `error_log` warning (E-04): junk in a
 * knob is operator-visible, but a warning is a recording, not a throw, so
 * the boot-path contract holds unchanged.
 *
 * @package Phlix\Common\Env
 * @since 1.2.4
 */
final class EnvConfig
{
    /**
     * Read an integer knob: unset/empty → $default, otherwise the value
     * cast the way `(int) getenv(...)` casts it (`"12x"` → 12 — the old
     * sites' behavior, preserved).
     */
    public static function integer(string $name, int $default): int
    {
        $raw = getenv($name);

        return $raw === false || $raw === '' ? $default : (int) $raw;
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
     * The words the bool grammar reads as TRUE (case-folded, trimmed).
     *
     * @var list<string>
     */
    private const TRUE_WORDS = ['1', 'true', 'yes', 'on'];

    /**
     * The words the bool grammar reads as FALSE (case-folded, trimmed).
     *
     * @var list<string>
     */
    private const FALSE_WORDS = ['0', 'false', 'no', 'off'];

    /**
     * Read a boolean knob — the single law E-04's nine hand-rolled truthy
     * lists should have been calling.
     *
     * Grammar (identical for every bool reader here, see
     * {@see boolValue()}): unset/empty → $default; true words → true; false
     * words → false; anything else → $default plus a one-line `error_log`
     * warning. The trim is load-bearing: the former copies disagreed on it,
     * so `PHLIX_RELAY_DISABLED=" false"` answered differently per site —
     * under one law it answers FALSE everywhere.
     */
    public static function bool(string $name, bool $default = false): bool
    {
        return self::boolValue(getenv($name), $default, $name);
    }

    /**
     * Read an OPT-OUT-style knob: a bool whose default is ON and which the
     * operator turns off by spelling a false word (the PHLIX_RELAY_ENABLED
     * shape). Same grammar as {@see bool()}; its own verb so the knob's
     * intent reads at the call site instead of a bare `, true)` argument.
     */
    public static function boolOptOut(string $name, bool $default = true): bool
    {
        return self::boolValue(getenv($name), $default, $name);
    }

    /**
     * The bool grammar as a pure function over an ALREADY-READ raw value.
     *
     * Public because a caller that receives the raw env value from its own
     * caller still owes the same law — `RelayTunnelBoot::envDisables()` is
     * exactly that seam (start.php runs outside PHPUnit, so the relay boot
     * gate is pinned by injecting raw values, not by putenv).
     *
     * @param string|false|null $raw     Raw env value; `getenv()`'s false and null both read as unset.
     * @param bool              $default Answer for unset/empty/junk.
     * @param string            $name    Knob name, used only to make the junk warning actionable.
     */
    public static function boolValue(string|false|null $raw, bool $default, string $name): bool
    {
        if (!is_string($raw)) {
            return $default;
        }

        $normalized = strtolower(trim($raw));

        if ($normalized === '') {
            return $default;
        }

        if (in_array($normalized, self::TRUE_WORDS, true)) {
            return true;
        }

        if (in_array($normalized, self::FALSE_WORDS, true)) {
            return false;
        }

        error_log(sprintf(
            'EnvConfig: %s="%s" is neither a true word (%s) nor a false word (%s); using default %s',
            $name,
            str_replace(["\r", "\n"], ' ', $normalized),
            implode('/', self::TRUE_WORDS),
            implode('/', self::FALSE_WORDS),
            $default ? 'true' : 'false',
        ));

        return $default;
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
