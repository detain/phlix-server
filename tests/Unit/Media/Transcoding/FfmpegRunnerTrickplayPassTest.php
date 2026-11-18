<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Streaming\Trickplay\TrickplayArtefacts;
use Phlix\Tests\Support\Trickplay\ScriptedTrickplayRunner;
use Phlix\Tests\Unit\Media\Music\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    private function scripted(float $duration = 6000.0, ?LoggerInterface $logger = null): ScriptedTrickplayRunner
    {
        return new ScriptedTrickplayRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', $this->dir, $duration, $logger);
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
            'trim=start=45,setpts=PTS-45/TB,fps=1/100:eof_action=pass,scale=160:90,'
            . 'tile=6x10:margin=2:padding=1[sprite]',
            $cmd
        );
        $this->assertStringContainsString(
            'trim=start=0,setpts=PTS-0/TB,fps=1/10:eof_action=pass,scale=320:-2[bif]',
            $cmd
        );
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
        $this->assertEqualsWithDelta(50.0, $timeline[0]['time'], 1e-9, 'cell 0 is sampled at its midpoint, 50 s');
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
        // ENOSPC the way a full disk does. The link appears during the run, after the
        // pre-pass sweep, so the sweep cannot remove it first. The old code wrote in
        // place and ignored the result, so a truncated timeline was published.
        $runner = $this->scripted(6000.0);
        $runner->devFullLinkAt = $this->dir . '/out/.tmp-timeline.json';

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
        $this->assertFileDoesNotExist($this->dir . '/out/timeline.json');
        $this->assertFalse(is_link($this->dir . '/out/.tmp-timeline.json'), 'The temporary must be removed.');
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testASpriteCountThatIsNotWholeRowsIsRefusedBeforeAnyDecode(): void
    {
        // 61 cells would be 11 rows of 6, with 5 cells the sampler never fills. The pass
        // must refuse it before ffmpeg starts, not publish a sheet with black cells.
        $runner = $this->scripted(6000.0);

        $this->assertNull($runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 61, 0, 320));
        $this->assertSame([], $runner->commands, 'A refused count must not start ffmpeg.');
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
    }

    public function testASpriteCountOfWholeRowsIsAccepted(): void
    {
        $runner = $this->scripted(6000.0);

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320);

        $this->assertNotNull($result);
        $this->assertCount(60, json_decode((string) file_get_contents($this->dir . '/out/timeline.json'), true));
    }

    public function testAFailedRenameOfTheTimelineRestoresEveryPreviousArtefact(): void
    {
        // The publish keeps each existing final under a previous-copy hard link, then renames the
        // temporary over the final. Here the timeline temporary's rename fails after the sprite was
        // already swapped. Both previous files must come back byte for byte, and no previous-copy
        // name may be left behind.
        mkdir($this->dir . '/out', 0755, true);
        file_put_contents($this->dir . '/out/sprite.jpg', 'OLD-SPRITE');
        file_put_contents($this->dir . '/out/timeline.json', 'OLD-TIMELINE');
        $runner = $this->scripted(6000.0);
        $runner->failRenameFrom = $this->dir . '/out/.tmp-timeline.json';

        $this->assertNull($runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320));

        $this->assertSame('OLD-SPRITE', file_get_contents($this->dir . '/out/sprite.jpg'));
        $this->assertSame('OLD-TIMELINE', file_get_contents($this->dir . '/out/timeline.json'));
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.gen');
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testAFailedStampRenameLeavesThePreviousPairUntouched(): void
    {
        // The stamp is published first. When its rename fails, nothing after it may run,
        // so the previous sprite and timeline stay exactly as they were.
        mkdir($this->dir . '/out', 0755, true);
        file_put_contents($this->dir . '/out/sprite.jpg', 'OLD-SPRITE');
        file_put_contents($this->dir . '/out/timeline.json', 'OLD-TIMELINE');
        $runner = $this->scripted(6000.0);
        $runner->failRenameFrom = $this->dir . '/out/.tmp-sprite.gen';

        $this->assertNull($runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320));

        $this->assertSame('OLD-SPRITE', file_get_contents($this->dir . '/out/sprite.jpg'));
        $this->assertSame('OLD-TIMELINE', file_get_contents($this->dir . '/out/timeline.json'));
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testEverySampleAndTheSpriteStampCarryTheResultGeneration(): void
    {
        $runner = $this->scripted(6000.0);

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 600, 320);

        $this->assertNotNull($result);
        $generation = $result['generation'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $generation);
        $this->assertSame($generation, file_get_contents($this->dir . '/out/sprite.gen'));
        $timeline = json_decode((string) file_get_contents($this->dir . '/out/timeline.json'), true);
        $this->assertCount(60, $timeline);
        foreach ($timeline as $entry) {
            $this->assertSame($generation, $entry['generation']);
        }
    }

    public function testTwoPassesNeverShareAGeneration(): void
    {
        $runner = $this->scripted(6000.0);

        $first = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/a', 60, 0, 320);
        $second = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/b', 60, 0, 320);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first['generation'], $second['generation']);
    }

    public function testTheTimelineUsesTheSamplersRoundedInterval(): void
    {
        // 6001 s / 60 is 100.01666... s. The fps filter is given it to three decimals, 100.017,
        // so the timeline labels must use that same value, not the unrounded quotient.
        $runner = $this->scripted(6001.0);

        $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320);

        $timeline = json_decode((string) file_get_contents($this->dir . '/out/timeline.json'), true);
        $this->assertStringContainsString('fps=1/100.017', $runner->commands[0]);
        $this->assertEqualsWithDelta(0.5 * 100.017, $timeline[0]['time'], 1e-9);
        $this->assertEqualsWithDelta(59.5 * 100.017, $timeline[59]['time'], 1e-9);
    }

    public function testTheSpriteWrapperAndTheBifWrapperSkipWhenTheItemLockIsHeld(): void
    {
        // A pass that does not hold the item lock could sweep another pass's files. Both
        // entry points that have no caller holding the lock must take it themselves, and
        // skip without waiting when another pass has it.
        mkdir($this->dir . '/out', 0755, true);
        $held = TrickplayArtefacts::tryLock($this->dir . '/out');
        $this->assertNotNull($held);
        $runner = $this->scripted(6000.0);

        try {
            $this->assertNull($runner->generateTrickplaySprites('/in.mkv', $this->dir . '/out', 60));
            $this->assertNull($runner->generateBifFrames('/in.mkv', $this->dir . '/out', 60, 320));
        } finally {
            TrickplayArtefacts::unlock($held);
        }

        $this->assertSame([], $runner->commands, 'A held lock must stop both wrappers before ffmpeg starts.');
    }

    public function testTheSpriteWrapperReleasesTheLockWhenItsPassEnds(): void
    {
        $runner = $this->scripted(6000.0);

        $this->assertNotNull($runner->generateTrickplaySprites('/in.mkv', $this->dir . '/out', 60));

        $again = TrickplayArtefacts::tryLock($this->dir . '/out');
        $this->assertNotNull($again, 'The wrapper must release the lock when it returns.');
        TrickplayArtefacts::unlock($again);
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

    public function testAStaleTemporarySpriteFromACrashIsNotPublishedByAPassThatWroteNothing(): void
    {
        // A crashed earlier pass left a sprite temporary behind. This pass exits 0 and
        // writes no sprite (the case where -loglevel error hides the reason). The stale
        // temporary must not be renamed into place, and the previous complete sprite must
        // survive the failed pass.
        mkdir($this->dir . '/out', 0755, true);
        file_put_contents($this->dir . '/out/.tmp-sprite.jpg', 'STALE-SPRITE');
        file_put_contents($this->dir . '/out/sprite.jpg', 'PREVIOUS-SPRITE');
        $runner = $this->scripted(6000.0);
        $runner->writeSprite = false;
        $runner->bifFramesToWrite = 0;

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320);

        $this->assertNull($result);
        $this->assertSame('PREVIOUS-SPRITE', file_get_contents($this->dir . '/out/sprite.jpg'));
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testStaleBifFramesFromACrashCannotCompleteAShortPass(): void
    {
        // Ten frames were left by a crashed run that wanted ten, plus one beyond the
        // count from a larger earlier run. This pass writes no frame, so its BIF is
        // short and the pass must fail. None of the stale frames may be counted.
        mkdir($this->dir . '/out', 0755, true);
        for ($i = 1; $i <= 11; $i++) {
            file_put_contents(sprintf($this->dir . '/out/bifframe_%05d.jpg', $i), 'STALE-FRAME');
        }
        $runner = $this->scripted(6000.0);
        $runner->bifFramesToWrite = 0;

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 10, 320);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->dir . '/out/sprite.jpg');
        $this->assertFileDoesNotExist($this->dir . '/out/timeline.json');
        for ($i = 1; $i <= 11; $i++) {
            $this->assertFileDoesNotExist(sprintf($this->dir . '/out/bifframe_%05d.jpg', $i));
        }
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testAFailedTimelinePublishKeepsThePreviousCompleteSprite(): void
    {
        // An item that was complete before this pass has a sprite and a timeline. The
        // timeline's final name is a non-empty directory, so the timeline rename fails
        // after the sprite rename has already happened. The previous sprite must come
        // back, not be lost.
        mkdir($this->dir . '/out/timeline.json', 0755, true);
        file_put_contents($this->dir . '/out/timeline.json/keep', 'x');
        file_put_contents($this->dir . '/out/sprite.jpg', 'PREVIOUS-SPRITE');
        $runner = $this->scripted(6000.0);

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320);

        $this->assertNull($result);
        $this->assertSame('PREVIOUS-SPRITE', file_get_contents($this->dir . '/out/sprite.jpg'));
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testAPassReplacesAnExistingSpriteAndLeavesNoPreviousCopy(): void
    {
        mkdir($this->dir . '/out', 0755, true);
        file_put_contents($this->dir . '/out/sprite.jpg', 'OLD-SPRITE');
        file_put_contents($this->dir . '/out/timeline.json', 'OLD-TIMELINE');
        $runner = $this->scripted(6000.0);

        $result = $runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320);

        $this->assertNotNull($result);
        $this->assertNotSame('OLD-SPRITE', file_get_contents($this->dir . '/out/sprite.jpg'));
        $this->assertNotSame('OLD-TIMELINE', file_get_contents($this->dir . '/out/timeline.json'));
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testAnExistingFinalIsNeverAbsentDuringThePublish(): void
    {
        // The publish replaces an existing final with one rename() over it. The final name must
        // still exist when that rename runs, and no rename may move a final name away.
        mkdir($this->dir . '/out', 0755, true);
        file_put_contents($this->dir . '/out/sprite.gen', 'OLD-GEN');
        file_put_contents($this->dir . '/out/sprite.jpg', 'OLD-SPRITE');
        file_put_contents($this->dir . '/out/timeline.json', 'OLD-TIMELINE');
        $runner = $this->scripted(6000.0);

        $this->assertNotNull($runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320));

        $finals = [$this->dir . '/out/sprite.gen', $this->dir . '/out/sprite.jpg', $this->dir . '/out/timeline.json'];
        $publishes = array_values(array_filter(
            $runner->renames,
            static fn (array $rename): bool => in_array($rename['to'], $finals, true)
        ));
        $this->assertCount(3, $publishes);
        foreach ($publishes as $rename) {
            $this->assertTrue($rename['toExisted'], 'The final must still exist when the temporary is renamed over it.');
        }
        foreach ($runner->renames as $rename) {
            $this->assertNotContains($rename['from'], $finals, 'No rename may move a final name away.');
        }
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testAnExistingFinalIsNeverAbsentDuringThePublishWhenTheLinkIsRefused(): void
    {
        // Where a hard link is refused the previous copy is a plain copy. The final name must
        // still exist when each temporary is renamed over it, and the publish must still succeed.
        mkdir($this->dir . '/out', 0755, true);
        file_put_contents($this->dir . '/out/sprite.gen', 'OLD-GEN');
        file_put_contents($this->dir . '/out/sprite.jpg', 'OLD-SPRITE');
        file_put_contents($this->dir . '/out/timeline.json', 'OLD-TIMELINE');
        $runner = $this->scripted(6000.0);
        $runner->failLink = true;

        $this->assertNotNull($runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320));

        $finals = [$this->dir . '/out/sprite.gen', $this->dir . '/out/sprite.jpg', $this->dir . '/out/timeline.json'];
        $this->assertCount(3, $runner->links, 'Each existing final was offered a hard link before its replacement.');
        $publishes = array_values(array_filter(
            $runner->renames,
            static fn (array $rename): bool => in_array($rename['to'], $finals, true)
        ));
        $this->assertCount(3, $publishes);
        foreach ($publishes as $rename) {
            $this->assertTrue($rename['toExisted'], 'The final must still exist when the temporary is renamed over it.');
        }
        foreach ($runner->renames as $rename) {
            $this->assertNotContains($rename['from'], $finals, 'No rename may move a final name away.');
        }
        $this->assertNotSame('OLD-SPRITE', file_get_contents($this->dir . '/out/sprite.jpg'));
        $this->assertSame([], $this->temporariesIn($this->dir . '/out'));
    }

    public function testARollbackRestoreThatIsRefusedStopsAndNamesTheFile(): void
    {
        // The timeline's rename fails, so the publish rolls back the sprite and the stamp. The
        // rollback restores newest first, so the sprite is restored before the stamp. Only the
        // restore onto sprite.jpg is refused; the stamp's restore would succeed if it were tried.
        // The rollback must stop at the refused sprite: the stamp keeps the new token, the timeline
        // keeps the old one, the single warning names the sprite, and the stamp is never restored.
        mkdir($this->dir . '/out', 0755, true);
        file_put_contents($this->dir . '/out/sprite.gen', 'OLD-GEN');
        file_put_contents($this->dir . '/out/sprite.jpg', 'OLD-SPRITE');
        file_put_contents($this->dir . '/out/timeline.json', 'OLD-TIMELINE');
        $logger = new RecordingLogger();
        $runner = $this->scripted(6000.0, $logger);
        $runner->failRenameFrom = $this->dir . '/out/.tmp-timeline.json';
        $runner->failRestoreTo = $this->dir . '/out/sprite.jpg';

        $this->assertNull($runner->generateTrickplayArtefacts('/in.mkv', $this->dir . '/out', 60, 0, 320));

        $this->assertSame('OLD-TIMELINE', file_get_contents($this->dir . '/out/timeline.json'));
        $this->assertNotSame('OLD-SPRITE', file_get_contents($this->dir . '/out/sprite.jpg'));
        $this->assertNotSame('OLD-GEN', file_get_contents($this->dir . '/out/sprite.gen'));
        $context = $logger->contextOf('could not restore a file');
        $this->assertNotNull($context, 'A refused restore must be logged.');
        $this->assertSame($this->dir . '/out/sprite.jpg', $context['file']);

        $restoreWarnings = array_filter(
            $logger->records,
            static fn (array $record): bool => $record['level'] === 'warning'
                && str_contains($record['message'], 'could not restore a file')
        );
        $this->assertCount(1, $restoreWarnings, 'Exactly one restore warning is logged for the refused sprite.jpg restore.');

        $stampRestores = array_filter(
            $runner->renames,
            static fn (array $rename): bool => basename($rename['from']) === '.tmp-previous-sprite.gen'
        );
        $this->assertSame(
            [],
            $stampRestores,
            'The stamp restore must not be attempted after a refused sprite restore.'
        );
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
