<?php

/**
 * Phlix media server component: Media\Storage.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Storage;

use Phlix\Admin\SettingsRepository;

/**
 * Single enforcement point for `artwork.download_enabled`.
 *
 * ## Why this class exists
 *
 * Artwork fetching had no switch at all: once {@see ArtworkStorage} was wired,
 * every metadata persist tried to download a poster and a title logo. An
 * operator being rate-limited by TMDB, or one who has run out of space on the
 * `artwork.storage_path` volume, had no way to stop it short of unwiring the
 * storage service entirely — which also breaks serving the artwork already on
 * disk.
 *
 * The gate lives here rather than inside {@see ArtworkStorage} on purpose:
 * `ArtworkStorage` is also the class that RESOLVES and SERVES cached artwork
 * ({@see ArtworkStorage::url()}, {@see ArtworkStorage::srcset()},
 * {@see ArtworkStorage::relativePath()}). Gating at the storage layer would
 * risk suppressing reads along with writes. Gating at the two call sites that
 * are the sole callers of the two DOWNLOAD methods keeps the blast radius
 * exactly "no new fetches", and leaves every already-cached file readable.
 *
 * ## Enforcement points
 *
 * Both are in {@see \Phlix\Media\Metadata\LibraryMetadataMatcher}, immediately
 * beside the pre-existing `$this->artworkStorage === null` guard and in the
 * same shape:
 *
 *  - `cacheArtworkLocally()` — the only caller of
 *    {@see ArtworkStorage::downloadAndStore()};
 *  - `cacheLogoLocally()` — the only caller of
 *    {@see ArtworkStorage::downloadAndStoreLogo()}.
 *
 * Both are reached only through `persistMetadata()`, which is the single
 * funnel for every metadata persist on both the background-worker and the
 * interactive HTTP path, so there is no third route to the downloads.
 *
 * ## Read path
 *
 * Class (a) LIVE: {@see SettingsRepository::getEffective()} is consulted per
 * persist, so switching the setting off stops the very next download without a
 * restart. The schema entry must therefore be `"restart": false`.
 *
 * The per-persist read is affordable because `persistMetadata()` already
 * performs a `media_items` UPDATE and (when enabled) an outbound image fetch;
 * one additional indexed `server_settings` SELECT is noise against that. This
 * is deliberately NOT the same trade made by
 * {@see \Phlix\Media\Library\ScanIgnorePatterns}, whose consumer runs per FILE
 * with no other I/O and therefore has to memoise.
 *
 * ## Safe degradation
 *
 * A null store, an unreadable store and an unparseable value all yield
 * {@see self::DEFAULT_ENABLED} (true) — the historical behaviour. A settings
 * outage must not silently stop an install from collecting artwork; the only
 * way to disable downloads is an explicit, readable "off".
 *
 * @package Phlix\Media\Storage
 * @since 1.6.0
 */
final class ArtworkDownloadPolicy
{
    /**
     * The dotted settings key backing {@see self::downloadsEnabled()}.
     */
    public const SETTING_KEY = 'artwork.download_enabled';

    /**
     * Shipped default, matching `config/artwork.php` and the unconditional
     * behaviour this gate replaced.
     */
    public const DEFAULT_ENABLED = true;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::DEFAULT_ENABLED}.
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
     * May new artwork be downloaded from the metadata provider right now?
     *
     * @return bool False ONLY when an override explicitly and readably says so.
     *
     * @since 1.6.0
     */
    public function downloadsEnabled(): bool
    {
        if ($this->settings === null) {
            return self::DEFAULT_ENABLED;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::SETTING_KEY);
        } catch (\Throwable) {
            // A settings-store failure must never masquerade as "the operator
            // turned artwork off" — that would present as artwork silently
            // never appearing, with no admin control showing anything wrong.
            return self::DEFAULT_ENABLED;
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
                default => self::DEFAULT_ENABLED,
            };
        }

        return self::DEFAULT_ENABLED;
    }
}
