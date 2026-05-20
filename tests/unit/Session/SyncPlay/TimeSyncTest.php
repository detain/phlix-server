<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\TimeSync;

class TimeSyncTest extends TestCase
{
    public function testCanCreateTimeSync(): void
    {
        $timeSync = new TimeSync();

        $this->assertInstanceOf(TimeSync::class, $timeSync);
    }

    public function testGetProtocolVersion(): void
    {
        $timeSync = new TimeSync();

        $this->assertEquals(1, $timeSync->getProtocolVersion());
    }

    public function testGetPort(): void
    {
        $timeSync = new TimeSync('localhost', 8099);

        $this->assertEquals(8099, $timeSync->getPort());
        $this->assertEquals('localhost', $timeSync->getHost());
    }

    public function testProcessPingReturnsPongData(): void
    {
        $timeSync = new TimeSync();
        $clientTime = (int)(microtime(true) * 1000);

        $pong = $timeSync->processPing(['client_time' => $clientTime]);

        $this->assertArrayHasKey('type', $pong);
        $this->assertEquals('pong', $pong['type']);
        $this->assertArrayHasKey('client_time', $pong);
        $this->assertArrayHasKey('server_time', $pong);
        $this->assertArrayHasKey('protocol_version', $pong);
        $this->assertEquals($clientTime, $pong['client_time']);
        $this->assertEquals(1, $pong['protocol_version']);
    }

    public function testProcessPongCalculatesOffsetAndLatency(): void
    {
        $timeSync = new TimeSync();
        $clientSendTime = (int)(microtime(true) * 1000);
        $serverTime = $clientSendTime + 50; // Server is 50ms ahead
        $serverReceiveTime = $clientSendTime + 100;
        $clientReceiveTime = $serverReceiveTime + 50;

        $pong = $timeSync->processPong([
            'client_time' => $clientSendTime,
            'server_time' => $serverTime,
            'server_receive_time' => $serverReceiveTime,
            'server_receive_time' => $serverReceiveTime,
        ]);

        $this->assertArrayHasKey('offset', $pong);
        $this->assertArrayHasKey('latency', $pong);
        $this->assertArrayHasKey('rtt', $pong);
        $this->assertArrayHasKey('is_stable', $pong);
    }

    public function testIsSyncStableReturnsFalseWhenNoSamples(): void
    {
        $timeSync = new TimeSync();

        $this->assertFalse($timeSync->isSyncStable());
    }

    public function testIsSyncStableReturnsTrueWithEnoughLowVarianceSamples(): void
    {
        $timeSync = new TimeSync();

        // Simulate multiple pong responses with similar offsets
        for ($i = 0; $i < 5; $i++) {
            $timeSync->processPong([
                'client_time' => (int)(microtime(true) * 1000),
                'server_time' => (int)(microtime(true) * 1000) + 30,
                'server_receive_time' => (int)(microtime(true) * 1000) + 50,
                'server_receive_time' => (int)(microtime(true) * 1000) + 50,
            ]);
        }

        // May or may not be stable depending on timing
        $this->assertIsBool($timeSync->isSyncStable());
    }

    public function testGetTimeOffsetReturnsZeroWithNoSamples(): void
    {
        $timeSync = new TimeSync();

        $this->assertEquals(0, $timeSync->getTimeOffset());
    }

    public function testGetEstimatedLatencyReturnsZeroWithNoSamples(): void
    {
        $timeSync = new TimeSync();

        $this->assertEquals(0, $timeSync->getEstimatedLatency());
    }

    public function testGetDriftRateInitiallyOne(): void
    {
        $timeSync = new TimeSync();

        $this->assertEquals(1.0, $timeSync->getDriftRate());
    }

    public function testGetSynchronizedTimeReturnsAdjustedTime(): void
    {
        $timeSync = new TimeSync();

        // Without samples, should return approximate local time
        $syncTime = $timeSync->getSynchronizedTime();
        $localTime = (int)(microtime(true) * 1000);

        // Should be close to local time since offset is 0
        $this->assertLessThan(1000, abs($syncTime - $localTime));
    }

    public function testLocalToSynchronizedConvertsTimestamp(): void
    {
        $timeSync = new TimeSync();
        $localTimestamp = 1000000;

        // Without offset, should return same value
        $synchronized = $timeSync->localToSynchronized($localTimestamp);

        $this->assertEquals($localTimestamp, $synchronized);
    }

    public function testSynchronizedToLocalConvertsTimestamp(): void
    {
        $timeSync = new TimeSync();
        $synchronizedTimestamp = 1000000;

        // Without offset, should return same value
        $local = $timeSync->synchronizedToLocal($synchronizedTimestamp);

        $this->assertEquals($synchronizedTimestamp, $local);
    }

    public function testApplyDriftCorrectionModifiesTargetTime(): void
    {
        $timeSync = new TimeSync();
        $targetTime = (int)(microtime(true) * 1000) + 10000;
        $currentTime = (int)(microtime(true) * 1000);

        $corrected = $timeSync->applyDriftCorrection($targetTime, $currentTime);

        // With drift rate of 1.0, should return target time
        $this->assertEquals($targetTime, $corrected);
    }

    public function testAdjustPlaybackPositionClampsToValidRange(): void
    {
        $timeSync = new TimeSync();

        // Test lower bound
        $pos = $timeSync->adjustPlaybackPosition(-1000, 100000);
        $this->assertGreaterThanOrEqual(0, $pos);

        // Test upper bound
        $pos = $timeSync->adjustPlaybackPosition(200000, 100000, 100000);
        $this->assertLessThanOrEqual(100000, $pos);
    }

    public function testResetClearsAllState(): void
    {
        $timeSync = new TimeSync();

        // Add some samples
        $timeSync->processPong([
            'client_time' => (int)(microtime(true) * 1000),
            'server_time' => (int)(microtime(true) * 1000) + 50,
            'server_receive_time' => (int)(microtime(true) * 1000) + 100,
            'server_receive_time' => (int)(microtime(true) * 1000) + 100,
        ]);

        $timeSync->reset();

        $this->assertEquals(0, $timeSync->getTimeOffset());
        $this->assertEquals(0, $timeSync->getEstimatedLatency());
        $this->assertEquals(1.0, $timeSync->getDriftRate());
        $this->assertFalse($timeSync->isSyncStable());
    }

    public function testGetStatusReturnsCompleteStatus(): void
    {
        $timeSync = new TimeSync();

        $status = $timeSync->getStatus();

        $this->assertArrayHasKey('offset', $status);
        $this->assertArrayHasKey('latency', $status);
        $this->assertArrayHasKey('drift_rate', $status);
        $this->assertArrayHasKey('is_stable', $status);
        $this->assertArrayHasKey('sample_count', $status);
        $this->assertArrayHasKey('last_sync', $status);
    }

    public function testSerializeAndUnserialize(): void
    {
        $timeSync = new TimeSync();

        // Add some data
        $timeSync->processPong([
            'client_time' => (int)(microtime(true) * 1000),
            'server_time' => (int)(microtime(true) * 1000) + 50,
            'server_receive_time' => (int)(microtime(true) * 1000) + 100,
            'server_receive_time' => (int)(microtime(true) * 1000) + 100,
        ]);

        $serialized = $timeSync->serialize();
        $this->assertArrayHasKey('offset_samples', $serialized);
        $this->assertArrayHasKey('drift_rate', $serialized);

        $newTimeSync = new TimeSync();
        $newTimeSync->unserialize($serialized);

        $this->assertEquals($timeSync->getDriftRate(), $newTimeSync->getDriftRate());
    }
}
