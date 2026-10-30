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
}
