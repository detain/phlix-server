<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Extras;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Extras\TrailerFinder;
use Phlex\Media\Transcoding\FfmpegRunner;

class TrailerFinderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = sys_get_temp_dir() . '/trailer_finder_test_' . uniqid();
        mkdir($this->fixturesDir . '/Trailers', 0755, true);
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

    public function testFindsSameLevelTrailerFile(): void
    {
        // Create main media file (just for path reference)
        $mediaDir = $this->fixturesDir;
        $mediaFilename = 'Movie (2020).mkv';

        // Create trailer file at same level
        file_put_contents($mediaDir . '/Movie (2020)-trailer.mkv', 'fake video');

        $finder = new TrailerFinder();
        $trailers = $finder->findLocalTrailers($mediaDir, $mediaFilename);

        $this->assertCount(1, $trailers);
        $this->assertStringContainsString('Movie (2020)-trailer.mkv', $trailers[0]['path']);
        $this->assertSame('Trailer', $trailers[0]['title']);
    }

    public function testFindsTrailersInSubfolder(): void
    {
        $mediaDir = $this->fixturesDir;
        $mediaFilename = 'Movie (2020).mkv';

        // Create trailer in Trailers subfolder
        file_put_contents($mediaDir . '/Trailers/Movie (2020)-teaser.mkv', 'fake video');

        $finder = new TrailerFinder();
        $trailers = $finder->findLocalTrailers($mediaDir, $mediaFilename);

        $this->assertCount(1, $trailers);
        $this->assertStringContainsString('Movie (2020)-teaser.mkv', $trailers[0]['path']);
        $this->assertSame('Teaser', $trailers[0]['title']);
    }

    public function testIgnoresNonMatchingExtensions(): void
    {
        $mediaDir = $this->fixturesDir;
        $mediaFilename = 'Movie (2020).mkv';

        // Create files with non-video extensions
        file_put_contents($mediaDir . '/Movie (2020)-trailer.txt', 'not a video');
        file_put_contents($mediaDir . '/Movie (2020)-trailer.jpg', 'not a video');

        $finder = new TrailerFinder();
        $trailers = $finder->findLocalTrailers($mediaDir, $mediaFilename);

        $this->assertCount(0, $trailers);
    }

    public function testExtractsTitleFromFilename(): void
    {
        $finder = new TrailerFinder();

        $this->assertSame('Trailer', $finder->extractTitleFromFilename('Movie-trailer.mkv'));
        $this->assertSame('Teaser', $finder->extractTitleFromFilename('Movie-teaser.mkv'));
        $this->assertSame('Clip', $finder->extractTitleFromFilename('Movie-clip.mkv'));
        $this->assertSame('Featurette', $finder->extractTitleFromFilename('Movie-featurette.mkv'));
        $this->assertSame('Behind the Scenes', $finder->extractTitleFromFilename('Movie-behind-the-scenes.mkv'));
        $this->assertSame('Interview', $finder->extractTitleFromFilename('Movie-interview.mkv'));
    }

    public function testReturnsEmptyArrayWhenNoTrailersFound(): void
    {
        $mediaDir = $this->fixturesDir;
        $mediaFilename = 'Movie (2020).mkv';

        // Don't create any trailer files
        $finder = new TrailerFinder();
        $trailers = $finder->findLocalTrailers($mediaDir, $mediaFilename);

        $this->assertIsArray($trailers);
        $this->assertCount(0, $trailers);
    }

    public function testSkipsHiddenFiles(): void
    {
        $mediaDir = $this->fixturesDir;
        $mediaFilename = 'Movie (2020).mkv';

        // Create hidden file
        file_put_contents($mediaDir . '/Trailers/.hidden-trailer.mkv', 'hidden');

        $finder = new TrailerFinder();
        $trailers = $finder->findLocalTrailers($mediaDir, $mediaFilename);

        $this->assertCount(0, $trailers);
    }
}
