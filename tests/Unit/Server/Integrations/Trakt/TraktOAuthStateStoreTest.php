<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Integrations\Trakt;

use Phlix\Server\Integrations\Trakt\SessionTraktOAuthStateStore;
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

    // -----------------------------------------------------------------
    // M-4: initiating-identity binding on the session surface too
    // -----------------------------------------------------------------

    public function test_consume_with_identity_round_trips_bound_user(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc', 'admin-42');

        self::assertSame(
            ['code_verifier' => 'verifier-abc', 'user_id' => 'admin-42'],
            $store->consumeWithIdentity('state-123'),
        );
    }

    public function test_consume_with_identity_reports_null_for_unbound_put(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        self::assertSame(
            ['code_verifier' => 'verifier-abc', 'user_id' => null],
            $store->consumeWithIdentity('state-123'),
        );
    }

    public function test_identity_is_wiped_with_the_rest_of_the_entry(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc', 'admin-42');

        // A wrong-state consume wipes EVERYTHING — the bound identity must not
        // survive for a later probe to correlate against.
        self::assertNull($store->consumeWithIdentity('state-WRONG'));
        self::assertNull($store->consumeWithIdentity('state-123'));
        self::assertArrayNotHasKey('trakt_oauth_user_id', $_SESSION);
    }

    /**
     * Contract: an empty-string identity is normalised to NO identity at the
     * store boundary (M-4 parse-don't-validate) — never a stored '' that a
     * forged empty userId could later match via hash_equals.
     */
    public function test_empty_string_identity_is_normalised_to_null(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc', '');

        self::assertSame(
            ['code_verifier' => 'verifier-abc', 'user_id' => null],
            $store->consumeWithIdentity('state-123'),
        );
    }
}
