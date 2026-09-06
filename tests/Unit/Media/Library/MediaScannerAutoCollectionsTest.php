<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\CollectionJob;
use Phlix\Media\CollectionJobStore;
use Phlix\Media\Library\MediaScanner;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S215 — the S33 promise, finally executable: auto-collection TMDB sync is
 * ENQUEUED as a {@see CollectionJob}, never run inline in the scan loop.
 *
 * Proves all three S215 ACs through a real {@see MediaScanner::scan()} of a
 * one-movie temp directory:
 *   AC(1) no HTTP call originates inside the scan loop for collections —
 *         behavioural half: the scan completes and the queue holds the job
 *         instead of a sync having run; structural half: the scanner no longer
 *         accepts or holds an HTTP-capable CollectionService AT ALL, so an
 *         inline TMDB call from scan code is now unrepresentable.
 *   AC(2) a disabled library enqueues nothing — and the S33 gate contract that
 *         the whole block (including the item lookup) is skipped is pinned via
 *         the repo double's findById counter.
 *   AC(3) lives in CollectionServiceTest / CollectionWorkerTest (the service
 *         hits /collection/{id} at most once per sync).
 *
 * The scanner's own item repository is a self-contained in-memory double
 * ({@see CollectionGateScannerRepo}, NOT a mock) that creates the movie and, on
 * the post-create `findById()`, returns it carrying a `tmdb_id` — the
 * precondition the enqueue block checks. Real behaviour end-to-end; restoring
 * the pre-S215 inline call (mutation) reddens the structural test, and dropping
 * the enqueue reddens the behavioural ones.
 */
final class MediaScannerAutoCollectionsTest extends TestCase
{
    private const TMDB_MOVIE_ID = 27205;

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
     * AC(1) behavioural: flag ON + store wired → the scan COMPLETES and the sync
     * lives in the QUEUE (one CollectionJob keyed to the indexed item), i.e. the
     * scanner deferred the work instead of performing it. There is no service on
     * the scanner to have called — see the structural twin below.
     */
    public function testEnabledFlagEnqueuesCollectionJobInsteadOfSyncingInline(): void
    {
        $store = $this->makeStore();
        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), self::TMDB_MOVIE_ID);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo,
            collectionJobStore: $store
        );

        $this->scanOneMovie($scanner, $scannerRepo, autoCollectionsEnabled: true);

        $this->assertSame(1, $store->queueSize(), 'Exactly one collection job must be queued for the new movie.');
        $this->assertSame(1, $scannerRepo->findByIdCalls(), 'The enqueue precondition must read the item exactly once.');

        $job = $store->dequeue();
        $this->assertInstanceOf(CollectionJob::class, $job);
        $this->assertSame(
            'id-1',
            $job?->itemId,
            'The queued job must carry the newly indexed item id (deferred sync, S215).'
        );
    }

    /**
     * AC(1) structural: the scanner's dependency surface holds NO
     * HTTP-capable collection collaborator. Pre-S215 it accepted a
     * CollectionService and called syncCollectionForMovie() per file; post-S215
     * an inline TMDB call from scan code is unrepresentable — a test double that
     * "throws on HTTP" cannot even be injected anymore.
     */
    public function testScannerHoldsNoHttpCapableCollectionDependency(): void
    {
        $ctorParamNames = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(MediaScanner::class, '__construct'))->getParameters()
        );
        $this->assertNotContains(
            'collectionService',
            $ctorParamNames,
            'S215 AC(1): MediaScanner must not accept the HTTP-capable CollectionService; '
            . 'the scan path may only know the job QUEUE.'
        );
        $this->assertContains(
            'collectionJobStore',
            $ctorParamNames,
            'S215: the queue store takes the service parameter\'s former slot.'
        );

        $httpCapable = [];
        foreach ((new \ReflectionClass(MediaScanner::class))->getProperties() as $property) {
            $type = (string) ($property->getType() ?? '');
            if (str_contains($type, 'CollectionService')) {
                $httpCapable[] = $property->getName();
            }
        }
        $this->assertSame(
            [],
            $httpCapable,
            'S215 AC(1): no MediaScanner property may be typed CollectionService — '
            . 'no HTTP-capable collection dependency can reach the scan loop.'
        );
    }

    /**
     * AC(2): flag OFF → NOTHING is enqueued, and the whole gate block —
     * including the findById() precondition lookup — is skipped (the S33 gate
     * contract, now asserted on the queue instead of on TMDB traffic).
     */
    public function testDisabledLibraryEnqueuesNothing(): void
    {
        $store = $this->makeStore();
        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), self::TMDB_MOVIE_ID);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo,
            collectionJobStore: $store
        );

        $this->scanOneMovie($scanner, $scannerRepo, autoCollectionsEnabled: false);

        $this->assertSame(0, $store->queueSize(), 'A disabled library must enqueue NOTHING.');
        $this->assertSame(
            0,
            $scannerRepo->findByIdCalls(),
            'A disabled library must skip the whole block, including the item lookup.'
        );
    }

    /**
     * Backward-compatible default: when the caller OMITS the flag (the historical
     * scan() call shape), the enqueue still runs — the scanner defaults the
     * toggle to true, so an un-migrated library keeps its (now async) behaviour.
     */
    public function testAbsentFlagDefaultsToEnqueueing(): void
    {
        $store = $this->makeStore();
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            new CollectionGateScannerRepo($this->createMock(Connection::class), self::TMDB_MOVIE_ID),
            collectionJobStore: $store
        );

        $this->tmpDir = sys_get_temp_dir() . '/phlix_s215_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
        file_put_contents($this->tmpDir . '/Inception (2010).mkv', 'x');

        $scanner->scan('lib-1', $this->tmpDir, 'movie');

        $this->assertSame(1, $store->queueSize(), 'Omitted flag keeps the historical default: enqueue runs.');
    }

    /**
     * Precondition scoping (parity with the inline era): an item WITHOUT a
     * numeric tmdb_id in metadata_json must NOT be enqueued — matching still
     * happens in the separate metadata job; the queue stays clean.
     */
    public function testUnmatchedItemIsNotEnqueued(): void
    {
        $store = $this->makeStore();
        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), null);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo,
            collectionJobStore: $store
        );

        $this->scanOneMovie($scanner, $scannerRepo, autoCollectionsEnabled: true);

        $this->assertSame(
            0,
            $store->queueSize(),
            'Items without a tmdb_id must never occupy a collection queue slot.'
        );
    }

    private function makeStore(): CollectionJobStore
    {
        $dir = sys_get_temp_dir() . '/phlix_s215_colq_' . uniqid('', true);
        $this->tmpQueues[] = $dir;
        return new CollectionJobStore($dir);
    }

    /**
     * Wire + run one scan of a one-movie temp directory against the given gate flag.
     */
    private function scanOneMovie(
        MediaScanner $scanner,
        CollectionGateScannerRepo $scannerRepo,
        bool $autoCollectionsEnabled
    ): void {
        $this->tmpDir = sys_get_temp_dir() . '/phlix_s215_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
        file_put_contents($this->tmpDir . '/Inception (2010).mkv', 'x');

        $scanner->scan('lib-1', $this->tmpDir, 'movie', false, null, $autoCollectionsEnabled);

        // Sanity: the movie was actually indexed (so the gate really was reached).
        $this->assertCount(1, $scannerRepo->items(), 'the movie file must be indexed by the scan');
    }
}
