<?php

/**
 * Phlix media server component: Subtitles.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Subtitles\Quota;

use Workerman\MySQL\Connection;

/**
 * DB-backed persistence of per-provider subtitle download quota (Wave 3 / F3).
 *
 * Subtitle providers (e.g. OpenSubtitles) meter DOWNLOADS, not searches, and
 * report the remaining allowance + a reset time when the quota is exhausted
 * (surfaced by the shared {@see \Phlix\Shared\Subtitle\Exception\QuotaExceeded}).
 * This repository records that state in the `subtitle_provider_quota` table
 * (provider is the primary key — one row per provider, no UUID needed) so the
 * fetch service can SKIP an exhausted provider on the next request instead of
 * spending a wasted round-trip, and so the state survives worker restarts.
 *
 * All access is parameterised through {@see Connection} (never string-built
 * SQL), per the host DB convention.
 *
 * @package Phlix\Media\Subtitles\Quota
 * @since 0.43.0
 */
final class SubtitleProviderQuotaRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The stored quota row for a provider, or null when none is recorded.
     *
     * @param string $provider Canonical source name (e.g. `opensubtitles`).
     *
     * @return array{
     *     provider: string,
     *     downloads_remaining: int|null,
     *     reset_time_utc: string|null,
     *     updated_at: string|null
     * }|null
     *
     * @since 0.43.0
     */
    public function get(string $provider): ?array
    {
        $rows = $this->db->query(
            'SELECT provider, downloads_remaining, reset_time_utc, updated_at
             FROM subtitle_provider_quota WHERE provider = ?',
            [$provider],
        );

        if (!is_array($rows) || $rows === [] || !is_array($rows[0] ?? null)) {
            return null;
        }
        $row = $rows[0];

        $remaining = $row['downloads_remaining'] ?? null;
        $reset = $row['reset_time_utc'] ?? null;
        $updated = $row['updated_at'] ?? null;

        return [
            'provider' => is_string($row['provider'] ?? null) ? $row['provider'] : $provider,
            'downloads_remaining' => is_numeric($remaining) ? (int) $remaining : null,
            'reset_time_utc' => is_string($reset) && $reset !== '' ? $reset : null,
            'updated_at' => is_string($updated) && $updated !== '' ? $updated : null,
        ];
    }

    /**
     * Whether a provider is currently known to be OUT of download quota.
     *
     * True only when a row records a non-positive remaining allowance AND the
     * reset time has NOT yet passed (an absent reset time is treated as
     * still-exhausted until a successful download clears it via
     * {@see recordSuccess()}). A reset time in the past means the window has
     * rolled over, so the provider is considered available again.
     *
     * @param string $provider Canonical source name.
     *
     * @since 0.43.0
     */
    public function isExhausted(string $provider): bool
    {
        $row = $this->get($provider);
        if ($row === null) {
            return false;
        }

        $remaining = $row['downloads_remaining'];
        if ($remaining === null || $remaining > 0) {
            return false;
        }

        $reset = $row['reset_time_utc'];
        if ($reset === null) {
            return true;
        }

        $resetTs = strtotime($reset);

        // Unparseable reset time → stay conservative (still exhausted).
        return $resetTs === false || $resetTs > time();
    }

    /**
     * Record a provider hitting its download quota.
     *
     * Upserts the provider's row from a {@see QuotaExceeded} context. A null
     * remaining is stored as 0 (the provider reported exhaustion without a
     * count), so {@see isExhausted()} treats it as out-of-quota.
     *
     * @param string      $provider           Canonical source name.
     * @param int|null    $downloadsRemaining  Remaining allowance the provider reported.
     * @param string|null $resetTimeUtc        ISO-8601 UTC reset time, or null.
     *
     * @since 0.43.0
     */
    public function recordQuotaExceeded(
        string $provider,
        ?int $downloadsRemaining,
        ?string $resetTimeUtc,
    ): void {
        $this->upsert($provider, $downloadsRemaining ?? 0, $resetTimeUtc);
    }

    /**
     * Record a successful download for a provider — clears any exhaustion.
     *
     * A completed download proves the provider has quota, so the stored
     * remaining/reset are cleared (set to NULL) and `updated_at` is refreshed.
     * This is the "download() success signal" that lets a provider recover
     * without waiting for its reset window to be re-observed.
     *
     * @param string $provider Canonical source name.
     *
     * @since 0.43.0
     */
    public function recordSuccess(string $provider): void
    {
        $this->upsert($provider, null, null);
    }

    /**
     * Insert-or-update a provider's quota row, stamping `updated_at`.
     *
     * @param string      $provider           Canonical source name.
     * @param int|null    $downloadsRemaining  Remaining allowance (NULL clears it).
     * @param string|null $resetTimeUtc        ISO-8601 UTC reset time (NULL clears it).
     */
    private function upsert(string $provider, ?int $downloadsRemaining, ?string $resetTimeUtc): void
    {
        $this->db->query(
            'INSERT INTO subtitle_provider_quota
                (provider, downloads_remaining, reset_time_utc, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                downloads_remaining = VALUES(downloads_remaining),
                reset_time_utc = VALUES(reset_time_utc),
                updated_at = VALUES(updated_at)',
            [$provider, $downloadsRemaining, $resetTimeUtc],
        );
    }
}
