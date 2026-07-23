<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\CollectionService;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Metadata\TmdbProvider;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S33 — per-library TMDB box-set auto-collection toggle (scanner gate).
 *
 * Proves the gate BOTH ways through a real {@see MediaScanner::scan()} of a
 * one-movie temp directory: the scanner's per-item collection-sync block only
 * runs {@see CollectionService::syncCollectionForMovie()} when the per-scan
 * `autoCollectionsEnabled` flag is on. The observable signal is the real
 * CollectionService reaching its TmdbProvider — when the gate is closed, the
 * whole block (including the item lookup) is skipped, so TMDB is never touched.
 *
 * The scanner's own item repository is a self-contained in-memory double (NOT a
 * mock) that creates the movie and, on the post-create `findById()`, returns it
 * carrying a `tmdb_id` — the precondition the scanner's inner check requires
 * before it would ever call the collection service. This is real behaviour: the
 * gate is exercised end-to-end, not asserted on a mock of the class under test.
 */
final class MediaScannerAutoCollectionsTest extends TestCase
{
    private const TMDB_MOVIE_ID = 27205;

    private string $tmpDir = '';

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
    }

    /**
     * Flag ON → the scanner reaches CollectionService, which (real) queries
     * TMDB for the movie's collection exactly once.
     */
    public function testEnabledFlagRunsCollectionSyncDuringScan(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->expects($this->once())
            ->method('getCollectionIdForMovie')
            ->with(self::TMDB_MOVIE_ID)
            ->willReturn(null); // movie belongs to no collection → clean success

        $this->runMovieScan($tmdb, autoCollectionsEnabled: true);
    }

    /**
     * Flag OFF → the scanner skips the collection-sync block entirely, so the
     * real CollectionService is never invoked and TMDB is never queried.
     */
    public function testDisabledFlagSkipsCollectionSyncDuringScan(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->expects($this->never())->method('getCollectionIdForMovie');
        $tmdb->expects($this->never())->method('getCollection');

        $this->runMovieScan($tmdb, autoCollectionsEnabled: false);
    }

    /**
     * Backward-compatible default: when the caller OMITS the flag (the historical
     * scan() call shape), generation still runs — the scanner defaults the toggle
     * to true, so an un-migrated library keeps its unconditional behaviour.
     */
    public function testAbsentFlagDefaultsToRunningCollectionSync(): void
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->expects($this->once())
            ->method('getCollectionIdForMovie')
            ->with(self::TMDB_MOVIE_ID)
            ->willReturn(null);

        $this->runMovieScan($tmdb, autoCollectionsEnabled: null);
    }

    /**
     * Wire a scanner (with a real CollectionService fronting the given TmdbProvider
     * mock) and scan a one-movie temp directory. When $autoCollectionsEnabled is
     * null the 6th scan() argument is omitted entirely, exercising the default.
     */
    private function runMovieScan(TmdbProvider $tmdb, ?bool $autoCollectionsEnabled): void
    {
        // The CollectionService's OWN repo: returns a movie carrying a tmdb_id so
        // syncCollectionForMovie() proceeds to consult the provider.
        $collectionRepo = $this->createMock(ItemRepository::class);
        $collectionRepo->method('findById')->willReturn([
            'id' => 'id-1',
            'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
        ]);
        $collectionService = new CollectionService(
            $this->createMock(Connection::class),
            $collectionRepo,
            $tmdb
        );

        $scannerRepo = new CollectionGateScannerRepo($this->createMock(Connection::class), self::TMDB_MOVIE_ID);
        $scanner = new MediaScanner(
            $this->createMock(Connection::class),
            $scannerRepo,
            collectionService: $collectionService
        );

        $this->tmpDir = sys_get_temp_dir() . '/phlix_s33_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
        file_put_contents($this->tmpDir . '/Inception (2010).mkv', 'x');

        if ($autoCollectionsEnabled === null) {
            $scanner->scan('lib-1', $this->tmpDir, 'movie');
        } else {
            $scanner->scan('lib-1', $this->tmpDir, 'movie', false, null, $autoCollectionsEnabled);
        }

        // Sanity: the movie was actually indexed (so the gate really was reached).
        $this->assertCount(1, $scannerRepo->items(), 'the movie file must be indexed by the scan');
    }
}
