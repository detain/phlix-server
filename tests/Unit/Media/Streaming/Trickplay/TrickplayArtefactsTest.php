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

    /** Writes a sprite, a timeline with $entries entries and a 12-frame BIF. */
    private function writeSet(int $entries = 60, ?string $bif = null): void
    {
        $timeline = [];
        for ($i = 0; $i < $entries; $i++) {
            $timeline[] = ['time' => $i * 2.0, 'x' => 2, 'y' => 2];
        }
        file_put_contents($this->dir . '/' . FfmpegRunner::SPRITE_FILENAME, self::JPEG);
        file_put_contents($this->dir . '/' . FfmpegRunner::TIMELINE_FILENAME, json_encode($timeline, JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->dir . '/' . BifWriter::FILENAME,
            $bif ?? BifWriter::build(array_fill(0, 12, self::JPEG), 10000)
        );
    }

    public function testAnItemIsCompleteOnlyWhenEveryArtefactIsWhole(): void
    {
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'An empty directory is not complete.');

        file_put_contents($this->dir . '/' . FfmpegRunner::SPRITE_FILENAME, self::JPEG);
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A sprite alone is not complete.');

        $this->writeSet();
        $this->assertTrue(TrickplayArtefacts::isComplete($this->dir));

        file_put_contents($this->dir . '/' . BifWriter::FILENAME, '');
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A zero-byte archive is a half-finished run.');
    }

    public function testATruncatedBifIsNotComplete(): void
    {
        $whole = BifWriter::build(array_fill(0, 12, self::JPEG), 10000);
        // Cut one byte off the last frame: the terminator's end offset no longer
        // equals the file size, so the archive must not be advertised.
        $this->writeSet(60, substr($whole, 0, -1));
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir));

        // Cut inside the index: the header claims frames the file does not hold.
        $this->writeSet(60, substr($whole, 0, 100));
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir));
    }

    public function testABifWithoutTheRokuMagicIsNotComplete(): void
    {
        $bif = BifWriter::build(array_fill(0, 12, self::JPEG), 10000);
        $this->writeSet(60, 'XXXXXXXX' . substr($bif, 8));

        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir));
    }

    public function testAShortOrLongTimelineIsNotComplete(): void
    {
        $this->writeSet(59);
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A 59-entry timeline is a short sprite grid.');

        $this->writeSet(61);
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A 61-entry timeline does not match the grid.');

        file_put_contents($this->dir . '/' . FfmpegRunner::TIMELINE_FILENAME, 'not json');
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'An unparseable timeline is not complete.');
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
