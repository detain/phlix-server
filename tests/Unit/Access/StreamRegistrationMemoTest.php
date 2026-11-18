<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Access;

use Phlix\Access\StreamSessionService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * Item A — the per-instance registration memo ({@see StreamSessionService::$registeredNs}).
 *
 * A memo hit inside the heartbeat window skips the existence SELECT. These cases pin
 * that a release clears the entry (so the next registration runs the SELECT again),
 * that an entry expires after the 15 s heartbeat window (driven by the injected clock),
 * and that the memo stays within its 4096-entry cap, evicting the least-recently-USED
 * entry (a hit counts as a use).
 */
final class StreamRegistrationMemoTest extends TestCase
{
    /** Existence SELECTs issued against active_streams. */
    private int $selects = 0;

    /**
     * A connection where every session row already exists.
     *
     * @param \Closure|null $clock Injected monotonic clock (ns); null uses hrtime(true).
     */
    private function service(?\Closure $clock = null): StreamSessionService
    {
        $this->selects = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql): mixed {
                if (str_starts_with($sql, 'SELECT 1 FROM active_streams')) {
                    $this->selects++;
                    return [['1' => '1']];
                }
                return [];
            }
        );

        return new StreamSessionService($db, null, $clock);
    }

    /** @return array<string, mixed> The registration memo, read through reflection. */
    private function memo(StreamSessionService $service): array
    {
        $prop = new ReflectionProperty(StreamSessionService::class, 'registeredNs');
        /** @var array<string, mixed> $entries */
        $entries = $prop->getValue($service);
        return $entries;
    }

    public function testReleaseStreamClearsTheRegistrationMemoSoTheNextRegisterRunsTheSelect(): void
    {
        $service = $this->service();

        $service->registerStream('p-1', 'dev-1', 'sess-1');
        self::assertSame(1, $this->selects);

        // Inside the window the second call is a memo hit: no SELECT.
        $service->registerStream('p-1', 'dev-1', 'sess-1');
        self::assertSame(1, $this->selects, 'a fresh memo entry must skip the existence SELECT');

        $service->releaseStream('sess-1');
        self::assertArrayNotHasKey('sess-1', $this->memo($service), 'release clears the memo entry');

        $service->registerStream('p-1', 'dev-1', 'sess-1');
        self::assertSame(2, $this->selects, 'after a release the next registration must run the SELECT');
    }

    public function testAMemoEntryForAnotherProfileDoesNotServeTheSameSessionId(): void
    {
        $service = $this->service();

        $service->registerStream('p-1', 'dev-1', 'sess-1');
        self::assertSame(1, $this->selects);

        // Same session id, different profile: the memo entry belongs to p-1, so p-2 must run the SELECT.
        $service->registerStream('p-2', 'dev-2', 'sess-1');
        self::assertSame(2, $this->selects, 'a memo entry for another profile must not serve this registration');
    }

    /**
     * The memo entry lives for HEARTBEAT_THROTTLE_NS (15 s) from its last refresh. Inside the
     * window a repeat registration skips the SELECT; at exactly the window's end the entry has
     * expired, so the SELECT runs again.
     */
    public function testAnExpiredMemoEntryRunsTheExistenceSelectAgain(): void
    {
        $now = 0;
        $service = $this->service(static function () use (&$now): int {
            return $now;
        });

        $service->registerStream('p-1', 'dev-1', 'sess-1');
        self::assertSame(1, $this->selects, 'the first registration runs the SELECT');

        $now = 14_999_999_999; // 1 ns inside the 15 s window
        $service->registerStream('p-1', 'dev-1', 'sess-1');
        self::assertSame(1, $this->selects, 'inside the window the memo hit skips the SELECT');

        $now = 15_000_000_000; // exactly the window: expired, so the SELECT runs again
        $service->registerStream('p-1', 'dev-1', 'sess-1');
        self::assertSame(2, $this->selects, 'after the window elapses the existence SELECT must run again');
    }

    public function testRegistrationMemoIsCappedAndEvictsTheLeastRecentlyUsedEntry(): void
    {
        $service = $this->service();

        // Fill exactly to the cap: s0 is the first (oldest) registration.
        for ($i = 0; $i < 4096; $i++) {
            $service->registerStream('p-1', 'dev-1', 's' . $i);
        }
        self::assertCount(4096, $this->memo($service));

        // A memo hit on s0 counts as a use, so s0 moves to the back of the eviction order.
        $before = $this->selects;
        $service->registerStream('p-1', 'dev-1', 's0');
        self::assertSame($before, $this->selects, 's0 is a memo hit');

        // One new session pushes the memo past its cap; the LRU entry is s1, not s0.
        $service->registerStream('p-1', 'dev-1', 'sNew');
        self::assertCount(4096, $this->memo($service), 'the memo must not grow past its cap');

        $before = $this->selects;
        $service->registerStream('p-1', 'dev-1', 's0');
        self::assertSame($before, $this->selects, 's0 was refreshed by its hit, so it is still memoised');

        $service->registerStream('p-1', 'dev-1', 's1');
        self::assertSame($before + 1, $this->selects, 's1 was the least-recently-used entry and was evicted');
    }
}
