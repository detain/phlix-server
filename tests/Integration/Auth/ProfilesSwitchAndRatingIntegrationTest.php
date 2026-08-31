<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S81 — the switched session gets ITS profile's rating cap, the switch keeps
 * exactly one active profile, PIN verify fails closed on REAL rows, and a
 * signup creates its first profile for real.
 *
 * ## Why real MySQL (the box runs it; CI runs it)
 *
 * All four S81 blocker fixes turn on row states a mock would have to invent:
 *
 *  1. **Blocker 1 (account-scoped rating cap).** An Owner profile (UNRATED →
 *     no cap) beside a Kid profile (PG → capped) under ONE account. The cap
 *     must follow the SESSION profile named by the claim, never the
 *     account-wide active row. Mocked, the claim-threading assertion just
 *     re-states the mock; with real rows the SQL's `WHERE` does the arguing.
 *  2. **Blocker 3 (switchProfile's invariant).** After a switch exactly ONE
 *     row is active — `COUNT(is_active = TRUE) = 1` against the table, not
 *     against a fake.
 *  3. **Blocker 2 (verifyPin fail-open).** A PIN-less real row must answer
 *     `false` + `hasPin() false`; after `setPin` the right PIN passes and the
 *     wrong one does not; after `removePin` it fails closed again. The OLD
 *     contract returned `true` for every attempt against every PIN-less row.
 *  4. **Blocker 5 (signup first profile).** `register()` runs the REAL
 *     transactional path (the unit tests drive the `db === null` fallback);
 *     the profile must exist next to the user row in the same commit.
 *
 * ## Crossing arms beside succeeding arms
 *
 * Every "claim is honoured" assertion sits beside a "claim is VERIFIED" arm:
 * naming ANOTHER account's profile id resolves to null (the uniform no-such-
 * profile), so the pairing distinguishes "the owner check fired" from "the
 * resolver was never consulted" (the S80 lesson this file inherits).
 *
 * The fixture is namespaced by a per-run token and removed in `tearDown`, so
 * it is safe against a shared `phlix_test`.
 */
final class ProfilesSwitchAndRatingIntegrationTest extends TestCase
{
    use \Phlix\Tests\Support\Database\RequiresRealDatabase;

    private ?Connection $db = null;

    private string $token = '';

    private string $userA = '';

    private string $userB = '';

    /** Owner profile of A — active, UNRATED (no cap). */
    private string $profileOwner = '';

    /** Kid profile of A — inactive, capped at PG. */
    private string $profileKid = '';

    private string $profileB = '';

    private ?UserProfileManager $profiles = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S81 profiles integration test. Runs in CI.');
        $this->assertNotNull($this->db);

        $this->token = substr(str_replace('-', '', Uuid::v4()), 0, 10);

        try {
            $this->conn()->query('SELECT id FROM user_profiles LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('user_profiles is not present on this database');
        }

        $this->seedFixture();

        $this->profiles = new UserProfileManager($this->conn());
    }

    protected function tearDown(): void
    {
        RequestContext::setProfileId(null);

        $db = $this->db;
        if ($db !== null) {
            // user_profiles/profile_settings cascade from users; users go first
            // is irrelevant (children cascade), the order keeps FKs satisfied.
            foreach ([$this->userA, $this->userB] as $userId) {
                if ($userId !== '') {
                    $db->query('DELETE FROM users WHERE id = ?', [$userId]);
                }
            }
        }

        $this->db = null;
        $this->profiles = null;

        parent::tearDown();
    }

    private function conn(): Connection
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return $db;
    }

    private function seedFixture(): void
    {
        $this->userA = $this->seedUser('s81a');
        $this->userB = $this->seedUser('s81b');

        // A's ACTIVE profile is the uncapped Owner; the Kid profile (capped PG)
        // is INACTIVE — so "the account-wide active row" and "the session's
        // profile" answer differently, which is exactly the S81 bug's shape.
        $this->profileOwner = $this->seedProfile($this->userA, 'Owner', true, null);
        $this->profileKid = $this->seedProfile($this->userA, 'Kid', false, 'PG');
        $this->profileB = $this->seedProfile($this->userB, 'Neighbour', true, null);
    }

    private function seedUser(string $prefix): string
    {
        $id = Uuid::v4();
        $this->conn()->query(
            "INSERT INTO users (id, username, email, password_hash, status)
             VALUES (?, ?, ?, ?, 'active')",
            [
                $id,
                $prefix . '_' . $this->token,
                $prefix . '_' . $this->token . '@example.test',
                'x',
            ]
        );

        return $id;
    }

    /**
     * @param string|null $contentRating null → NO profile_settings row at all
     *                                   (the true production default shape for
     *                                   a profile that never got parental
     *                                   controls) → uncapped.
     */
    private function seedProfile(string $userId, string $name, bool $isActive, ?string $contentRating): string
    {
        $id = Uuid::v4();
        $this->conn()->query(
            'INSERT INTO user_profiles (id, user_id, name, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $userId, $name . ' ' . $this->token, $isActive ? 1 : 0, '2024-01-01 00:00:00']
        );

        if ($contentRating !== null) {
            $this->conn()->query(
                'INSERT INTO profile_settings (id, profile_id, content_rating, allow_unrated) VALUES (?, ?, ?, 0)',
                [Uuid::v4(), $id, $contentRating]
            );
        }

        return $id;
    }

    // ---- blocker 1: the cap follows the SESSION profile ----------------------

    public function testRatingCapFollowsTheSessionProfileNotTheAccountActiveRow(): void
    {
        $gate = new RatingGate(new ItemRepository($this->conn()), $this->profiles, new UserRepository($this->conn()));

        // The account-wide active profile is the UNRATED Owner → NO cap.
        $accountWide = $this->profiles->getActiveRatingFilter($this->userA, null);
        $this->assertNull(
            $accountWide,
            'the active Owner profile is uncapped — if this is non-null the fixture lost its shape',
        );

        // The SAME user under the Kid claim gets the Kid's cap.
        $kidCap = $this->profiles->getActiveRatingFilter($this->userA, $this->profileKid);
        $this->assertNotNull($kidCap, 'the Kid session must get the Kid profile cap');
        $this->assertContains('PG', $kidCap['allowedRatings']);
        $this->assertNotContains('R', $kidCap['allowedRatings']);

        // And the cap flows through the gate when the SESSION carries the claim.
        try {
            RequestContext::setProfileId($this->profileKid);
            $viaGate = $gate->resolveFilterForUser($this->userA);
            $this->assertNotNull($viaGate, 'resolveFilterForUser must honour the session profile');
            $this->assertNotContains('R', $viaGate['allowedRatings']);

            RequestContext::setProfileId($this->profileOwner);
            $this->assertNull($gate->resolveFilterForUser($this->userA), 'the Owner session stays uncapped');

            // No session claim at all (CLI, refresh-token-less contexts) → the
            // account-wide active row, i.e. today's behaviour, unchanged.
            RequestContext::setProfileId(null);
            $this->assertNull($gate->resolveFilterForUser($this->userA));
        } finally {
            RequestContext::setProfileId(null);
        }
    }

    public function testForeignProfileClaimResolvesToNothingNotAnotherAccountsCap(): void
    {
        // A claiming B's profile id: uniform null (no such profile FOR A), never
        // B's settings — and never silently A's own, which would look like a
        // pass while ignoring the check.
        $this->assertNull($this->profiles->getActiveProfile($this->userA, $this->profileB));
        $this->assertNull($this->profiles->getActiveRatingFilter($this->userA, $this->profileB));

        // SUCCEEDING control beside the refusal: naming its OWN kid profile
        // DOES resolve and DOES carry the cap.
        $own = $this->profiles->getActiveProfile($this->userA, $this->profileKid);
        $this->assertNotNull($own);
        $this->assertSame($this->profileKid, $own['id']);
    }

    // ---- blocker 3: switchProfile keeps exactly one active row ----------------

    public function testSwitchProfileLeavesExactlyOneActiveProfile(): void
    {
        $before = $this->activeCount($this->userA);
        $this->assertSame(1, $before, 'fixture: exactly one active profile to begin with');

        $this->assertTrue($this->profiles->switchProfile($this->userA, $this->profileKid));

        $after = $this->activeCount($this->userA);
        $this->assertSame(1, $after, 'after a switch exactly ONE profile may be active');

        $active = $this->profiles->getActiveProfile($this->userA);
        $this->assertNotNull($active);
        $this->assertSame($this->profileKid, $active['id'], 'the Kid row must be the active one');

        // Ownership: switching to ANOTHER account's profile is refused and
        // changes nothing.
        $this->assertFalse($this->profiles->switchProfile($this->userA, $this->profileB));
        $this->assertSame(1, $this->activeCount($this->userA));
        $stillKid = $this->profiles->getActiveProfile($this->userA);
        $this->assertSame($this->profileKid, $stillKid['id'], 'a refused switch must leave the state untouched');
    }

    private function activeCount(string $userId): int
    {
        $row = $this->conn()->query(
            'SELECT COUNT(*) AS c FROM user_profiles WHERE user_id = ? AND is_active = TRUE',
            [$userId]
        );
        $row = is_array($row) ? ($row[0] ?? null) : null;

        return is_array($row) ? (int)($row['c'] ?? 0) : -1;
    }

    // ---- blocker 2: verifyPin fails closed on real rows -----------------------

    public function testVerifyPinFailsClosedWithoutAPinAndRoundTripsWithOne(): void
    {
        // The Kid profile has a settings row with pin_hash NULL.
        $this->assertFalse(
            $this->profiles->hasPin($this->profileKid),
            'the fixture profile has no PIN configured',
        );
        $this->assertFalse(
            $this->profiles->verifyPin($this->profileKid, '1234'),
            'S81: a PIN-less profile must NOT answer true (the old fail-open)',
        );

        $this->profiles->setPin($this->profileKid, '1234');
        $this->assertTrue($this->profiles->hasPin($this->profileKid));
        $this->assertTrue($this->profiles->verifyPin($this->profileKid, '1234'), 'the right PIN passes');
        $this->assertFalse($this->profiles->verifyPin($this->profileKid, '9999'), 'a wrong PIN does not');

        $this->profiles->removePin($this->profileKid);
        $this->assertFalse($this->profiles->hasPin($this->profileKid), 'after removal, no PIN is configured');
        $this->assertFalse(
            $this->profiles->verifyPin($this->profileKid, '1234'),
            'after removal the PIN-less profile fails closed again — never a pass',
        );
    }

    // ---- blocker 5: signup creates the first profile, transactionally ----------

    public function testRegisterCreatesFirstProfileThroughTheRealTransaction(): void
    {
        $suffix = substr(str_replace('-', '', Uuid::v4()), 0, 8);
        $username = 's81signup_' . $suffix;

        $profiles = new UserProfileManager($this->conn());
        $auth = new AuthManager(
            new UserRepository($this->conn()),
            new JwtHandler('s81-test-secret-key-not-a-real-one', 'HS256', 3600, 604800),
            new AuditLogger(LoggerFactory::get('auth')),
            null,
            null,
            $this->conn(),
            null,
            null,
            null,
            null,
            $profiles,
        );

        $result = $auth->register($username, $username . '@example.test', 'topsecret123');
        $userId = $result['user']['id'] ?? '';
        $this->assertNotSame('', $userId, 'register() must return the new user');

        try {
            $rows = $profiles->findByUserId($userId);
            $this->assertCount(1, $rows, 'signup creates exactly ONE first profile');
            $this->assertSame(AuthManager::FIRST_PROFILE_NAME, $rows[0]['name']);
            $this->assertTrue((bool)$rows[0]['is_active'], 'the first profile is active');
        } finally {
            $this->conn()->query('DELETE FROM users WHERE id = ?', [$userId]);
        }
    }
}
