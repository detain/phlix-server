<?php

/**
 * Phlix media server tests: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\StreamProbeBackfill;
use Phlix\Media\Transcoding\FfmpegRunner;
use Psr\Log\NullLogger;

/**
 * Lazy playback-info stream backfill (migration 071): pre-071 items get ONE
 * blocking ffprobe on their first playback-info request; the
 * `streams_probed_at` marker (stamped on success AND on PROBE failure)
 * guarantees the probe never runs twice — including for files that genuinely
 * have one audio track and no subtitles. A failed stream WRITE is the one
 * failure that is NOT stamped: it rolls back, so the item still needs the
 * repair.
 */
class StreamProbeBackfillTest extends TestCase
{
    /** @var list<string> Temp files to unlink in tearDown. */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    /** Creates a real (empty) media file on disk so the is_file() gate passes. */
    private function makeTempFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phlix-probe-');
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** @return array<string, mixed> A realistic multi-track ffprobe result. */
    private function multiTrackProbe(): array
    {
        return [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264',
                 'width' => 1920, 'height' => 1080, 'bit_rate' => '5000000'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'ac3',
                 'channels' => 6, 'disposition' => ['default' => 1],
                 'tags' => ['language' => 'eng', 'title' => 'Surround 5.1']],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
                ['index' => 3, 'codec_type' => 'subtitle', 'codec_name' => 'subrip',
                 'tags' => ['language' => 'eng']],
            ],
            'format' => ['duration' => '600.0'],
        ];
    }

    /**
     * The single pre-071 shape that triggers the backfill: one audio row and
     * no subtitle rows.
     *
     * @return list<array<string, mixed>>
     */
    private function legacyStreams(): array
    {
        return [
            ['id' => 's-v', 'stream_type' => 'video', 'stream_index' => 0, 'codec' => 'h264'],
            ['id' => 's-a', 'stream_type' => 'audio', 'stream_index' => 1, 'codec' => 'aac'],
        ];
    }

    public function testUnprobedItemGetsProbedPersistedMarkedAndReRead(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->with($path)->willReturn($this->multiTrackProbe());

        $fresh = [['id' => 'new-1', 'stream_type' => 'video'], ['id' => 'new-2', 'stream_type' => 'subtitle']];
        $repo = $this->createMock(ItemRepository::class);
        // The replacement is ONE atomic repository call (delete + all inserts in
        // a single transaction) — see ItemRepository::replaceStreams(); the rows
        // it is handed are asserted below.
        $added = [];
        $repo->expects($this->once())->method('replaceStreams')
            ->with('movie-1', $this->anything())
            ->willReturnCallback(function (string $itemId, array $streams) use (&$added): void {
                $added = $streams;
            });
        $repo->expects($this->never())->method('deleteStreamsByItem');
        $repo->expects($this->never())->method('addStream');
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');
        $repo->expects($this->once())->method('getItemStreams')->with('movie-1')->willReturn($fresh);

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $result = $backfill->ensureFor($item, $this->legacyStreams());

        $this->assertSame($fresh, $result, 'returns the re-read rows after persisting');
        // The full set flowed through: 1 video + 2 audio + 1 subtitle, with
        // the scan-time metadata fields (channels/title/is_default).
        $this->assertSame(
            ['video', 'audio', 'audio', 'subtitle'],
            array_map(fn ($s) => $s['stream_type'], $added)
        );
        $this->assertSame(6, $added[1]['channels']);
        $this->assertSame('Surround 5.1', $added[1]['title']);
        $this->assertSame(1, $added[1]['is_default']);
    }

    public function testProbedMarkerPreventsSecondProbe(): void
    {
        $path = $this->makeTempFile();

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        // The whole point: across BOTH requests the blocking probe runs once.
        $ffmpeg->expects($this->once())->method('probe')->willReturn($this->multiTrackProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('getItemStreams')->willReturn([['id' => 'new', 'stream_type' => 'subtitle']]);
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());

        // First playback-info request: unprobed → probes + stamps the marker.
        $backfill->ensureFor(['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null], $this->legacyStreams());

        // Second request re-reads the item, which now carries the marker —
        // even though its rows would still "look" legacy, no probe runs.
        $stored = $this->legacyStreams();
        $result = $backfill->ensureFor(
            ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => '2026-07-10 12:00:00'],
            $stored
        );
        $this->assertSame($stored, $result, 'marked item is served its stored rows untouched');
    }

    public function testFullyProbedLookingRowsAreTrustedWithoutProbing(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('markStreamsProbed');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());

        // Any subtitle row → trusted.
        $withSubs = [['stream_type' => 'video'], ['stream_type' => 'subtitle']];
        $this->assertSame(
            $withSubs,
            $backfill->ensureFor(['id' => 'm1', 'path' => $this->makeTempFile(), 'streams_probed_at' => null], $withSubs)
        );

        // Two audio rows → trusted.
        $twoAudio = [['stream_type' => 'audio'], ['stream_type' => 'audio']];
        $this->assertSame(
            $twoAudio,
            $backfill->ensureFor(['id' => 'm2', 'path' => $this->makeTempFile(), 'streams_probed_at' => null], $twoAudio)
        );
    }

    public function testProbeFailureMarksProbedAndDegradesToStoredRows(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->willReturn(null); // ffprobe failed

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->never())->method('deleteStreamsByItem');
        $repo->expects($this->never())->method('addStream');
        // Stamped anyway — a broken file must not re-run the probe every request.
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }

    public function testProbeThrowingMarksProbedAndDegradesToStoredRows(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->willThrowException(new \RuntimeException('boom'));

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }

    /**
     * A rolled-back stream write that CAN succeed later must NOT stamp the
     * marker. `replaceStreams()` rolls back, so the item is left holding exactly
     * the rows that triggered the repair; stamping would then have both entry
     * points skip it forever, and nothing else rebuilds these rows (a rescan
     * short-circuits once duration + `source` are populated, and
     * `scripts/backfill-streams.php` reselects only items with zero rows or a
     * NULL stream_index). So the item must stay eligible for a later retry.
     *
     * Six differently-SHAPED transient failures, because one planted case only
     * proves that case:
     *  - a deadlock as it actually arrives, i.e. RE-WRAPPED by
     *    `PhlixMySQLConnection::execute()` into a fresh `PDOException` with the
     *    failing SQL prefixed and **no `errorInfo`** (the common shape: only
     *    2006/2013 keep the original object);
     *  - a lock-wait timeout that DID keep its `errorInfo` (the 2006/2013 path);
     *  - a dropped connection recognised by its SQLSTATE class alone (`08S01`),
     *    with an errno that is not in the allow-list;
     *  - `PooledMySQLConnection`'s plain `RuntimeException('pool exhausted…')`,
     *    which is not a `PDOException` at all and reaches this handler because
     *    the pool leases on `replaceStreams()`'s own `beginTrans()`;
     *  - the two CONNECT-time shapes, which are the reason `sqlErrorOf()` reads
     *    `errorInfo` at all: PDO words a failed connect
     *    `SQLSTATE[08004] [1040] Too many connections`, with the errno in
     *    BRACKETS and no colon after `]`, so the message parser cannot see it
     *    and only `errorInfo` can. One is recognised by its errno (2002) and one
     *    by its SQLSTATE class (08004), so neither signal alone carries both.
     *
     * @dataProvider transientWriteFailures
     */
    public function testTransientStreamWriteFailureIsNotStampedSoTheItemStaysRepairable(
        \Throwable $failure
    ): void {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->willReturn($this->multiTrackProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())->method('replaceStreams')->willThrowException($failure);
        // THE assertion: never stamped, so the next request retries the repair.
        $repo->expects($this->never())->method('markStreamsProbed');
        // And no post-write re-read either — there is nothing new to read.
        $repo->expects($this->never())->method('getItemStreams');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored), 'degrades to the stored rows');
    }

    /**
     * Transient write failures — the identical statement can succeed next time.
     *
     * @return array<string, array{0: \Throwable}>
     */
    public static function transientWriteFailures(): array
    {
        return [
            'deadlock, re-wrapped by execute() (no errorInfo)' => [self::rewrapped(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to '
                . 'get lock; try restarting transaction',
                40001
            )],
            'lock wait timeout, errorInfo intact' => [self::withErrorInfo(
                'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded',
                'HY000',
                1205
            )],
            'connection dropped, recognised by SQLSTATE class 08' => [self::withErrorInfo(
                'SQLSTATE[08S01]: Communication link failure: 1159 Got timeout reading '
                . 'communication packets',
                '08S01',
                1159
            )],
            'pool exhausted (not a PDOException at all)' => [
                new \RuntimeException('pool exhausted: could not acquire a connection within 10 s'),
            ],
            // The two shapes only `errorInfo` can classify. Both messages are
            // verbatim PDO output from MySQL 8.0.46 and are UNPARSEABLE by the
            // SQLSTATE pattern, so dropping the errorInfo reader stamps them.
            'connect refused, errno only in errorInfo (2002)' => [self::connectFailure(
                'SQLSTATE[HY000] [2002] Connection refused',
                'HY000',
                2002
            )],
            'too many connections, SQLSTATE class only in errorInfo (1040)' => [self::connectFailure(
                'SQLSTATE[08004] [1040] Too many connections',
                '08004',
                1040
            )],
        ];
    }

    /**
     * The OTHER direction, and the reason this classification exists: a write
     * failure that will fail IDENTICALLY on every retry must be stamped.
     *
     * Without the stamp the guard chain is unchanged on the next request — the
     * rollback restored exactly the rows that triggered the repair — so every
     * single request for the item re-runs the ~1s BLOCKING ffprobe, forever,
     * inside one of 14 resident single-threaded Workerman workers.
     * `ensureFor()` has 79,218 unstamped candidates on the measured library, so
     * that is a production stall rather than a slow endpoint.
     *
     * Six shapes again, including the three that pin the chosen default: an
     * UNRECOGNISED failure is treated as deterministic (master's behaviour —
     * never worse), the SQLSTATE parser takes the LAST match so a decoy
     * inside the re-wrapped SQL text — which contains the row's own parameter
     * values — cannot flip a permanent failure to "transient", and a CONNECT
     * failure that will never clear by itself (1045) stays deterministic even
     * though `errorInfo` makes its errno readable.
     *
     * @dataProvider deterministicWriteFailures
     */
    public function testDeterministicStreamWriteFailureIsStampedSoTheProbeCannotHotLoop(
        \Throwable $failure
    ): void {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->willReturn($this->multiTrackProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())->method('replaceStreams')->willThrowException($failure);
        // THE assertion: stamped exactly once, which is what bounds the probe.
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');
        // Still no re-read: the write rolled back, there is nothing new to read.
        $repo->expects($this->never())->method('getItemStreams');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored), 'still degrades to the stored rows');
    }

    /**
     * Deterministic write failures — the same rows would fail the same way for
     * ever, so retrying buys nothing and costs a blocking probe each time.
     *
     * @return array<string, array{0: \Throwable}>
     */
    public static function deterministicWriteFailures(): array
    {
        return [
            'data too long (1406)' => [self::rewrapped(
                "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for "
                . "column 'title' at row 1",
                22001
            )],
            'incorrect string value (1366)' => [self::rewrapped(
                "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xED\\xA0\\x80' "
                . "for column 'title' at row 1",
                0
            )],
            'un-migrated schema, unknown column (1054)' => [self::rewrapped(
                "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'color_space' in "
                . "'field list'",
                42
            )],
            'unrecognised failure defaults to deterministic' => [
                new \RuntimeException('something nobody enumerated'),
            ],
            'a decoy SQLSTATE in the row data does not win' => [new \PDOException(
                "SQL:INSERT INTO media_streams (…,title,…) VALUES (…,'SQLSTATE[40001]: x: 1213 "
                . "Deadlock',…) SQLSTATE[22001]: String data, right truncated: 1406 Data too "
                . "long for column 'title' at row 1",
                22001
            )],
            // The other direction for the errorInfo reader: reading errorInfo
            // must not make every CONNECT failure look transient. A wrong
            // password fails identically for ever, so it is stamped.
            'connect denied (1045) is NOT transient' => [self::connectFailure(
                "SQLSTATE[HY000] [1045] Access denied for user 'phlix'@'127.0.0.1' "
                . '(using password: YES)',
                'HY000',
                1045
            )],
        ];
    }

    /**
     * The failure exactly as `PhlixMySQLConnection::execute()` re-throws it:
     * `new PDOException('SQL:' . lastSQL() . ' ' . $e->getMessage(), (int) $e->getCode())`.
     * A freshly constructed PDOException carries NO `errorInfo`, which is why the
     * classification also parses the message.
     */
    private static function rewrapped(string $pdoMessage, int $code): \PDOException
    {
        return new \PDOException(
            'SQL:INSERT INTO media_streams (id, media_item_id, stream_index, stream_type) '
            . "VALUES ('s-1','movie-1',1,'audio') " . $pdoMessage,
            $code
        );
    }

    /**
     * A PDOException that still carries its driver `errorInfo` — the shape
     * `PhlixMySQLConnection::execute()` re-throws unchanged for 2006/2013 after
     * its one-shot reconnect fails.
     */
    private static function withErrorInfo(string $message, string $sqlState, int $errno): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = [$sqlState, $errno, $message];
        return $e;
    }

    /**
     * A failure raised while OPENING the connection rather than while running a
     * statement — `new PDO(...)` inside `PooledMySQLConnection::acquire()`'s
     * `rawFactory()`, or inside workerman/mysql's `beginTrans()` reconnect.
     *
     * Two things make it its own shape, both reproduced against MySQL 8.0.46:
     * it reaches the classifier VERBATIM (nothing re-wraps it, so `errorInfo`
     * survives), and PDO words it `SQLSTATE[08004] [1040] Too many connections`
     * — errno in brackets, no colon after `]` — which the message pattern in
     * `sqlErrorOf()` cannot read. `errorInfo` is therefore the only signal, and
     * `getMessage()` deliberately carries nothing the parser can pick up.
     */
    private static function connectFailure(string $message, string $sqlState, int $errno): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = [$sqlState, $errno, $message];
        return $e;
    }

    /**
     * The half of the errorInfo proof that the classification tests cannot show
     * on their own: every CONNECT-shaped message above is UNPARSEABLE by
     * `sqlErrorOf()`'s message reader (the pattern is asserted here verbatim, so
     * it fails if either side drifts). Together with
     * `testTransientStreamWriteFailureIsNotStampedSoTheItemStaysRepairable`
     * classifying those same failures as transient, that pins `errorInfo` as the
     * signal actually consulted — a message reader alone would see `['', 0]` and
     * stamp them.
     */
    public function testConnectFailureMessagesCarryNothingTheMessageReaderCanParse(): void
    {
        $pattern = '/SQLSTATE\[([0-9A-Za-z]{5})\]:[^:]*:\s*(\d+)\b/';

        // A statement failure IS parseable — the control, so a pattern that
        // matched nothing at all could not pass this test.
        $this->assertSame(
            1,
            preg_match($pattern, 'SQLSTATE[HY000]: General error: 1366 Incorrect string value'),
            'statement failures must stay parseable from the message'
        );

        $connectShapes = [
            'connect refused, errno only in errorInfo (2002)',
            'too many connections, SQLSTATE class only in errorInfo (1040)',
        ];
        foreach ($connectShapes as $case) {
            $failure = self::transientWriteFailures()[$case][0];
            $this->assertSame(0, preg_match($pattern, $failure->getMessage()), $case);
        }
        $denied = self::deterministicWriteFailures()['connect denied (1045) is NOT transient'][0];
        $this->assertSame(0, preg_match($pattern, $denied->getMessage()), 'access denied');
    }

    /**
     * The retry BOUND, end to end over two consecutive requests: a deterministic
     * write failure stamps on the first request, and the stamp is what stops the
     * second one from re-running the blocking probe.
     *
     * The item row is re-read per request in production, so the second call is
     * given the row as it now stands — which is precisely what the stamp changed.
     */
    public function testADeterministicWriteFailureStopsTheProbeOnTheNextRequest(): void
    {
        $path = $this->makeTempFile();

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        // THE bound: one blocking probe across BOTH requests.
        $ffmpeg->expects($this->once())->method('probe')->willReturn($this->multiTrackProbe());

        $stamped = false;
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('replaceStreams')->willThrowException(self::rewrapped(
            "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'title' at row 1",
            22001
        ));
        $repo->expects($this->once())->method('markStreamsProbed')
            ->willReturnCallback(function () use (&$stamped): void {
                $stamped = true;
            });

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        for ($request = 0; $request < 2; $request++) {
            $backfill->ensureFor(
                ['id' => 'movie-1', 'path' => $path,
                 'streams_probed_at' => $stamped ? '2026-07-28 09:00:00' : null],
                $stored
            );
        }

        $this->assertTrue($stamped, 'the deterministic failure stamped the marker');
    }

    /**
     * The mirror, and the property the previous round established: a TRANSIENT
     * write failure leaves the item unstamped, so the very next request DOES try
     * again — the item is genuinely repairable, not merely un-stamped.
     */
    public function testATransientWriteFailureIsRetriedOnTheNextRequest(): void
    {
        $path = $this->makeTempFile();

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        // Retried: two requests, two probes.
        $ffmpeg->expects($this->exactly(2))->method('probe')->willReturn($this->multiTrackProbe());

        $stamped = false;
        $attempts = 0;
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('replaceStreams')->willReturnCallback(function () use (&$attempts): void {
            $attempts++;
            throw self::rewrapped(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
                40001
            );
        });
        $repo->expects($this->never())->method('markStreamsProbed');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        for ($request = 0; $request < 2; $request++) {
            $backfill->ensureFor(
                ['id' => 'movie-1', 'path' => $path,
                 'streams_probed_at' => $stamped ? '2026-07-28 09:00:00' : null],
                $stored
            );
        }

        $this->assertSame(2, $attempts, 'the repair was attempted again on the second request');
        $this->assertFalse($stamped, 'and the item was never stamped');
    }

    /**
     * The one sibling of the stamp/no-stamp family that had no test at all
     * (round-5 finding 4): when no {@see FfmpegRunner} can be built — a missing
     * or unreadable `config/ffmpeg.php`, or a construction failure — the item is
     * left UNSTAMPED.
     *
     * That is the correct behaviour and it must stay pinned: a transiently broken
     * probe config would otherwise permanently mask every item it touched from
     * both entry points, exactly like a wrongly-stamped write failure.
     *
     * `resolveFfmpeg()` is private and builds its runner from a fixed config path,
     * so the "construction failed" state is set directly — it is the state that
     * branch exists to handle, and there is no injectable seam for it.
     */
    public function testNoUsableProbeRunnerLeavesTheItemUnstampedSoItCanBeRepairedLater(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('markStreamsProbed');
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->never())->method('getItemStreams');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        (new \ReflectionProperty(StreamProbeBackfill::class, 'ffmpegResolved'))->setValue($backfill, true);
        (new \ReflectionProperty(StreamProbeBackfill::class, 'ffmpeg'))->setValue($backfill, null);

        $stored = $this->legacyStreams();
        $this->assertSame($stored, $backfill->ensureFor($item, $stored), 'degrades to the stored rows');
        // The narrow detail-endpoint trigger shares probeAndReplace(), so it degrades identically.
        $codecLess = [['id' => 's-v', 'stream_type' => 'video', 'stream_index' => 0, 'codec' => null]];
        $this->assertSame($codecLess, $backfill->ensureVideoCodecFor($item, $codecLess));
    }

    public function testMissingFileSkipsProbeWithoutStampingSoItCanRetryLater(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);
        // NOT stamped: the item keeps its one-shot probe for when the file
        // re-appears (e.g. a temporarily unmounted share).
        $repo->expects($this->never())->method('markStreamsProbed');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();
        $item = ['id' => 'movie-1', 'path' => '/nonexistent/movie.mkv', 'streams_probed_at' => null];

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }

    public function testEmptyProbedStreamSetKeepsStoredRowsButStillMarks(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        // Probe succeeds but exposes no playable streams (image/data-only).
        $ffmpeg->expects($this->once())->method('probe')->willReturn(['streams' => [], 'format' => []]);

        $repo = $this->createMock(ItemRepository::class);
        // Existing rows are NOT wiped for an empty replacement set.
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->never())->method('deleteStreamsByItem');
        $repo->expects($this->never())->method('addStream');
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }

    public function testItemWithoutIdIsServedUnchanged(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor([], $stored));
    }

    public function testMarkerWriteFailureInsideCatchIsSwallowed(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willThrowException(new \RuntimeException('probe boom'));

        $repo = $this->createMock(ItemRepository::class);
        // Pre-071 schema: even the marker UPDATE fails — must not escape.
        $repo->method('markStreamsProbed')->willThrowException(new \RuntimeException('no such column'));

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }
}
