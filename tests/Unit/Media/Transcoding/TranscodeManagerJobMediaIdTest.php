<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Coverage for {@see TranscodeManager::getJobMediaItemId()} — the job → media
 * item mapping the parental ACCESS gate uses at status/serve time (Finding 1) to
 * re-check a transcode job's effective content rating.
 */
class TranscodeManagerJobMediaIdTest extends TestCase
{
    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_tmjm_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->segmentDir)) {
            @rmdir($this->segmentDir);
        }
    }

    public function testResolvesMediaItemIdFromRow(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$captured): array {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, 'SELECT media_item_id FROM transcode_jobs WHERE id = ?')) {
                    return [['media_item_id' => 'media-42']];
                }
                return [];
            }
        );
        $manager = new TranscodeManager($db, $this->createMock(FfmpegRunner::class), $this->segmentDir, null, 6);

        $this->assertSame('media-42', $manager->getJobMediaItemId('job-1'));
        // The lookup is parameterized (the job id is bound, not interpolated).
        $this->assertSame(['job-1'], $captured[0][1]);
    }

    public function testNullForUnknownOrEmptyJob(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $manager = new TranscodeManager($db, $this->createMock(FfmpegRunner::class), $this->segmentDir, null, 6);

        $this->assertNull($manager->getJobMediaItemId('nope'));
        // An empty job id short-circuits without a query.
        $this->assertNull($manager->getJobMediaItemId(''));
    }
}
