<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\SessionLastfmOAuthStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Covers the {@see SessionLastfmOAuthStateStore} contract: a `state => userId`
 * pair survives a put/consume round-trip, mismatches return null, and a second
 * consume of the same state is rejected as a replay.
 *
 * Mirrors the Trakt OAuth state-store tests; protects the Last.fm
 * account-linking CSRF fix (PR #260 follow-up).
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\SessionLastfmOAuthStateStore
 */
final class SessionLastfmOAuthStateStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function test_round_trip_returns_user_id(): void
    {
        $store = new SessionLastfmOAuthStateStore();
        $store->put('state-123', 'user-abc');

        self::assertSame('user-abc', $store->consume('state-123'));
    }

    public function test_consume_with_mismatched_state_returns_null(): void
    {
        $store = new SessionLastfmOAuthStateStore();
        $store->put('state-123', 'user-abc');

        self::assertNull($store->consume('state-WRONG'));
    }

    public function test_consume_is_one_shot(): void
    {
        $store = new SessionLastfmOAuthStateStore();
        $store->put('state-123', 'user-abc');

        self::assertSame('user-abc', $store->consume('state-123'));
        // Replay attempt — the entry was wiped on the first consume.
        self::assertNull($store->consume('state-123'));
    }

    public function test_consume_when_never_issued_returns_null(): void
    {
        $store = new SessionLastfmOAuthStateStore();

        self::assertNull($store->consume('whatever'));
    }

    public function test_mismatched_state_still_wipes_stored_entry(): void
    {
        $store = new SessionLastfmOAuthStateStore();
        $store->put('state-123', 'user-abc');

        // A wrong-state attempt MUST also wipe the entry so an attacker
        // cannot probe and then immediately replay with the right state.
        self::assertNull($store->consume('state-WRONG'));
        self::assertNull($store->consume('state-123'));
    }
}
