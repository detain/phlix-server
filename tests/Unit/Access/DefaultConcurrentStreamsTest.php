<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Access;

use Phlix\Access\StreamSessionService;
use Phlix\Admin\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Guard for `access.default_concurrent_streams`.
 *
 * ## Why the fallback is the whole feature
 *
 * `StreamSessionService::getStreamLimit()` returns a hardcoded allowance when a
 * profile has no `profile_stream_limits` row. That reads like a rarely-taken
 * edge case, and it is the opposite: NOTHING writes such a row at profile
 * creation — the only writer is `updateStreamLimit()`, reached solely from the
 * admin API — so the fallback governs essentially every profile on the server,
 * evaluated afresh on every playback.
 *
 * That is what makes this a live, server-wide control rather than a
 * creation-time seed, and it is why the tests below assert through
 * `getStreamLimit()` (the real read path) rather than only through the accessor.
 *
 * Mutation-verified: restoring the literal `1` in `getStreamLimit()` reddens
 * the two `getStreamLimit` tests while the accessor tests stay green — which is
 * exactly the half-wired state worth catching.
 *
 * @covers \Phlix\Access\StreamSessionService
 */
final class DefaultConcurrentStreamsTest extends TestCase
{
    /** Differs from the shipped default of 1. */
    private const OVERRIDE = 4;

    /**
     * A service whose `profile_stream_limits` lookup finds NO row — the common
     * case in production.
     */
    private function serviceWithNoRow(mixed $configured): StreamSessionService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with(StreamSessionService::DEFAULT_STREAMS_SETTING_KEY)
            ->willReturn($configured);

        return new StreamSessionService($db, $settings);
    }

    // ─────────────────────────────────────────────────────────────────
    // the real read path
    // ─────────────────────────────────────────────────────────────────

    public function test_get_stream_limit_uses_the_override_when_no_row_exists(): void
    {
        $limit = $this->serviceWithNoRow(self::OVERRIDE)->getStreamLimit('profile-1');

        $this->assertSame(self::OVERRIDE, $limit->maxConcurrentStreams);
        $this->assertSame('profile-1', $limit->profileId);
    }

    public function test_get_stream_limit_falls_back_to_the_shipped_default_without_a_store(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $limit = (new StreamSessionService($db))->getStreamLimit('profile-1');

        $this->assertSame(StreamSessionService::DEFAULT_CONCURRENT_STREAMS, $limit->maxConcurrentStreams);
    }

    // ─────────────────────────────────────────────────────────────────
    // clamps
    // ─────────────────────────────────────────────────────────────────

    public function test_zero_is_clamped_up_so_playback_is_not_denied_server_wide(): void
    {
        // Without the floor, a 0 here would refuse playback to every profile
        // that has no explicit row — which is nearly all of them.
        $this->assertSame(
            StreamSessionService::MIN_CONCURRENT_STREAMS,
            $this->serviceWithNoRow(0)->defaultConcurrentStreams()
        );
    }

    public function test_an_absurd_value_is_clamped_down(): void
    {
        $this->assertSame(
            StreamSessionService::MAX_CONCURRENT_STREAMS,
            $this->serviceWithNoRow(999999)->defaultConcurrentStreams()
        );
    }

    public function test_a_numeric_string_is_coerced(): void
    {
        $this->assertSame(3, $this->serviceWithNoRow('3')->defaultConcurrentStreams());
    }

    public function test_a_non_numeric_value_falls_back_to_the_shipped_default(): void
    {
        $this->assertSame(
            StreamSessionService::DEFAULT_CONCURRENT_STREAMS,
            $this->serviceWithNoRow('unlimited')->defaultConcurrentStreams()
        );
    }

    public function test_a_throwing_store_falls_back_rather_than_lifting_the_cap(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db gone'));

        $service = new StreamSessionService($db, $settings);

        $this->assertSame(
            StreamSessionService::DEFAULT_CONCURRENT_STREAMS,
            $service->defaultConcurrentStreams()
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // an explicit per-profile row still wins
    // ─────────────────────────────────────────────────────────────────

    public function test_an_explicit_profile_row_overrides_the_global_default(): void
    {
        // The setting is a FALLBACK. An administrator who has set a specific
        // limit for a profile must not have it silently replaced.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'profile_id' => 'profile-1',
            'max_concurrent_streams' => 7,
        ]]);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn(self::OVERRIDE);

        $limit = (new StreamSessionService($db, $settings))->getStreamLimit('profile-1');

        $this->assertSame(7, $limit->maxConcurrentStreams);
    }
}
