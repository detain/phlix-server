<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\MediaAsset;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MediaAsset\MediaAssetGenerationJob;
use Phlix\Media\MediaAsset\MediaAssetJob;
use Phlix\Media\Transcoding\FfmpegRunner;
use Workerman\MySQL\Connection;

/**
 * Consequence tests for the `trickplay.enabled` setting.
 *
 * ## The defect
 *
 * `trickplay.enabled` shipped in server-settings.schema.json and had **no reader
 * anywhere**. An operator on production had set it to `0`; sprites kept being
 * generated. It resolved to a real default in `config/trickplay.php`, so
 * `SettingsDefaultResolvabilityTest` passed it and always would.
 *
 * The reason it was hard to spot: there used to be TWO trickplay
 * implementations. `config/trickplay.php` described the older one
 * (`TrickplayGenerator` + `TrickplayConfig`, with
 * `interval_seconds`/`grid_*`/`thumb_*`), which was dead code — its only entry
 * point, `StreamManager::generateTrickplay()`, threw unless
 * `StreamManager::setTrickplay()` ran, and that setter had no callers. S275
 * confirmed that at runtime (a pcov trace over a full media-asset run never even
 * autoloaded the class) and deleted the whole older path, along with the config
 * keys that only described it. The implementation that actually runs is this
 * one, reached from `MediaAssetWorker`, and it never consulted any setting.
 *
 * These assertions check the OBSERVABLE EFFECT — whether FFmpeg is asked to
 * produce sprites — never that a flag was read.
 */
class TrickplayEnabledGateTest extends TestCase
{
    /**
     * @param bool|null $effective Value returned by getEffective('trickplay.enabled');
     *        null means "no SettingsRepository supplied at all".
     */
    private function makeJob(?bool $effective, FfmpegRunner $ffmpeg): MediaAssetGenerationJob
    {
        $settings = null;
        if ($effective !== null) {
            $settings = $this->createMock(SettingsRepository::class);
            $settings->method('getEffective')->willReturn($effective);
        }

        return new MediaAssetGenerationJob(
            $ffmpeg,
            $this->createMock(ItemRepository::class),
            $this->createMock(Connection::class),
            null,
            $settings,
        );
    }

    /**
     * Invoke the private generateTrickplaySprites() for one job.
     */
    private function invoke(MediaAssetGenerationJob $job, MediaAssetJob $assetJob): bool
    {
        $ref = new \ReflectionClass(MediaAssetGenerationJob::class);
        $method = $ref->getMethod('generateTrickplaySprites');
        $method->setAccessible(true);

        /** @var bool $result */
        $result = $method->invoke($job, $assetJob);

        return $result;
    }

    private function assetJob(): MediaAssetJob
    {
        return new MediaAssetJob('item-1', '/media/movie.mkv', 3600);
    }

    /**
     * CONSEQUENCE: with the setting off, FFmpeg must never be asked to generate
     * sprites.
     *
     * Mutation-verified: removing the `trickplayEnabled()` guard from
     * generateTrickplaySprites() fails this test.
     */
    public function test_disabled_setting_prevents_sprite_generation(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('generateTrickplaySprites');

        $job = $this->makeJob(false, $ffmpeg);

        $this->assertTrue(
            $this->invoke($job, $this->assetJob()),
            'A disabled trickplay setting is a successful no-op, not a failure — '
            . 'process() ANDs this with the chapter result, so returning false '
            . 'would mark every media-asset job failed.'
        );
    }

    /**
     * CONSEQUENCE: with the setting on, generation proceeds as before.
     */
    public function test_enabled_setting_allows_sprite_generation(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn(sys_get_temp_dir() . '/phlix-trickplay-test');
        $ffmpeg->expects($this->once())
            ->method('generateTrickplaySprites')
            ->willReturn(null);

        $job = $this->makeJob(true, $ffmpeg);

        $this->invoke($job, $this->assetJob());
    }

    /**
     * Backward compatibility: with no SettingsRepository the job behaves exactly
     * as it did before the gate existed.
     */
    public function test_absent_settings_repository_defaults_to_enabled(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn(sys_get_temp_dir() . '/phlix-trickplay-test');
        $ffmpeg->expects($this->once())
            ->method('generateTrickplaySprites')
            ->willReturn(null);

        $job = $this->makeJob(null, $ffmpeg);

        $this->invoke($job, $this->assetJob());
    }

    /**
     * A settings-store failure must not silently stop asset generation — the
     * fail-safe direction is "keep generating".
     */
    public function test_settings_failure_defaults_to_enabled(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db down'));

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn(sys_get_temp_dir() . '/phlix-trickplay-test');
        $ffmpeg->expects($this->once())
            ->method('generateTrickplaySprites')
            ->willReturn(null);

        $job = new MediaAssetGenerationJob(
            $ffmpeg,
            $this->createMock(ItemRepository::class),
            $this->createMock(Connection::class),
            null,
            $settings,
        );

        $this->invoke($job, $this->assetJob());
    }
}
