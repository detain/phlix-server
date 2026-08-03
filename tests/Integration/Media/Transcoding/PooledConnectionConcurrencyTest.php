<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use Phlix\Common\Database\PooledMySQLConnection;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

/**
 * Real-MySQL proof for S9 — the coroutine DB connection pool
 * ({@see PooledMySQLConnection}) is now ON by default. A mocked connection can
 * prove the epoch-guard LOGIC in isolation
 * (see {@see \Phlix\Tests\Unit\Media\Transcoding\TranscodeManagerTest}), but it
 * cannot exercise the thing S9 actually turns on: many coroutines, each holding
 * its OWN leased physical connection, hammering the SAME row concurrently. That
 * is the class of behaviour where workerman/mysql's known coroutine traps
 * (cross-coroutine connection sharing → error 2014 "Commands out of sync",
 * native-prepare corruption across yields) historically bit, so it must be
 * validated against a live server.
 *
 * This test spins up nothing itself; it runs against whatever MySQL the
 * DB_HOST/DB_PORT env points at (CI's `phlix_test` service, or a disposable
 * local `mysql:8.0.46` container). With no reachable MySQL / no swoole it
 * self-skips cleanly, mirroring {@see \Phlix\Tests\Integration\Media\BrowseIndexUsageTest}.
 *
 * Three properties are asserted, none of which a mock can show:
 *  1. Independent queries genuinely run in PARALLEL on the pool — proven by
 *     timing: N coroutines each issuing `SELECT SLEEP(t)` finish in ~t, not
 *     ~N*t, and the in-flight counter peaks well above 1. The contrast case
 *     (pool_size=1) is asserted to SERIALISE, so the test would catch a silent
 *     fallback to full serialisation rather than passing on "it didn't crash".
 *  2. Under genuine concurrent read/write churn on one row there is NO
 *     corruption (every value a reader sees was actually written), NO error
 *     2014 / "commands out of sync", and NO uncaught exception; and the cache
 *     CONVERGES — once writes stop, both the epoch-guarded in-worker cache and a
 *     direct DB read settle on the final written value (the stale-cache-forever
 *     bug the epoch guard prevents would leave a wrong value stuck here).
 *  3. Sustained churn with far more coroutines than pool slots does not leak or
 *     deadlock on the pool's blocking acquire() path.
 *
 */
final class PooledConnectionConcurrencyTest extends TestCase
{
    use RequiresRealDatabase;

    private string $host = '127.0.0.1';
    private int $port = 3306;
    private string $user = 'root';
    private string $password = 'root';
    private string $database = 'phlix_test';

    private string $segmentDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('swoole not loaded — the connection pool is a coroutine-only path.');
        }

        $this->host = getenv('DB_HOST') ?: '127.0.0.1';
        $this->port = (int) (getenv('DB_PORT') ?: 3306);
        $this->user = getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'root');
        $this->password = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : 'root';
        $this->database = getenv('DB_DATABASE') ?: (getenv('DB_NAME') ?: 'phlix_test');

        // Silence swoole's per-syscall TRACE spam (it would swamp the test log
        // and trip failOnOutput). Mirrors MediaScannerTest's concurrency tests.
        //
        // ⚠ MUST come BEFORE the database guard below, not after. The test methods
        // in this class call \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL) and
        // never disable it, so under phpunit.xml's executionOrder="random" the 2nd
        // and 3rd setUp() run the guard's real PDO round-trip with the hooks still
        // on — i.e. exactly the traffic this trace_flags reset silences, emitted
        // into a run configured with beStrictAboutOutputDuringTests="true" and
        // failOnRisky="true". This is the known S137 flake file; do not move the
        // guard back above this line.
        \Swoole\Coroutine::set(['log_level' => SWOOLE_LOG_ERROR, 'trace_flags' => 0]);

        // ⚠ KNOWN AND UNADDRESSED — this reorder fixes the OUTPUT half only.
        // The hooks are still on when the guard runs, and the guard's PDO
        // round-trip still happens OUTSIDE any coroutine (S126 review round 1 #4,
        // round 2 #7). The connection it opens is cached process-wide in
        // ConnectionPool::$connections['mysql'] and never closed, so a
        // coroutine-hooked socket is still open at PHPUnit's RSHUTDOWN — the
        // hazard src/Common/Database/ConnectionPool.php:142-146 documents as
        // "API must be called in the coroutine". Pre-S126 this setUp() did no PDO
        // I/O at all, so S126 added that exposure here; swoole 6.2.1 is loaded on
        // the dev box and in both .github/workflows/phpunit.yml jobs (:37, :170),
        // so it is a live path in the job that has MySQL. It is NOT fixed below.
        // Closing it means running the guard inside Coroutine::run() (which
        // changes how markTestSkipped's exception propagates, so it needs the
        // MySQL-backed CI job to verify and cannot be validated on a box with no
        // MySQL) or dropping the hooks around it. Tracked as a follow-up; do not
        // read the comment above as having covered it.

        // The guard is given this test's own resolved host/port rather than reading
        // DB_HOST/DB_PORT itself, so it probes exactly the server the hand-built
        // PooledMySQLConnection below will connect to. It also runs a real `SELECT 1`
        // through ConnectionPool's SHARED connection, which is a different instance
        // from the pool this test builds — see IntegrationDbGuard.
        $this->requireHealthyDatabase(
            'skipping pool concurrency test. Runs in CI / docker.',
            $this->host,
            $this->port,
        );

        $this->segmentDir = sys_get_temp_dir() . '/phlix_s9_conc_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->segmentDir !== '' && is_dir($this->segmentDir)) {
            $this->rrmdir($this->segmentDir);
        }
        parent::tearDown();
    }

    /**
     * Opens a fresh pool front (its own idle channel + lease map) so pool state
     * never leaks between tests.
     */
    private function pool(int $poolSize): PooledMySQLConnection
    {
        return new PooledMySQLConnection(
            $this->host,
            $this->port,
            $this->user,
            $this->password,
            $this->database,
            $poolSize,
            'utf8mb4'
        );
    }

    /**
     * PROPERTY 1 — real parallelism, and its serialised contrast.
     *
     * With pool_size == N, N coroutines each running `SELECT SLEEP(t)` overlap:
     * total wall time is ~t (one sleep), the in-flight counter reaches N, and it
     * is far below the serial floor N*t. With pool_size == 1 the SAME workload
     * is forced onto one connection and serialises to ~N*t with a peak in-flight
     * of 1 — the guard that this test genuinely measures parallelism rather than
     * passing on a pool that silently fell back to serialising everything.
     */
    public function testIndependentQueriesRunInParallelOnThePoolAndSerialiseAtSizeOne(): void
    {
        $n = 6;
        $sleep = 0.20;

        [$parElapsed, $parPeak] = $this->runSleepFanOut($this->pool($n), $n, $sleep);
        [$serElapsed, $serPeak] = $this->runSleepFanOut($this->pool(1), $n, $sleep);

        $serialFloor = $n * $sleep; // 1.20s

        // Parallel: peaked above 1 and finished far under the serial floor.
        $this->assertGreaterThan(
            1,
            $parPeak,
            sprintf('pool_size=%d must run queries concurrently (peak in-flight was %d)', $n, $parPeak)
        );
        $this->assertLessThan(
            $serialFloor * 0.5,
            $parElapsed,
            sprintf(
                'pool_size=%d must parallelise: %d x SLEEP(%.2f) took %.3fs, serial floor is %.2fs',
                $n,
                $n,
                $sleep,
                $parElapsed,
                $serialFloor
            )
        );

        // Contrast: pool_size=1 serialises to ~the serial floor. This makes the
        // parallel assertion meaningful — a broken/disabled pool would look like
        // this. (Timing is the authoritative signal here: the in-flight counter
        // increments just BEFORE the blocking acquire(), so a coroutine parked
        // waiting for the single connection still counts as "in-flight" — only
        // the wall clock distinguishes true serialisation from parallelism.)
        unset($serPeak);
        $this->assertGreaterThan(
            $serialFloor * 0.8,
            $serElapsed,
            sprintf('pool_size=1 must serialise (~%.2fs); measured %.3fs', $serialFloor, $serElapsed)
        );
    }

    /**
     * PROPERTY 2 — no corruption / 2014 / stale-cache under concurrent churn.
     *
     * Readers repeatedly read the job row through TranscodeManager's
     * epoch-guarded cache while writers repeatedly UPDATE the row's `error`
     * column to a strictly increasing sequence number and then invalidate the
     * cache — the exact "UPDATE immediately followed by invalidateJobRowCache()"
     * shape of all 5 real invalidation call sites, now happening across DIFFERENT
     * pooled connections with no shared-connection mutex ordering them.
     *
     * Asserts: every value read is one that was actually written (no torn /
     * cross-query corruption — the fingerprint of connection sharing / 2014); no
     * exception (2014 or otherwise) escaped any coroutine; the load genuinely
     * overlapped (peak in-flight > 1); and after writes stop the cache CONVERGES
     * — a final read through the cache AND a direct DB read both return the last
     * written value (the epoch guard's whole job: no stale row stuck in the
     * TTL-less cache).
     */
    public function testConcurrentReadWriteChurnStaysCoherentWithoutCorruptionOrErrors(): void
    {
        $this->runChurn(
            poolSize: 6,
            readerCoros: 12,
            writerCoros: 6,
            readsPer: 60,
            writesPer: 40
        );
    }

    /**
     * PROPERTY 3 — soak / pool-exhaustion. Many more coroutines than pool slots
     * (36 coroutines over 4 connections) churning for hundreds of iterations
     * must all complete: the blocking acquire() (channel-pop) path is exercised
     * hard, catching a lease leak or a deadlock that only shows up once
     * `created >= maxSize` forces waiters to block on releases.
     *
     * @group soak
     */
    public function testSustainedChurnWithPoolExhaustionDoesNotDeadlockOrLeak(): void
    {
        $this->runChurn(
            poolSize: 4,
            readerCoros: 24,
            writerCoros: 12,
            readsPer: 30,
            writesPer: 20
        );
    }

    /**
     * Fan out $n coroutines each issuing one `SELECT SLEEP($sleep)` on $pool,
     * tracking wall time and peak concurrent in-flight queries.
     *
     * @return array{0: float, 1: int} [elapsedSeconds, peakInFlight]
     */
    private function runSleepFanOut(PooledMySQLConnection $pool, int $n, float $sleep): array
    {
        $inFlight = 0;
        $peak = 0;
        $errors = [];
        $elapsed = 0.0;

        \Swoole\Coroutine\run(function () use ($pool, $n, $sleep, &$inFlight, &$peak, &$errors, &$elapsed): void {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
            $wg = new \Swoole\Coroutine\WaitGroup();
            $t0 = hrtime(true);
            for ($i = 0; $i < $n; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($pool, $sleep, &$inFlight, &$peak, &$errors, $wg): void {
                    try {
                        $inFlight++;
                        $peak = max($peak, $inFlight);
                        $pool->query('SELECT SLEEP(?) AS s', [$sleep]);
                    } catch (Throwable $e) {
                        $errors[] = $e->getMessage();
                    } finally {
                        $inFlight--;
                        $wg->done();
                    }
                });
            }
            $wg->wait();
            $elapsed = (hrtime(true) - $t0) / 1e9;
            $pool->closeConnection();
        });

        $this->assertSame([], $errors, 'no query on the pool may error: ' . implode(' | ', $errors));

        return [$elapsed, $peak];
    }

    /**
     * Drive concurrent readers + writers against one real row and assert
     * coherence (see {@see testConcurrentReadWriteChurnStaysCoherentWithoutCorruptionOrErrors}).
     */
    private function runChurn(
        int $poolSize,
        int $readerCoros,
        int $writerCoros,
        int $readsPer,
        int $writesPer
    ): void {
        $pool = $this->pool($poolSize);
        $ff = $this->createMock(FfmpegRunner::class);
        $manager = new TranscodeManager($pool, $ff, $this->segmentDir, null, 6);

        $jobId = $this->uuid();
        $libraryId = $this->uuid();
        $mediaItemId = $this->uuid();
        $hlsDir = $this->segmentDir . '/' . $jobId;
        mkdir($hlsDir, 0755, true);

        $entry = new ReflectionMethod(TranscodeManager::class, 'jobRowEntry');
        $entry->setAccessible(true);
        $invalidate = new ReflectionMethod(TranscodeManager::class, 'invalidateJobRowCache');
        $invalidate->setAccessible(true);

        // Seed the row (outside any coroutine → the pool's CLI connection). The
        // transcode_job FK-references a media_item, which FK-references a library,
        // so seed both parents; the library CASCADE-deletes everything at the end.
        $pool->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$libraryId, 'S9 Pool Concurrency', 'movie', json_encode(['/tmp/phlix-s9-pool'])]
        );
        $pool->query(
            'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
            [$mediaItemId, $libraryId, 'S9 Pool Fixture', 'movie', '/tmp/phlix-s9-pool/fixture.mkv']
        );
        // duration_seconds is the mutated field: it is an INT and — crucially —
        // is one of the columns TranscodeManager::JOB_ROW_COLUMNS actually SELECTs
        // into the cached row, so a reader observes what a writer wrote. Seed 0.
        $pool->query(
            'INSERT INTO transcode_jobs (id, media_item_id, input_path, output_path, hls_dir, status, duration_seconds) '
            . "VALUES (?, ?, ?, ?, ?, 'running', 0)",
            [$jobId, $mediaItemId, '/tmp/in.mkv', '/tmp/out.m3u8', $hlsDir]
        );

        $seq = 0;                 // shared monotonic write counter (atomic between yields)
        $maxWritten = 0;
        $observed = [];           // every distinct error value a reader saw
        $badValues = [];          // reader values that were never written
        $errors = [];             // any exception message from any coroutine
        $inFlight = 0;
        $peak = 0;

        \Swoole\Coroutine\run(function () use (
            $pool,
            $manager,
            $entry,
            $invalidate,
            $jobId,
            $readerCoros,
            $writerCoros,
            $readsPer,
            $writesPer,
            &$seq,
            &$maxWritten,
            &$observed,
            &$badValues,
            &$errors,
            &$inFlight,
            &$peak
        ): void {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
            $wg = new \Swoole\Coroutine\WaitGroup();

            // Writers: UPDATE error = <next seq> then invalidate — the call-site pattern.
            for ($w = 0; $w < $writerCoros; $w++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use (
                    $pool,
                    $invalidate,
                    $manager,
                    $jobId,
                    $writesPer,
                    &$seq,
                    &$maxWritten,
                    &$errors,
                    &$inFlight,
                    &$peak,
                    $wg
                ): void {
                    try {
                        for ($i = 0; $i < $writesPer; $i++) {
                            $value = ++$seq;
                            $maxWritten = max($maxWritten, $value);
                            $inFlight++;
                            $peak = max($peak, $inFlight);
                            $pool->query(
                                'UPDATE transcode_jobs SET duration_seconds = ? WHERE id = ?',
                                [$value, $jobId]
                            );
                            $inFlight--;
                            $invalidate->invoke($manager, $jobId);
                        }
                    } catch (Throwable $e) {
                        $inFlight = max(0, $inFlight - 1);
                        $errors[] = 'writer: ' . $e->getMessage();
                    } finally {
                        $wg->done();
                    }
                });
            }

            // Readers: read the row through the epoch-guarded cache.
            for ($r = 0; $r < $readerCoros; $r++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use (
                    $entry,
                    $manager,
                    $jobId,
                    $readsPer,
                    &$observed,
                    &$badValues,
                    &$maxWritten,
                    &$errors,
                    &$inFlight,
                    &$peak,
                    $wg
                ): void {
                    try {
                        for ($i = 0; $i < $readsPer; $i++) {
                            $inFlight++;
                            $peak = max($peak, $inFlight);
                            /** @var array{row: array<string,mixed>}|null $e */
                            $e = $entry->invoke($manager, $jobId);
                            $inFlight--;
                            $this->assertIsArray($e);
                            $raw = $e['row']['duration_seconds'] ?? null;
                            $val = is_numeric($raw) ? (int) $raw : -1;
                            $observed[$val] = true;
                            // Every value must be one actually written (0 seed .. maxWritten).
                            // A torn / cross-query read (the 2014 fingerprint) would land
                            // outside this window. (Reads interleave with writers via the
                            // yield on each cache-miss SELECT — writers invalidate often, so
                            // most reads miss and yield; no explicit sleep needed.)
                            if ($val < 0 || $val > $maxWritten) {
                                $badValues[] = $val;
                            }
                        }
                    } catch (Throwable $e) {
                        $inFlight = max(0, $inFlight - 1);
                        $errors[] = 'reader: ' . $e->getMessage();
                    } finally {
                        $wg->done();
                    }
                });
            }

            $wg->wait();
            $pool->closeConnection();
        });

        // No exception (2014 "commands out of sync", corruption, or otherwise)
        // escaped any coroutine. Check the specific fingerprints first, then the
        // catch-all empty assertion.
        foreach ($errors as $msg) {
            $this->assertStringNotContainsStringIgnoringCase('2014', $msg);
            $this->assertStringNotContainsStringIgnoringCase('out of sync', $msg);
        }
        $this->assertSame([], $errors, 'concurrent churn raised errors: ' . implode(' | ', $errors));

        // No corruption: every value a reader saw was one that had been written
        // by that point (seed 0 .. maxWritten).
        $this->assertSame(
            [],
            $badValues,
            'readers saw value(s) never written (corruption / torn read): ' . implode(',', $badValues)
        );

        // The load genuinely overlapped — not accidentally serialised.
        $this->assertGreaterThan(1, $peak, 'expected concurrent in-flight queries during the churn');

        $this->assertGreaterThan(0, $maxWritten, 'writers must have written at least once');

        // CONVERGENCE — the epoch guard's payoff. Writers have stopped; do a
        // final authoritative write via the (now-idle) pool, invalidate, then
        // prove BOTH a fresh cache read AND a direct DB read agree on it. Under
        // the pre-fix bug a reader whose SELECT was in flight across an invalidate
        // could pin a stale value in the TTL-less cache; here the cache must
        // reflect the true final row.
        $final = $maxWritten + 1_000_000;
        $cachedValue = null;
        $dbValue = null;
        \Swoole\Coroutine\run(function () use (
            $pool,
            $manager,
            $entry,
            $invalidate,
            $jobId,
            $libraryId,
            $final,
            &$cachedValue,
            &$dbValue
        ): void {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
            $pool->query('UPDATE transcode_jobs SET duration_seconds = ? WHERE id = ?', [$final, $jobId]);
            $invalidate->invoke($manager, $jobId);

            /** @var array{row: array<string,mixed>}|null $e */
            $e = $entry->invoke($manager, $jobId);   // repopulates the cache
            $cachedValue = is_array($e) && is_numeric($e['row']['duration_seconds'] ?? null)
                ? (int) $e['row']['duration_seconds']
                : null;

            $rows = $pool->query('SELECT duration_seconds FROM transcode_jobs WHERE id = ?', [$jobId]);
            $firstRow = is_array($rows) ? ($rows[0] ?? null) : null;
            $dbValue = is_array($firstRow) && is_numeric($firstRow['duration_seconds'] ?? null)
                ? (int) $firstRow['duration_seconds']
                : null;

            // CASCADE-deletes the media_item and its transcode_jobs.
            $pool->query('DELETE FROM libraries WHERE id = ?', [$libraryId]);
            $pool->closeConnection();
        });

        $this->assertSame($final, $dbValue, 'the final write must be the persisted DB value');
        $this->assertSame(
            $final,
            $cachedValue,
            'the epoch-guarded cache must converge on the final written value (no stale row stuck without a TTL)'
        );
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
