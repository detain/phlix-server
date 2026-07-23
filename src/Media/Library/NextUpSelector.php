<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

/**
 * Pure (DB-free) "Next Up" ordering + next-episode selection helpers (S36).
 *
 * Given a series' flat episode list — each row carrying its `season_number` /
 * `episode_number` (parsed from `metadata_json`, see {@see MediaItemShaper}),
 * a tie-break `title`, and a per-episode watch `state` derived from
 * `playback_state` — these helpers produce the single ordered PLAYBACK sequence
 * and resolve the first episode the viewer has NOT already watched / is not
 * mid-way through.
 *
 * The ordering is a server-side port of the phlix-ui `episode-order.ts`
 * (`orderEpisodesForPlayback`) + `series-grouping.ts` (`compareEpisodes`) logic
 * so the "Next Up" pick agrees with the player's auto-advance:
 *
 * - Only NUMBERED seasons (`season >= 1`) participate; Specials (season 0 or a
 *   missing number) are EXCLUDED entirely and are never returned as "next".
 * - Seasons ascend by number; within a season episodes ascend by episode number
 *   (a missing number sorts LAST); ties break on the episode title/name.
 *
 * "Next" walks FORWARD from the series' most-recently-touched episode through
 * that ordering to the first episode that is neither {@see STATE_WATCHED} nor
 * {@see STATE_IN_PROGRESS} — so a binge-watcher who finished eps 1-3 gets ep 4,
 * and a series whose finale is the last watched episode yields no next at all.
 *
 * @phpstan-type NextUpEpisode array{
 *     id: string,
 *     season_number: int|null,
 *     episode_number: int|null,
 *     title: string,
 *     state: string
 * }
 */
final class NextUpSelector
{
    /**
     * "Watched" fraction of an episode's duration. Mirrors the Continue-Watching
     * rail's exact 0.95 threshold so an abandoned-near-the-end episode counts as
     * finished for Next-Up purposes.
     *
     * @var float
     */
    public const WATCHED_THRESHOLD = 0.95;

    /** Episode has no playback_state row (never played) → an unwatched candidate. */
    public const STATE_FRESH = 'fresh';

    /** Episode is currently being watched (resume territory — lives on the CW rail). */
    public const STATE_IN_PROGRESS = 'in_progress';

    /** Episode has been finished (explicit S30 stop-at-0, or abandoned past 95%). */
    public const STATE_WATCHED = 'watched';

    /**
     * Classify an episode's watch state from its most-recent `playback_state` row.
     *
     * Rules (binding S36 design — playback_state ONLY, never `user_item_data`):
     * - No row (`$status === null`) → {@see STATE_FRESH} (never played).
     * - Watched: (`status='stopped' AND position=0` — the S30 finish signal) OR
     *   (`duration>0 AND position >= duration*0.95` — abandoned near the end).
     * - In progress: `status IN ('playing','paused') AND position>0 AND
     *   position < duration*0.95`.
     * - Anything else → {@see STATE_FRESH}.
     *
     * @param string|null $status   `playback_status`, or null when no row exists.
     * @param int         $position `position_ticks`.
     * @param int         $duration `duration_ticks`.
     *
     * @return string One of {@see STATE_FRESH}, {@see STATE_IN_PROGRESS}, {@see STATE_WATCHED}.
     */
    public static function classify(?string $status, int $position, int $duration): string
    {
        if ($status === null || $status === '') {
            return self::STATE_FRESH;
        }

        // Explicit S30 finish signal: stopped exactly at the start.
        if ($status === 'stopped' && $position === 0) {
            return self::STATE_WATCHED;
        }

        // Abandoned past the 95% mark — treat as finished (mirrors CW).
        if ($duration > 0 && $position >= $duration * self::WATCHED_THRESHOLD) {
            return self::STATE_WATCHED;
        }

        // Actively watching, before the 95% mark.
        if (
            ($status === 'playing' || $status === 'paused')
            && $position > 0
            && $duration > 0
            && $position < $duration * self::WATCHED_THRESHOLD
        ) {
            return self::STATE_IN_PROGRESS;
        }

        return self::STATE_FRESH;
    }

    /**
     * The ordered episode sequence used for PLAYBACK / Next-Up.
     *
     * Numbered seasons only (`season >= 1`); Specials (season 0 / missing) are
     * dropped. Seasons ascend by number; within a season episodes ascend by
     * episode number (missing → last), tie-break on title.
     *
     * @param list<NextUpEpisode> $episodes
     *
     * @return list<NextUpEpisode>
     */
    public static function orderForPlayback(array $episodes): array
    {
        $numbered = array_values(array_filter(
            $episodes,
            static function (array $e): bool {
                $s = $e['season_number'] ?? null;
                return is_int($s) && $s >= 1;
            }
        ));

        usort($numbered, static function (array $a, array $b): int {
            $sa = is_int($a['season_number'] ?? null) ? $a['season_number'] : PHP_INT_MAX;
            $sb = is_int($b['season_number'] ?? null) ? $b['season_number'] : PHP_INT_MAX;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            $ea = is_int($a['episode_number'] ?? null) ? $a['episode_number'] : PHP_INT_MAX;
            $eb = is_int($b['episode_number'] ?? null) ? $b['episode_number'] : PHP_INT_MAX;
            if ($ea !== $eb) {
                return $ea <=> $eb;
            }

            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return $numbered;
    }

    /**
     * Resolve the "next up" episode for a series.
     *
     * Orders the episodes for playback, finds the index of the series'
     * most-recently-touched episode (falling back to the start of the sequence
     * when that episode isn't part of the numbered ordering — e.g. it was a
     * Special), then walks FORWARD (inclusive) to the first episode that is
     * neither watched nor in-progress.
     *
     * @param list<NextUpEpisode> $episodes            Every episode of the series.
     * @param string|null         $mostRecentTouchedId The id of the series' most
     *                                                  recently played episode, or
     *                                                  null to scan from the start.
     *
     * @return NextUpEpisode|null The next episode to play, or null when the series
     *                            has nothing left to watch.
     */
    public static function pickNext(array $episodes, ?string $mostRecentTouchedId): ?array
    {
        $ordered = self::orderForPlayback($episodes);
        if ($ordered === []) {
            return null;
        }

        $startIdx = 0;
        if ($mostRecentTouchedId !== null && $mostRecentTouchedId !== '') {
            foreach ($ordered as $i => $ep) {
                if (($ep['id'] ?? null) === $mostRecentTouchedId) {
                    $startIdx = $i;
                    break;
                }
            }
        }

        $count = count($ordered);
        for ($i = $startIdx; $i < $count; $i++) {
            $state = $ordered[$i]['state'] ?? self::STATE_FRESH;
            if ($state !== self::STATE_WATCHED && $state !== self::STATE_IN_PROGRESS) {
                return $ordered[$i];
            }
        }

        return null;
    }
}
