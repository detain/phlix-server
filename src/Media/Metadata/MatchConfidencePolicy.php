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
 * Single enforcement point for `metadata.min_match_confidence` (settings
 * program W3, finding F3).
 *
 * ## Why this class exists
 *
 * {@see MovieMetadataResolver::resolveTmdbId()} used to take
 * `$results[0] ?? null` BLIND: whatever TMDB's own popularity-ordered search
 * happened to rank first became the match, with no check that the returned
 * title/year resembled the file being matched. This policy supplies the
 * threshold that gate compares against; the score itself is computed by the
 * resolver's own {@see MovieMetadataResolver::matchConfidence()} heuristic.
 *
 * ## What the threshold does NOT claim (honesty law)
 *
 * The score is a cheap title-character-overlap + year-exactness HEURISTIC
 * computed in-resolver, not a provider-side relevance score (TMDB's own
 * `popularity`/`vote` ranking is a separate, follow-up concern). A threshold
 * of 0.7 therefore means "70% of this bounded title/year similarity model",
 * nothing more precise. Admin help text must not overstate it as a
 * probability or an accuracy guarantee.
 *
 * ## Read path
 *
 * Class (a) LIVE: {@see SettingsRepository::getEffective()} is consulted at
 * every match decision — NO static/instance cache of the value — so switching
 * the setting takes effect on the very next resolve without a restart
 * (resident-process law). `config/metadata.php` is NOT composed into
 * `config/server.php`, so the settings store is the only live read path;
 * the config file's `min_match_confidence` is the shipped default the store
 * falls back to.
 *
 * ## Safe degradation
 *
 * A null store, an unreadable store and an unparseable value all yield
 * {@see self::DEFAULT_MIN_CONFIDENCE} (0.0) — the pre-setting blind-first
 * behaviour. A settings outage must never START rejecting matches (that would
 * present as rescans mysteriously matching nothing while the admin control
 * shows nothing wrong); the only way to gate is an explicit, readable value.
 *
 * ## Clamps
 *
 * The value is a 0..1 similarity score bound, so anything outside is clamped
 * into the domain rather than rejected: an admin fat-fingering `7` gets the
 * strictest gate (1.0 = accept only a perfect title+year score), a negative
 * disables the gate (0.0). The shared schema's `minimum`/`maximum` stop the
 * admin API from persisting out-of-range values; these constants additionally
 * cover a `server_settings` row written by direct SQL, a restored backup, or
 * an orphaned row left behind by a renamed key.
 *
 * ## ADMIN-UI SURFACE (W4 follow-up closed the schema half)
 *
 * `detain/phlix-shared` declares this key in server-settings.schema.json
 * (5c4b59f) with the SAME bounds this class clamps to. Admin-API admission
 * is LIVE: the owner-gated re-vendor shipped the 84-key v0.51.0 schema into
 * `vendor/`, and AdminSettingsController::allowedKeys() derives from that
 * VENDORED schema — the key is PUT-able now, bounds and all (the -0.5-reject /
 * 0.5-accept pair is pinned against the real vendored schema in
 * tests/Integration/Admin/AdminSettingsRealSchemaPutTest). The effective-value
 * path has been live all along: this policy reads any `server_settings` row
 * (or config/metadata.php default) per lookup.
 *
 * @package Phlix\Media\Metadata
 * @since 1.8.0
 */
final class MatchConfidencePolicy
{
    /**
     * The dotted settings key backing {@see self::minConfidence()}.
     */
    public const SETTING_KEY = 'metadata.min_match_confidence';

    /**
     * Shipped default: 0.0 = gate OFF. Every non-null first search result is
     * accepted exactly as before the setting existed, so behaviour at this
     * default is byte-for-byte identical to the blind `$results[0]` path.
     */
    public const DEFAULT_MIN_CONFIDENCE = 0.0;

    /**
     * Domain floor of the similarity score (see {@see self::clamp()}).
     */
    public const MIN_BOUND = 0.0;

    /**
     * Domain ceiling of the similarity score: 1.0 accepts only a perfect
     * title+year heuristic score.
     */
    public const MAX_BOUND = 1.0;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::DEFAULT_MIN_CONFIDENCE}.
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
     * The effective minimum match confidence, clamped into 0..1.
     *
     * 0.0 means "accept the first search result regardless of score" — today's
     * behaviour. Values above 0 are read at EVERY resolve call (no caching),
     * so an override takes effect on the very next match.
     *
     * @return float Threshold in {@see self::MIN_BOUND}..{@see self::MAX_BOUND}.
     *
     * @since 1.8.0
     */
    public function minConfidence(): float
    {
        if ($this->settings === null) {
            return self::DEFAULT_MIN_CONFIDENCE;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::SETTING_KEY);
        } catch (\Throwable) {
            // A settings-store failure must never masquerade as "the operator
            // raised the confidence bar" — that would present as matches
            // silently vanishing behind a dead admin control. Degrade to the
            // pre-setting behaviour (gate off).
            return self::DEFAULT_MIN_CONFIDENCE;
        }

        return self::clamp(self::coerce($configured));
    }

    /**
     * Interpret a persisted value as a confidence float.
     *
     * The schema (once it declares this key) will type it `number`, and
     * {@see SettingsRepository} decodes `float`/`int` rows to real PHP numbers,
     * so those arms cover the normal path. The numeric-string arm exists because
     * a `server_settings` row can be written by any means and the store keeps
     * `value_type` alongside the raw text. Anything not recognised falls back
     * to the shipped default rather than being coerced by PHP loose casting.
     *
     * @param mixed $configured Raw effective value.
     */
    private static function coerce(mixed $configured): float
    {
        if (is_float($configured)) {
            return $configured;
        }

        if (is_int($configured)) {
            return (float) $configured;
        }

        if (is_string($configured) && is_numeric(trim($configured))) {
            return (float) trim($configured);
        }

        return self::DEFAULT_MIN_CONFIDENCE;
    }

    /**
     * Clamp a configured threshold into the 0..1 score domain.
     *
     * Also normalises non-finite floats (an `INF` row decodes through
     * `is_float`): they are unusable as a bound, so they degrade to the
     * shipped default rather than clamping to a domain edge.
     *
     * @param float $value Raw coerced value.
     */
    private static function clamp(float $value): float
    {
        if (!is_finite($value)) {
            return self::DEFAULT_MIN_CONFIDENCE;
        }

        return max(self::MIN_BOUND, min(self::MAX_BOUND, $value));
    }
}
