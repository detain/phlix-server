<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Metadata\Writer\MetadataWriteJob;
use Phlix\Media\Metadata\Writer\MetadataWriteJobStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Workerman\MySQL\Connection;

/**
 * S87 — the order-locked arc's head proof: the scan path ENQUEUES a metadata
 * write job per finalized item, and performs NO blocking filesystem I/O for
 * metadata write-back inline in the scan (S87 AC 1 + AC 2).
 *
 * Same harness as the S215 auto-collections gate test
 * ({@see MediaScannerAutoCollectionsTest}): a real {@see MediaScanner::scan()}
 * over a one-movie temp directory against the self-contained
 * {@see CollectionGateScannerRepo} in-memory double — real behaviour end to
 * end, mutation of the enqueue reddens the behavioural tests, and the scanner
 * holding any writer-executing dependency (instead of only the queue) reddens
 * the structural ones.
 *
 * Gate direction differs from S33 ON PURPOSE: `options.metadataWrite.enabled`
 * is absent-means-OFF (disk mutation is opt-in), so the omitted-flag tests here
 * pin OFF, mirroring how LibraryRow::metadataWriteEnabled() is specced.
 */
final class MediaScannerMetadataWriteEnqueueTest extends TestCase
{
    /**
     * Lane survival token (S87, wave 44). Code-resident on purpose and woven
     * into the critical AC assertion messages below: premerge asserts it on the
     * COMMENT-STRIPPED corpus, so deleting the feature — or this class — cannot
     * be papered over with a docblock echo.
     */
    private const string SURVIVAL_TOKEN = 'S87TAGWX2K6';

    private string $tmpDir = '';

    /** @var list<string> Queue directories to sweep in tearDown (S439 zero-residue). */
    private array $tmpQueues = [];

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach ((array) glob($this->tmpDir . '/*') as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tmpDir);
        }

        foreach ($this->tmpQueues as $dir) {
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '/*') as $file) {
                    if (is_string($file) && is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($dir);
            }
        }
        $this->tmpQueues = [];
    }

    /**
     * AC(1): gate ON + store wired → the scan COMPLETES and the write-back
     * lives in the QUEUE as one MetadataWriteJob keyed to the indexed item —
     * proving finalize enqueues, [S87TAGWX2K6].
     */
    public function testFinalizeEnqueuesMetadataWriteJobWhenLibraryOptsIn(): void
    {
        $store = $this->makeStore();
        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), null);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo,
            metadataWriteJobStore: $store
        );

        $this->scanOneMovie($scanner, $scannerRepo, metadataWriteEnabled: true);

        $this->assertSame(
            1,
            $store->queueSize(),
            'S87 AC(1): exactly one metadata-write job must be queued for the finalized movie ['
            . self::SURVIVAL_TOKEN . '].'
        );

        $job = $store->dequeue();
        $this->assertInstanceOf(MetadataWriteJob::class, $job);
        $this->assertSame(
            'id-1',
            $job->itemId,
            'The queued job must carry the newly indexed item id (deferred write-back) ['
            . self::SURVIVAL_TOKEN . '].'
        );
        $this->assertSame('lib-1', $job->libraryId, 'The job carries its library for scoping.');
    }

    /**
     * AC(1) multiplicity: two finalized files → two jobs (one per item, never
     * one per scan), and the queue paths stay independent of the collection
     * gate (autoCollections OFF here proves the blocks are separate).
     */
    public function testEveryFinalizedItemEnqueuesItsOwnJob(): void
    {
        $store = $this->makeStore();
        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), null);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo,
            metadataWriteJobStore: $store
        );

        $this->tmpDir = sys_get_temp_dir() . '/phlix_s87_two_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
        file_put_contents($this->tmpDir . '/Inception (2010).mkv', 'x');
        file_put_contents($this->tmpDir . '/Interstellar (2014).mkv', 'x');

        $scanner->scan('lib-1', $this->tmpDir, 'movie', false, null, false, true);

        $this->assertCount(2, $scannerRepo->items(), 'both movie files must be indexed');
        $this->assertSame(
            2,
            $store->queueSize(),
            'Per-ITEM enqueue: one job per finalized item, independent of the S33 collection gate ['
            . self::SURVIVAL_TOKEN . '].'
        );
    }

    /**
     * Gate OFF (explicit false): NOTHING is enqueued — an opted-OUT library's
     * disk is never queued for mutation.
     */
    public function testDisabledGateEnqueuesNothing(): void
    {
        $store = $this->makeStore();
        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), null);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo,
            metadataWriteJobStore: $store
        );

        $this->scanOneMovie($scanner, $scannerRepo, metadataWriteEnabled: false);

        $this->assertSame(
            0,
            $store->queueSize(),
            'A library that did not opt in must enqueue NOTHING [' . self::SURVIVAL_TOKEN . '].'
        );
    }

    /**
     * Gate is OPT-IN: the historical 6-arg scan() call shape (flag omitted)
     * enqueues nothing — the default is false, the deliberate inverse of the
     * S33 auto-collections default.
     */
    public function testAbsentFlagDefaultsToNoEnqueue(): void
    {
        $store = $this->makeStore();
        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), null);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo,
            metadataWriteJobStore: $store
        );

        $this->tmpDir = sys_get_temp_dir() . '/phlix_s87_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
        file_put_contents($this->tmpDir . '/Inception (2010).mkv', 'x');

        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $this->assertSame(
            0,
            $store->queueSize(),
            'Omitted flag means OFF for this feature: disk write-back is opt-in per library ['
            . self::SURVIVAL_TOKEN . '].'
        );
    }

    /**
     * Store not wired (tests/legacy callers): gate ON must not fatal and must
     * not enqueue — the guard clause is (store !== null && gate).
     */
    public function testUnwiredStoreKeepsScanGreen(): void
    {
        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), null);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo
        );

        $this->scanOneMovie($scanner, $scannerRepo, metadataWriteEnabled: true);

        $this->assertCount(1, $scannerRepo->items(), 'the scan itself must be unaffected');
    }

    /**
     * AC(2) behavioural: with the gate ON, the scan writes NOTHING into the
     * media directory — no sidecars, no tag files, no temp files. The only
     * artifact the scan produces for this feature is one small queue file in
     * the queue directory (lightweight enqueue is the accepted async pattern;
     * actual metadata I/O is deferred to the worker) [S87TAGWX2K6].
     */
    public function testScanPathCreatesNoMetadataArtifactsBesideTheMedia(): void
    {
        $store = $this->makeStore();
        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), null);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo,
            metadataWriteJobStore: $store
        );

        $this->tmpDir = sys_get_temp_dir() . '/phlix_s87_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
        file_put_contents($this->tmpDir . '/Inception (2010).mkv', 'x');
        $before = (array) glob($this->tmpDir . '/*');

        $scanner->scan('lib-1', $this->tmpDir, 'movie', false, null, false, true);

        $after = (array) glob($this->tmpDir . '/*');
        $this->assertSame(
            $before,
            $after,
            'S87 AC(2): the scan path must not write any metadata artifact next to the media — '
            . 'NFO/poster/tag/sidecar writing belongs to the worker drain (S88/S89) ['
            . self::SURVIVAL_TOKEN . '].'
        );
        $this->assertSame(1, $store->queueSize(), 'the deferral target is the QUEUE, not the disk');
    }

    /**
     * AC(2) structural: the scanner knows the QUEUE and nothing else. It must
     * not accept or hold any writer/registry — executing a write inline from
     * scan code is unrepresentable, the same enforcement shape S215 used.
     */
    public function testScannerHoldsNoWriterExecutionDependency(): void
    {
        $ctorParamNames = array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(MediaScanner::class, '__construct'))->getParameters()
        );
        $this->assertContains(
            'metadataWriteJobStore',
            $ctorParamNames,
            'S87: the queue store is the scanner\'s ONLY metadata-write dependency ['
            . self::SURVIVAL_TOKEN . '].'
        );
        foreach (['metadataWriterRegistry', 'metadataWriter', 'writerRegistry'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $ctorParamNames,
                'S87 AC(2): the scan path may not hold an executable writer collaborator.'
            );
        }

        $executable = [];
        foreach ((new ReflectionClass(MediaScanner::class))->getProperties() as $property) {
            $type = (string) ($property->getType() ?? '');
            if (str_contains($type, 'MetadataWriter')) {
                $executable[] = $property->getName();
            }
        }
        $this->assertSame(
            [],
            $executable,
            'S87 AC(2): no MediaScanner property may be typed to the writer interface/registry — '
            . 'only the job store is reachable from scan code.'
        );
    }

    private function makeStore(): MetadataWriteJobStore
    {
        $dir = sys_get_temp_dir() . '/phlix_s87_mwq_' . uniqid('', true);
        $this->tmpQueues[] = $dir;
        return new MetadataWriteJobStore($dir);
    }

    /**
     * One-movie temp directory scan with the write-back gate in a known state.
     */
    private function scanOneMovie(
        MediaScanner $scanner,
        CollectionGateScannerRepo $scannerRepo,
        bool $metadataWriteEnabled
    ): void {
        $this->tmpDir = sys_get_temp_dir() . '/phlix_s87_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
        file_put_contents($this->tmpDir . '/Inception (2010).mkv', 'x');

        $added = $scanner->scan('lib-1', $this->tmpDir, 'movie', false, null, false, $metadataWriteEnabled);

        // Sanity: the movie was actually indexed and finalize ran (so the gate really was reached).
        $this->assertSame(1, $added, 'the movie file must be indexed by the scan');
        $this->assertCount(1, $scannerRepo->items(), 'the item row must exist');
    }
}
