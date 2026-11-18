<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Access;

use Phlix\Access\StreamSessionService;
use Phlix\Admin\SettingsRepository;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * #6a — the request-path heartbeat is throttled per session in-process.
 *
 * Every segment request of an active stream used to issue a heartbeat UPDATE. The
 * throttle caps that at one write per 15 s per session per process. These cases pin
 * the write count inside and after the window, the release reset, the memo bound
 * and the logged fallback for an unreadable default-streams setting.
 */
final class StreamSessionHeartbeatThrottleTest extends TestCase
{
    /** Heartbeat UPDATEs the scripted connection has seen. */
    private int $heartbeatWrites = 0;

    /**
     * Connection where the session row already exists (the idempotent SELECT hit).
     */
    private function existingRowService(): StreamSessionService
    {
        $this->heartbeatWrites = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql): mixed {
                if (str_starts_with($sql, 'SELECT 1 FROM active_streams')) {
                    return [['1' => '1']];
                }
                if (str_starts_with($sql, 'UPDATE active_streams')) {
                    $this->heartbeatWrites++;
                    return 1;
                }
                if (str_starts_with($sql, 'DELETE FROM active_streams')) {
                    return 1;
                }
                return [];
            }
        );

        return new StreamSessionService($db);
    }

    /** Age one session's throttle slot past the window, as if 16 s had passed. */
    private function ageMemo(StreamSessionService $service, string $sessionId, int $ageNs): void
    {
        $memo = new ReflectionProperty(StreamSessionService::class, 'lastHeartbeatNs');
        /** @var array<string, int> $slots */
        $slots = $memo->getValue($service);
        $slots[$sessionId] = hrtime(true) - $ageNs;
        $memo->setValue($service, $slots);
    }

    public function test_a_burst_of_requests_for_one_session_writes_the_heartbeat_once(): void
    {
        $service = $this->existingRowService();

        for ($i = 0; $i < 25; $i++) {
            self::assertTrue($service->registerStream('p-1', 'dev-1', 'sess-1'));
        }

        self::assertSame(1, $this->heartbeatWrites, 'the 25 segment requests must share one heartbeat write');
    }

    public function test_the_heartbeat_is_written_again_once_the_window_has_passed(): void
    {
        $service = $this->existingRowService();
        $service->registerStream('p-1', 'dev-1', 'sess-1');
        self::assertSame(1, $this->heartbeatWrites);

        // 16 s is past the 15 s throttle window.
        $this->ageMemo($service, 'sess-1', 16_000_000_000);
        $service->registerStream('p-1', 'dev-1', 'sess-1');

        self::assertSame(2, $this->heartbeatWrites);
    }

    public function test_sessions_are_throttled_independently(): void
    {
        $service = $this->existingRowService();

        $service->registerStream('p-1', 'dev-1', 'sess-a');
        $service->registerStream('p-1', 'dev-2', 'sess-b');

        self::assertSame(2, $this->heartbeatWrites, 'a second session must not be starved by the first');
    }

    public function test_releasing_a_stream_clears_its_throttle_slot(): void
    {
        $service = $this->existingRowService();
        $service->registerStream('p-1', 'dev-1', 'sess-1');
        self::assertSame(1, $this->heartbeatWrites);

        $service->releaseStream('sess-1');
        $service->registerStream('p-1', 'dev-1', 'sess-1');

        self::assertSame(2, $this->heartbeatWrites, 'a released-and-restarted session must heartbeat immediately');
    }

    public function test_the_throttle_memo_is_bounded_and_prunes_aged_entries(): void
    {
        $service = $this->existingRowService();

        // Fill the memo past its cap with slots that have all aged out.
        $memo = new ReflectionProperty(StreamSessionService::class, 'lastHeartbeatNs');
        $stale = [];
        for ($i = 0; $i < 1100; $i++) {
            $stale['old-' . $i] = hrtime(true) - 60_000_000_000;
        }
        $memo->setValue($service, $stale);

        $service->registerStream('p-1', 'dev-1', 'sess-new');

        /** @var array<string, int> $after */
        $after = $memo->getValue($service);
        self::assertCount(1, $after, 'aged-out slots are pruned; only the live session remains');
        self::assertArrayHasKey('sess-new', $after);
    }

    public function test_an_unreadable_default_streams_setting_falls_back_to_the_shipped_default(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('settings store down'));

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $service = new StreamSessionService($db, $settings);

        self::assertSame(StreamSessionService::DEFAULT_CONCURRENT_STREAMS, $service->defaultConcurrentStreams());
    }
}
