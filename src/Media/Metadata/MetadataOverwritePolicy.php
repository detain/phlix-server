<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Admin\SettingsRepository;

/**
 * Single enforcement point for `metadata.overwrite_existing`.
 *
 * ## Why this class exists
 *
 * A metadata (re)match unconditionally did `array_merge($existing, $resolved)`
 * at every persist site — freshly resolved fields always won. There was no way
 * for an operator who had hand-corrected an item's metadata to stop the next
 * forced rescan (or an interactive re-apply) from clobbering it.
 *
 * This class is the one place that answers "may a (re)match overwrite an item
 * that already has metadata right now?". It is a deliberate mirror of
 * {@see \Phlix\Media\Storage\ArtworkDownloadPolicy}: a tiny value that wraps an
 * optional {@see SettingsRepository} and reads the effective setting live, so
 * the exact same safe-degradation and DI-naming rules apply.
 *
 * ## Whole-item semantics (not per-field)
 *
 * There is no per-field provenance or "locked field" concept anywhere in the
 * pipeline, so "don't overwrite" can only mean a WHOLE-ITEM skip: when the
 * setting is off AND the item has already been resolved, the (re)match is
 * skipped entirely for that item — it is not re-resolved and not re-merged.
 * The decision is applied by {@see \Phlix\Media\Metadata\LibraryMetadataMatcher}
 * at its three (re)resolve entry points (`matchItem()`, `matchSeries()`,
 * `applyMatchResolved()`), which between them dominate every
 * `array_merge($existing, $resolved)` overwrite site in that class. This
 * mirrors the pre-existing manual-override short-circuit in `matchItem()`.
 *
 * ## Read path
 *
 * Class (a) LIVE: {@see SettingsRepository::getEffective()} is consulted at the
 * decision point, so switching the setting takes effect on the very next match
 * without a restart. `config/metadata.php` is NOT composed into
 * `config/server.php`, so a boot `$appConfig['metadata']` lookup would resolve
 * to nothing — the effective value MUST come through the settings store. The
 * schema entry is therefore `"restart": false`.
 *
 * ## Safe degradation
 *
 * A null store, an unreadable store and an unparseable value all yield
 * {@see self::DEFAULT_OVERWRITE} (true) — the historical unconditional-overwrite
 * behaviour. A settings outage must never silently START preserving stale
 * metadata (that would present as rescans mysteriously doing nothing); the only
 * way to switch overwrite off is an explicit, readable "off".
 *
 * @package Phlix\Media\Metadata
 * @since 1.7.0
 */
final class MetadataOverwritePolicy
{
    /**
     * The dotted settings key backing {@see self::overwriteExisting()}.
     */
    public const SETTING_KEY = 'metadata.overwrite_existing';

    /**
     * Shipped default (true = overwrite), matching the unconditional
     * `array_merge($existing, $resolved)` behaviour this gate replaced. At this
     * default the matcher behaves byte-for-byte as before the setting existed.
     */
    public const DEFAULT_OVERWRITE = true;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::DEFAULT_OVERWRITE}.
     *
     *        NOTE for DI: PHP-DI SKIPS optional constructor parameters during
     *        autowiring, so any binding that needs a configured policy must
     *        name this parameter explicitly. Left unnamed, the setting is inert
     *        by construction.
     */
    public function __construct(
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * May a (re)match overwrite an item that already has metadata right now?
     *
     * @return bool True unless an override explicitly and readably says "off".
     *
     * @since 1.7.0
     */
    public function overwriteExisting(): bool
    {
        if ($this->settings === null) {
            return self::DEFAULT_OVERWRITE;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::SETTING_KEY);
        } catch (\Throwable) {
            // A settings-store failure must never masquerade as "the operator
            // turned overwrite off" — that would present as rescans silently
            // refreshing nothing, with no admin control showing anything wrong.
            return self::DEFAULT_OVERWRITE;
        }

        return self::coerce($configured);
    }

    /**
     * Interpret a persisted value as a boolean.
     *
     * The schema types this key `bool`, and {@see SettingsRepository} decodes a
     * `bool` row to a real PHP bool, so the `is_bool()` arm covers the normal
     * path. The rest exists because a `server_settings` row can also be written
     * by direct SQL, a restored backup or a row orphaned by a renamed key, and
     * such a row can carry any of the textual spellings below. Anything not
     * recognised falls back to the default rather than being coerced by PHP's
     * loose truthiness — `(bool) 'false'` is TRUE, which is precisely the kind
     * of silent misreading this method exists to prevent.
     *
     * @param mixed $configured Raw effective value.
     */
    private static function coerce(mixed $configured): bool
    {
        if (is_bool($configured)) {
            return $configured;
        }

        if (is_int($configured)) {
            return $configured !== 0;
        }

        if (is_string($configured)) {
            return match (strtolower(trim($configured))) {
                '1', 'true', 'yes', 'on'   => true,
                '0', 'false', 'no', 'off', '' => false,
                default => self::DEFAULT_OVERWRITE,
            };
        }

        return self::DEFAULT_OVERWRITE;
    }
}
