<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Media\Transcoding\TranscodeManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * Finding 3. A failed Workerman `Timer::add()` must not latch the watchdog. The timer id stays
 * null, so the next `watchdogTick()` (run on every launch decision) tries again. The old code set
 * the id to 0 and never retried.
 *
 * Outside a Workerman runtime `Timer::add()` throws, which is the failure this test exercises.
 */
final class TranscodeManagerWatchdogArmTest extends TestCase
{
    private string $segmentDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->segmentDir = sys_get_temp_dir() . '/phlix_tmwarm_' . uniqid('', true);
        mkdir($this->segmentDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->segmentDir);
        parent::tearDown();
    }

    private function manager(): TranscodeManager
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeTimeout')->willReturn(7200);

        return new TranscodeManager(
            $db,
            $ffmpeg,
            $this->segmentDir,
            null,
            6,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }

    public function testAFailedTimerArmIsNotLatchedSoTheNextTickRetries(): void
    {
        $manager = $this->manager();
        $manager->setSegmentProcessRegistry(new SegmentProcessRegistry());

        $tick = new ReflectionMethod(TranscodeManager::class, 'watchdogTick');
        $tick->setAccessible(true);
        $timerId = new ReflectionProperty(TranscodeManager::class, 'watchdogTimerId');
        $timerId->setAccessible(true);

        $tick->invoke($manager);
        self::assertNull($timerId->getValue($manager), 'a failed arm must not latch the 0 sentinel');

        $tick->invoke($manager);
        self::assertNull($timerId->getValue($manager), 'the next tick retries, and still fails outside Workerman');
    }
}
