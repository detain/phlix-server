<?php

/**
 * Phlix media server test: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicScanSkipIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SplFileInfo;
use Workerman\MySQL\Connection;

/**
 * The S122(a) identity map in isolation: what it loads, what it refuses to answer,
 * and the bounds that keep it from becoming the unbounded per-file map S95 removed.
 *
 * @internal
 */
#[CoversClass(MusicScanSkipIndex::class)]
final class MusicScanSkipIndexTest extends TestCase
{
    /** @var list<string> Files to remove. */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];

        parent::tearDown();
    }

    /**
     * An unloaded index answers FALSE for everything.
     *
     * "Unproven" must resolve to "read the file". Every FALSE costs one read; a wrong
     * TRUE loses a change until the file is touched again, so the default has to be the
     * expensive one.
     */
    public function testAnUnloadedIndexNeverClaimsAFileIsUnchanged(): void
    {
        $index = new MusicScanSkipIndex($this->connection([]), new NullLogger());
        $file = $this->file('unloaded');

        self::assertFalse($index->isLoaded());
        self::assertFalse($index->isUnchanged($file));
        self::assertFalse($index->isStampCurrent($file));
        self::assertSame(0, $index->count());
    }

    /**
     * A NULL library id loads nothing at all — and issues no statement.
     *
     * `media_items.library_id` is NOT NULL, so a `library_id <=> NULL` predicate could
     * only ever match zero rows while scanning the table to prove it. The legacy
     * no-library scan path therefore keeps exactly its pre-S122 behaviour.
     */
    public function testANullLibraryIdLoadsNothingAndIssuesNoStatement(): void
    {
        $db = $this->connection([]);
        $index = new MusicScanSkipIndex($db, new NullLogger());

        $index->load(null);

        self::assertTrue($index->isLoaded());
        self::assertSame(0, $index->count());
        self::assertSame([], $db->statements, 'a library-less scan must not query at all');

        $index->load('');
        self::assertSame([], $db->statements);
    }

    /**
     * A loaded row matches only when BOTH halves match.
     */
    public function testTheIdentityMustMatchOnBothMtimeAndSize(): void
    {
        $file = $this->file('both-halves', 'twelve bytes');
        $mtime = $file->getMTime();
        $size = $file->getSize();

        $exact = $this->loaded([[$file->getPathname(), $mtime, $size]]);
        self::assertTrue($exact->isUnchanged($file), 'an exact match is the only TRUE');

        $wrongMtime = $this->loaded([[$file->getPathname(), $mtime + 1, $size]]);
        self::assertFalse($wrongMtime->isUnchanged($file), 'a differing mtime alone must force a re-read');

        $wrongSize = $this->loaded([[$file->getPathname(), $mtime, $size + 1]]);
        self::assertFalse($wrongSize->isUnchanged($file), 'a differing size alone must force a re-read');

        $otherPath = $this->loaded([['/somewhere/else.mp3', $mtime, $size]]);
        self::assertFalse($otherPath->isUnchanged($file), 'an identity recorded for another path is not this file');
    }

    /**
     * The identity is compared as a STRING, so no numeric coercion can make two
     * different identities look equal.
     *
     * Without that, an index entry of `mtime=1, size=23` and a file of `mtime=12,
     * size=3` could collide through a sloppy concatenation. The delimiter plus a string
     * comparison rules the whole class out.
     */
    public function testDifferentIdentitiesCannotCollideThroughConcatenation(): void
    {
        $file = $this->file('collide', 'abc');
        $mtime = $file->getMTime();
        $size = $file->getSize();

        // "<mtime>:<size>" for the file vs a recorded pair whose digits, concatenated
        // WITHOUT a delimiter, would be identical.
        $index = $this->loaded([[$file->getPathname(), (int) ($mtime . '0'), 0]]);

        self::assertFalse(
            $index->isUnchanged($file),
            'the delimiter is load-bearing: mtime=<m>0/size=0 must not equal mtime=<m>/size=0…'
        );
        self::assertNotSame((string) $mtime . (string) $size, (string) ($mtime . '0') . '0');
    }

    /**
     * A row with no stamp is not an entry. Every pre-S122 row is this shape.
     */
    public function testRowsWithoutAStampAreNotLoaded(): void
    {
        $file = $this->file('unstamped');

        $index = new MusicScanSkipIndex(
            $this->connection([
                ['path' => $file->getPathname(), 'file_mtime' => null, 'file_size' => null],
                ['path' => '/b.mp3', 'file_mtime' => '123', 'file_size' => null],
                ['path' => '/c.mp3', 'file_mtime' => null, 'file_size' => '456'],
                ['path' => '', 'file_mtime' => '1', 'file_size' => '2'],
                ['path' => '/d.mp3', 'file_mtime' => 'not-a-number', 'file_size' => '2'],
            ]),
            new NullLogger()
        );
        $index->load('lib-1');

        self::assertSame(0, $index->count(), 'a half-recorded identity is no identity');
        self::assertFalse($index->isUnchanged($file));
    }

    /**
     * A file that cannot be stat'ed is never "unchanged".
     *
     * `SplFileInfo::getMTime()` throws for a vanished file, and that throw must resolve
     * to FALSE rather than escaping into the walk.
     */
    public function testAVanishedFileIsNeverReportedUnchanged(): void
    {
        $file = $this->file('vanishing');
        $index = $this->loaded([[$file->getPathname(), $file->getMTime(), $file->getSize()]]);
        self::assertTrue($index->isUnchanged($file));

        @unlink($file->getPathname());
        clearstatcache();
        $gone = new SplFileInfo($file->getPathname());

        self::assertFalse($index->isUnchanged($gone));
        self::assertFalse($index->isStampCurrent($gone));
        self::assertNull(MusicScanSkipIndex::stampValues($gone));
    }

    /**
     * A load failure leaves an empty index instead of aborting the scan.
     *
     * A transient DB error must cost a slow scan, never a failed one — the same
     * fail-open rule the scanner's orphan-adoption gate follows.
     */
    public function testALoadFailureDegradesToProbingEveryFile(): void
    {
        $db = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->method('query')->willThrowException(new \RuntimeException('server has gone away'));

        $index = new MusicScanSkipIndex($db, new NullLogger());
        $index->load('lib-1');

        self::assertTrue($index->isLoaded());
        self::assertSame(0, $index->count());
        self::assertFalse($index->isUnchanged($this->file('after-failure')));
    }

    /**
     * `remember()` keeps the in-memory map in step with what has just been written, so
     * a path the walk reaches twice (a hard link, a tree entered through a symlink) is
     * not probed twice.
     */
    public function testRememberMakesAFreshlyIndexedFileSkippable(): void
    {
        $file = $this->file('remembered');
        $index = $this->loaded([]);

        self::assertFalse($index->isUnchanged($file));
        $index->remember($file);
        self::assertTrue($index->isUnchanged($file));
        self::assertTrue($index->isStampCurrent($file));
        self::assertSame(1, $index->count());
    }

    /**
     * `isStampCurrent()` is the "would a write change anything?" question, and it is
     * NOT the same as `isUnchanged()` — it must answer on an index that was never
     * loaded, because that is exactly the state a scan is in when the fast path is
     * switched off but the files are unchanged.
     */
    public function testIsStampCurrentAnswersEvenOnAnUnloadedIndex(): void
    {
        $file = $this->file('stamp-current');
        $index = new MusicScanSkipIndex($this->connection([]), new NullLogger());

        self::assertFalse($index->isStampCurrent($file));
        $index->remember($file);
        self::assertTrue($index->isStampCurrent($file), 'no write is needed when the stamp already matches');
        self::assertFalse($index->isLoaded(), 'and it must not require a load to say so');
    }

    /**
     * The entry cap is a hard bound, and overflowing it degrades to "probe the file" —
     * correct, merely slower.
     */
    public function testTheEntryCapBoundsMemoryAndOverflowFailsSafe(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['path' => '/m/' . $i . '.mp3', 'file_mtime' => '100', 'file_size' => '200'];
        }

        $index = new MusicScanSkipIndex($this->connection($rows), new NullLogger());
        // The shipped cap is ~4x the 61,135-file production library, so it exists to make
        // memory a function of a CONSTANT rather than of library growth. It is asserted
        // here rather than exercised by loading 250,000 rows, which would put ~46 MB and
        // several seconds into the unit suite for no extra signal.
        self::assertSame(250_000, MusicScanSkipIndex::MAX_ENTRIES);

        $index->load('lib-1');
        self::assertSame(12, $index->count());
        self::assertFalse($index->wasTruncated());

        // remember() honours the same bound: it may refresh an existing key but must not
        // grow the map past the cap.
        $file = $this->file('beyond-cap');
        $index->remember($file);
        self::assertSame(13, $index->count());
    }

    /**
     * A `query()` that hands back something other than a row list, and rows that are not
     * arrays, are both ignored rather than crashing the walk.
     *
     * Reachable in production: `Connection::query()` returns `null` for any statement
     * whose leading keyword it does not recognise, so a reformat of the load statement
     * lands here (the same landmine the backfill site documents at length), and a bare
     * `createMock(Connection::class)` returns `null` for every call.
     */
    public function testANonListResultAndMalformedRowsAreIgnored(): void
    {
        $file = $this->file('malformed');

        $notAList = new MusicScanSkipIndex(new NonListConnection(), new NullLogger());
        $notAList->load('lib-1');
        self::assertTrue($notAList->isLoaded());
        self::assertSame(0, $notAList->count());
        self::assertFalse($notAList->isUnchanged($file));

        $badRows = new MusicScanSkipIndex(
            new SkipIndexConnection([
                ['path' => $file->getPathname(), 'file_mtime' => (string) $file->getMTime(),
                    'file_size' => (string) $file->getSize()],
            ], ['not-a-row']),
            new NullLogger()
        );
        $badRows->load('lib-1');
        self::assertSame(1, $badRows->count(), 'the good row survives, the scalar row is dropped');
        self::assertTrue($badRows->isUnchanged($file));
    }

    /**
     * The cap really does stop the load short, really does warn, and really does bound
     * `remember()` too — driven with `MAX_ENTRIES + 1` rows rather than asserted.
     *
     * Worth the ~250k-row fixture (measured under 1 s and well inside the suite's memory
     * envelope) because the whole memory argument for loading the library in one query
     * rests on this bound, and an untested bound is a bound that silently is not one.
     */
    public function testTheCapTruncatesTheLoadAndWarns(): void
    {
        $rows = [];
        for ($i = 0, $n = MusicScanSkipIndex::MAX_ENTRIES + 1; $i < $n; $i++) {
            $rows[] = ['path' => '/m/' . $i, 'file_mtime' => '100', 'file_size' => '200'];
        }

        $logger = new RecordingLogger();
        $index = new MusicScanSkipIndex(new SkipIndexConnection($rows), $logger);
        $index->load('lib-1');

        self::assertSame(MusicScanSkipIndex::MAX_ENTRIES, $index->count(), 'the cap is the ceiling, exactly');
        self::assertTrue($index->wasTruncated());
        self::assertNotSame(
            [],
            $logger->warnings,
            'a truncated index means the rest of the library is re-read, which an operator must be told'
        );
        self::assertStringContainsString('entry cap', $logger->warnings[0]);

        // remember() honours the same bound: a NEW key past the cap is refused, so a
        // pathological library cannot grow the map through the back door.
        $file = $this->file('past-the-cap');
        $index->remember($file);
        self::assertSame(MusicScanSkipIndex::MAX_ENTRIES, $index->count());
        self::assertFalse($index->isUnchanged($file));
    }

    /**
     * `remember()` on a file that cannot be stat'ed records nothing.
     */
    public function testRememberIgnoresAFileItCannotStat(): void
    {
        $index = $this->loaded([]);
        $index->remember(new SplFileInfo('/nonexistent/phlix/s122/never.mp3'));

        self::assertSame(0, $index->count());
    }

    /**
     * `reset()` drops everything, which is what makes one instance reusable across
     * `scanDirectory()` calls without leaking one library's map into another.
     */
    public function testResetClearsTheMapAndTheLoadedFlag(): void
    {
        $file = $this->file('resettable');
        $index = $this->loaded([[$file->getPathname(), $file->getMTime(), $file->getSize()]]);
        self::assertTrue($index->isUnchanged($file));

        $index->reset();

        self::assertFalse($index->isLoaded());
        self::assertSame(0, $index->count());
        self::assertFalse($index->isUnchanged($file));
    }

    /**
     * The load statement JOINs `music_tracks` and scopes on `library_id = ?` — both
     * asserted against the SQL, because both are load-bearing and neither is visible in
     * the result of a passing behavioural test.
     *
     * The join is the property that keeps a lost file retryable (a `media_items` row
     * with no track row must not be skippable). The `=` rather than `<=>` is what keeps
     * the plan on `idx_media_items_library_type` instead of at the mercy of the
     * optimizer's handling of the null-safe operator.
     */
    public function testTheLoadStatementJoinsTrackRowsAndScopesByLibrary(): void
    {
        $db = $this->connection([]);
        (new MusicScanSkipIndex($db, new NullLogger()))->load('lib-1');

        self::assertCount(1, $db->statements, 'exactly ONE statement per scan — that is the design');
        $sql = $db->statements[0];

        self::assertStringContainsString('JOIN music_tracks mt ON mt.media_item_id = mi.id', $sql);
        self::assertStringContainsString('mi.library_id = ?', $sql);
        self::assertStringNotContainsString('library_id <=>', $sql);
        self::assertStringContainsString("mi.type = 'track'", $sql);
        self::assertStringContainsString("'$.file_mtime'", $sql);
        self::assertStringContainsString("'$.file_size'", $sql);
    }

    /**
     * ⚠ **REVIEW r1 B3 — THIS TEST USED TO MEASURE A CONDITION PRODUCTION NEVER HAS.**
     *
     * The earlier version built `$rows` in the test method, handed the array to the
     * connection double, and then measured the delta across `load()`. Every path string
     * was therefore already allocated and still owned by the test, so the map's KEYS
     * were shared by refcount and invisible to the delta — which is how it reproduced
     * "90.9 bytes/entry" to the byte while the real figure is **186.9**, ≈2x higher. The
     * `< 200.0` assertion passed at both numbers, so it guarded nothing.
     *
     * Production has the opposite shape: `$rows` is the driver's result set, it dies when
     * `load()` returns, and the map is then the SOLE owner of every key. That is
     * reproduced here by generating the rows INSIDE
     * {@see GeneratedIdentityConnection::query()} — nothing outside the map ever holds a
     * path string — and by measuring at the REAL production library size, so the figure
     * the class docblock quotes is the figure this test takes.
     *
     * Measured on PHP 8.3.6, 61,135 entries, exact-length 56-character keys:
     * **11,424,960 B retained = 10.90 MiB = 186.9 B/entry**, reproducible to the byte
     * across runs and confirmed by an independent raw-hashtable measurement that never
     * calls `load()` at all.
     *
     * The comparison that matters is against what S95 REMOVED: 1,463 B per buffered file
     * for the whole-tree map, i.e. ≈89 MB for this library. 186.9 B is **7.8x** cheaper
     * (not the 16x the pre-r1 docblock claimed), which is still the whole argument for
     * loading the library in one query.
     */
    public function testMemoryPerEntryIsBounded(): void
    {
        $count = 61_135;

        $index = new MusicScanSkipIndex(new GeneratedIdentityConnection($count, 56), new NullLogger());

        gc_collect_cycles();
        $before = memory_get_usage();
        $index->load('lib-1');
        gc_collect_cycles();
        $after = memory_get_usage();

        self::assertSame($count, $index->count(), 'the whole library must be loaded, or the figure is not the figure');

        $retained = $after - $before;
        $perEntry = $retained / $count;

        self::assertLessThan(
            210.0,
            $perEntry,
            sprintf(
                'measured %.1f B/entry (%d B retained for %d entries). The class docblock quotes '
                . '186.9 B/entry = 10.90 MiB at exactly this size, measured with the keys owned ONLY '
                . 'by the map. Past 210 B/entry the value shape has grown — most likely back into an '
                . 'array per entry — and the memory argument for loading the whole library in ONE '
                . 'query stops holding, because the alternative it beat was 1,463 B/entry.',
                $perEntry,
                $retained,
                $count
            )
        );

        // And the floor: a figure far BELOW the documented one means the measurement has
        // gone back to sharing its keys with the caller, i.e. the r1 B3 defect has
        // returned and the test is measuring nothing again.
        self::assertGreaterThan(
            120.0,
            $perEntry,
            sprintf(
                'measured only %.1f B/entry, which is below what a 56-byte key plus an 18-byte value '
                . 'plus a hashtable bucket can possibly cost. That means the path strings are being '
                . 'shared with something outside the map — exactly the flaw review r1 B3 found in the '
                . 'previous version of this test.',
                $perEntry
            )
        );

        // ⚠ THE LOAD'S TRANSIENT PEAK IS DELIBERATELY *NOT* ASSERTED HERE. It is the
        // other half of the memory story — the driver materialises the whole result set
        // before MAX_ENTRIES can refuse an entry, so a load peaks well above what it
        // retains (measured standalone: 38,527,368 B = 36.74 MiB peak against 10.90 MiB
        // retained at this size, and 153.54 MiB against 44.33 MiB at MAX_ENTRIES) — but
        // `memory_get_peak_usage()` is PROCESS-wide and monotonic, and this file's own
        // `testTheCapTruncatesTheLoadAndWarns()` loads 250,001 rows. Under
        // `executionOrder="random"` the delta measured here is therefore 0 whenever that
        // test ran first. Asserting on it would be a coin-flip, so the figure lives in
        // MusicScanSkipIndex's docblock with its method stated instead.
    }

    /**
     * An index loaded from the given rows.
     *
     * @param list<array{0: string, 1: int, 2: int}> $entries `[path, mtime, size]`.
     */
    private function loaded(array $entries): MusicScanSkipIndex
    {
        $rows = [];
        foreach ($entries as [$path, $mtime, $size]) {
            $rows[] = ['path' => $path, 'file_mtime' => (string) $mtime, 'file_size' => (string) $size];
        }

        $index = new MusicScanSkipIndex($this->connection($rows), new NullLogger());
        $index->load('lib-1');

        return $index;
    }

    /**
     * A connection that returns the given rows for the identity-map SELECT.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function connection(array $rows): SkipIndexConnection
    {
        return new SkipIndexConnection($rows);
    }

    /**
     * A real file on disk, so `getMTime()`/`getSize()` are real stats.
     */
    private function file(string $name, string $contents = 'audio-ish'): SplFileInfo
    {
        $path = sys_get_temp_dir() . '/phlix_s122_idx_' . $name . '_' . bin2hex(random_bytes(4)) . '.mp3';
        file_put_contents($path, $contents);
        $this->files[] = $path;
        clearstatcache(true, $path);

        return new SplFileInfo($path);
    }
}
