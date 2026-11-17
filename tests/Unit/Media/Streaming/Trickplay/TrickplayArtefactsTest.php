<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Trickplay;

use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Streaming\Trickplay\TrickplayArtefacts;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;

final class TrickplayArtefactsTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/trickplay_artefacts_' . uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $entry) {
            unlink($this->dir . '/' . $entry);
        }
        rmdir($this->dir);
    }

    public function testTheCadenceAndLayoutAreThePublishedValues(): void
    {
        // These are the values the archive and sprite formats are published with.
        // Changing one changes the artefacts every client has cached, so the change
        // must be deliberate and this pin must move with it.
        $this->assertSame(60, TrickplayArtefacts::SPRITE_COUNT);
        $this->assertSame(10, TrickplayArtefacts::BIF_TARGET_INTERVAL_SECONDS);
        $this->assertSame(600, TrickplayArtefacts::BIF_MAX_FRAMES);
        $this->assertSame(320, TrickplayArtefacts::BIF_FRAME_WIDTH);
    }

    /**
     * @return array<string, array{0: float, 1: int}>
     */
    public static function runtimes(): array
    {
        return [
            'one per ten seconds' => [3600.0, 360],
            'rounds up' => [3601.0, 361],
            'floor of two' => [5.0, 2],
            'cap of six hundred' => [10000.0, 600],
            'unknown runtime falls back to the sprite count' => [0.0, 60],
            'negative runtime is unknown too' => [-3.0, 60],
        ];
    }

    /**
     * @dataProvider runtimes
     */
    public function testBifFrameCountFollowsTheCadenceAndClamps(float $duration, int $expected): void
    {
        $this->assertSame($expected, TrickplayArtefacts::bifFrameCount($duration));
    }

    public function testAnItemIsCompleteOnlyWhenAllThreeArtefactsAreNonEmpty(): void
    {
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'An empty directory is not complete.');

        file_put_contents($this->dir . '/' . FfmpegRunner::SPRITE_FILENAME, self::JPEG);
        file_put_contents($this->dir . '/' . FfmpegRunner::TIMELINE_FILENAME, '[]');
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A sprite without its BIF is not complete.');

        file_put_contents($this->dir . '/' . BifWriter::FILENAME, '');
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A zero-byte archive is a half-finished run.');

        file_put_contents($this->dir . '/' . BifWriter::FILENAME, BifWriter::build([self::JPEG, self::JPEG], 10000));
        $this->assertTrue(TrickplayArtefacts::isComplete($this->dir));
    }

    public function testWriteBifNeedsAtLeastTwoFrames(): void
    {
        $frame = $this->dir . '/bifframe_00001.jpg';
        file_put_contents($frame, self::JPEG);

        $this->assertFalse(TrickplayArtefacts::writeBif([$frame], 10000, $this->dir));
        $this->assertFileDoesNotExist($this->dir . '/' . BifWriter::FILENAME);
    }

    public function testWriteBifBuildsTheArchiveFromTheFrames(): void
    {
        $frames = [$this->dir . '/a.jpg', $this->dir . '/b.jpg'];
        foreach ($frames as $frame) {
            file_put_contents($frame, self::JPEG);
        }

        $this->assertTrue(TrickplayArtefacts::writeBif($frames, 10000, $this->dir));

        $bif = (string) file_get_contents($this->dir . '/' . BifWriter::FILENAME);
        $this->assertSame(BifWriter::MAGIC, substr($bif, 0, 8));
        $this->assertSame(BifWriter::build([self::JPEG, self::JPEG], 10000), $bif);
        foreach ($frames as $frame) {
            @unlink($frame);
        }
    }
}
