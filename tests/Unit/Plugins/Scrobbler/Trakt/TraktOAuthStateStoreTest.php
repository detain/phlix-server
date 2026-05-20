<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\SessionTraktOAuthStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Covers the {@see SessionTraktOAuthStateStore} contract: state values
 * survive a put/consume round-trip, mismatches return null, and a
 * second consume of the same state is rejected as a replay.
 *
 * See post-O.7 wave 1 security audit, finding H.4.
 */
final class TraktOAuthStateStoreTest extends TestCase
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

    public function test_round_trip_returns_verifier(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        self::assertSame('verifier-abc', $store->consume('state-123'));
    }

    public function test_consume_with_mismatched_state_returns_null(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        self::assertNull($store->consume('state-WRONG'));
    }

    public function test_consume_is_one_shot(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        self::assertSame('verifier-abc', $store->consume('state-123'));
        // Replay attempt — the entry was wiped on the first consume.
        self::assertNull($store->consume('state-123'));
    }

    public function test_consume_when_never_issued_returns_null(): void
    {
        $store = new SessionTraktOAuthStateStore();

        self::assertNull($store->consume('whatever'));
    }

    public function test_mismatched_state_still_wipes_stored_entry(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        // A wrong-state attempt MUST also wipe the entry so an attacker
        // cannot probe and then immediately replay with the right state.
        self::assertNull($store->consume('state-WRONG'));
        self::assertNull($store->consume('state-123'));
    }
}
