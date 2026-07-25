<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media;

/**
 * THE canonical `media_items.type` vocabulary.
 *
 * ## Why this class exists
 *
 * `media_items.type` is a 13-member column ENUM, and before this class the list
 * was re-typed by hand in at least four places — `MediaItemShaper::VALID_TYPES`,
 * `StorageSnapshotHelper::TYPE_TO_BUCKET`, `UpnpClassMap::TYPE_TO_CLASS` and the
 * `stats_*` migrations — each carrying a comment asking the next author to keep
 * it "in lockstep" with the others. That convention failed repeatedly and
 * silently:
 *
 * - `stats_playback_events.media_type` was left at migration 019's FOUR members
 *   (`movie`,`series`,`music`,`photo`), so once S31 started writing the real
 *   type every episode/track/photo play raised MySQL error **1265 "Data
 *   truncated"** under `STRICT_TRANS_TABLES` and threw an uncaught
 *   `PDOException` out of the HTTP worker (S102).
 * - `stats_storage.media_type` was missing a `book` destination, so book and
 *   audiobook bytes were dropped from the admin dashboard (migration 086).
 * - `TYPE_TO_BUCKET` was missing `track` — the type the music scanner actually
 *   writes — so Music totals read zero.
 *
 * Every one of those is the same defect: a duplicated vocabulary drifting out of
 * sync with the column. So the list now lives here ONCE, and
 * {@see \Phlix\Tests\Unit\Media\MediaItemTypeDriftTest} reads the ENUM back out
 * of the migration SQL and fails the build if this constant, any consumer map,
 * or any of the type-carrying columns disagree. Drift is no longer a silent
 * runtime mislabel — it is a red test.
 *
 * ## Source of truth
 *
 * The members below are copied VERBATIM, in order, from the last migration that
 * redefines the column:
 * `migrations/034_media_items_type_audiobook.sql` line 41 —
 * built up by 001 (`movie`,`series`,`season`,`episode`,`music`,`album`,`artist`,
 * `video`,`audio`,`book`,`photo`) → 011 (`track`) → 034 (`audiobook`).
 *
 * ⚠ Note `photo`, NOT `image`. `image` is a scanner-side argument label only
 * (see {@see \Phlix\Media\Library\MediaScanner}) and has never been a column
 * member; treating it as one is a long-standing source of bugs in this repo.
 *
 * ## Adding a member
 *
 * 1. Write the migration (`ALTER TABLE media_items MODIFY COLUMN type ENUM(...)`).
 * 2. Widen `stats_playback_events.media_type` in the same migration — it stores
 *    the raw type verbatim.
 * 3. Add the member here.
 * 4. Give it a bucket in {@see \Phlix\Stats\StorageSnapshotHelper::TYPE_TO_BUCKET}
 *    and a UPnP class in {@see \Phlix\Media\Library\MediaItemShaper} /
 *    {@see \Phlix\Dlna\UpnpClassMap::TYPE_TO_CLASS}.
 *
 * The drift test names whichever of those steps you skipped.
 *
 * @package Phlix\Media
 * @since 1.9
 */
final class MediaItemType
{
    /**
     * Every member of the `media_items.type` column ENUM, in column order.
     *
     * @var list<string>
     */
    public const ALL = [
        'movie',
        'series',
        'season',
        'episode',
        'track',
        'music',
        'album',
        'artist',
        'video',
        'audio',
        'book',
        'photo',
        'audiobook',
    ];

    /**
     * Fallback used when a type cannot be resolved or is not a column member.
     *
     * `movie` is deliberately the same fallback
     * {@see \Phlix\Media\Library\MediaItemShaper::shape()} and
     * {@see \Phlix\Session\PlaybackController} already use, so an unresolvable
     * row stays consistent across the whole pipeline instead of each layer
     * inventing its own sentinel.
     */
    public const FALLBACK = 'movie';

    /**
     * Is `$type` a member of the `media_items.type` column ENUM?
     *
     * @param string $type Candidate type string.
     *
     * @return bool True when the column would accept the value.
     *
     * @since 1.9
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /**
     * Coerce an arbitrary value to a column-valid type.
     *
     * Anything that is not a member becomes {@see FALLBACK}. Callers that need
     * to know a coercion happened should compare the result against their input
     * (see {@see \Phlix\Stats\StatsCollector::recordPlaybackStart()}, which logs
     * a warning so a future 14th ENUM member surfaces in the log rather than
     * corrupting stats silently).
     *
     * @param mixed $type Candidate type (typically a raw DB column value).
     *
     * @return string A member of {@see ALL}.
     *
     * @since 1.9
     */
    public static function normalize(mixed $type): string
    {
        return is_string($type) && self::isValid($type) ? $type : self::FALLBACK;
    }

    /**
     * Prevent instantiation — this class is a static constant holder only.
     */
    private function __construct()
    {
    }
}
