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
 * Single enforcement point for `metadata.cache_ttl_hours` (settings program W3).
 *
 * ## Why this class exists
 *
 * {@see MetadataManager::hasRecentMetadata()} hardcoded `< 86400` (24 hours) as
 * the "is this provider's metadata fresh enough to skip re-fetching?" window.
 * Providers change on their own schedules and operators with volatile
 * libraries (airing shows, newly released films) want to shorten the window
 * while stable archives want to lengthen it. This policy owns the effective
 * value AND the freshness decision itself, so the one comparison lives in one
 * testable place.
 *
 * ## Read path
 *
 * Class (a) LIVE: {@see SettingsRepository::getEffective()} is consulted at
 * every freshness check — NO static/instance cache of the value, per the
 * resident-process law — so changing the TTL takes effect on the very next
 * refresh without a restart. `config/metadata.php` is NOT composed into
 * `config/server.php`, so the settings store is the only live read path; the
 * config file's `cache_ttl_hours` is the shipped default the store falls back
 * to.
 *
 * ## Safe degradation
 *
 * A null store, an unreadable store and an unparseable value all yield
 * {@see self::DEFAULT_TTL_HOURS} (24) — the historical hardcoded window. A
 * settings outage therefore keeps REFRESHING at exactly the old cadence; it can
 * neither stampede providers (a 0/unparseable TTL re-fetching everything every
 * pass) nor freeze metadata forever (an INF/negative TTL never re-fetching).
 *
 * ## Clamps
 *
 * 1 hour floor: a sub-hour window makes every library pass a re-fetch storm
 * against rate-limited providers for negligible staleness gains. 8760-hour
 * (365-day) ceiling: past a year "cache" functionally means "never refresh",
 * which is a provider-removal decision, not a TTL. The shared schema's
 * `minimum`/`maximum` (follow-up lane) stop the admin API from persisting
 * out-of-range values; these constants additionally cover a `server_settings`
 * row written by direct SQL, a restored backup, or an orphaned row left behind
 * by a renamed key.
 *
 * ## KNOWN LIMIT (honest scope)
 *
 * Until `detain/phlix-shared`'s server-settings.schema.json declares this key
 * (a follow-up — outside this lane), it has no admin-UI surface and the only
 * way to set an override is a `server_settings` row, exactly like
 * `metadata.embedded_write_enabled` shipped first.
 *
 * @package Phlix\Media\Metadata
 * @since 1.8.0
 */
final class MetadataCachePolicy
{
    /**
     * The dotted settings key backing {@see self::ttlHours()}.
     */
    public const SETTING_KEY = 'metadata.cache_ttl_hours';

    /**
     * Shipped default: 24 hours. At this default {@see self::ttlSeconds()}
     * returns exactly the 86400 the manager hardcoded before the setting
     * existed, so behaviour is byte-for-byte preserved.
     */
    public const DEFAULT_TTL_HOURS = 24;

    /**
     * Floor: 1 hour (see class docblock "Clamps").
     */
    public const MIN_TTL_HOURS = 1;

    /**
     * Ceiling: 365 days (see class docblock "Clamps").
     */
    public const MAX_TTL_HOURS = 8760;

    /**
     * Seconds per hour — the unit conversion between the admin-facing key
     * (hours, human-scale) and the comparison (unix timestamps, seconds).
     */
    private const SECONDS_PER_HOUR = 3600;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::DEFAULT_TTL_HOURS}.
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
     * The effective cache TTL in hours, clamped to the defended domain.
     *
     * @return int Hours in {@see self::MIN_TTL_HOURS}..{@see self::MAX_TTL_HOURS}.
     *
     * @since 1.8.0
     */
    public function ttlHours(): int
    {
        if ($this->settings === null) {
            return self::DEFAULT_TTL_HOURS;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::SETTING_KEY);
        } catch (\Throwable) {
            // A settings-store failure keeps the historical refresh cadence
            // (24h) rather than inventing a new one — see class docblock.
            return self::DEFAULT_TTL_HOURS;
        }

        return self::clamp(self::coerce($configured));
    }

    /**
     * The effective cache TTL in seconds.
     *
     * @return int Seconds — exactly 86400 at the shipped default.
     *
     * @since 1.8.0
     */
    public function ttlSeconds(): int
    {
        return $this->ttlHours() * self::SECONDS_PER_HOUR;
    }

    /**
     * Is a stored `metadata_refreshed_at` value still fresh at `$now`?
     *
     * The single comparison this policy owns. Absent/unparseable timestamps
     * are NOT fresh (a refresh is attempted), exactly as
     * {@see MetadataManager::hasRecentMetadata()} behaved before this class
     * existed. One honest nuance preserved from the original: the old code
     * compared `(time() - $refreshedAt) < 86400` with no lower bound, so a
     * clock-skewed FUTURE timestamp also counted fresh. That leniency is kept
     * byte-for-byte — changing it would be a behavior change, not a bug-fix.
     *
     * The clock is INJECTED (`$now`), never read here, so boundary behaviour
     * is unit-testable without process-time fiddling; the production caller
     * passes `time()` at the decision point. `time()` (wall clock) is correct
     * here: `metadata_refreshed_at` is a wall-clock timestamp, so the
     * monotonic-clock rule for measured intervals does not apply.
     *
     * @param string|null $refreshedAtRaw The stored timestamp string, if any.
     * @param int         $now            Current unix time, injected by caller.
     *
     * @return bool True when the stored refresh is younger than the TTL.
     *
     * @since 1.8.0
     */
    public function isFresh(?string $refreshedAtRaw, int $now): bool
    {
        if ($refreshedAtRaw === null) {
            return false;
        }

        $refreshedAt = strtotime($refreshedAtRaw);
        if ($refreshedAt === false) {
            return false;
        }

        return ($now - $refreshedAt) < $this->ttlSeconds();
    }

    /**
     * Interpret a persisted value as a whole number of hours.
     *
     * Same tolerated-spelling story as {@see MatchConfidencePolicy::coerce()}:
     * real ints/floats cover the decoded-row path, numeric strings cover rows
     * written as text. A float is ROUNDED (not truncated) to hours — an admin
     * who stores `12.6` means "about 13", not "12". Anything else falls back
     * to the shipped default.
     *
     * @param mixed $configured Raw effective value.
     */
    private static function coerce(mixed $configured): int
    {
        if (is_int($configured)) {
            return $configured;
        }

        if (is_float($configured)) {
            return is_finite($configured) ? (int) round($configured) : self::DEFAULT_TTL_HOURS;
        }

        if (is_string($configured) && is_numeric(trim($configured))) {
            $hours = (float) trim($configured);

            return (int) round($hours);
        }

        return self::DEFAULT_TTL_HOURS;
    }

    /**
     * Clamp a configured TTL into the defended hour domain.
     *
     * @param int $hours Raw coerced hours.
     */
    private static function clamp(int $hours): int
    {
        return max(self::MIN_TTL_HOURS, min(self::MAX_TTL_HOURS, $hours));
    }
}
