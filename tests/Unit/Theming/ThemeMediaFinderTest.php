<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use PHPUnit\Framework\TestCase;
use Phlix\Theming\ThemeMedia;
use Phlix\Theming\ThemeMediaFinder;

class ThemeMediaFinderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = sys_get_temp_dir() . '/theme_media_finder_' . uniqid();
        mkdir($this->fixturesDir, 0755, true);
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

    public function testFindsThemeMp3InLibraryRoot(): void
    {
        $themeMp3 = $this->fixturesDir . '/theme.mp3';
        file_put_contents($themeMp3, 'fake mp3 content');

        $finder = new ThemeMediaFinder();
        $result = $finder->findForLibrary('lib-123', $this->fixturesDir);

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertNotNull($result->audio);
        $this->assertSame('mp3', $result->audio->format);
        $this->assertSame($themeMp3, $result->audio->path);
    }

    public function testFindsBackdropMp4InLibraryRoot(): void
    {
        $backdropMp4 = $this->fixturesDir . '/backdrop.mp4';
        file_put_contents($backdropMp4, 'fake mp4 content');

        $finder = new ThemeMediaFinder();
        $result = $finder->findForLibrary('lib-123', $this->fixturesDir);

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertNotNull($result->video);
        $this->assertSame('mp4', $result->video->format);
        $this->assertSame($backdropMp4, $result->video->path);
    }

    public function testFindsBothAudioAndVideo(): void
    {
        $themeMp3 = $this->fixturesDir . '/theme.mp3';
        $backdropMp4 = $this->fixturesDir . '/backdrop.mp4';
        file_put_contents($themeMp3, 'fake mp3 content');
        file_put_contents($backdropMp4, 'fake mp4 content');

        $finder = new ThemeMediaFinder();
        $result = $finder->findForLibrary('lib-123', $this->fixturesDir);

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertNotNull($result->audio);
        $this->assertNotNull($result->video);
        $this->assertTrue($result->hasAudio());
        $this->assertTrue($result->hasVideo());
    }

    public function testReturnsNullWhenNoThemeMediaFound(): void
    {
        // Create an empty directory with no theme media
        $finder = new ThemeMediaFinder();
        $result = $finder->findForLibrary('lib-123', $this->fixturesDir);

        $this->assertNull($result);
    }

    public function testFindsThemeForMediaItemDirectory(): void
    {
        // Create a library structure with theme in parent directory
        $libraryDir = $this->fixturesDir . '/Library';
        $movieDir = $libraryDir . '/Movie (2020)';
        mkdir($movieDir, 0755, true);

        // Put theme.mp3 in the library root (parent of movie dir)
        $themeMp3 = $libraryDir . '/theme.mp3';
        file_put_contents($themeMp3, 'fake mp3 content');

        $finder = new ThemeMediaFinder();
        $result = $finder->findForMediaItem('lib-123', $movieDir);

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertNotNull($result->audio);
        $this->assertSame($themeMp3, $result->audio->path);
    }

    public function testPrefersMp3OverOtherAudioFormats(): void
    {
        // Create theme files in priority order
        $themeMp3 = $this->fixturesDir . '/theme.mp3';
        $themeOgg = $this->fixturesDir . '/theme.ogg';
        file_put_contents($themeMp3, 'mp3 content');
        file_put_contents($themeOgg, 'ogg content');

        $finder = new ThemeMediaFinder();
        $result = $finder->findForLibrary('lib-123', $this->fixturesDir);

        $this->assertNotNull($result->audio);
        $this->assertSame('mp3', $result->audio->format);
    }

    public function testPrefersMp4OverWebmForVideo(): void
    {
        $backdropMp4 = $this->fixturesDir . '/backdrop.mp4';
        $backdropWebm = $this->fixturesDir . '/backdrop.webm';
        file_put_contents($backdropMp4, 'mp4 content');
        file_put_contents($backdropWebm, 'webm content');

        $finder = new ThemeMediaFinder();
        $result = $finder->findForLibrary('lib-123', $this->fixturesDir);

        $this->assertNotNull($result->video);
        $this->assertSame('mp4', $result->video->format);
    }

    public function testAudioUrlIsCorrectlyFormatted(): void
    {
        $themeMp3 = $this->fixturesDir . '/theme.mp3';
        file_put_contents($themeMp3, 'fake mp3 content');

        $finder = new ThemeMediaFinder();
        $result = $finder->findForLibrary('lib-123', $this->fixturesDir);

        $this->assertNotNull($result->audio);
        $this->assertStringContainsString('/stream/theme-media/audio', $result->audio->url);
        $this->assertStringContainsString(urlencode($themeMp3), $result->audio->url);
    }

    public function testVideoUrlIsCorrectlyFormatted(): void
    {
        $backdropMp4 = $this->fixturesDir . '/backdrop.mp4';
        file_put_contents($backdropMp4, 'fake mp4 content');

        $finder = new ThemeMediaFinder();
        $result = $finder->findForLibrary('lib-123', $this->fixturesDir);

        $this->assertNotNull($result->video);
        $this->assertStringContainsString('/stream/theme-media/video', $result->video->url);
        $this->assertStringContainsString(urlencode($backdropMp4), $result->video->url);
    }

    public function testScannedAtIsSetCorrectly(): void
    {
        $themeMp3 = $this->fixturesDir . '/theme.mp3';
        file_put_contents($themeMp3, 'fake mp3 content');

        $beforeScan = new \DateTimeImmutable();

        $finder = new ThemeMediaFinder();
        $result = $finder->findForLibrary('lib-123', $this->fixturesDir);

        $afterScan = new \DateTimeImmutable();

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual($beforeScan, $result->scannedAt);
        $this->assertLessThanOrEqual($afterScan, $result->scannedAt);
    }
}
