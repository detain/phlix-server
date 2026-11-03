<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\ChannelManager;
use Phlix\LiveTv\GuideManager;
use Phlix\LiveTv\LiveTvManager;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunDiscovery;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunApiClient;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunTunerDriver;
use Phlix\LiveTv\Tuners\TunerDriverInterface;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\MockObject\MockObject;
use Workerman\MySQL\Connection;

class LiveTvManagerTest extends TestCase
{
    private LiveTvManager $manager;
    /** @var Connection&MockObject */
    private $mockDb;
    /** @var ChannelManager&MockObject */
    private $mockChannelManager;
    /** @var GuideManager&MockObject */
    private $mockGuideManager;
    /** @var Recorder&MockObject */
    private $mockRecorder;
    /** @var StructuredLogger&MockObject */
    private $mockLogger;
    private TunerDriverInterface $tunerDriver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $this->mockChannelManager = $this->createMock(ChannelManager::class);
        $this->mockGuideManager = $this->createMock(GuideManager::class);
        $this->mockRecorder = $this->createMock(Recorder::class);
        $this->mockLogger = $this->createMock(StructuredLogger::class);

        // Create real HDHomeRun driver for testing
        $discovery = new HdHomeRunDiscovery(null, 1);
        $apiClient = new HdHomeRunApiClient('http://127.0.0.1');
        $this->tunerDriver = new HdHomeRunTunerDriver($discovery, $apiClient, null);

        $this->manager = new LiveTvManager(
            $this->mockDb,
            $this->mockChannelManager,
            $this->mockGuideManager,
            $this->mockRecorder,
            $this->tunerDriver,
            $this->mockLogger
        );
    }

    public function testCanCreateLiveTvManager(): void
    {
        $this->assertInstanceOf(LiveTvManager::class, $this->manager);
    }

    public function testGetTunerReturnsNullForNonexistent(): void
    {
        $tuner = $this->manager->getTuner('nonexistent_tuner');
        $this->assertNull($tuner);
    }

    public function testGetTunersReturnsArray(): void
    {
        $tuners = $this->manager->getTuners();
        $this->assertEmpty($tuners);
    }

    public function testTuneToChannelThrowsOnNonexistentChannel(): void
    {
        $this->mockChannelManager->method('getChannel')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Channel not found');

        $this->manager->tuneToChannel('nonexistent_channel');
    }

    public function testTuneToChannelThrowsWhenNoTunerAvailable(): void
    {
        $this->mockChannelManager->method('getChannel')
            ->willReturn(['id' => 'channel_1', 'name' => 'Test Channel']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No available tuner');

        $this->manager->tuneToChannel('channel_1');
    }

    public function testGetTuneRequestReturnsNullForNonexistent(): void
    {
        $request = $this->manager->getTuneRequest('nonexistent_request');
        $this->assertNull($request);
    }

    public function testGetActiveTuneRequestsReturnsArray(): void
    {
        $requests = $this->manager->getActiveTuneRequests();
        $this->assertEmpty($requests);
    }

    public function testStopTuningDoesNotThrow(): void
    {
        // Should not throw even for non-existent tune request
        $this->manager->stopTuning('nonexistent_request');
        $this->assertEmpty($this->manager->getActiveTuneRequests());
    }

    public function testGetChannelManagerReturnsChannelManager(): void
    {
        $channelManager = $this->manager->getChannelManager();
        $this->assertSame($this->mockChannelManager, $channelManager);
    }

    public function testGetGuideManagerReturnsGuideManager(): void
    {
        $guideManager = $this->manager->getGuideManager();
        $this->assertSame($this->mockGuideManager, $guideManager);
    }

    public function testGetRecorderReturnsRecorder(): void
    {
        $recorder = $this->manager->getRecorder();
        $this->assertSame($this->mockRecorder, $recorder);
    }

    public function testTunerStatusConstants(): void
    {
        $this->assertEquals('idle', LiveTvManager::TUNER_STATUS_IDLE);
        $this->assertEquals('scanning', LiveTvManager::TUNER_STATUS_SCANNING);
        $this->assertEquals('tuning', LiveTvManager::TUNER_STATUS_TUNING);
        $this->assertEquals('streaming', LiveTvManager::TUNER_STATUS_STREAMING);
        $this->assertEquals('error', LiveTvManager::TUNER_STATUS_ERROR);
    }

    public function testTunerTypeConstants(): void
    {
        $this->assertEquals('dvb_t', LiveTvManager::TUNER_TYPE_DVB_T);
        $this->assertEquals('dvb_s', LiveTvManager::TUNER_TYPE_DVB_S);
        $this->assertEquals('dvb_c', LiveTvManager::TUNER_TYPE_DVB_C);
        $this->assertEquals('atsc', LiveTvManager::TUNER_TYPE_ATSC);
        $this->assertEquals('hdhomerun', LiveTvManager::TUNER_TYPE_HDHOMERUN);
    }

    /**
     * Seed the private tuner cache with one entry and return it.
     *
     * @param array<string, mixed> $entry
     */
    private function seedTunerCache(array $entry): void
    {
        $cache = new \ReflectionProperty($this->manager, 'tuners');
        $cache->setAccessible(true);
        $cache->setValue($this->manager, [$entry['id'] => $entry]);
    }

    public function testUpdateTunerWritesAllowedColumnsAndSyncsCacheName(): void
    {
        // M11 (srphp major 5): the tuner-table mutation semantics live in the
        // manager — this is the domain test the inline-controller SQL made
        // impossible. One UPDATE over the allow-list, cache re-flavoured for
        // the re-read, and NO shape change to the cached tuner object.
        $this->seedTunerCache([
            'id' => 'hdhr_1',
            'name' => 'HDHomeRun (1234)',
            'type' => 'hdhomerun',
            'status' => 'idle',
        ]);

        $this->mockDb->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains(
                    'UPDATE livetv_tuners SET name = ?, enabled = ?, '
                    . 'updated_at = NOW() WHERE tuner_id = ?'
                ),
                $this->equalTo(['Living Room', 1, 'hdhr_1'])
            )
            ->willReturn(1);

        // @psalm-suppress InvalidArgument: the extra 'evil' key is DELIBERATE —
        // this probe proves the historical allow-list ignores unknown fields.
        // The strict param shape is exactly what makes the literal a shape
        // violation; the suppression documents that the mismatch is the test
        // (same pattern as AuthMethodPolicyTest's malformed-union pin).
        /** @psalm-suppress InvalidArgument */
        $this->assertTrue($this->manager->updateTuner('hdhr_1', [
            'name' => 'Living Room',
            'enabled' => true,
            'evil' => 'DROP TABLE',
        ]));

        $tuner = $this->manager->getTuner('hdhr_1');
        $this->assertIsArray($tuner);
        $this->assertSame('Living Room', $tuner['name']);
        // The served tuner object must not gain keys the endpoint never had.
        $this->assertArrayNotHasKey('enabled', $tuner);
    }

    public function testUpdateTunerWithNothingWritableSkipsTheQuery(): void
    {
        // Historical allow-list kept exactly: a mistyped name and an explicit
        // null enabled (isset semantics) write nothing — and issue no query.
        $this->seedTunerCache(['id' => 'hdhr_1', 'name' => 'Old', 'type' => 'hdhomerun']);

        $this->mockDb->expects($this->never())->method('query');

        $this->assertFalse($this->manager->updateTuner('hdhr_1', [
            'name' => 12345,
            'enabled' => null,
        ]));
        $this->assertFalse($this->manager->updateTuner('hdhr_1', []));

        $tuner = $this->manager->getTuner('hdhr_1');
        $this->assertIsArray($tuner);
        $this->assertSame('Old', $tuner['name']);
    }

    public function testDeleteTunerRemovesRowAndCacheEntry(): void
    {
        $this->seedTunerCache(['id' => 'iptv_x', 'name' => 'IPTV', 'type' => 'iptv']);

        $this->mockDb->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM livetv_tuners WHERE tuner_id = ?'),
                $this->equalTo(['iptv_x'])
            )
            ->willReturn(1);

        $this->manager->deleteTuner('iptv_x');

        $this->assertNull($this->manager->getTuner('iptv_x'));
    }
}
