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
        file_put_contents(
            $this->dir . '/' . FfmpegRunner::TIMELINE_FILENAME,
            json_encode($timeline, JSON_THROW_ON_ERROR)
        );
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

    public function testAFailureMarkerIsSetReadAndCleared(): void
    {
        $this->assertFalse(TrickplayArtefacts::hasFailedMarker($this->dir));

        TrickplayArtefacts::markFailed($this->dir, 'BIF not written');
        $this->assertTrue(TrickplayArtefacts::hasFailedMarker($this->dir));
        $marker = $this->dir . '/' . TrickplayArtefacts::FAILED_MARKER_FILENAME;
        $record = json_decode((string) file_get_contents($marker), true);
        $this->assertIsArray($record);
        $this->assertSame('BIF not written', $record['reason']);

        TrickplayArtefacts::clearFailedMarker($this->dir);
        $this->assertFalse(TrickplayArtefacts::hasFailedMarker($this->dir));
        // Clearing an absent marker is a no-op, not an error.
        TrickplayArtefacts::clearFailedMarker($this->dir);
    }

    public function testTheItemLockIsExclusiveAndCanBeReleased(): void
    {
        $first = TrickplayArtefacts::tryLock($this->dir);
        $this->assertNotNull($first);

        // A second acquisition on the same item is refused without waiting. This
        // is the same arbitration two processes get.
        $this->assertNull(TrickplayArtefacts::tryLock($this->dir));

        TrickplayArtefacts::unlock($first);
        $second = TrickplayArtefacts::tryLock($this->dir);
        $this->assertNotNull($second, 'Once released, the item can be locked again.');
        TrickplayArtefacts::unlock($second);
    }

    /**
     * Writes a whole sprite, a 60-entry timeline and a 12-frame BIF, with the given stamps.
     * A null argument leaves that part unstamped: no `generation` field on the entries, or no
     * stamp file. $taggedEntries limits how many timeline entries carry the token.
     */
    private function writeGenerationSet(
        ?string $timelineGeneration,
        ?string $spriteStamp,
        ?string $bifStamp,
        int $taggedEntries = 60
    ): void {
        // Clear the stamps a previous call left, so a null argument really means "absent".
        @unlink($this->dir . '/' . FfmpegRunner::SPRITE_GENERATION_FILENAME);
        @unlink($this->dir . '/' . BifWriter::GENERATION_FILENAME);

        $timeline = [];
        for ($i = 0; $i < 60; $i++) {
            $entry = ['time' => $i * 2.0, 'x' => 2, 'y' => 2];
            if ($timelineGeneration !== null && $i < $taggedEntries) {
                $entry['generation'] = $timelineGeneration;
            }
            $timeline[] = $entry;
        }
        file_put_contents($this->dir . '/' . FfmpegRunner::SPRITE_FILENAME, self::JPEG);
        file_put_contents(
            $this->dir . '/' . FfmpegRunner::TIMELINE_FILENAME,
            json_encode($timeline, JSON_THROW_ON_ERROR)
        );
        $bif = BifWriter::build(array_fill(0, 12, self::JPEG), 10000);
        file_put_contents($this->dir . '/' . BifWriter::FILENAME, $bif);
        if ($spriteStamp !== null) {
            file_put_contents($this->dir . '/' . FfmpegRunner::SPRITE_GENERATION_FILENAME, $spriteStamp);
        }
        if ($bifStamp !== null) {
            file_put_contents($this->dir . '/' . BifWriter::GENERATION_FILENAME, $bifStamp);
        }
    }

    public function testAStampedSetIsCompleteOnlyWhenEveryPartCarriesOneGeneration(): void
    {
        $gen = '0123456789abcdef';
        $other = 'fedcba9876543210';

        $this->writeGenerationSet($gen, $gen, $gen);
        $this->assertTrue(TrickplayArtefacts::isComplete($this->dir), 'One generation in all three parts is complete.');

        $this->writeGenerationSet($gen, $other, $gen);
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A sprite from another pass is a torn pair.');

        $this->writeGenerationSet($gen, $gen, $other);
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A BIF from another pass is a torn set.');

        $this->writeGenerationSet($gen, null, $gen);
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A missing sprite stamp is not complete.');

        $this->writeGenerationSet($gen, $gen, null);
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A BIF without its stamp is not complete.');
    }

    public function testAMismatchedOrDamagedTimelineGenerationIsIncomplete(): void
    {
        $gen = '0123456789abcdef';

        $this->writeGenerationSet($gen, $gen, $gen, 59);
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'Some entries untagged is a torn timeline.');

        $this->writeGenerationSet('not-a-token', 'not-a-token', 'not-a-token');
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'A token that is not 16 hex digits is invalid.');

        $this->writeGenerationSet($gen, $gen, $gen);
        $timeline = json_decode((string) file_get_contents($this->dir . '/' . FfmpegRunner::TIMELINE_FILENAME), true);
        $timeline[0]['generation'] = 'fedcba9876543210';
        file_put_contents($this->dir . '/' . FfmpegRunner::TIMELINE_FILENAME, json_encode($timeline));
        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'Two tokens in one timeline are torn.');
    }

    public function testALegacySetWithoutAnyStampIsStillAccepted(): void
    {
        // Bound: artefacts written before stamps existed carry no generation at all. They are
        // accepted when whole. A stamp that appears beside an untagged timeline is not legacy.
        $this->writeGenerationSet(null, null, null);
        $this->assertTrue(TrickplayArtefacts::isComplete($this->dir), 'A whole pre-stamp set stays complete.');

        $this->writeGenerationSet(null, '0123456789abcdef', null);
        $this->assertFalse(
            TrickplayArtefacts::isComplete($this->dir),
            'A stray stamp beside a legacy timeline is torn.'
        );
    }

    public function testAnArchiveWrittenWithItsGenerationCompletesTheSet(): void
    {
        $gen = '0123456789abcdef';
        $this->writeGenerationSet($gen, $gen, null);
        $frames = [];
        for ($i = 0; $i < 2; $i++) {
            $frames[] = $this->dir . '/frame' . $i . '.jpg';
            file_put_contents($frames[$i], self::JPEG);
        }

        $this->assertFalse(TrickplayArtefacts::isComplete($this->dir), 'No BIF stamp yet, so not complete.');
        $this->assertTrue(TrickplayArtefacts::writeBif($frames, 10000, $this->dir, $gen));
        $this->assertTrue(TrickplayArtefacts::isComplete($this->dir), 'The stamped archive completes the set.');
    }

    public function testAnUnstampedBifWriteRemovesTheStampItReplaces(): void
    {
        $frames = [];
        for ($i = 0; $i < 2; $i++) {
            $frames[] = $this->dir . '/frame' . $i . '.jpg';
            file_put_contents($frames[$i], self::JPEG);
        }
        $this->assertTrue(TrickplayArtefacts::writeBif($frames, 10000, $this->dir, '0123456789abcdef'));
        $this->assertFileExists($this->dir . '/' . BifWriter::GENERATION_FILENAME);

        $this->assertTrue(TrickplayArtefacts::writeBif($frames, 10000, $this->dir));
        $this->assertFileDoesNotExist($this->dir . '/' . BifWriter::GENERATION_FILENAME);
    }

    public function testABifFailureMarkerDoesNotSkipTheItem(): void
    {
        // The BIF-only marker is informational. The backfill's skip check reads only the
        // generation-failed marker, so a BIF failure must not set it.
        TrickplayArtefacts::markBifFailed($this->dir, 'BIF not written');

        $this->assertFileExists($this->dir . '/' . TrickplayArtefacts::BIF_FAILED_MARKER_FILENAME);
        $this->assertFalse(TrickplayArtefacts::hasFailedMarker($this->dir));

        TrickplayArtefacts::clearBifFailedMarker($this->dir);
        $this->assertFileDoesNotExist($this->dir . '/' . TrickplayArtefacts::BIF_FAILED_MARKER_FILENAME);
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
