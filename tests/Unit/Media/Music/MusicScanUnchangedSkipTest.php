<?php

/**
 * Phlix media server test: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicScanPrefetcher;
use Phlix\Media\Music\MusicScanSkipIndex;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S122(a) — the unchanged-file fast path, and every way it must NOT fire.
 *
 * A skip predicate is a cache, so this file is written the way a cache-invalidation
 * suite has to be: for each half of the predicate there is a test that the half
 * FIRES and a test that it does NOT, and for each gate that suppresses the fast
 * path there is a test that the suppression works. A green suite that only ever
 * proves "unchanged files are fast" would pass just as happily against a scanner
 * that skipped everything unconditionally.
 *
 * The scans here are driven end to end through {@see MusicLibraryScanner::scanDirectory()}
 * against {@see SkipSchemaConnection}, a purpose-built in-memory `media_items` +
 * `music_*` schema, and every assertion is on a PROBE COUNT — the number of times the
 * scanner actually opened a file to read tags. That is the only measure that matters:
 * the whole step is "do not open the file", and a test that asserted only on
 * `ScanResult` counters would stay green if the skip were moved to AFTER
 * `probeMetadata()`, which would delete 100 % of the benefit.
 *
 * @internal
 */
#[CoversClass(MusicLibraryScanner::class)]
#[CoversClass(MusicScanSkipIndex::class)]
final class MusicScanUnchangedSkipTest extends TestCase
{
    /** @var list<string> Temp directories to remove. */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * AC 5, first half — an unchanged file IS skipped, and skipped means NOT OPENED.
     *
     * The second scan must probe ZERO files. `scanned` still counts them (they were
     * visited), `added`/`updated`/`failed` stay at 0, and the album's `total_tracks`
     * is untouched because no album was flushed.
     */
    public function testAnUnchangedFileIsSkippedWithoutBeingProbed(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);

        $first = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(3, $first->added, 'first scan must index all three files');
        self::assertSame(3, $scanner->probeCount, 'first scan must read all three files');

        $scanner->resetProbes();
        $second = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            0,
            $scanner->probeCount,
            'THE POINT OF S122(a): a rescan of an unchanged library must not open a single file. '
            . 'A non-zero count here means the skip is not firing — or has been moved to after '
            . 'probeMetadata(), which keeps every counter assertion green while removing the entire benefit.'
        );
        self::assertSame(3, $second->scanned, 'a skipped file is still a file the scan visited');
        self::assertSame(0, $second->added);
        self::assertSame(0, $second->updated);
        self::assertSame(0, $second->failed);
    }

    /**
     * AC 5, second half + AC 2 — a file whose mtime moved is re-read, and ONLY that file.
     */
    public function testATouchedFileIsProbedAgain(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        touch($dir . '/track-2.mp3', time() + 120);
        clearstatcache();

        $scanner->resetProbes();
        $result = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(1, $scanner->probeCount, 'exactly the touched file must be re-read');
        self::assertSame([$dir . '/track-2.mp3'], $scanner->probedPaths, 'and it must be the RIGHT file');
        self::assertSame(3, $result->scanned);
    }

    /**
     * AC 2 — **size differing is enough on its own.** The mtime is restored to its
     * original value after the content grows, so mtime alone would say "unchanged".
     *
     * This is the half a `mtime`-only predicate gets wrong, and the reason the
     * predicate is a conjunction.
     */
    public function testAFileWhoseSizeChangedUnderAPreservedMtimeIsProbedAgain(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $path = $dir . '/track-1.mp3';
        $mtime = filemtime($path);
        self::assertIsInt($mtime);

        file_put_contents($path, file_get_contents($path) . 'grown');
        touch($path, $mtime);
        clearstatcache();
        self::assertSame($mtime, filemtime($path), 'the fixture must actually preserve the mtime');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            [$path],
            $scanner->probedPaths,
            'size is half the predicate: a same-mtime size change must still force a re-read'
        );
    }

    /**
     * AC 2 — **mtime differing is enough on its own.** The file keeps its exact byte
     * length while its content and mtime change, so size alone would say "unchanged".
     */
    public function testAFileWhoseMtimeChangedAtAConstantSizeIsProbedAgain(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $path = $dir . '/track-2.mp3';
        $before = filesize($path);
        self::assertIsInt($before);

        $content = file_get_contents($path);
        self::assertIsString($content);
        file_put_contents($path, strrev($content));
        touch($path, time() + 300);
        clearstatcache();
        self::assertSame($before, filesize($path), 'the fixture must actually preserve the size');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame([$path], $scanner->probedPaths, 'mtime is the other half of the predicate');
    }

    /**
     * ⚠ THE DOCUMENTED FAILURE MODE, ASSERTED RATHER THAN IMPLIED.
     *
     * A file whose content changes while BOTH mtime and size are preserved is
     * **missed** — and it stays missed until something touches it. This test exists so
     * that the limitation is a stated, tested property rather than a surprise found in
     * production, and so that anyone who later claims the predicate is exact has a
     * counter-example in front of them.
     *
     * Reaching this state requires a writer that deliberately restores the timestamp
     * (`touch -r`, `cp --preserve=timestamps`, a padding tag editor). The operator
     * escape hatch is in {@see MusicScanSkipIndex}'s docblock: clear the two
     * `metadata_json` keys for the library and the next scan re-reads everything.
     */
    public function testASameSizeSameMtimeContentChangeIsMissedAndThatIsTheKnownTradeOff(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $path = $dir . '/track-1.mp3';
        $mtime = filemtime($path);
        $size = filesize($path);
        self::assertIsInt($mtime);
        self::assertIsInt($size);

        $content = file_get_contents($path);
        self::assertIsString($content);
        file_put_contents($path, strrev($content));
        touch($path, $mtime);
        clearstatcache();
        self::assertSame($mtime, filemtime($path));
        self::assertSame($size, filesize($path));

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            0,
            $scanner->probeCount,
            'KNOWN AND ACCEPTED: an in-place edit that preserves both mtime and size is not '
            . 'detected. If this assertion ever fails because the predicate was strengthened, '
            . 'that is an improvement — update the docblock in MusicScanSkipIndex to match.'
        );
    }

    /**
     * ⚠ **REVIEW r1 B1 — THE STAMP MUST BE THE IDENTITY FROM BEFORE THE READ, NOT FROM
     * THE FLUSH. THIS IS A DATA-LOSS REGRESSION TEST.**
     *
     * The scanner reads a file's tags in the walk and writes them much later, when the
     * album is flushed — at least {@see MusicLibraryScanner}'s 32-album window later,
     * and for an album whose tracks are spread across the tree, potentially at the very
     * END of a multi-hour walk. `SplFileInfo` does not memoise its `stat()`, so a
     * `getMTime()`/`getSize()` taken at flush time observes the file as it is THEN.
     *
     * If the stamp is taken at flush time, an ORDINARY tag write landing in that window
     * — one that changes size AND mtime, nothing exotic, not the documented
     * both-preserved case — is recorded as "already indexed at this identity" against
     * the tags read BEFORE it. Every later scan then matches the stamp and skips the
     * file, so **the edit is permanently invisible.** Measured against
     * `a4cd2173`: this test's third scan probed **0** files and the pre-edit title
     * stayed in the database forever.
     *
     * Stamping the PRE-read identity cannot do that: the stamp is then OLDER than the
     * bytes on disk, so the next scan sees a mismatch and re-reads. A redundant read is
     * the only error this direction can make.
     *
     * The window is reproduced exactly where it lives — the edit happens INSIDE
     * `probeViaGetId3()`, after the tags for that file have been decided and before the
     * flush that writes them, which is precisely "a tag editor ran while the scan was
     * further along the tree".
     */
    public function testAFileEditedBetweenItsProbeAndItsFlushIsReReadOnTheNextScan(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $path = $dir . '/track-1.mp3';
        $indexedTitle = $db->tracks[$this->mediaItemIdFor($db, $path)]['title'];
        self::assertSame('track-1', $indexedTitle, 'scan 1 must have indexed the pre-edit tags');

        // Make the file eligible for a probe on scan 2 …
        touch($path, time() + 60);
        clearstatcache();
        // … and have an ordinary tag write land DURING that probe: size grows AND mtime
        // moves forward, which is what any real tag editor does.
        $scanner->editAfterProbe = [$path => 'appended-by-a-tag-editor'];

        $scanner->resetProbes();
        $second = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame([$path], $scanner->probedPaths, 'scan 2 must be the scan that reads it');
        self::assertSame(0, $second->failed);

        $stamped = $this->stampFor($db, $path);
        $onDisk = [filemtime($path), filesize($path)];
        self::assertNotSame(
            $onDisk,
            $stamped,
            sprintf(
                'THE DEFECT ITSELF: the row was stamped with the identity the file has AFTER the edit '
                . '(%s), i.e. taken at flush time, while the tags in the row are the ones read BEFORE it. '
                . 'The stamp must be the pre-read identity, which is now %s on disk.',
                json_encode($onDisk),
                json_encode($stamped)
            )
        );

        $scanner->resetProbes();
        $third = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            [$path],
            $scanner->probedPaths,
            'THE CONSEQUENCE: an edit that landed between the probe and the flush must be picked up by '
            . 'the NEXT scan. A count of 0 here means the file was stamped with its post-edit identity '
            . 'and the edit is lost for good — reproduced end to end against a4cd2173.'
        );
        self::assertSame(0, $third->failed);
    }

    /**
     * The same window on the `'added'` path (review r1 B1).
     *
     * A brand-new file's stamp goes in on the very INSERT that creates its
     * `media_items` row ({@see MusicLibraryScanner::createMediaItem()}'s
     * `$extraMetadata`), which is also inside `flushAlbum()` and therefore also after
     * the read. A file created and then edited while the walk is still elsewhere in the
     * tree must not be stamped as though the later bytes were the indexed ones.
     */
    public function testANewFileEditedBetweenItsProbeAndItsInsertIsReReadOnTheNextScan(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);

        $path = $dir . '/track-1.mp3';
        $scanner->editAfterProbe = [$path => 'grown-during-the-first-scan'];

        $first = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(2, $first->added, 'both files must be indexed on the first scan');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            [$path],
            $scanner->probedPaths,
            'a file edited after its tags were read but before its INSERT must be re-read next scan; '
            . 'the stamp that INSERT carries has to be the pre-read identity'
        );
    }

    /**
     * S96(e) MUST NOT REGRESS: while any `music_*` row still has a NULL
     * `media_item_id`, the fast path is off, so the album is flushed and the heal runs.
     *
     * This is the interaction that makes S122(a) safe to combine with S96: the heal
     * lives inside `flushAlbum()`, `flushAlbum()` only runs for probed files, so a
     * scan that skipped everything would never heal anything.
     */
    public function testTheFastPathIsDisabledWhileAMusicRowStillNeedsHealing(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        // Reproduce the S96(e) shape EXACTLY: `createMediaItem()` failed outright, so the
        // `music_artists` row was written with `media_item_id = NULL` and there is NO
        // `media_items` artist row at all.
        //
        // ⚠ THE ARTIST ROW MUST GO TOO, AND THAT IS THE WHOLE POINT OF THIS FIXTURE.
        // Nulling `media_item_id` while LEAVING the `media_items` row behind produces an
        // ORPHAN, which `hasAdoptableMusicMediaItem()` already catches — so the test would
        // pass with the heal gate deleted (measured: mutating
        // `hasUnhealedMusicMediaItem()` to `return false` left the earlier version of this
        // test GREEN, i.e. it proved nothing). Removing the row leaves the heal gate as
        // the ONLY thing that can disable the fast path here.
        $key = array_key_first($db->artists);
        self::assertIsString($key);
        $orphanedId = $db->artists[$key]['media_item_id'];
        $db->artists[$key]['media_item_id'] = null;
        $db->mediaItems = array_values(array_filter(
            $db->mediaItems,
            static fn(array $row): bool => $row['id'] !== $orphanedId
        ));
        self::assertSame([], $db->orphanedMusicMediaItems(), 'this fixture must NOT contain an orphan');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            3,
            $scanner->probeCount,
            'an unhealed music row must switch the fast path OFF for the whole scan, or S96(e)\'s '
            . 'backfill never runs again on a settled library'
        );
        self::assertIsString(
            $db->artists[$key]['media_item_id'],
            'and the heal must actually have happened'
        );
    }

    /**
     * The other gate: while an adoptable orphan exists the fast path is off, so the
     * adoption in `upsertArtist()` still gets its chance.
     */
    public function testTheFastPathIsDisabledWhileAnOrphanedMediaItemIsAdoptable(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        // An artist media_items row that no music_artists row points at — the exact
        // residue hasAdoptableMusicMediaItem() exists to find.
        $db->mediaItems[] = [
            'id' => 'orphan-artist-media-item',
            'library_id' => 'lib-1',
            'type' => 'artist',
            'name' => 'Nobody Points At Me',
            'path' => '',
            'parent_id' => null,
            'metadata_json' => ['sub_type' => 'artist', 'name' => 'Nobody Points At Me'],
        ];

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            3,
            $scanner->probeCount,
            'an adoptable orphan must switch the fast path OFF, or the orphan can never be adopted'
        );
    }

    /**
     * The heal gate FAILS SAFE, and "safe" here is the opposite direction from the
     * adoption gate next to it.
     *
     * A transient error on the adoption probe degrades to "do the per-entity lookups" —
     * slower but correct. A transient error on the HEAL gate must degrade to **do not
     * skip anything**, because the alternative is skipping on an answer we could not
     * establish, i.e. potentially missing a real change. Neither may abort a multi-hour
     * scan that has nothing else wrong with it.
     */
    public function testAFailingHealGateDisablesTheFastPathInsteadOfSkipping(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $db->throwFor('AS unhealed FROM music_artists');

        $scanner->resetProbes();
        $result = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            3,
            $scanner->probeCount,
            'an unanswerable heal gate must switch the fast path OFF — a skip we cannot justify is a '
            . 'change we may silently miss, while a probe we did not need is only slow'
        );
        self::assertSame(0, $result->failed, 'and it must not fail the scan');
    }

    /**
     * ⚠ THE PER-FILE RE-READ OF `$mayAdopt`, PINNED — the fast path must switch OFF
     * MID-WALK when a caught write failure leaves a fresh orphan.
     *
     * This is what {@see MusicLibraryScanner::canSkip()} exists for, and it needs a
     * fixture with more than `MAX_OPEN_ALBUMS` (32) albums to reach: `flushAlbum()` —
     * the only place that can flip the flag — runs DURING the walk only when the open-album
     * window overflows. With a handful of albums everything is flushed after the walk, so
     * the flip cannot be observed and a `canSkip()` mutated to `return true` stays GREEN
     * (measured on the earlier, small-fixture version of this test).
     *
     * ⚠ AND A SKIPPED FILE NEVER OPENS AN ALBUM, which tightens the fixture further: the
     * open-album window only ever holds albums belonging to PROBED files, so the window
     * cannot overflow unless at least 33 files are probed. A fixture where one file is
     * touched and 39 are unchanged produces exactly one open album, no eviction, and no
     * flip — measured, and it is why the first version of this test proved nothing.
     *
     * Shape: 40 single-track albums, indexed and stamped. Then the first 33 files in WALK
     * order are touched (so they are probed and their albums open, overflowing the
     * window), the first-walked file is additionally given a NEW album title so a
     * `music_albums` INSERT is issued for it, and that INSERT is armed to write nothing.
     * `upsertAlbum()` therefore mints a `media_items` row it cannot reference and sets
     * `$mayAdopt = true` through the reference chain — so the 7 files still to come, all
     * unchanged, must go back to being PROBED.
     *
     * The discriminating number: **40 probes with the per-file read, 33 without it.**
     */
    public function testAMidWalkWriteFailureSwitchesTheFastPathOffForTheRest(): void
    {
        [$dir, $db] = $this->albumTree(40);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(40, $scanner->probeCount, 'the first scan must index all 40');

        $order = $this->walkOrder($dir);
        self::assertCount(40, $order);

        // 33 touched files => 33 open albums => the window (32) overflows and the
        // least-recently-touched album — the first one — is flushed DURING the walk.
        foreach (array_slice($order, 0, 33) as $path) {
            touch($path, time() + 90);
        }
        clearstatcache();

        // The flushed album must be the one whose write fails, so make the first-walked
        // file look as though it moved to a brand-new album and refuse that INSERT.
        $scanner->albumOverride = [$order[0] => 'A Brand New Album'];
        $db->returnNullFor('INSERT INTO music_albums');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            40,
            $scanner->probeCount,
            'after a mid-walk failure left an unreferenced media_items row, the 7 REMAINING '
            . 'unchanged files must be probed again — otherwise that orphan can never be adopted. '
            . 'A count of 33 means only the touched files were read, i.e. $mayAdopt is being '
            . 'captured once before the walk instead of re-read per file.'
        );
        self::assertContains($order[39], $scanner->probedPaths, 'including the very last file');
    }

    /**
     * ⚠ THE `JOIN music_tracks` IN {@see MusicScanSkipIndex::load()}, PINNED.
     *
     * A `media_items` row that carries a stamp but has NO `music_tracks` row is a file
     * that is NOT in the library — the shape produced when the track INSERT writes
     * nothing after the media_item was already minted. Without the join it would be
     * skipped forever and the file would be permanently missing; with it, the file is
     * probed and the track row is created.
     */
    public function testAStampedMediaItemWithNoTrackRowIsProbedAndRepaired(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertCount(3, $db->tracks);

        // Drop one music_tracks row, leaving its stamped media_items row in place.
        $lostMediaItemId = array_key_first($db->tracks);
        self::assertIsString($lostMediaItemId);
        unset($db->tracks[$lostMediaItemId]);

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            1,
            $scanner->probeCount,
            'a stamped media_item with no track row must NOT be skippable — deleting the join in '
            . 'MusicScanSkipIndex::load() turns this retry into permanent data loss'
        );
        self::assertArrayHasKey($lostMediaItemId, $db->tracks, 'and the missing track row must be restored');
    }

    /**
     * A file the scan LOST must not be stamped, or the next scan skips a file that was
     * never successfully indexed.
     */
    public function testAFileTheScanLostIsNotStampedAndIsRetried(): void
    {
        [$dir, $db] = $this->fixture(1);
        $scanner = $this->scanner($db);

        $db->returnNullFor('INSERT INTO music_tracks');
        $failed = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(1, $failed->failed, 'the fixture must actually lose the file');
        self::assertCount(0, $db->tracks);

        $db->clearNullFor();
        $scanner->resetProbes();
        $recovered = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(1, $scanner->probeCount, 'a lost file must be retried, never skipped');
        self::assertSame(0, $recovered->failed);
        self::assertCount(1, $db->tracks, 'and the retry must index it');
    }

    /**
     * THE DEPLOY PATH, stated as a test because it is the honest answer to "is the
     * FIRST rescan after this ships fast?" — no, it is not.
     *
     * Every pre-S122 row carries no `(mtime, size)`, so nothing is skippable until a
     * scan records it. The first rescan therefore reads everything (exactly as today)
     * and stamps as it goes; the second is the fast one. There is deliberately no
     * filesystem backfill migration — recording today's mtime for a row whose indexed
     * tags may predate a change would manufacture a silent miss.
     */
    public function testAnUnstampedLibraryIsReadOnceAndThenSkipped(): void
    {
        [$dir, $db] = $this->fixture(4);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        // Reproduce a pre-S122 database: rows exist, stamps do not.
        foreach ($db->mediaItems as $i => $row) {
            unset(
                $db->mediaItems[$i]['metadata_json'][MusicScanSkipIndex::KEY_MTIME],
                $db->mediaItems[$i]['metadata_json'][MusicScanSkipIndex::KEY_SIZE]
            );
        }

        $scanner->resetProbes();
        $backfill = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(4, $scanner->probeCount, 'an unstamped library must be read in full once');
        self::assertSame(0, $backfill->added);
        self::assertSame(0, $backfill->failed);

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(
            0,
            $scanner->probeCount,
            'the pass that read the library must have stamped it, so the NEXT pass skips it'
        );
    }

    /**
     * The stamp is written on the `updated` branch too, not just `skipped` — otherwise
     * a file that changes once is re-read on every subsequent scan forever.
     */
    public function testAChangedFileIsStampedSoItIsSkippedOnTheFollowingScan(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $path = $dir . '/track-1.mp3';
        touch($path, time() + 60);
        clearstatcache();
        $scanner->titleSuffix = ' (remastered)';

        $scanner->resetProbes();
        $changed = $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(1, $scanner->probeCount);
        self::assertSame(1, $changed->updated, 'the fixture must actually take the updated branch');

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');
        self::assertSame(0, $scanner->probeCount, 'an updated file must be stamped, not left to re-read forever');
    }

    /**
     * A skipped file must not disturb `music_albums.total_tracks`.
     *
     * `refreshAlbumTrackTotal()` runs per FLUSH, and a fully-skipped album is never
     * flushed — so the column has to already be right, which it is because the
     * previous scan derived it from `music_tracks`. This pins that the fast path does
     * not zero it.
     */
    public function testASkippedAlbumKeepsItsTrackTotal(): void
    {
        [$dir, $db] = $this->fixture(3);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $albumId = array_key_first($db->albums);
        self::assertIsInt($albumId);
        self::assertSame(3, $db->albums[$albumId]['total_tracks']);

        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(
            3,
            $db->albums[$albumId]['total_tracks'],
            'a skipped album must keep the total its last flush computed'
        );
    }

    /**
     * A scan with no library id (the legacy `POST /api/v1/music/scan` path) must
     * behave exactly as before: no index is loaded, so nothing is ever skipped.
     *
     * `media_items.library_id` is NOT NULL, so there is no row such a scan could match
     * anyway — loading would be a guaranteed-empty full scan.
     */
    public function testTheLegacyNoLibraryScanPathNeverSkips(): void
    {
        [$dir, $db] = $this->fixture(2);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, null);

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, null);

        self::assertSame(2, $scanner->probeCount, 'a library-less scan has no identity map and must not skip');
    }

    /**
     * Builds a one-album fixture tree plus a matching empty database.
     *
     * @param int $files Number of tracks.
     * @return array{0: string, 1: SkipSchemaConnection}
     */
    private function fixture(int $files): array
    {
        $dir = sys_get_temp_dir() . '/phlix_s122_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        for ($i = 1; $i <= $files; $i++) {
            // Distinct lengths so a size-based assertion cannot pass by accident.
            file_put_contents($dir . '/track-' . $i . '.mp3', str_repeat('a', 100 + $i));
        }

        return [$dir, new SkipSchemaConnection()];
    }

    /**
     * Builds a tree of `$albums` single-track albums, one per subdirectory, so that a scan
     * exceeds {@see MusicLibraryScanner}'s 32-album open window and therefore flushes
     * albums DURING the walk rather than only at the end.
     *
     * @param int $albums Number of albums (and files).
     * @return array{0: string, 1: SkipSchemaConnection}
     */
    private function albumTree(int $albums): array
    {
        $dir = sys_get_temp_dir() . '/phlix_s122_tree_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        for ($i = 1; $i <= $albums; $i++) {
            $sub = $dir . '/album-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            mkdir($sub, 0o777, true);
            file_put_contents($sub . '/track.mp3', str_repeat('a', 100 + $i));
        }

        return [$dir, new SkipSchemaConnection()];
    }

    /**
     * The order the scanner's own walk will visit files in.
     *
     * Read from the filesystem rather than assumed: `RecursiveDirectoryIterator` yields in
     * `readdir()` order, which is not sorted and not portable, so a test that needs "the
     * first file the scan reaches" has to ask.
     *
     * @param string $dir Root directory.
     * @return list<string>
     */
    private function walkOrder(string $dir): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && !$file->isDir() && $file->getExtension() === 'mp3') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /**
     * ⚠ **REVIEW r1 B2 — THE CORRECTED CLAIM, MADE FALSIFIABLE.**
     *
     * {@see MusicLibraryScanner::stampFileIdentity()}'s docblock used to say the stamp
     * `UPDATE` is "skipped entirely … whenever the fast path is switched off for the scan
     * (an unhealed row, or an adoptable orphan) but the files themselves are unchanged",
     * and that this "keeps the exceptional, slow scan from also issuing 61,135 pointless
     * UPDATEs". It cannot: `scanDirectory()` only LOADS the index when the fast path is
     * available, so on exactly those scans the index is empty and `isStampCurrent()` is
     * false for every file.
     *
     * This test measures the real behaviour — 5 unchanged files, one unhealed
     * `music_artists` row, fast path off ⇒ **5 probes and 5 `JSON_SET` UPDATEs** — so the
     * corrected docblock is pinned to a number and cannot silently drift back.
     */
    public function testWithTheFastPathOffEveryUnchangedFileIsStillProbedAndStillReStamped(): void
    {
        [$dir, $db] = $this->fixture(5);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        // Same shape as the heal-gate test: the artist row loses its media_item_id AND
        // its media_items row, so the HEAL gate (not the orphan gate) is what turns the
        // fast path off.
        $key = array_key_first($db->artists);
        self::assertIsString($key);
        $orphanedId = $db->artists[$key]['media_item_id'];
        $db->artists[$key]['media_item_id'] = null;
        $db->mediaItems = array_values(array_filter(
            $db->mediaItems,
            static fn(array $row): bool => $row['id'] !== $orphanedId
        ));

        $scanner->resetProbes();
        $db->statements = [];
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(5, $scanner->probeCount, 'the fast path must be off, so every file is read');
        self::assertSame(
            5,
            $this->countStatements($db, 'UPDATE media_items SET metadata_json = JSON_SET'),
            'and every one of them is re-stamped: the index was never loaded on this scan, so '
            . 'isStampCurrent() cannot suppress anything. This is the measured fact the '
            . 'stampFileIdentity() docblock now states — if this ever becomes 0, the index is being '
            . 'loaded unconditionally and that docblock needs rewriting again.'
        );
    }

    /**
     * The other half of r1 B2: the ONE path on which the suppression really does fire.
     *
     * Index loaded (healthy library, fast path on), then `$mayAdopt` flips mid-walk after
     * a caught write failure. The unchanged files after the flip are probed — `canSkip()`
     * is false from then on — but their stamps are already in the loaded index, so no
     * `JSON_SET` is issued for them.
     */
    public function testTheStampSuppressionFiresOnTheMidWalkFlipPath(): void
    {
        [$dir, $db] = $this->albumTree(40);
        $scanner = $this->scanner($db);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $order = $this->walkOrder($dir);
        foreach (array_slice($order, 0, 33) as $path) {
            touch($path, time() + 90);
        }
        clearstatcache();

        $scanner->albumOverride = [$order[0] => 'A Brand New Album'];
        $db->returnNullFor('INSERT INTO music_albums');

        $scanner->resetProbes();
        $db->statements = [];
        $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(40, $scanner->probeCount, 'the flip must switch the fast path off for the rest');

        $stamps = $this->countStatements($db, 'UPDATE media_items SET metadata_json = JSON_SET');
        self::assertLessThan(
            40,
            $stamps,
            sprintf(
                'issued %d stamp UPDATEs for 40 probed files. The 7 files probed AFTER the flip were '
                . 'unchanged and their identities were already in the loaded index, so '
                . 'isStampCurrent() must suppress their writes — this is the only scan shape on which '
                . 'that suppression can fire at all (review r1 B2).',
                $stamps
            )
        );
    }

    /**
     * ⚠ **REVIEW r1 NON-BLOCKING 1 — `music_read_concurrency = 1` MUST NOT WALK THE TREE
     * TWICE.**
     *
     * With the pool disabled every `submit()` is inert, but the scanner still built a
     * SECOND `RecursiveIteratorIterator` over the whole tree and pulled it in lockstep
     * with the real walk — one `readdir`/`getattr` per entry, on the very knob that
     * exists for a `direct_io` mount where those round trips are most expensive, while
     * `config/scanner.php` promised "byte-for-byte the pre-S122 scanner".
     *
     * The extra work is pure filesystem I/O, which a PHP process cannot count on itself
     * without wrapping every path — so the guard is pinned at the source, the way this
     * suite already pins the concurrency cap's citation
     * ({@see MusicScanPrefetcherTest::testTheCapCitesTheMeasurementThatJustifiesIt()}).
     * ⚠ If you reformat `scanDirectory()`, update these two needles rather than deleting
     * them.
     */
    public function testTheLookaheadWalkIsNotEvenCreatedWhenThePoolIsDisabled(): void
    {
        $source = file_get_contents((new \ReflectionClass(MusicLibraryScanner::class))->getFileName() ?: '');
        self::assertIsString($source);

        self::assertStringContainsString(
            '$lookahead = $prefetcher->poolSize() > 0 ? $this->audioFileIterator($path) : null;',
            $source,
            'the second walk must be created ONLY when the pool actually has readers'
        );
        self::assertStringContainsString(
            '$lookahead !== null',
            $source,
            'and the lookahead loop must tolerate its absence'
        );

        // Behavioural half: a pool-less scan indexes exactly the same library and reports
        // no prefetching at all, so the guard cannot be "satisfied" by breaking the scan.
        [$dir, $db] = $this->fixture(3);
        $logger = new RecordingLogger();
        $scanner = $this->scanner($db, $logger);

        $result = $scanner->scanDirectory($dir, null, 'lib-1');

        self::assertSame(3, $result->added);
        $summary = $logger->contextOf('Music directory scan complete');
        self::assertIsArray($summary);
        self::assertSame(1, $summary['readers_in_flight'] ?? null, 'the scanner itself is the only reader');
        self::assertSame(0, $summary['prefetched'] ?? null, 'nothing can be prefetched with no pool');
    }

    /**
     * ⭐ **S122(b) — WITH THE POOL ON, A RESCAN OF AN UNCHANGED LIBRARY WARMS NOTHING.**
     *
     * ⚠ **Added by the S122/S148 AC audit (2026-08-02), because this was a hole big
     * enough to drive the whole step through.** Every other test in this file runs at
     * `readConcurrency() === 1`, where `scanDirectory()` does not even create the
     * read-ahead walk — so the gate that decides which files the pool is asked to warm,
     *
     * ```php
     * if (!$this->canSkip($mayAdopt, $readEveryFile) || !$skipIndex->isUnchanged($ahead)) {
     *     $prefetcher->submit($ahead->getPathname());
     * }
     * ```
     *
     * was unreachable from the entire suite. Mutating it to `if (true)` — i.e. hand the
     * pool EVERY file the lookahead sees — left `tests/Unit/Media/Music/` plus
     * `tests/Integration/Media/` at **OK (347 tests, 8781 assertions)**, while measured
     * end to end on a 24-file library it took a rescan's `prefetched` from **0 to 24**.
     * That mutant re-opens every unchanged file in the reader children, over the same
     * mount, on exactly the scan S122(a) exists to make free; the main walk's probe count
     * stays at 0, so no probe-count assertion can see it.
     *
     * Both directions are asserted, because either one alone is satisfiable by a broken
     * pool: the FIRST scan must warm all three files (or "0 on the rescan" would just
     * mean the pool never started), and the SECOND must warm none.
     *
     * `prefetch_dropped` is checked at 0 as well: a submission the pool could not place
     * is still a submission the gate should not have made, and counting only `prefetched`
     * would let a saturated pool hide the regression.
     */
    public function testARescanOfAnUnchangedLibraryWarmsNothingWithThePoolOn(): void
    {
        [$dir, $db] = $this->fixture(3);
        $logger = new RecordingLogger();
        $scanner = $this->scanner($db, $logger);
        $scanner->concurrency = MusicScanPrefetcher::DEFAULT_READERS;

        $scanner->scanDirectory($dir, null, 'lib-1');

        $first = $logger->contextOf('Music directory scan complete');
        self::assertIsArray($first);
        self::assertSame(
            MusicScanPrefetcher::DEFAULT_READERS,
            $first['readers_in_flight'] ?? null,
            'the pool must actually be running, or everything below passes vacuously'
        );
        self::assertSame(
            3,
            $first['prefetched'] ?? null,
            'a FIRST scan reads every file, so the read-ahead must be offered every file'
        );

        $scanner->resetProbes();
        $scanner->scanDirectory($dir, null, 'lib-1');

        $second = $logger->contextOf('Music directory scan complete');
        self::assertIsArray($second);
        self::assertSame(0, $scanner->probeCount, 'the main walk still skips all three');
        self::assertSame(
            MusicScanPrefetcher::DEFAULT_READERS,
            $second['readers_in_flight'] ?? null,
            'and the pool is still up on the rescan — this is not the pool-disabled case'
        );
        self::assertSame(
            0,
            $second['prefetched'] ?? null,
            'THE POINT: a file the walk will not open must not be handed to a reader either. '
            . 'The lookahead consults the SAME canSkip() + isUnchanged() pair the walk does, so '
            . 'the two cannot disagree about which files are read. Measured at 24 with the gate '
            . 'mutated to `if (true)`, against 0 here.'
        );
        self::assertSame(
            0,
            $second['prefetch_dropped'] ?? null,
            'and nothing was even offered, so nothing could be dropped — a saturated pool must '
            . 'not be able to disguise a submission the gate should have suppressed'
        );
    }

    /**
     * ⚠ **REVIEW r1 NON-BLOCKING 2 — the drop counter reaches the completion summary.**
     *
     * `MusicScanPrefetcher::$dropped` was documented as being FOR "the scan's completion
     * summary" and then emitted nowhere, which made it unfalsifiable. It is the one number
     * that says the pool was saturated, so it is now a summary key alongside `prefetched`.
     */
    public function testTheCompletionSummaryCarriesEveryS122Counter(): void
    {
        [$dir, $db] = $this->fixture(2);
        $logger = new RecordingLogger();
        $scanner = $this->scanner($db, $logger);
        $scanner->scanDirectory($dir, null, 'lib-1');

        $scanner->scanDirectory($dir, null, 'lib-1');

        $summary = $logger->contextOf('Music directory scan complete');
        self::assertIsArray($summary);

        foreach (
            [
                'skipped_unchanged',
                'skip_index_entries',
                'readers_in_flight',
                'prefetched',
                'prefetch_dropped',
            ] as $key
        ) {
            self::assertArrayHasKey(
                $key,
                $summary,
                $key . ' must ALWAYS be in the summary, even at 0 — that is how an operator confirms '
                . 'from one log line that the fast path engaged and how a 0 on a settled library reads '
                . 'as the anomaly it is'
            );
        }

        self::assertSame(2, $summary['skipped_unchanged'], 'the rescan skipped both files');
        self::assertSame(0, $summary['prefetch_dropped'], 'and dropped nothing, because there is no pool');
    }

    /**
     * How many recorded statements contain `$needle`.
     *
     * @param SkipSchemaConnection $db     Fixture database.
     * @param string               $needle Statement substring.
     * @return int
     */
    private function countStatements(SkipSchemaConnection $db, string $needle): int
    {
        $n = 0;
        foreach ($db->statements as $sql) {
            if (str_contains($sql, $needle)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The `media_items.id` the fixture database holds for a track path.
     *
     * @param SkipSchemaConnection $db   Fixture database.
     * @param string               $path Absolute file path.
     * @return string
     */
    private function mediaItemIdFor(SkipSchemaConnection $db, string $path): string
    {
        foreach ($db->mediaItems as $row) {
            if ($row['type'] === 'track' && $row['path'] === $path) {
                return $row['id'];
            }
        }

        self::fail('no media_items row for ' . $path);
    }

    /**
     * The `(mtime, size)` currently stamped in `metadata_json` for a track path.
     *
     * @param SkipSchemaConnection $db   Fixture database.
     * @param string               $path Absolute file path.
     * @return array{0: int|null, 1: int|null}
     */
    private function stampFor(SkipSchemaConnection $db, string $path): array
    {
        foreach ($db->mediaItems as $row) {
            if ($row['type'] !== 'track' || $row['path'] !== $path) {
                continue;
            }
            $mtime = $row['metadata_json'][MusicScanSkipIndex::KEY_MTIME] ?? null;
            $size = $row['metadata_json'][MusicScanSkipIndex::KEY_SIZE] ?? null;

            return [is_int($mtime) ? $mtime : null, is_int($size) ? $size : null];
        }

        self::fail('no media_items row for ' . $path);
    }

    /**
     * A scanner whose tag reads are counted and whose read-ahead pool is off.
     */
    private function scanner(SkipSchemaConnection $db, ?RecordingLogger $logger = null): ProbeCountingScanner
    {
        return new ProbeCountingScanner(
            $db,
            $this->createMock(FfmpegRunner::class),
            $logger ?? $this->createMock(StructuredLogger::class)
        );
    }

    /**
     * @param string $dir Directory to delete recursively.
     * @return void
     */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
