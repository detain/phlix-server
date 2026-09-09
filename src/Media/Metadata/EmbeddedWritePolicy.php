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
 * Single enforcement point for `metadata.embedded_write_enabled` — the
 * explicit, default-OFF opt-in that gates the destructive embedded-tag writer
 * (S89, updates.md #53).
 *
 * ## Why this class exists (and why it is NOT {@see MetadataOverwritePolicy})
 *
 * `MetadataOverwritePolicy::overwriteExisting()` answers "may a write that is
 * ALREADY allowed overwrite existing metadata?" and ships TRUE — permissive by
 * design, because it gates a non-destructive re-match. Embedded tag writing
 * asks the PRIOR question — "is embedded writing allowed AT ALL?" — and must
 * ship FALSE: rewriting tags inside the operator's media files can corrupt or
 * irreversibly alter them, so nothing may happen until the operator opts in
 * explicitly. The two policies answer different questions with opposite safe
 * defaults and therefore cannot share a class or a setting key.
 *
 * It is the third sibling of {@see MetadataOverwritePolicy} and
 * {@see \Phlix\Media\Storage\ArtworkDownloadPolicy}: a tiny value wrapping an
 * optional {@see SettingsRepository}, read LIVE at the decision point, so
 * toggling the setting takes effect without a restart.
 *
 * ## Read path
 *
 * The key is NOT composed into `config/server.php`; like its siblings the
 * effective value MUST come through the settings store
 * (`config/metadata.php`'s `embedded_write_enabled` is the shipped default the
 * store falls back to).
 *
 * ## Safe degradation — INVERTED versus the siblings
 *
 * A null store, an unreadable store and an unparseable value all yield
 * {@see self::DEFAULT_EMBEDDED_WRITE} (FALSE). For artwork-download and
 * overwrite the shipped default is "act as before" (true); for embedded
 * writing "act as before" IS off — the feature did not exist before S89 — so a
 * settings outage must NEVER silently start mutating media files. The only way
 * to switch embedded writing on is an explicit, readable "on".
 *
 * ## KNOWN LIMIT (honest scope)
 *
 * The key is read programmatically; it has no admin-UI surface until the
 * `detain/phlix-shared` `server-settings.schema.json` declares it (that schema
 * lives in the shared package, outside this repository and outside this step).
 *
 * @package Phlix\Media\Metadata
 * @since S89
 */
final class EmbeddedWritePolicy
{
    /**
     * The dotted settings key backing {@see self::embeddedWriteEnabled()}.
     */
    public const SETTING_KEY = 'metadata.embedded_write_enabled';

    /**
     * Shipped default: OFF. Embedded tag writing is destructive and strictly
     * opt-in; at this default {@see \Phlix\Media\Metadata\Writer\EmbeddedMetadataWriter}
     * never touches a media file.
     */
    public const DEFAULT_EMBEDDED_WRITE = false;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::DEFAULT_EMBEDDED_WRITE}.
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
     * May embedded tags be written into media files at all?
     *
     * @return bool True ONLY when an override explicitly and readably says "on".
     *
     * @since S89
     */
    public function embeddedWriteEnabled(): bool
    {
        if ($this->settings === null) {
            return self::DEFAULT_EMBEDDED_WRITE;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::SETTING_KEY);
        } catch (\Throwable) {
            // A settings-store failure must never masquerade as "the operator
            // enabled embedded writing" — that would present as media files
            // silently mutating behind a dead admin toggle. Degrade OFF.
            return self::DEFAULT_EMBEDDED_WRITE;
        }

        return self::coerce($configured);
    }

    /**
     * Interpret a persisted value as a boolean.
     *
     * Same explicit-spelling table as {@see MetadataOverwritePolicy::coerce()}
     * — a `server_settings` row can be written by direct SQL or a restored
     * backup and carry any textual spelling; anything unrecognised falls back
     * to the shipped default (here: OFF) rather than PHP loose truthiness,
     * because `(bool) 'false'` is TRUE and would enable a destructive writer.
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
                default => self::DEFAULT_EMBEDDED_WRITE,
            };
        }

        return self::DEFAULT_EMBEDDED_WRITE;
    }
}
