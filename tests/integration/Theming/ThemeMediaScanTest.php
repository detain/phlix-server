<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Theming;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Theming\ThemeMedia;
use Phlix\Theming\ThemeMediaFinder;
use Phlix\Theming\ThemeMediaRepository;
use Workerman\MySQL\Connection;

class ThemeMediaScanTest extends TestCase
{
    private string $fixturesDir;
    private Connection $db;
    private ThemeMediaFinder $finder;
    private ThemeMediaRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesDir = sys_get_temp_dir() . '/theme_media_integration_' . uniqid();
        mkdir($this->fixturesDir, 0755, true);

        // Use a mock DB (integration test uses mock for DB operations)
        $this->db = $this->createMock(Connection::class);

        // Use a real finder but without FFprobe (mock it if needed)
        $this->finder = new ThemeMediaFinder(null);

        $this->repository = new ThemeMediaRepository($this->db);
    }

    protected function tearDown(): void
    {
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

    public function testScanLibraryDetectsThemeFilesAndCaches(): void
    {
        // Create fixture library with theme media
        $themeMp3 = $this->fixturesDir . '/theme.mp3';
        $backdropMp4 = $this->fixturesDir . '/backdrop.mp4';

        file_put_contents($themeMp3, 'fake mp3 content');
        file_put_contents($backdropMp4, 'fake mp4 content');

        // Find theme media first
        $themeMedia = $this->finder->findForLibrary('lib-test-123', $this->fixturesDir);

        $this->assertInstanceOf(ThemeMedia::class, $themeMedia);
        $this->assertTrue($themeMedia->hasAudio());
        $this->assertTrue($themeMedia->hasVideo());
        $this->assertSame($themeMp3, $themeMedia->audio->path);
        $this->assertSame($backdropMp4, $themeMedia->video->path);

        // Setup DB mock to verify upsert call - must be done BEFORE calling upsert
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO theme_media'),
                $this->callback(function ($params) use ($themeMp3, $backdropMp4) {
                    return $params[0] === 'lib-test-123'
                        && $params[1] === $themeMp3
                        && $params[5] === $backdropMp4
                        && $params[4] === 'mp3'
                        && $params[10] === 'mp4';
                })
            );

        // Cache the result
        $this->repository->upsert($themeMedia);
    }

    public function testFindForLibraryReturnsNullWhenNoThemeMedia(): void
    {
        // Empty directory
        $result = $this->finder->findForLibrary('lib-empty', $this->fixturesDir);

        $this->assertNull($result);
    }

    public function testFindForLibraryReturnsOnlyAudioWhenNoVideo(): void
    {
        $themeMp3 = $this->fixturesDir . '/theme.mp3';
        file_put_contents($themeMp3, 'fake mp3 content');

        $result = $this->finder->findForLibrary('lib-audio-only', $this->fixturesDir);

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertTrue($result->hasAudio());
        $this->assertFalse($result->hasVideo());
        $this->assertSame($themeMp3, $result->audio->path);
    }

    public function testFindForLibraryReturnsOnlyVideoWhenNoAudio(): void
    {
        $backdropMp4 = $this->fixturesDir . '/backdrop.mp4';
        file_put_contents($backdropMp4, 'fake mp4 content');

        $result = $this->finder->findForLibrary('lib-video-only', $this->fixturesDir);

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertFalse($result->hasAudio());
        $this->assertTrue($result->hasVideo());
        $this->assertSame($backdropMp4, $result->video->path);
    }

    public function testFindForMediaItemScansParentDirectory(): void
    {
        // Create nested structure: library/Series/theme.mp3
        $seriesDir = $this->fixturesDir . '/Series';
        $episodeDir = $seriesDir . '/Season 1';
        mkdir($episodeDir, 0755, true);

        $themeMp3 = $seriesDir . '/theme.mp3';
        file_put_contents($themeMp3, 'fake mp3 content');

        // When scanning for an episode, should find theme in parent
        $result = $this->finder->findForMediaItem('lib-series', $episodeDir);

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertTrue($result->hasAudio());
        $this->assertSame($themeMp3, $result->audio->path);
    }
}
