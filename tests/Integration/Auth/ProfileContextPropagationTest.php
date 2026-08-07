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
use Phlix\Media\UserItemDataRepository;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S80 — profile context really is per SESSION, and a session cannot reach another
 * account's profile.
 *
 * ## What is actually being claimed
 *
 * S80 replaces "the account's `user_profiles.is_active` flag decides what every
 * request sees" with "the `profile_id` claim on the presenting token decides".
 * Two things have to be true for that to be safe, and both need a real database
 * because both turn on rows a mock would have to invent:
 *
 *   1. **Two live sessions of ONE account can hold two DIFFERENT profiles at the
 *      same time**, and neither can see the other's data. That is the step's
 *      stated acceptance criterion.
 *   2. **A claim is verified, not obeyed.** A token is signed, so a client cannot
 *      forge one — but the server itself must never mint or honour a claim naming
 *      a profile the subject does not own, because a profile can be deleted or
 *      reassigned while an hour-long access token is still live.
 *
 * ## Why every refusal here has a succeeding control beside it
 *
 * "User A asked for user B's profile and did not get B's data" is ALSO what a
 * completely broken resolver produces — one that ignores the claim entirely and
 * always returns the account default would pass that assertion. So each refusal
 * is asserted next to a case where the SAME code path DOES honour a claim: A
 * naming its own second profile gets that second profile's data, with a visibly
 * different rating. Only the pair distinguishes "the check fired" from "nothing
 * ever fires".
 *
 * The fixture is namespaced by a per-run token and removed in `tearDown`, so it is
 * safe against a shared `phlix_test`.
 */
final class ProfileContextPropagationTest extends TestCase
{
    use RequiresRealDatabase;

    /** Rating stored for user A under their FIRST (active) profile. */
    private const RATING_A_FIRST = 1;

    /** Rating stored for user A under their SECOND profile. */
    private const RATING_A_SECOND = 2;

    /** Rating stored for user B — the value that must never reach user A. */
    private const RATING_B = 9;

    private ?Connection $db = null;

    private string $token = '';

    private string $userA = '';

    private string $userB = '';

    private string $profileA1 = '';

    private string $profileA2 = '';

    private string $profileB1 = '';

    private string $itemId = '';

    private string $libraryId = '';

    private ?JwtHandler $jwt = null;

    private ?AuthManager $auth = null;

    private ?UserItemDataRepository $itemData = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S80 profile-context propagation test. Runs in CI.');
        $this->assertNotNull($this->db);

        $this->token = substr(str_replace('-', '', Uuid::v4()), 0, 10);

        // The suite needs migration 100 applied (profile_id on user_item_data).
        // A specific schema object being absent is a legitimate skip; the
        // ACQUISITION above stayed outside the try, per the S126 rule.
        try {
            $this->conn()->query('SELECT profile_id FROM user_item_data LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('migration 100 (user_item_data.profile_id) is not applied on this database');
        }

        $this->seedFixture();

        $profiles = new UserProfileManager($this->conn());
        $this->jwt = new JwtHandler('s80-test-secret-key-not-a-real-one', 'HS256', 3600, 604800);
        $this->auth = new AuthManager(
            new UserRepository($this->conn()),
            $this->jwt,
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
        $this->itemData = new UserItemDataRepository($this->conn(), $profiles);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null && $this->itemId !== '') {
            // user_item_data and user_profiles both CASCADE from users; the media
            // item is deleted explicitly.
            foreach ([$this->userA, $this->userB] as $userId) {
                if ($userId !== '') {
                    $db->query('DELETE FROM users WHERE id = ?', [$userId]);
                }
            }
            $db->query('DELETE FROM media_items WHERE id = ?', [$this->itemId]);
            if ($this->libraryId !== '') {
                $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
            }
        }

        $this->db = null;
        $this->auth = null;
        $this->itemData = null;
        $this->jwt = null;

        parent::tearDown();
    }

    /**
     * THE acceptance criterion: two concurrent sessions of ONE account hold two
     * DIFFERENT profiles at the same time and read two different data sets.
     *
     * Both tokens exist simultaneously and are validated in an interleaved order,
     * so this cannot pass by one session overwriting a piece of shared state that
     * the other then reads back — which is exactly what the pre-S80
     * `user_profiles.is_active` flag would have done.
     */
    public function testTwoSessionsOfOneAccountHoldDifferentProfilesSimultaneously(): void
    {
        $sessionOne = $this->tokenFor($this->userA, $this->profileA1);
        $sessionTwo = $this->tokenFor($this->userA, $this->profileA2);

        // Interleaved on purpose: one, two, one, two.
        $firstOfOne = $this->resolvedProfile($sessionOne);
        $firstOfTwo = $this->resolvedProfile($sessionTwo);
        $secondOfOne = $this->resolvedProfile($sessionOne);
        $secondOfTwo = $this->resolvedProfile($sessionTwo);

        $this->assertSame($this->profileA1, $firstOfOne);
        $this->assertSame($this->profileA2, $firstOfTwo);
        $this->assertSame(
            $this->profileA1,
            $secondOfOne,
            'session one must still be on its own profile after session two ran'
        );
        $this->assertSame($this->profileA2, $secondOfTwo);

        // And the DATA each session reads differs.
        $this->assertSame(self::RATING_A_FIRST, $this->ratingFor($this->userA, $firstOfOne));
        $this->assertSame(self::RATING_A_SECOND, $this->ratingFor($this->userA, $firstOfTwo));

        // The account-wide flag is untouched by either — nothing about serving a
        // request mutates it any more.
        $this->assertSame(
            $this->profileA1,
            $this->activeProfileIdFor($this->userA),
            'serving a request under a non-active profile must not flip is_active'
        );
    }

    /**
     * A token for user A that NAMES user B's profile is refused: the resolver
     * degrades to A's own default and A reads A's data, never B's.
     *
     * ⚠ The succeeding control is in the same test and it matters: without it,
     * a resolver that ignored the claim entirely and always answered `profileA1`
     * would pass the refusal half. The control proves the very same call DOES
     * honour a claim when the claim is legitimate.
     */
    public function testATokenNamingAnotherAccountsProfileIsRefusedWhileAnOwnedOneIsHonoured(): void
    {
        // ---- the refusal ----
        $crossAccount = $this->tokenFor($this->userA, $this->profileB1);
        $refused = $this->resolvedProfile($crossAccount);

        $this->assertNotSame(
            $this->profileB1,
            $refused,
            "a token for user A must never resolve to user B's profile"
        );
        $this->assertSame(
            $this->profileA1,
            $refused,
            "the refusal must degrade to A's own default profile, not to nothing"
        );
        $this->assertSame(
            self::RATING_A_FIRST,
            $this->ratingFor($this->userA, (string) $refused),
            "user A must read user A's data"
        );
        $this->assertNotSame(
            self::RATING_B,
            $this->ratingFor($this->userA, (string) $refused),
            "user B's rating must never surface for user A"
        );

        // ---- the succeeding control: A naming A's OTHER profile IS honoured ----
        $ownSecond = $this->tokenFor($this->userA, $this->profileA2);
        $honoured = $this->resolvedProfile($ownSecond);

        $this->assertSame(
            $this->profileA2,
            $honoured,
            'the same code path must HONOUR a claim naming a profile the caller owns'
        );
        $this->assertSame(
            self::RATING_A_SECOND,
            $this->ratingFor($this->userA, (string) $honoured),
            "the honoured claim must read that profile's own data, not the default's"
        );
        $this->assertNotSame(
            $refused,
            $honoured,
            'the refused and honoured cases must produce DIFFERENT profiles — '
            . 'if they matched, the resolver would simply be ignoring the claim'
        );
    }

    /**
     * The repository is the second gate. Even handed user B's profile id directly,
     * a read for user A refuses rather than returning B's row — with the owned
     * profile as the control beside it.
     */
    public function testTheRepositoryRefusesACrossAccountProfileButAcceptsAnOwnedOne(): void
    {
        $refused = false;
        try {
            $this->itemDataRepo()->getItemData($this->userA, $this->itemId, $this->profileB1);
        } catch (\Phlix\Auth\ProfileNotOwnedException $e) {
            $refused = true;
        }

        $this->assertTrue($refused, "reading user B's profile as user A must be refused");

        // Control: the identical call with A's own second profile succeeds and
        // returns that profile's distinct data.
        $owned = $this->itemDataRepo()->getItemData($this->userA, $this->itemId, $this->profileA2);

        $this->assertIsArray($owned, 'the same call shape must succeed for an owned profile');
        $this->assertSame(self::RATING_A_SECOND, $owned['rating']);
    }

    /**
     * A refresh carries the session's profile across the re-mint. Without this the
     * device silently reverts to the account default an hour into a session.
     */
    public function testRefreshingATokenPreservesTheSessionsProfile(): void
    {
        $refresh = $this->jwtHandler()->createRefreshToken(
            $this->userA,
            [JwtHandler::CLAIM_PROFILE_ID => $this->profileA2]
        );

        $response = $this->authManager()->refreshToken($refresh);

        $this->assertSame(
            $this->profileA2,
            $response['profile_id'] ?? null,
            'the refreshed pair must report the same profile'
        );
        $this->assertSame(
            $this->profileA2,
            $this->resolvedProfile((string) $response['access_token']),
            'the refreshed ACCESS token must still carry the profile'
        );
        $this->assertSame(
            $this->profileA2,
            JwtHandler::profileIdClaim($this->jwtHandler()->validateToken((string) $response['refresh_token'])),
            'the refreshed REFRESH token must carry it too, or the NEXT refresh loses it'
        );

        // Control: a refresh token with no profile claim at all (every token minted
        // before S80) still refreshes, and lands on the account default.
        $legacy = $this->jwtHandler()->createRefreshToken($this->userA);
        $legacyResponse = $this->authManager()->refreshToken($legacy);

        $this->assertSame(
            $this->profileA1,
            $legacyResponse['profile_id'] ?? null,
            'a pre-S80 refresh token must still work and adopt the account default'
        );
    }

    /**
     * A fresh login stamps the account's default profile, and both minted tokens
     * carry it.
     */
    public function testAFreshAuthResponseStampsTheDefaultProfileOnBothTokens(): void
    {
        $response = $this->authManager()->buildAuthResponse($this->userA);

        $this->assertSame($this->profileA1, $response['profile_id'] ?? null);
        $this->assertSame(
            $this->profileA1,
            JwtHandler::profileIdClaim($this->jwtHandler()->validateToken((string) $response['access_token']))
        );
        $this->assertSame(
            $this->profileA1,
            JwtHandler::profileIdClaim($this->jwtHandler()->validateToken((string) $response['refresh_token']))
        );
    }

    /**
     * A claim naming a profile that has since been DELETED degrades to the account
     * default rather than failing the request.
     *
     * Refusing outright would lock the user out of their own account for the
     * remaining lifetime of the token, with nothing they could do but wait.
     */
    public function testAClaimForADeletedProfileDegradesToTheAccountDefault(): void
    {
        $session = $this->tokenFor($this->userA, $this->profileA2);

        // Control first: while the profile exists, the claim IS honoured.
        $this->assertSame($this->profileA2, $this->resolvedProfile($session));

        $this->conn()->query('DELETE FROM user_profiles WHERE id = ?', [$this->profileA2]);

        $this->assertSame(
            $this->profileA1,
            $this->resolvedProfile($session),
            'a stale claim must degrade to the default, not break the session'
        );
    }

    // ---- helpers -------------------------------------------------------------

    private function conn(): Connection
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return $db;
    }

    private function jwtHandler(): JwtHandler
    {
        $jwt = $this->jwt;
        $this->assertNotNull($jwt);

        return $jwt;
    }

    private function authManager(): AuthManager
    {
        $auth = $this->auth;
        $this->assertNotNull($auth);

        return $auth;
    }

    private function itemDataRepo(): UserItemDataRepository
    {
        $repo = $this->itemData;
        $this->assertNotNull($repo);

        return $repo;
    }

    /** An access token for `$userId` carrying `$profileId` as its claim. */
    private function tokenFor(string $userId, string $profileId): string
    {
        return $this->jwtHandler()->createAccessToken(
            $userId,
            [JwtHandler::CLAIM_PROFILE_ID => $profileId]
        );
    }

    /**
     * The profile the production auth path resolves for a token — i.e. exactly
     * what {@see \Phlix\Server\Http\RequestAuthenticator} puts on the request.
     */
    private function resolvedProfile(string $accessToken): ?string
    {
        $auth = $this->authManager()->validateAccessToken($accessToken);
        $this->assertIsArray($auth, 'the token must validate');

        $profileId = $auth['profile_id'] ?? null;

        return is_string($profileId) ? $profileId : null;
    }

    private function ratingFor(string $userId, string $profileId): ?int
    {
        $data = $this->itemDataRepo()->getItemData($userId, $this->itemId, $profileId);

        return $data === null ? null : $data['rating'];
    }

    private function activeProfileIdFor(string $userId): ?string
    {
        $rows = $this->conn()->query(
            'SELECT id FROM user_profiles WHERE user_id = ? AND is_active = TRUE LIMIT 1',
            [$userId]
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $id = $rows[0]['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    private function seedFixture(): void
    {
        $db = $this->conn();

        $this->userA = $this->seedUser('s80a');
        $this->userB = $this->seedUser('s80b');

        $this->profileA1 = $this->seedProfile($this->userA, 'Owner', true, '2024-01-01 00:00:00');
        $this->profileA2 = $this->seedProfile($this->userA, 'Kid', false, '2024-02-01 00:00:00');
        $this->profileB1 = $this->seedProfile($this->userB, 'Neighbour', true, '2024-01-01 00:00:00');

        $this->libraryId = Uuid::v4();
        $db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'movie', ?)",
            [$this->libraryId, 'S80 Library ' . $this->token, '["/tmp/s80"]']
        );

        $this->itemId = Uuid::v4();
        $db->query(
            "INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, 'movie', ?)",
            [$this->itemId, $this->libraryId, 'S80 Fixture ' . $this->token, '/tmp/s80-' . $this->token . '.mkv']
        );

        // Three DISTINCT ratings, so any leak between them is visible as a value
        // rather than only as a row count.
        $this->seedItemData($this->userA, $this->profileA1, self::RATING_A_FIRST);
        $this->seedItemData($this->userA, $this->profileA2, self::RATING_A_SECOND);
        $this->seedItemData($this->userB, $this->profileB1, self::RATING_B);
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

    private function seedProfile(string $userId, string $name, bool $isActive, string $createdAt): string
    {
        $id = Uuid::v4();
        $this->conn()->query(
            'INSERT INTO user_profiles (id, user_id, name, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $userId, $name . ' ' . $this->token, $isActive ? 1 : 0, $createdAt]
        );

        return $id;
    }

    private function seedItemData(string $userId, string $profileId, int $rating): void
    {
        $this->conn()->query(
            'INSERT INTO user_item_data (user_id, profile_id, item_id, favorite, rating) VALUES (?, ?, ?, 1, ?)',
            [$userId, $profileId, $this->itemId, $rating]
        );
    }
}
