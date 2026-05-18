<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Relay;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\LiveTvManager;
use Phlex\LiveTv\Relay\HlsRelayManager;
use Phlex\LiveTv\Relay\HlsRelaySession;
use Phlex\LiveTv\Relay\HlsSegmentPrefetcher;
use Phlex\Media\Streaming\HlsStreamer;
use Phlex\Common\Logger\StructuredLogger;

/**
 * Stub RelayConsumer for testing since RelayConsumer is final.
 *
 * @since 0.12.0
 */
class StubRelayConsumer
{
    public array $registeredMounts = [];

    public function registerMount(string $pathPrefix, callable $handler): void
    {
        $this->registeredMounts[$pathPrefix] = $handler;
    }

    public function unregisterMount(string $pathPrefix): void
    {
        unset($this->registeredMounts[$pathPrefix]);
    }
}

/**
 * Unit tests for HlsRelayManager.
 *
 * @since 0.12.0
 */
class HlsRelayManagerTest extends TestCase
{
    private HlsRelayManager $manager;
    private $mockDb;
    private $mockLiveTvManager;
    private $mockHlsStreamer;
    private StubRelayConsumer $stubRelayConsumer;
    private $mockLogger;
    private HlsSegmentPrefetcher $segmentPrefetcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(\Workerman\MySQL\Connection::class);
        $this->mockLiveTvManager = $this->createMock(LiveTvManager::class);
        $this->mockHlsStreamer = $this->createMock(HlsStreamer::class);
        $this->stubRelayConsumer = new StubRelayConsumer();
        $this->mockLogger = $this->createMock(StructuredLogger::class);

        $this->segmentPrefetcher = new HlsSegmentPrefetcher(null, 3, 10485760, 30);

        $this->manager = new HlsRelayManager(
            $this->mockLiveTvManager,
            $this->mockHlsStreamer,
            $this->stubRelayConsumer,
            $this->mockDb,
            $this->segmentPrefetcher,
            null, // logger - use null for simpler testing
            '/relay/live',
            10
        );
    }

    /**
     * Check if Workerman Timer is available.
     *
     * @return bool True if Timer can be used.
     */
    private function isTimerAvailable(): bool
    {
        try {
            \Workerman\Timer::add(1, function () {}, [], false);
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function testCanCreateManager(): void
    {
        $this->assertInstanceOf(HlsRelayManager::class, $this->manager);
    }

    /**
     * @group workerman
     */
    public function testStartRelaySessionCreatesTuneRequest(): void
    {
        // Skip if Workerman Timer not available
        if (!$this->isTimerAvailable()) {
            $this->markTestSkipped('Workerman Timer not available in this environment');
        }

        $channelId = 'channel-123';
        $userId = 'user-456';

        $tuneResult = [
            'id' => 'tune-request-789',
            'channel_id' => $channelId,
            'tuner_id' => 'tuner-1',
            'started_at' => time(),
            'stream_url' => '/livetv/tune-request-789/stream',
        ];

        // getActiveSessions is called first to check max sessions
        $this->mockDb
            ->method('query')
            ->willReturn([]); // Empty sessions list

        $this->mockLiveTvManager
            ->expects($this->once())
            ->method('tuneToChannel')
            ->with($channelId)
            ->willReturn($tuneResult);

        $this->mockHlsStreamer
            ->expects($this->once())
            ->method('getVariantPlaylistUrl')
            ->willReturn('/hls/test/stream_0.m3u8');

        $session = $this->manager->startRelaySession($channelId, $userId);

        $this->assertInstanceOf(HlsRelaySession::class, $session);
        $this->assertEquals($channelId, $session->getChannelId());
        $this->assertEquals($tuneResult['id'], $session->getTuneRequestId());
    }

    /**
     * @group workerman
     */
    public function testStartRelaySessionStoresInDb(): void
    {
        if (!$this->isTimerAvailable()) {
            $this->markTestSkipped('Workerman Timer not available in this environment');
        }

        $channelId = 'channel-abc';
        $userId = 'user-xyz';

        $tuneResult = [
            'id' => 'tune-123',
            'channel_id' => $channelId,
            'tuner_id' => 'tuner-1',
            'started_at' => time(),
            'stream_url' => '/livetv/tune-123/stream',
        ];

        $this->mockDb
            ->method('query')
            ->willReturn([]); // Empty sessions list for getActiveSessions

        $this->mockLiveTvManager
            ->method('tuneToChannel')
            ->willReturn($tuneResult);

        $this->mockHlsStreamer
            ->method('getVariantPlaylistUrl')
            ->willReturn('/hls/test/stream_0.m3u8');

        $session = $this->manager->startRelaySession($channelId, $userId);

        $this->assertInstanceOf(HlsRelaySession::class, $session);
    }

    public function testStartRelaySessionThrowsWhenMaxSessionsReached(): void
    {
        $channelId = 'channel-123';
        $userId = 'user-456';

        // Return existing sessions up to max
        $existingSessions = array_fill(0, 10, ['session_id' => 'session-1']);
        $this->mockDb
            ->method('query')
            ->willReturn($existingSessions);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Maximum concurrent relay sessions reached');

        $this->manager->startRelaySession($channelId, $userId);
    }

    public function testStopRelaySessionReleasesTuner(): void
    {
        $sessionId = 'session-abc-123';

        // Return session data on SELECT query
        $this->mockDb
            ->method('query')
            ->willReturnCallback(function ($sql) use ($sessionId) {
                if (str_contains($sql, 'SELECT')) {
                    return [[
                        'session_id' => $sessionId,
                        'user_id' => 'user-1',
                        'channel_id' => 'channel-1',
                        'tune_request_id' => 'tune-1',
                        'mount_url' => '/relay/live/' . $sessionId . '/playlist.m3u8',
                        'started_at' => date('Y-m-d H:i:s'),
                        'last_activity_at' => date('Y-m-d H:i:s'),
                        'bytes_relayed' => 0,
                    ]];
                }
                return [];
            });

        $this->mockLiveTvManager
            ->expects($this->once())
            ->method('stopTuning')
            ->with('tune-1');

        $this->manager->stopRelaySession($sessionId);
    }

    public function testStopRelaySessionDoesNothingForNonexistentSession(): void
    {
        $sessionId = 'nonexistent-session';

        // Returns empty array to simulate no session found
        $this->mockDb
            ->method('query')
            ->willReturn([]);

        // Should not call stopTuning since session doesn't exist
        $this->mockLiveTvManager
            ->expects($this->never())
            ->method('stopTuning');

        $this->manager->stopRelaySession($sessionId);

        // If we get here without error, test passes
        $this->assertTrue(true);
    }

    public function testGetActiveSessions(): void
    {
        $expectedSessions = [
            ['session_id' => 'session-1', 'user_id' => 'user-1'],
            ['session_id' => 'session-2', 'user_id' => 'user-2'],
        ];

        $this->mockDb
            ->expects($this->once())
            ->method('query')
            ->with($this->stringContains('SELECT * FROM livetv_relay_sessions'))
            ->willReturn($expectedSessions);

        $sessions = $this->manager->getActiveSessions();

        $this->assertCount(2, $sessions);
        $this->assertEquals($expectedSessions, $sessions);
    }

    public function testGetUserSessionReturnsActiveSession(): void
    {
        $userId = 'user-abc-123';
        $expectedSession = [
            'session_id' => 'session-xyz',
            'user_id' => $userId,
            'channel_id' => 'channel-1',
            'tune_request_id' => 'tune-1',
            'started_at' => '2024-01-01 12:00:00',
            'last_activity_at' => '2024-01-01 12:30:00',
            'bytes_relayed' => 1024,
        ];

        $this->mockDb
            ->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('WHERE user_id = ?'),
                [$userId]
            )
            ->willReturn([$expectedSession]);

        $session = $this->manager->getUserSession($userId);

        $this->assertInstanceOf(HlsRelaySession::class, $session);
        $this->assertEquals('session-xyz', $session->getSessionId());
    }

    public function testGetUserSessionReturnsNullWhenNoSession(): void
    {
        $userId = 'user-with-no-session';

        $this->mockDb
            ->expects($this->once())
            ->method('query')
            ->willReturn([]);

        $session = $this->manager->getUserSession($userId);

        $this->assertNull($session);
    }

    public function testGetUserSessionReturnsNullWhenMultipleSessions(): void
    {
        $userId = 'user-multi';

        // Return only the most recent (limit 1 in query)
        $this->mockDb
            ->method('query')
            ->willReturn([]);

        $session = $this->manager->getUserSession($userId);

        $this->assertNull($session);
    }

    public function testGetSegmentPrefetcher(): void
    {
        $prefetcher = $this->manager->getSegmentPrefetcher();

        $this->assertSame($this->segmentPrefetcher, $prefetcher);
    }
}
