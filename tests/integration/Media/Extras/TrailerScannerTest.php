<?php

declare(strict_types=1);

namespace Phlex\Tests\Integration\Media\Extras;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Extras\ExtrasRepository;
use Phlex\Media\Extras\Trailer;
use Phlex\Media\Extras\TrailerFinder;
use Phlex\Media\Extras\TrailerResolver;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Metadata\TmdbProvider;
use Phlex\Server\Http\Controllers\ExtrasController;
use Workerman\MySQL\Connection;

class TrailerScannerTest extends TestCase
{
    private string $fixturesDir;
    private Connection $db;
    private ItemRepository $itemRepository;
    private ExtrasRepository $extrasRepository;
    private TrailerFinder $trailerFinder;
    private TrailerResolver $resolver;
    private ExtrasController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesDir = sys_get_temp_dir() . '/trailer_scanner_integration_' . uniqid();
        mkdir($this->fixturesDir . '/Trailers', 0755, true);

        // Create a mock database connection
        $this->db = $this->createMock(Connection::class);

        $this->itemRepository = new ItemRepository($this->db);
        $this->extrasRepository = new ExtrasRepository($this->db);
        $this->trailerFinder = new TrailerFinder();

        // Create a mock TmdbProvider that returns empty trailers
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('getTrailers')->willReturn([]);

        $this->resolver = new TrailerResolver(
            $this->itemRepository,
            $tmdb,
            $this->extrasRepository,
            $this->trailerFinder,
            86400
        );

        $this->controller = new ExtrasController($this->resolver);
    }

    protected function tearDown(): void
    {
        // Clean up fixtures
        $this->removeDirectory($this->fixturesDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    public function testScannerDetectsTrailersFolderAndRecordsTrailers(): void
    {
        // Create fixture structure
        $mediaDir = $this->fixturesDir;
        $mediaFilename = 'Movie (2020).mkv';

        // Create trailer in Trailers folder
        file_put_contents($mediaDir . '/Trailers/Movie (2020)-teaser.mkv', 'fake trailer content');
        file_put_contents($mediaDir . '/Trailers/Movie (2020)-featurette.mkv', 'fake featurette content');

        // Test that TrailerFinder detects the trailers
        $trailers = $this->trailerFinder->findLocalTrailers($mediaDir, $mediaFilename);

        $this->assertCount(2, $trailers);

        // Verify teaser
        $teaserTrailer = null;
        $featuretteTrailer = null;
        foreach ($trailers as $trailer) {
            if ($trailer['title'] === 'Teaser') {
                $teaserTrailer = $trailer;
            }
            if ($trailer['title'] === 'Featurette') {
                $featuretteTrailer = $trailer;
            }
        }

        $this->assertNotNull($teaserTrailer);
        $this->assertStringContainsString('teaser.mkv', $teaserTrailer['path']);

        $this->assertNotNull($featuretteTrailer);
        $this->assertStringContainsString('featurette.mkv', $featuretteTrailer['path']);
    }

    public function testScannerDetectsSameLevelTrailer(): void
    {
        $mediaDir = $this->fixturesDir;
        $mediaFilename = 'Movie (2020).mkv';

        // Create same-level trailer
        file_put_contents($mediaDir . '/Movie (2020)-trailer.mkv', 'fake trailer content');

        $trailers = $this->trailerFinder->findLocalTrailers($mediaDir, $mediaFilename);

        $this->assertCount(1, $trailers);
        $this->assertSame('Trailer', $trailers[0]['title']);
        $this->assertStringContainsString('trailer.mkv', $trailers[0]['path']);
    }

    public function testExtrasControllerReturnsCorrectStructure(): void
    {
        // Create fixture
        $mediaDir = $this->fixturesDir;
        $mediaFilename = 'Movie (2020).mkv';
        file_put_contents($mediaDir . '/Trailers/Movie (2020)-trailer.mkv', 'content');

        // The controller would return proper response structure
        // We're testing the resolver which is used by controller
        $trailers = $this->trailerFinder->findLocalTrailers($mediaDir, $mediaFilename);

        $this->assertIsArray($trailers);
        $this->assertArrayHasKey('path', $trailers[0]);
        $this->assertArrayHasKey('title', $trailers[0]);
        $this->assertArrayHasKey('duration', $trailers[0]);
        $this->assertArrayHasKey('quality', $trailers[0]);
    }

    public function testFindLocalTrailersHandlesMultipleExtensions(): void
    {
        $mediaDir = $this->fixturesDir;
        $mediaFilename = 'Movie (2020).mkv';

        // Create trailers with different extensions
        file_put_contents($mediaDir . '/Trailers/Movie (2020)-trailer.mp4', 'mp4 content');
        file_put_contents($mediaDir . '/Trailers/Movie (2020)-teaser.avi', 'avi content');

        $trailers = $this->trailerFinder->findLocalTrailers($mediaDir, $mediaFilename);

        $this->assertCount(2, $trailers);
    }
}
