<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\ChannelManager;
use Phlex\LiveTv\GuideManager;
use Phlex\LiveTv\LiveTvManager;
use Phlex\LiveTv\Recorder;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunDiscovery;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunApiClient;
use Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunTunerDriver;
use Phlex\LiveTv\Tuners\TunerDriverInterface;
use Phlex\Common\Logger\StructuredLogger;

class LiveTvManagerTest extends TestCase
{
    private LiveTvManager $manager;
    private $mockDb;
    private $mockChannelManager;
    private $mockGuideManager;
    private $mockRecorder;
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
        $this->assertIsArray($tuners);
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
        $this->assertIsArray($requests);
    }

    public function testStopTuningDoesNotThrow(): void
    {
        // Should not throw even for non-existent tune request
        $this->manager->stopTuning('nonexistent_request');
        $this->assertTrue(true);
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
}
