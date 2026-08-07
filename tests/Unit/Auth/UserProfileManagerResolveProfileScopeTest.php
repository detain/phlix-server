<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\ProfileNotOwnedException;
use Phlix\Auth\UserProfileManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see UserProfileManager::resolveProfileIdForUser()} (S79).
 *
 * This method is the horizontal-privilege boundary for every piece of
 * profile-scoped data, so the tests below are written as a pair wherever a
 * refusal is asserted: a refusal on its own is also what a method that refuses
 * EVERYTHING produces, so each refusal sits beside a succeeding control that
 * proves the same code path accepts a legitimate request.
 *
 * The connection is mocked and the assertions are on the SQL shape and bound
 * parameters, matching the convention in {@see UserProfileManagerTest}.
 */
final class UserProfileManagerResolveProfileScopeTest extends TestCase
{
    private const USER = 'user-1';
    private const OTHER_USER = 'user-2';

    /**
     * A caller-supplied profile that the account owns is accepted, and the
     * ownership lookup is scoped by BOTH the profile id and the user id.
     *
     * This is the succeeding control for
     * {@see self::testARequestedProfileOwnedByAnotherAccountIsRefused()}.
     */
    public function testARequestedProfileOwnedByTheCallerIsAccepted(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('SELECT id FROM user_profiles'),
                    $this->stringContains('WHERE id = ? AND user_id = ?')
                ),
                $this->equalTo(['own-profile', self::USER])
            )
            ->willReturn([['id' => 'own-profile']]);

        $manager = new UserProfileManager($db);

        $this->assertSame('own-profile', $manager->resolveProfileIdForUser(self::USER, 'own-profile'));
    }

    /**
     * A caller-supplied profile belonging to a DIFFERENT account is refused.
     *
     * The mocked lookup returns no row precisely because the SQL carries
     * `AND user_id = ?`; a resolver that dropped that predicate would find the
     * row and hand back another account's profile.
     */
    public function testARequestedProfileOwnedByAnotherAccountIsRefused(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->equalTo(['victim-profile', self::OTHER_USER]))
            ->willReturn([]);

        $manager = new UserProfileManager($db);

        $this->expectException(ProfileNotOwnedException::class);
        $manager->resolveProfileIdForUser(self::OTHER_USER, 'victim-profile');
    }

    /**
     * A profile id that does not exist at all is refused the same way — the
     * message must not distinguish the two, so an attacker cannot enumerate which
     * profile ids are real.
     */
    public function testAnUnknownProfileIsRefusedIndistinguishablyFromSomeoneElses(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $manager = new UserProfileManager($db);

        $missingMessage = null;
        try {
            $manager->resolveProfileIdForUser(self::USER, 'aaaa');
        } catch (ProfileNotOwnedException $e) {
            $missingMessage = $e->getMessage();
        }

        $foreignMessage = null;
        try {
            $manager->resolveProfileIdForUser(self::USER, 'bbbb');
        } catch (ProfileNotOwnedException $e) {
            $foreignMessage = $e->getMessage();
        }

        $this->assertIsString($missingMessage, 'an unknown profile must be refused');
        $this->assertIsString($foreignMessage, "another account's profile must be refused");

        // The message is a pure template over (profile id, user id) — both of
        // which the caller already supplied. It carries no fact about the
        // profile's existence or its real owner, so the two refusals are
        // indistinguishable once the caller's own input is substituted out.
        $this->assertSame(
            str_replace('aaaa', '<id>', $missingMessage),
            str_replace('bbbb', '<id>', $foreignMessage),
            'both refusals must produce the identical message once the requested id is masked'
        );
        $this->assertSame(
            'Profile <id> is not available to user ' . self::USER,
            str_replace('aaaa', '<id>', $missingMessage),
            'the refusal message must stay a fixed template'
        );
    }

    /**
     * With no requested profile, the account's active profile wins — resolved by
     * the same `is_active DESC, created_at ASC, id ASC` ordering the migration's
     * backfill uses, so a runtime write lands where the migration would have put it.
     */
    public function testNoRequestedProfileFallsBackToTheAccountDefaultOrdering(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('SELECT id FROM user_profiles'),
                    $this->stringContains('WHERE user_id = ?'),
                    $this->stringContains('ORDER BY is_active DESC, created_at ASC, id ASC'),
                    $this->stringContains('LIMIT 1')
                ),
                $this->equalTo([self::USER])
            )
            ->willReturn([['id' => 'active-profile']]);

        $manager = new UserProfileManager($db);

        $this->assertSame('active-profile', $manager->resolveProfileIdForUser(self::USER));
    }

    /**
     * An empty or whitespace-only requested id is treated as "not supplied"
     * rather than as a profile named ''. Otherwise a client sending
     * `?profile_id=` would get a refusal instead of its own data.
     *
     * @dataProvider blankRequestProvider
     */
    public function testABlankRequestedProfileIsTreatedAsAbsent(?string $requested): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('ORDER BY is_active DESC'),
                $this->equalTo([self::USER])
            )
            ->willReturn([['id' => 'active-profile']]);

        $manager = new UserProfileManager($db);

        $this->assertSame('active-profile', $manager->resolveProfileIdForUser(self::USER, $requested));
    }

    /**
     * @return list<array{0:string|null}>
     */
    public static function blankRequestProvider(): array
    {
        return [[null], [''], ['   ']];
    }

    /**
     * A surrounding-whitespace profile id is trimmed before the ownership lookup,
     * so `' own-profile '` resolves rather than being refused.
     */
    public function testARequestedProfileIsTrimmedBeforeTheOwnershipLookup(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->equalTo(['own-profile', self::USER]))
            ->willReturn([['id' => 'own-profile']]);

        $manager = new UserProfileManager($db);

        $this->assertSame('own-profile', $manager->resolveProfileIdForUser(self::USER, '  own-profile  '));
    }

    /**
     * An account with NO profile gets one created, named after its username, and
     * the id handed back is re-read through the default-ordering query rather than
     * being the freshly inserted one — see the concurrency note on the method.
     */
    public function testAnAccountWithNoProfileGetsOneCreatedFromItsUsername(): void
    {
        $seen = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param list<mixed> $params
             *
             * @return mixed
             */
            function (string $sql, array $params = []) use (&$seen) {
                // Record only — assertions run outside the callback so a failure
                // cannot be swallowed (S120 assertion-escape rule).
                $seen[] = ['sql' => $sql, 'params' => $params];

                if (str_contains($sql, 'FROM users WHERE id = ?')) {
                    return [['username' => 'ada']];
                }
                if (str_contains($sql, 'COUNT(*) as count FROM user_profiles')) {
                    return [['count' => 0]];
                }
                if (str_contains($sql, 'ORDER BY is_active DESC')) {
                    // First call: no profile yet. Second call (after create): one.
                    $already = 0;
                    foreach ($seen as $entry) {
                        if (str_contains((string) $entry['sql'], 'ORDER BY is_active DESC')) {
                            $already++;
                        }
                    }

                    return $already > 1 ? [['id' => 'created-profile']] : [];
                }

                return 1;
            }
        );

        $manager = new UserProfileManager($db);
        $resolved = $manager->resolveProfileIdForUser(self::USER);

        $this->assertSame('created-profile', $resolved);

        $insert = null;
        foreach ($seen as $entry) {
            if (str_contains((string) $entry['sql'], 'INSERT INTO user_profiles')) {
                $insert = $entry;
                break;
            }
        }

        $this->assertNotNull($insert, 'a profile must actually be inserted for a profile-less account');
        $this->assertContains('ada', $insert['params'], 'the new profile is named after the username');
        $this->assertContains(self::USER, $insert['params'], 'the new profile belongs to the caller');
    }

    /**
     * When the username cannot be used verbatim — absent, blank, or longer than
     * {@see UserProfileManager::MAX_NAME_LENGTH} bytes, which is exactly the
     * predicate `create()` itself validates — the fallback name is used instead of
     * letting `create()` throw.
     *
     * @dataProvider unusableUsernameProvider
     */
    public function testAnUnusableUsernameFallsBackToTheDefaultProfileName(mixed $username): void
    {
        $seen = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param list<mixed> $params
             *
             * @return mixed
             */
            function (string $sql, array $params = []) use (&$seen, $username) {
                $seen[] = ['sql' => $sql, 'params' => $params];

                if (str_contains($sql, 'FROM users WHERE id = ?')) {
                    return [['username' => $username]];
                }
                if (str_contains($sql, 'COUNT(*) as count FROM user_profiles')) {
                    return [['count' => 0]];
                }
                if (str_contains($sql, 'ORDER BY is_active DESC')) {
                    $already = 0;
                    foreach ($seen as $entry) {
                        if (str_contains((string) $entry['sql'], 'ORDER BY is_active DESC')) {
                            $already++;
                        }
                    }

                    return $already > 1 ? [['id' => 'created-profile']] : [];
                }

                return 1;
            }
        );

        $manager = new UserProfileManager($db);
        $manager->resolveProfileIdForUser(self::USER);

        $insert = null;
        foreach ($seen as $entry) {
            if (str_contains((string) $entry['sql'], 'INSERT INTO user_profiles')) {
                $insert = $entry;
                break;
            }
        }

        $this->assertNotNull($insert, 'a profile must still be created');
        $this->assertContains(
            UserProfileManager::DEFAULT_PROFILE_NAME,
            $insert['params'],
            'an unusable username must fall back to the default profile name'
        );
    }

    /**
     * @return array<string, array{0:mixed}>
     */
    public static function unusableUsernameProvider(): array
    {
        return [
            'null' => [null],
            'blank' => ['   '],
            'non-string' => [12345],
            'over the byte limit' => [str_repeat('x', UserProfileManager::MAX_NAME_LENGTH + 1)],
        ];
    }

    /**
     * An empty user id is rejected outright and issues no query. Without this a
     * blank `$request->userId` would resolve "the first profile of the account
     * whose user_id is ''" — i.e. nothing, and then create a profile for a
     * non-existent account.
     */
    public function testAnEmptyUserIdIsRejectedWithoutTouchingTheDatabase(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $manager = new UserProfileManager($db);

        $this->expectException(\InvalidArgumentException::class);
        $manager->resolveProfileIdForUser('   ');
    }

    /**
     * The succeeding control for the test above: a non-empty user id DOES reach
     * the database.
     */
    public function testANonEmptyUserIdReachesTheDatabase(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('query')->willReturn([['id' => 'p']]);

        $manager = new UserProfileManager($db);

        $this->assertSame('p', $manager->resolveProfileIdForUser(self::USER));
    }
}
