<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Streaming\Trickplay\TrickplayArtefacts;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;

/**
 * The single-pass trickplay command (sprite sheet, timeline and BIF frames from
 * one decode) and its orchestration.
 *
 * The runner is subclassed so the probe and the process launch are scripted:
 * the test captures the built command and writes the artefacts that ffmpeg would
 * have produced. That exercises the real command builder and the real
 * gating/timeline/cleanup logic without a binary.
 */
final class FfmpegRunnerTrickplayPassTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/trickplay_pass_' . uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function scripted(float $duration = 6000.0): ScriptedTrickplayRunner
    {
        return new ScriptedTrickplayRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', $this->dir, $duration);
    }

    public function testCombinedPassDecodesOnceAndFansOutToBothOutputs(): void
    {
        $runner = $this->scripted();

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNotNull($result);
        $this->assertCount(1, $runner->commands, 'Both outputs must come from ONE ffmpeg process.');
        $cmd = $runner->commands[0];
        $this->assertSame(1, substr_count($cmd, ' -i '), 'There must be exactly one input, so one decode.');
        // Three branches off one decode: sprite, slot counter and BIF.
        $this->assertStringContainsString('split=3[in_sprite][in_slots][in_bif]', $cmd);
        $this->assertStringContainsString('-map "[sprite]"', $cmd);
        $this->assertStringContainsString('-map "[slots]" -f framemd5', $cmd);
        $this->assertStringContainsString('-map "[bif]"', $cmd);
    }

    public function testCommandIsNicedCappedAndHasNoKeyframeOnlyDecode(): void
    {
        $runner = $this->scripted();

        $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $cmd = $runner->commands[0];
        $this->assertStringStartsWith('nice -n 19 ', $cmd);
        $this->assertStringContainsString('-threads 4', $cmd);
        // Keyframe-only decode would move every sample to the nearest keyframe
        // (measured mean 1.6 s, max 5.1 s error on the BIF grid), so it must not
        // be used. The ONLY way to change this is a deliberate, measured decision.
        $this->assertStringNotContainsString('skip_frame', $cmd);
    }

    public function testSharedSeekSitsAtTheEarliestSampleAndEachBranchTrimsToItsOwnGrid(): void
    {
        // duration 6000 s: sprite interval 100 s (offset 50 s); BIF count 600 means
        // interval 10 s (offset 5 s). The one input seek sits at the earlier sample,
        // 5 s, and the sprite branch trims the remaining 45 s, so every sample still
        // lands at its original absolute time.
        $runner = $this->scripted(6000.0);

        $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $cmd = $runner->commands[0];
        $this->assertStringContainsString("-ss '5'", $cmd);
        // Each branch shifts its clock by its own trim, so its fps grid stays
        // anchored on its own offset (PTS-STARTPTS would move it by a frame).
        $this->assertStringContainsString(
            'trim=start=45,setpts=PTS-45/TB,fps=1/100:eof_action=pass,scale=160:90,tile=6x10:margin=2:padding=1[sprite]',
            $cmd
        );
        $this->assertStringContainsString('trim=start=0,setpts=PTS-0/TB,fps=1/10:eof_action=pass,scale=320:-2[bif]', $cmd);
        // The slot counter samples the sprite grid exactly as the sprite branch does.
        $this->assertStringContainsString(
            'trim=start=45,setpts=PTS-45/TB,fps=1/100:eof_action=pass,scale=16:9[slots]',
            $cmd
        );
        $this->assertStringNotContainsString('STARTPTS', $cmd);
    }

    public function testSpriteOnlyPassSplitsOnlyToCountItsSlots(): void
    {
        $runner = $this->scripted();

        $result = $runner->generateTrickplaySprites('/in.mkv', $this->dir . '/out', 60);

        $this->assertNotNull($result);
        $cmd = $runner->commands[0];
        $this->assertStringContainsString('split=2[in_sprite][in_slots]', $cmd);
        $this->assertStringNotContainsString('[bif]', $cmd);
        $this->assertStringContainsString('-map "[sprite]"', $cmd);
    }

    public function testASpriteThatEndsEarlyIsRejectedAndPublishesNothing(): void
    {
        // The reviewed repro: a 120 s container whose video stops at 118 s. ffmpeg exits
        // 0 with an empty error stream, but its sampler produced 59 slots for 60 cells.
        // The last cell would be black, so the pass must fail and publish nothing.
        $runner = $this->scripted(6000.0);
        $runner->spriteSlotsToWrite = 59;

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
        $this->assertFileDoesNotExist($this->dir . '/out/timeline.json');
        $this->assertFileDoesNotExist($this->dir . '/out/bifframe_00001.jpg');
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testASpriteWithNoSlotCountIsRejected(): void
    {
        // The slot file is missing (ffmpeg wrote nothing): the count is unknown, so the
        // sprite cannot be verified and the pass fails.
        $runner = $this->scripted(6000.0);
        $runner->spriteSlotsToWrite = -1;

        $this->assertNull($runner->generateTrickplaySprites('/in.mkv', $this->dir . '/out', 60));
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testTheSlotCountFileIsRemovedWhenTheRunThrows(): void
    {
        $runner = $this->scripted(6000.0);
        $runner->throwOnRun = true;

        try {
            $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);
            $this->fail('The launch exception must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('launch failed', $e->getMessage());
        }
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testTimelineMatchesTheSpriteGridAtTheSampledTimes(): void
    {
        $runner = $this->scripted(6000.0);

        $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320);

        $timeline = json_decode((string) file_get_contents($this->dir . '/out/timeline.json'), true);
        $this->assertIsArray($timeline);
        $this->assertCount(60, $timeline);
        // Thumbnail i is labelled at the MIDPOINT of its interval, (i + 0.5) * 100 s,
        // which is the instant it is sampled at. So cell 0 is 50 s, not the t=0 slate.
        $this->assertEqualsWithDelta(50.0, $timeline[0]['time'], 1e-9);
        $this->assertEqualsWithDelta(150.0, $timeline[1]['time'], 1e-9);
        $this->assertSame(['x' => 2, 'y' => 2], ['x' => $timeline[0]['x'], 'y' => $timeline[0]['y']]);
        // Column 5 starts at margin + 5 * (160 + 1) = 807; row 1 at margin + 91 = 93.
        $this->assertSame(['x' => 807, 'y' => 2], ['x' => $timeline[5]['x'], 'y' => $timeline[5]['y']]);
        $this->assertSame(['x' => 2, 'y' => 93], ['x' => $timeline[6]['x'], 'y' => $timeline[6]['y']]);
    }

    public function testBifLabelsIntervalStartsAndSpriteCellsLabelTheirMidpoint(): void
    {
        // Duration 6000 s: sprite cells every 100 s, BIF frames every 10 s. The BIF
        // labels frame i at i * its interval (the start of the interval); the sprite
        // timeline labels cell i at its sample instant, the midpoint (i + 0.5) * interval.
        $runner = $this->scripted(6000.0);
        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);
        $this->assertNotNull($result);

        $timeline = json_decode((string) file_get_contents($this->dir . '/out/timeline.json'), true);
        $this->assertIsArray($timeline);
        $this->assertEqualsWithDelta(50.0, $timeline[0]['time'], 1e-9, 'sprite cell 0 is sampled at its midpoint, 50 s');
        $this->assertEqualsWithDelta(150.0, $timeline[1]['time'], 1e-9, 'sprite cell 1 is sampled at 1.5 x 100 s');

        $bifPath = TrickplayArtefacts::writeBif($result['bif_frames'], $result['bif_interval_ms'], $this->dir . '/out')
            ? $this->dir . '/out/' . BifWriter::FILENAME
            : null;
        $this->assertNotNull($bifPath);
        $bif = (string) file_get_contents($bifPath);
        /** @var array{1: int} $multiplier */
        $multiplier = unpack('V', substr($bif, 16, 4));
        $this->assertSame(10000, $multiplier[1], 'BIF multiplier is the 10 s interval in ms');
        /** @var array{1: int} $entry1 */
        $entry1 = unpack('V', substr($bif, 64 + 8, 4));
        $this->assertSame(1, $entry1[1], 'BIF frame 1 carries timestamp 1 multiplier unit, i.e. 10 s');
        // BIF frame 1 is labelled 1 x its interval, 10 s (1 x 10000 ms).
        $this->assertSame(10.0, $multiplier[1] / 1000.0);
    }

    public function testBifFramesAreReturnedInCaptureOrderWithTheirInterval(): void
    {
        $runner = $this->scripted(6000.0);

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNotNull($result);
        $this->assertCount(600, $result['bif_frames']);
        $this->assertSame($this->dir . '/out/bifframe_00001.jpg', $result['bif_frames'][0]);
        $this->assertSame($this->dir . '/out/bifframe_00600.jpg', $result['bif_frames'][599]);
        $this->assertSame(10000, $result['bif_interval_ms']);
    }

    public function testBifGapFailsThePassAndPublishesNothing(): void
    {
        $runner = $this->scripted(6000.0);
        $runner->skipBifIndexes = [6];

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 10, 320);

        $this->assertNull($result, 'A hole in the BIF index is a partial archive and must fail the pass.');
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
        $this->assertFileDoesNotExist($this->dir . '/out/timeline.json');
        for ($i = 1; $i <= 10; $i++) {
            $this->assertFileDoesNotExist(sprintf($this->dir . '/out/bifframe_%05d.jpg', $i));
        }
    }

    public function testBifShortfallWithNonZeroExitFailsThePassAndPublishesNothing(): void
    {
        // The reviewed repro: a directory planted where frame 10 goes. ffmpeg writes
        // the sprite and frames 1-9, then fails with exit 251. The sprite is complete,
        // but the archive would cover 90 s of a 120 s title, so nothing is published.
        $runner = $this->scripted(6000.0);
        $runner->bifFramesToWrite = 9;
        $runner->exitCodeToReturn = 251;

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 10, 320);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
        $this->assertFileDoesNotExist($this->dir . '/out/timeline.json');
        $this->assertFileDoesNotExist($this->dir . '/out/bifframe_00001.jpg');
    }

    public function testNonZeroExitFailsThePassEvenWhenEveryArtefactWasWritten(): void
    {
        $runner = $this->scripted(6000.0);
        $runner->exitCodeToReturn = 1;

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 10, 320);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
        $this->assertFileDoesNotExist($this->dir . '/out/timeline.json');
    }

    public function testExitZeroWithAnErrorReportFailsThePassAndPublishesNothing(): void
    {
        // A truncated Matroska input: every frame count is complete (the scripted
        // ffmpeg writes them all), exit is 0, but ffmpeg printed "File ended
        // prematurely". The sprite would carry black cells over the missing tail.
        $runner = $this->scripted(6000.0);
        $runner->ffmpegMessage = '[matroska,webm @ 0x0] File ended prematurely';

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
        $this->assertFileDoesNotExist($this->dir . '/out/timeline.json');
        $this->assertFileDoesNotExist($this->dir . '/out/bifframe_00001.jpg');
    }

    public function testASuccessfulPassLeavesNoTemporaryBehind(): void
    {
        $runner = $this->scripted(6000.0);

        $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testADiskFullTimelineWritePublishesNothingAndLeavesNoTemporary(): void
    {
        if (!file_exists('/dev/full')) {
            $this->markTestSkipped('/dev/full is needed to force a real ENOSPC on write.');
        }
        // The timeline's temporary is a link to /dev/full, so its write fails with
        // ENOSPC the way a full disk does. The old code wrote in place and
        // ignored the result, so a truncated timeline was published.
        mkdir($this->dir . '/out', 0755, true);
        symlink('/dev/full', $this->dir . '/out/.tmp-timeline.json');
        $runner = $this->scripted(6000.0);

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
        $this->assertFileDoesNotExist($this->dir . '/out/timeline.json');
        $this->assertFalse(is_link($this->dir . '/out/.tmp-timeline.json'), 'The temporary must be removed.');
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    /** @return list<string> Temporary names left in $dir. */
    private function temporariesIn(string $dir): array
    {
        $names = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            if (str_starts_with($name, '.tmp-')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function testACleanRunWithEmptyOutputStillPublishes(): void
    {
        $runner = $this->scripted(6000.0);

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNotNull($result);
        $this->assertFileExists($this->dir . '/out/sprite.jpg');
        $this->assertFileExists($this->dir . '/out/timeline.json');
    }

    public function testShortBifWithExitZeroStillFailsThePass(): void
    {
        // A silent early end of input can exit 0 with too few frames; the count
        // check is what catches it, so the sprite must not be published either.
        $runner = $this->scripted(6000.0);
        $runner->bifFramesToWrite = 0;

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
    }

    public function testSpriteFailureReturnsNullAndRemovesTheBifFrames(): void
    {
        $runner = $this->scripted();
        $runner->writeSprite = false;
        $runner->bifFramesToWrite = 5;

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 5, 320);

        $this->assertNull($result);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertFileDoesNotExist(sprintf($this->dir . '/out/bifframe_%05d.jpg', $i));
        }
    }

    public function testATooShortBifFailsThePassAndItsFilesAreRemoved(): void
    {
        $runner = $this->scripted();
        $runner->bifFramesToWrite = 1;

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->dir . '/out/bifframe_00001.jpg');
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
    }

    public function testRejectsRequestsWithNoBranchOrAnUnusableBif(): void
    {
        $runner = $this->scripted();

        $this->assertNull($runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 0, 0, 320));
        $this->assertNull($runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 1, 320));
        $this->assertNull($runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 60, 1));
        $this->assertSame([], $runner->commands, 'An invalid request must not launch ffmpeg.');
    }

    public function testAnUnprobeableOrZeroLengthFileReturnsNull(): void
    {
        $unprobeable = $this->scripted();
        $unprobeable->probeFails = true;
        $this->assertNull($unprobeable->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 60, 320));

        $zero = $this->scripted(0.0);
        $this->assertNull($zero->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 60, 320));
        $this->assertSame([], $zero->commands);
    }

    public function testBifOnlyWrapperReturnsFramesAndIntervalMs(): void
    {
        $runner = $this->scripted(6000.0);

        $result = $runner->generateBifFrames('/in.mkv', $this->dir . '/out', 60, 320);

        $this->assertNotNull($result);
        [$frames, $intervalMs] = $result;
        $this->assertCount(60, $frames);
        $this->assertSame(100000, $intervalMs);
        $this->assertStringNotContainsString('[sprite]', $runner->commands[0]);
    }

    public function testSpriteWrapperReturnsSpriteAndTimelinePaths(): void
    {
        $runner = $this->scripted();

        $result = $runner->generateTrickplaySprites('/in.mkv', $this->dir . '/out', 60);

        $this->assertSame(
            [$this->dir . '/out/sprite.jpg', $this->dir . '/out/timeline.json'],
            $result
        );
    }
}

/**
 * Runner whose probe and process launch are scripted. The launch seam writes the
 * artefacts the command names, the way ffmpeg would.
 */
final class ScriptedTrickplayRunner extends FfmpegRunner
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    /** @var list<string> */
    public array $commands = [];

    public bool $writeSprite = true;

    public bool $probeFails = false;

    /** How many BIF frames the scripted ffmpeg writes. */
    public int $bifFramesToWrite = 600;

    /** @var list<int> BIF frame indexes to leave unwritten (a hole in the sequence). */
    public array $skipBifIndexes = [];

    /** Exit code the scripted ffmpeg reports. */
    public int $exitCodeToReturn = 0;

    /** Text ffmpeg writes to its captured output, or null for none. */
    public ?string $ffmpegMessage = null;

    /** Slots the scripted sampler lists in the framemd5 file; -1 writes no file. */
    public int $spriteSlotsToWrite = 60;

    /** Whether the launch throws after writing its outputs. */
    public bool $throwOnRun = false;

    public function __construct(string $ffmpeg, string $ffprobe, string $transcodeDir, private float $duration)
    {
        parent::__construct($ffmpeg, $ffprobe, $transcodeDir);
    }

    /**
     * @return array{streams: array<int, array<string, mixed>>, format: array<string, mixed>}|null
     */
    public function probe(string $inputPath): ?array
    {
        if ($this->probeFails) {
            return null;
        }

        return [
            'streams' => [],
            'format' => ['duration' => (string) $this->duration],
        ];
    }

    protected function runTrickplayCommand(string $cmd, array &$output, int &$exitCode): void
    {
        $this->commands[] = $cmd;
        $output = $this->ffmpegMessage === null ? [] : [$this->ffmpegMessage];
        $exitCode = $this->exitCodeToReturn;

        // The sprite is written under its temporary name and renamed afterwards.
        if ($this->writeSprite && preg_match("#'([^']*/\\.tmp-sprite\\.jpg)'#", $cmd, $m) === 1) {
            file_put_contents($m[1], self::JPEG);
        }

        if (preg_match("#'([^']*/bifframe_%05d\\.jpg)'#", $cmd, $m) === 1) {
            for ($i = 1; $i <= $this->bifFramesToWrite; $i++) {
                if (in_array($i, $this->skipBifIndexes, true)) {
                    continue;
                }
                file_put_contents(sprintf($m[1], $i), self::JPEG);
            }
        }

        // The slot counter: one framemd5 line per sampled slot, after the header lines.
        if ($this->spriteSlotsToWrite >= 0 && preg_match("#-f framemd5 '([^']*)'#", $cmd, $m) === 1) {
            $lines = ['#format: frame checksums', '#version: 2'];
            for ($i = 0; $i < $this->spriteSlotsToWrite; $i++) {
                $lines[] = $i . ', ' . $i . ', ' . $i . ', 1, 1, 0x00000000';
            }
            file_put_contents($m[1], implode("\n", $lines) . "\n");
        }

        if ($this->throwOnRun) {
            throw new \RuntimeException('launch failed');
        }
    }
}
