<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\SeriesMerger;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

class SeriesMergerTest extends TestCase
{
    public function testMergesOneEpisodeSeriesIntoHundredEpisodeSeriesWithNoOrphans(): void
    {
        $repo = $this->makeRepo();

        // Primary: HxH with 100 episodes under a single Season 1.
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Hunter x Hunter']);
        $primarySeason = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        for ($i = 1; $i <= 100; $i++) {
            $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primarySeason, 'type' => 'episode', 'name' => "E{$i}", 'metadata_json' => ['season' => 1, 'episode' => $i]]);
        }

        // Duplicate: HxH with 1 episode under its own Season 1.
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'HunterxHunter']);
        $dupSeason = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $dupEpisode = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupSeason, 'type' => 'episode', 'name' => 'E101', 'metadata_json' => ['season' => 1, 'episode' => 101]]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        // 1 episode moved, dup season shell + dup series shell deleted.
        self::assertSame(1, $result['moved']);
        self::assertSame(2, $result['deleted']);

        // The moved episode is now under the PRIMARY's season.
        self::assertSame($primarySeason, $repo->parentOf($dupEpisode));

        // Primary season now holds 101 episodes.
        self::assertCount(101, $repo->childrenOfType($primarySeason, 'episode'));

        // Duplicate shells are gone — exactly one series remains.
        self::assertNull($repo->find($dup));
        self::assertNull($repo->find($dupSeason));
        self::assertCount(1, $repo->itemsOfType('series'));

        // ZERO orphans: every episode resolves to a live parent.
        self::assertSame(0, $repo->orphanCount());
    }

    public function testSameSeasonNumberCollisionReparentsUnderPrimarySeasonWithoutDuplicateSeason(): void
    {
        $repo = $this->makeRepo();

        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $primaryS1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primaryS1, 'type' => 'episode', 'name' => 'P-E1', 'metadata_json' => ['season' => 1, 'episode' => 1]]);

        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show.']);
        $dupS1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $de1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupS1, 'type' => 'episode', 'name' => 'D-E2', 'metadata_json' => ['season' => 1, 'episode' => 2]]);
        $de2 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupS1, 'type' => 'episode', 'name' => 'D-E3', 'metadata_json' => ['season' => 1, 'episode' => 3]]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        // Both dup episodes moved; dup S1 shell + dup series shell deleted.
        self::assertSame(2, $result['moved']);
        self::assertSame(2, $result['deleted']);

        // Episodes are under the PRIMARY's S1 — NO second Season-1 row created.
        self::assertSame($primaryS1, $repo->parentOf($de1));
        self::assertSame($primaryS1, $repo->parentOf($de2));
        self::assertCount(1, array_filter(
            $repo->childrenOfType($primary, 'season'),
            fn (array $s): bool => is_array($s['metadata_json']) && ($s['metadata_json']['season'] ?? null) === 1
        ));
        self::assertCount(3, $repo->childrenOfType($primaryS1, 'episode'));
        self::assertNull($repo->find($dupS1));
        self::assertSame(0, $repo->orphanCount());
    }

    public function testReparentsWholeSeasonWhenPrimaryLacksThatSeasonNumber(): void
    {
        $repo = $this->makeRepo();

        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $primaryS1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primaryS1, 'type' => 'episode', 'name' => 'P-E1', 'metadata_json' => ['season' => 1, 'episode' => 1]]);

        // Duplicate carries a Season 2 the primary does not have.
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $dupS2 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'season', 'name' => 'Season 2', 'metadata_json' => ['season' => 2]]);
        $de1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupS2, 'type' => 'episode', 'name' => 'D-S2E1', 'metadata_json' => ['season' => 2, 'episode' => 1]]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        // Whole season re-parented (counts as 1 move); its episode rides along.
        // Only the empty series shell is deleted (the season is kept).
        self::assertSame(1, $result['moved']);
        self::assertSame(1, $result['deleted']);

        // The Season-2 row is now under the primary, with its episode intact.
        self::assertSame($primary, $repo->parentOf($dupS2));
        self::assertSame($dupS2, $repo->parentOf($de1));
        self::assertCount(2, $repo->childrenOfType($primary, 'season'));
        self::assertNull($repo->find($dup));
        self::assertSame(0, $repo->orphanCount());
    }

    public function testReparentsStrayDirectEpisodeOntoPrimarySeries(): void
    {
        $repo = $this->makeRepo();

        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        // An episode whose parent is the SERIES itself (no season container).
        $stray = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'episode', 'name' => 'Stray', 'metadata_json' => ['episode' => 1]]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        self::assertSame(1, $result['moved']);
        self::assertSame(1, $result['deleted']);
        self::assertSame($primary, $repo->parentOf($stray));
        self::assertNull($repo->find($dup));
        self::assertSame(0, $repo->orphanCount());
    }

    public function testMovieMergeFillsGapsThenDeletesDuplicateKeepingPrimary(): void
    {
        $repo = $this->makeRepo();

        // Primary movie: has a poster, but a BLANK overview and no genres.
        $primary = $repo->seed([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'type' => 'movie',
            'name' => 'Dune',
            'metadata_json' => ['poster_url' => 'http://p/keep.jpg', 'overview' => '', 'year' => 2021],
        ]);
        // Duplicate movie: has the overview + genres the primary lacks, and a
        // poster that must NOT clobber the primary's existing one.
        $dup = $repo->seed([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'type' => 'movie',
            'name' => 'Dune',
            'metadata_json' => [
                'poster_url' => 'http://p/loser.jpg',
                'overview' => 'Sandworms.',
                'genres' => ['Sci-Fi'],
                'imdb_id' => 'tt1160419',
            ],
        ]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        self::assertSame(0, $result['moved']);
        self::assertSame(1, $result['deleted']);

        // Duplicate row gone, primary kept.
        self::assertNull($repo->find($dup));
        self::assertNotNull($repo->find($primary));
        self::assertCount(1, $repo->itemsOfType('movie'));

        $meta = $repo->find($primary)['metadata_json'];
        self::assertIsArray($meta);
        // Gap-filled: blank overview + missing genres + missing imdb_id.
        self::assertSame('Sandworms.', $meta['overview']);
        self::assertSame(['Sci-Fi'], $meta['genres']);
        self::assertSame('tt1160419', $meta['imdb_id']);
        // NOT overwritten: existing poster + year are untouched.
        self::assertSame('http://p/keep.jpg', $meta['poster_url']);
        self::assertSame(2021, $meta['year']);
    }

    public function testMovieMergeSkipsCanonicalKeyAndEmptyDuplicateValues(): void
    {
        $repo = $this->makeRepo();

        // Primary movie: completely blank metadata so every gap is fillable.
        $primary = $repo->seed([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'type' => 'movie',
            'name' => 'Dune',
            'metadata_json' => ['canonical_key' => 'movie:keepme', 'overview' => ''],
        ]);
        // Duplicate carries: its OWN canonical_key (must NOT be carried to the
        // primary), an EMPTY overview (must NOT fill the primary's blank gap),
        // and a real genres value (the only thing that should be carried).
        $dup = $repo->seed([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'type' => 'movie',
            'name' => 'Dune',
            'metadata_json' => [
                'canonical_key' => 'movie:loser',
                'overview' => '',
                'genres' => ['Sci-Fi'],
            ],
        ]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        self::assertSame(0, $result['moved']);
        self::assertSame(1, $result['deleted']);

        $meta = ($repo->find($primary) ?? [])['metadata_json'];
        self::assertIsArray($meta);
        // canonical_key is the primary's own identity — NEVER overwritten by the
        // duplicate's (the canonical_key skip branch in fillGaps).
        self::assertSame('movie:keepme', $meta['canonical_key']);
        // The duplicate's EMPTY overview must NOT fill the primary's blank one —
        // it stays '' (the isEmptyValue($value) skip branch in fillGaps).
        self::assertSame('', $meta['overview']);
        // The real value IS carried.
        self::assertSame(['Sci-Fi'], $meta['genres']);
    }

    public function testMovieMergeDecodesRawMetadataJsonStringFromDuplicate(): void
    {
        $repo = $this->makeRepo();

        // Primary has a blank overview; its metadata arrives as a HYDRATED array.
        $primary = $repo->seed([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'type' => 'movie',
            'name' => 'Dune',
            'metadata_json' => ['overview' => ''],
        ]);
        // Duplicate's metadata is ONLY present as a raw JSON STRING in
        // metadata_json (no hydrated 'metadata' key) — exercises the
        // metadataOf() json_decode fallback path.
        $dup = $repo->seedRaw([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'type' => 'movie',
            'name' => 'Dune',
            'metadata_json' => json_encode(['overview' => 'Decoded from JSON.', 'year' => 1984]),
        ]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        self::assertSame(0, $result['moved']);
        self::assertSame(1, $result['deleted']);

        $meta = ($repo->find($primary) ?? [])['metadata_json'];
        self::assertIsArray($meta);
        // Values decoded from the duplicate's raw JSON string filled the gaps.
        self::assertSame('Decoded from JSON.', $meta['overview']);
        self::assertSame(1984, $meta['year']);
    }

    public function testMovieMergeHandlesUnhydratedArrayAndAbsentMetadata(): void
    {
        $repo = $this->makeRepo();

        // Primary movie whose metadata arrives un-hydrated as a metadata_json
        // ARRAY (no 'metadata' key) — exercises the metadataOf() is_array($raw)
        // fallback (vs the json_decode string path).
        $primary = $repo->seedRaw([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'type' => 'movie',
            'name' => 'Dune',
            'metadata_json' => ['overview' => ''],
        ]);
        // Duplicate carries NO metadata at all (null metadata_json, no 'metadata')
        // — exercises the metadataOf() final empty-return path. With no donor
        // values the primary is left untouched, only the dup row is removed.
        $dup = $repo->seedRaw([
            'library_id' => 'lib-1',
            'parent_id' => null,
            'type' => 'movie',
            'name' => 'Dune',
            'metadata_json' => null,
        ]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        self::assertSame(0, $result['moved']);
        self::assertSame(1, $result['deleted']);
        self::assertNull($repo->find($dup));
        // Nothing to fill from an empty duplicate — primary metadata unchanged.
        self::assertSame(['overview' => ''], ($repo->find($primary) ?? [])['metadata_json']);
    }

    public function testSeasonMatchingDecodesRawMetadataJsonStringForSeasonNumber(): void
    {
        $repo = $this->makeRepo();

        // Primary S1 stored with a raw JSON metadata_json string (no hydrated
        // 'metadata') so seasonNumberOf() -> metadataOf() takes the decode path.
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $primaryS1 = $repo->seedRaw(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => json_encode(['season' => 1])]);

        // Duplicate S1 also stored as a raw JSON string.
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show.']);
        $dupS1 = $repo->seedRaw(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => json_encode(['season' => 1])]);
        $de1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupS1, 'type' => 'episode', 'name' => 'D-E2', 'metadata_json' => ['season' => 1, 'episode' => 2]]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        // The season numbers decoded from the raw JSON strings MATCH, so the dup
        // episode is re-parented under the primary's S1 and the dup shells go.
        self::assertSame(1, $result['moved']);
        self::assertSame(2, $result['deleted']);
        self::assertSame($primaryS1, $repo->parentOf($de1));
        self::assertCount(
            1,
            array_filter(
                $repo->childrenOfType($primary, 'season'),
                static function (array $s) use ($repo): bool {
                    $id = $s['id'] ?? null;
                    return is_string($id) && $repo->seasonNumber($id) === 1;
                }
            )
        );
        self::assertSame(0, $repo->orphanCount());
    }

    public function testIgnoresNonSeasonDirectChildOfPrimaryWhenIndexingSeasons(): void
    {
        $repo = $this->makeRepo();

        // The PRIMARY series carries a STRAY direct episode (a non-season direct
        // child) alongside its season — indexing must skip it, not treat it as a
        // season.
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $primaryS1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $primaryStray = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'episode', 'name' => 'P-Stray', 'metadata_json' => ['episode' => 9]]);

        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show.']);
        $dupS1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $de1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupS1, 'type' => 'episode', 'name' => 'D-E2', 'metadata_json' => ['season' => 1, 'episode' => 2]]);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        // The dup S1's episode folds under the primary's existing S1 (the stray
        // primary episode was correctly NOT mistaken for a same-number season).
        self::assertSame(1, $result['moved']);
        self::assertSame(2, $result['deleted']);
        self::assertSame($primaryS1, $repo->parentOf($de1));
        // The primary's pre-existing stray episode is untouched.
        self::assertSame($primary, $repo->parentOf($primaryStray));
        self::assertSame(0, $repo->orphanCount());
    }

    public function testSkipsMatchedSeasonEpisodeWithMissingIdWithoutCrashing(): void
    {
        $repo = $this->makeRepo();

        // Primary HAS S1 so the duplicate's S1 enters the matched-season episode
        // re-parent loop.
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show.']);
        $dupS1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $de1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupS1, 'type' => 'episode', 'name' => 'D-E2', 'metadata_json' => ['season' => 1, 'episode' => 2]]);

        // Corrupt the episode's id to a non-string so the defensive empty-id
        // guard in the matched-season episode loop skips it instead of crashing.
        $repo->corruptId($de1);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$dup]);

        // The corrupt-id episode is NOT moved (skipped by the guard), but the now
        // structurally-empty dup season + dup series shells are still deleted.
        self::assertSame(0, $result['moved']);
        self::assertSame(2, $result['deleted']);
        self::assertNull($repo->find($dup));
        self::assertNull($repo->find($dupS1));
    }

    public function testRejectsSelfMergeAsNoOp(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$primary]);

        self::assertSame(['moved' => 0, 'deleted' => 0], $result);
        // The primary is NEVER deleted by a self-merge.
        self::assertNotNull($repo->find($primary));
    }

    public function testRejectsCrossTypeDuplicate(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Dune']);
        $movieDup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$movieDup]);

        self::assertSame(['moved' => 0, 'deleted' => 0], $result);
        // The cross-type row is skipped, never deleted.
        self::assertNotNull($repo->find($movieDup));
    }

    public function testRejectsCrossLibraryDuplicate(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $otherLibDup = $repo->seed(['library_id' => 'lib-2', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge($primary, [$otherLibDup]);

        self::assertSame(['moved' => 0, 'deleted' => 0], $result);
        self::assertNotNull($repo->find($otherLibDup));
    }

    public function testMergingAnAlreadyCleanSetIsANoOp(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);

        // No duplicate ids at all.
        self::assertSame(['moved' => 0, 'deleted' => 0], (new SeriesMerger($repo, $this->mockConn()))->merge($primary, []));

        // A duplicate id that does not exist — skipped, not a crash.
        self::assertSame(['moved' => 0, 'deleted' => 0], (new SeriesMerger($repo, $this->mockConn()))->merge($primary, ['ghost-id']));
    }

    public function testMissingPrimaryIsANoOp(): void
    {
        $repo = $this->makeRepo();
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);

        $result = (new SeriesMerger($repo, $this->mockConn()))->merge('no-such-primary', [$dup]);

        self::assertSame(['moved' => 0, 'deleted' => 0], $result);
        // The would-be duplicate is untouched when the primary is missing.
        self::assertNotNull($repo->find($dup));
    }

    public function testWrapsMergeInARealTransactionAndCommits(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);

        $conn = $this->createMock(PhlixMySQLConnection::class);
        $conn->expects(self::once())->method('beginTrans')->willReturn(true);
        $conn->expects(self::once())->method('commitTrans')->willReturn(true);
        $conn->expects(self::never())->method('rollBackTrans');

        $result = (new SeriesMerger($repo, $conn))->merge($primary, [$dup]);

        self::assertSame(1, $result['deleted']);
        self::assertNull($repo->find($dup));
    }

    public function testRollsBackAndRethrowsWhenAReparentFails(): void
    {
        $repo = $this->makeRepo(failOnUpdate: true);
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $primaryS1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $dupS1 = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupS1, 'type' => 'episode', 'name' => 'E1', 'metadata_json' => ['season' => 1, 'episode' => 1]]);

        $conn = $this->createMock(PhlixMySQLConnection::class);
        $conn->expects(self::once())->method('beginTrans')->willReturn(true);
        $conn->expects(self::once())->method('rollBackTrans')->willReturn(true);
        $conn->expects(self::never())->method('commitTrans');

        $this->expectException(\RuntimeException::class);

        try {
            (new SeriesMerger($repo, $conn))->merge($primary, [$dup]);
        } finally {
            // Defense-in-depth: the re-parent was ordered BEFORE any delete, so
            // the duplicate series shell is NOT deleted on the failing path.
            self::assertNotNull($repo->find($dup));
        }
    }

    /**
     * In-memory ItemRepository double: a simple id-keyed store supporting the
     * find/re-parent/delete primitives SeriesMerger uses, plus test helpers.
     */
    private function makeRepo(bool $failOnUpdate = false): InMemorySeriesRepository
    {
        $mockConn = $this->createMock(Connection::class);

        return new InMemorySeriesRepository($mockConn, $failOnUpdate);
    }

    private function mockConn(): PhlixMySQLConnection
    {
        $conn = $this->createMock(PhlixMySQLConnection::class);
        $conn->method('beginTrans')->willReturn(true);
        $conn->method('commitTrans')->willReturn(true);
        $conn->method('rollBackTrans')->willReturn(true);
        return $conn;
    }
}


/**
 * In-memory ItemRepository double: a simple id-keyed store supporting the
 * find/re-parent/delete primitives SeriesMerger uses, plus test helpers.
 */
class InMemorySeriesRepository extends ItemRepository
{
            /** @var array<string, array<string, mixed>> */
    private array $store = [];
    private int $seq = 0;
    private bool $failOnUpdate;

    public function __construct(Connection $db, bool $failOnUpdate)
    {
        parent::__construct($db);
        $this->failOnUpdate = $failOnUpdate;
    }

            /**
             * Ids of rows seeded with a RAW (string) metadata_json — for these
             * the find* helpers deliberately do NOT inject a hydrated 'metadata'
             * key, so the production metadataOf() json_decode fallback runs.
             *
             * @var array<string, true>
             */
    private array $rawMeta = [];

            /** @param array<string, mixed> $row */
    public function seed(array $row): string
    {
        $id = 'id-' . (++$this->seq);
        $this->store[$id] = array_merge([
            'id' => $id,
            'library_id' => null,
            'parent_id' => null,
            'name' => null,
            'type' => null,
            'path' => 'synthetic:' . $id,
            'metadata_json' => [],
        ], $row, ['id' => $id]);
        return $id;
    }

            /**
             * Seed a row whose metadata_json is a RAW JSON string exactly as the
             * DB column stores it (ItemRepository would normally hydrate it). The
             * find* helpers leave it un-hydrated so SeriesMerger::metadataOf()
             * must decode it itself.
             *
             * @param array<string, mixed> $row
             */
    public function seedRaw(array $row): string
    {
        $id = $this->seed($row);
        $this->rawMeta[$id] = true;
        return $id;
    }

    public function findById(string $id): ?array
    {
        if (!isset($this->store[$id])) {
            return null;
        }
        return $this->hydrate($this->store[$id]);
    }

    public function findByParent(string $parentId): array
    {
        $out = [];
        foreach ($this->store as $row) {
            if (($row['parent_id'] ?? null) === $parentId) {
                $out[] = $this->hydrate($row);
            }
        }
        return $out;
    }

    public function findByParents(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }
        $children = [];
        foreach ($this->store as $row) {
            $parentId = $row['parent_id'] ?? null;
            if (is_string($parentId) && in_array($parentId, $parentIds, true)) {
                if (!isset($children[$parentId])) {
                    $children[$parentId] = [];
                }
                $children[$parentId][] = $this->hydrate($row);
            }
        }
        return $children;
    }

            /**
             * Mimic ItemRepository hydration: a row seeded normally gets a
             * decoded 'metadata' array; a raw-seeded row is returned verbatim
             * (string metadata_json, no 'metadata') to drive the decode path.
             *
             * @param array<string, mixed> $row
             * @return array<string, mixed>
             */
    private function hydrate(array $row): array
    {
        $id = $row['id'] ?? null;
        if (is_string($id) && isset($this->rawMeta[$id])) {
            return $row;
        }
        $row['metadata'] = is_array($row['metadata_json'] ?? null) ? $row['metadata_json'] : [];
        return $row;
    }

    public function update(string $id, array $data): void
    {
        if ($this->failOnUpdate) {
            throw new \RuntimeException('simulated re-parent failure');
        }
        if (!isset($this->store[$id])) {
            return;
        }
        foreach ($data as $key => $value) {
            $this->store[$id][$key] = $value;
        }
    }

    public function delete(string $id): void
    {
        unset($this->store[$id]);
    }

            // ---- test inspection helpers ----

            /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->store[$id] ?? null;
    }

    public function parentOf(string $id): ?string
    {
        $parent = $this->store[$id]['parent_id'] ?? null;
        return is_string($parent) ? $parent : null;
    }

            /**
             * The season number a stored row carries, decoding a raw JSON
             * metadata_json string when present (mirrors the production read).
             */
    public function seasonNumber(string $id): ?int
    {
        $raw = $this->store[$id]['metadata_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || !isset($raw['season']) || !is_numeric($raw['season'])) {
            return null;
        }
        return (int) $raw['season'];
    }

            /** Replace a stored row's id field with a non-string (corrupt) value. */
    public function corruptId(string $id): void
    {
        if (isset($this->store[$id])) {
            $this->store[$id]['id'] = null;
        }
    }

            /** @return list<array<string, mixed>> */
    public function childrenOfType(string $parentId, string $type): array
    {
        $out = [];
        foreach ($this->store as $row) {
            if (($row['parent_id'] ?? null) === $parentId && ($row['type'] ?? null) === $type) {
                $out[] = $row;
            }
        }
        return $out;
    }

            /** @return list<array<string, mixed>> */
    public function itemsOfType(string $type): array
    {
        $out = [];
        foreach ($this->store as $row) {
            if (($row['type'] ?? null) === $type) {
                $out[] = $row;
            }
        }
        return $out;
    }

            /**
             * Count rows whose parent_id points at an id no longer in the store
             * (a true orphan). Top-level rows (parent_id null) are never orphans.
             */
    public function orphanCount(): int
    {
        $count = 0;
        foreach ($this->store as $row) {
            $parent = $row['parent_id'] ?? null;
            if (is_string($parent) && !isset($this->store[$parent])) {
                $count++;
            }
        }
        return $count;
    }
}
