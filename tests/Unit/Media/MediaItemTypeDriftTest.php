<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Dlna\UpnpClassMap;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\MediaItemType;
use Phlix\Stats\StorageSnapshotHelper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The DRIFT ALARM for the `media_items.type` vocabulary (S102).
 *
 * ## Why this test is different from the ones it supersedes
 *
 * `StorageSnapshotHelperTest` and `UpnpClassMapTest` already cross-check the PHP
 * copies of this ENUM against EACH OTHER — but each of them also hardcodes the
 * member list a third and fourth time, and NONE of them looks at the database
 * schema. So all the PHP copies could agree perfectly while the actual column
 * disagreed with every one of them. That is not hypothetical: it is exactly the
 * S102 production bug. `stats_playback_events.media_type` sat at migration 019's
 * FOUR members while the PHP side had thirteen, so once S31 began writing the
 * real type, every episode / track / photo play raised MySQL error 1265
 * ("Data truncated for column 'media_type'") under `STRICT_TRANS_TABLES` and threw
 * an uncaught `PDOException` out of the HTTP worker. 235 rows, all `movie`, and
 * every non-movie play lost.
 *
 * This test closes that loop: it PARSES THE MIGRATION SQL and compares the real
 * column ENUMs against {@see MediaItemType::ALL} and against every consumer map.
 * It needs no database, so it runs everywhere the unit suite runs — a widened
 * `media_items.type` that forgets any of its dependants is now a red test rather
 * than a runtime 500 discovered in production.
 *
 * @covers \Phlix\Media\MediaItemType
 */
final class MediaItemTypeDriftTest extends TestCase
{
    /**
     * Absolute path to the migrations directory.
     */
    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    /**
     * Resolve a column's EFFECTIVE ENUM members by replaying the migration SQL in
     * filename order and keeping the LAST definition found.
     *
     * Matches both `CREATE TABLE` column lines and `ALTER TABLE … MODIFY COLUMN`,
     * which between them are the only ways this repo defines an ENUM column.
     *
     * @param string $table  Table the column belongs to (used to scope CREATE TABLE bodies).
     * @param string $column Column name.
     *
     * @return list<string> Members in column order; empty when no definition exists.
     */
    private function enumMembersFromMigrations(string $table, string $column): array
    {
        $files = glob(self::migrationsDir() . '/*.sql');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'No migration SQL found');
        sort($files);

        $members = [];

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            // Drop `--` comment lines so prose mentioning an ENUM (there is a lot
            // of it in this repo's migrations) can never be mistaken for a
            // definition.
            $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

            foreach ($this->definitionsIn($sql, $table, $column) as $memberList) {
                $found = [];
                preg_match_all("/'([^']*)'/", $memberList, $found);
                /** @var list<string> $captured */
                $captured = $found[1];
                if ($captured !== []) {
                    $members = $captured;
                }
            }
        }

        return $members;
    }

    /**
     * Every raw ENUM member-list body defining `$table`.`$column` in one file, in
     * source order.
     *
     * @return list<string>
     */
    private function definitionsIn(string $sql, string $table, string $column): array
    {
        $out = [];

        // ALTER TABLE <table> [...] MODIFY COLUMN <column> ENUM(...)
        $alter = [];
        preg_match_all(
            '/ALTER\s+TABLE\s+' . preg_quote($table, '/')
            . '\s+MODIFY\s+COLUMN\s+' . preg_quote($column, '/')
            . '\s+ENUM\s*\(([^)]*)\)/is',
            $sql,
            $alter
        );
        foreach ($alter[1] as $body) {
            $out[] = $body;
        }

        // CREATE TABLE <table> ( … <column> ENUM(...) … )
        $create = [];
        preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . preg_quote($table, '/')
            . '\s*\((.*?)\n\s*\)\s*ENGINE/is',
            $sql,
            $create
        );
        foreach ($create[1] as $body) {
            $col = [];
            if (
                preg_match(
                    '/^\s*' . preg_quote($column, '/') . '\s+ENUM\s*\(([^)]*)\)/im',
                    $body,
                    $col
                ) === 1
            ) {
                $out[] = $col[1];
            }
        }

        return $out;
    }

    /**
     * The parser itself must be trustworthy — if it silently found nothing, every
     * other assertion here would pass vacuously.
     */
    public function testTheMigrationParserActuallyFindsTheKnownColumns(): void
    {
        $this->assertNotSame(
            [],
            $this->enumMembersFromMigrations('media_items', 'type'),
            'Parser found no media_items.type ENUM — the assertions below would be vacuous'
        );
        $this->assertNotSame(
            [],
            $this->enumMembersFromMigrations('stats_playback_events', 'media_type'),
            'Parser found no stats_playback_events.media_type ENUM'
        );
        $this->assertNotSame(
            [],
            $this->enumMembersFromMigrations('stats_storage', 'media_type'),
            'Parser found no stats_storage.media_type ENUM'
        );
    }

    /**
     * {@see MediaItemType::ALL} is the source of truth, so it must match the
     * column it claims to describe — members AND order (ENUM ordinals are
     * positional in MySQL).
     */
    public function testMediaItemTypeMatchesTheMediaItemsColumnEnum(): void
    {
        $this->assertSame(
            $this->enumMembersFromMigrations('media_items', 'type'),
            MediaItemType::ALL,
            'MediaItemType::ALL must be the media_items.type column ENUM, verbatim and in order'
        );
    }

    /**
     * THE S102 REGRESSION. `stats_playback_events.media_type` stores the raw
     * `media_items.type` value, so it must accept every member. With migration
     * 019's four-member ENUM this assertion fails on nine of thirteen members —
     * and in production each of those nine was an uncaught `PDOException`.
     */
    public function testStatsPlaybackEventsAcceptsEveryMediaItemType(): void
    {
        $stored = $this->enumMembersFromMigrations('stats_playback_events', 'media_type');

        $this->assertSame(
            MediaItemType::ALL,
            $stored,
            'stats_playback_events.media_type stores the RAW media_items.type value, so its ENUM '
            . 'must be the same members in the same order (migration 094). A missing member is '
            . 'MySQL error 1265 on every play of that type.'
        );

        // Name the specific value that broke production, so the reason this test
        // exists survives any future refactor of the assertion above.
        $this->assertContains(
            'episode',
            $stored,
            'Every episode play writes media_type=episode; without the member the INSERT is error 1265'
        );
    }

    /**
     * `stats_storage.media_type` is deliberately COARSE — it is the one column of
     * the three with a real reader (`DashboardService::getStorageSummary()` groups
     * by it into a fixed five-key shape), so it must stay exactly
     * {@see StorageSnapshotHelper::BUCKETS} and NOT be widened to the 13 types.
     */
    public function testStatsStorageStaysTheCoarseBucketVocabulary(): void
    {
        $this->assertSame(
            $this->enumMembersFromMigrations('stats_storage', 'media_type'),
            StorageSnapshotHelper::BUCKETS,
            'stats_storage.media_type is the coarse bucket column; BUCKETS must mirror it exactly'
        );
    }

    /**
     * Every fold target must be a value the coarse column can store.
     */
    public function testEveryFoldTargetIsAStatsStorageEnumMember(): void
    {
        $storageEnum = $this->enumMembersFromMigrations('stats_storage', 'media_type');

        foreach (StorageSnapshotHelper::TYPE_TO_BUCKET as $type => $bucket) {
            $this->assertContains(
                $bucket,
                $storageEnum,
                sprintf('Type "%s" folds to "%s", which stats_storage.media_type cannot store', $type, $bucket)
            );
        }
    }

    /**
     * The storage fold must be exhaustive over the vocabulary: an unmapped type is
     * dropped from the snapshot silently (how `track`, `book` and `audiobook`
     * bytes all went missing before).
     */
    public function testStorageFoldCoversEveryType(): void
    {
        $this->assertSame(
            $this->sorted(MediaItemType::ALL),
            $this->sorted(array_keys(StorageSnapshotHelper::TYPE_TO_BUCKET)),
            'StorageSnapshotHelper::TYPE_TO_BUCKET must have one key per MediaItemType::ALL member'
        );
    }

    /**
     * The DLNA class map must be exhaustive too, or the affected items serve as
     * the generic `object.item` fallback.
     */
    public function testUpnpClassMapCoversEveryType(): void
    {
        $this->assertSame(
            $this->sorted(MediaItemType::ALL),
            $this->sorted(array_keys(UpnpClassMap::TYPE_TO_CLASS)),
            'UpnpClassMap::TYPE_TO_CLASS must have one key per MediaItemType::ALL member'
        );
    }

    /**
     * The API shaper's allow-list is now an alias of the source of truth rather
     * than a fifth hand-typed copy; pin that so nobody re-inlines the literal.
     */
    public function testShaperValidTypesIsTheSourceOfTruth(): void
    {
        $valid = (new ReflectionClass(MediaItemShaper::class))->getConstant('VALID_TYPES');

        $this->assertSame(
            MediaItemType::ALL,
            $valid,
            'MediaItemShaper::VALID_TYPES must be MediaItemType::ALL, not a re-typed copy'
        );
    }

    /**
     * `image` has never been a member of `media_items.type` — the scanner uses it
     * as an argument label only, and treating it as a column value is a
     * long-standing source of bugs in this repo. `photo` is the real member.
     */
    public function testImageIsNotAMemberButPhotoIs(): void
    {
        $this->assertFalse(MediaItemType::isValid('image'), '`image` is a scanner label, not a column member');
        $this->assertTrue(MediaItemType::isValid('photo'), '`photo` is the real stills member');
        $this->assertNotContains('image', $this->enumMembersFromMigrations('media_items', 'type'));
    }

    /**
     * `normalize()` is what keeps an unexpected value off the wire: members pass
     * through verbatim, everything else becomes the shared fallback.
     */
    public function testNormalizePassesMembersThroughAndCoercesTheRest(): void
    {
        foreach (MediaItemType::ALL as $type) {
            $this->assertSame($type, MediaItemType::normalize($type));
        }

        $this->assertSame(MediaItemType::FALLBACK, MediaItemType::normalize('image'));
        $this->assertSame(MediaItemType::FALLBACK, MediaItemType::normalize(''));
        $this->assertSame(MediaItemType::FALLBACK, MediaItemType::normalize(null));
        $this->assertSame(MediaItemType::FALLBACK, MediaItemType::normalize(42));
        $this->assertContains(MediaItemType::FALLBACK, MediaItemType::ALL);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
