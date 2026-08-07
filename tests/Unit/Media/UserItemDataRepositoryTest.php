<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Auth\ProfileNotOwnedException;
use Phlix\Auth\UserProfileManager;
use Phlix\Media\UserItemDataRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see UserItemDataRepository} (E10 favorites/ratings, re-scoped
 * per profile by S79).
 *
 * The Workerman MySQL connection is mocked; assertions cover the SQL shape,
 * positional binding order (flat `[$a, $b]` arrays — the codebase idiom),
 * upsert clauses, rating-range validation, null handling, and row coercion.
 *
 * S79 additions: every statement must name `profile_id`, and the bound profile
 * must be the one {@see UserProfileManager::resolveProfileIdForUser()} returned —
 * never a value the caller handed in unchecked.
 */
class UserItemDataRepositoryTest extends TestCase
{
    private const USER = 'user-1';
    private const ITEM = 'item-1';

    /** The profile id the mocked resolver hands back for {@see self::USER}. */
    private const PROFILE = 'profile-1';

    /**
     * Build a repository whose profile resolver always returns {@see self::PROFILE}.
     */
    private function repo(Connection $db): UserItemDataRepository
    {
        return new UserItemDataRepository($db, $this->resolverReturning(self::PROFILE));
    }

    /**
     * A {@see UserProfileManager} double whose `resolveProfileIdForUser()` returns
     * `$profileId` for any input.
     */
    private function resolverReturning(string $profileId): UserProfileManager
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('resolveProfileIdForUser')->willReturn($profileId);

        return $profiles;
    }

    public function testGetItemDataReturnsNullWhenNoRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT favorite, rating, like_level, watched FROM user_item_data'),
                $this->equalTo([self::USER, self::PROFILE, self::ITEM])
            )
            ->willReturn([]);

        $this->assertNull($this->repo($db)->getItemData(self::USER, self::ITEM));
    }

    public function testGetItemDataCoercesFavoriteAndRating(): void
    {
        $db = $this->createMock(Connection::class);
        // The driver returns string column values; the repo must coerce.
        $db->method('query')->willReturn([
            ['favorite' => '1', 'rating' => '7', 'like_level' => '2', 'watched' => '1'],
        ]);

        $data = $this->repo($db)->getItemData(self::USER, self::ITEM);

        $this->assertSame(
            ['favorite' => true, 'rating' => 7, 'like_level' => 2, 'watched' => true],
            $data
        );
    }

    public function testGetItemDataNullRatingCoercesToNull(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['favorite' => '0', 'rating' => null, 'like_level' => '0', 'watched' => '0'],
        ]);

        $data = $this->repo($db)->getItemData(self::USER, self::ITEM);

        $this->assertSame(
            ['favorite' => false, 'rating' => null, 'like_level' => 0, 'watched' => false],
            $data
        );
    }

    public function testGetItemDataSelectsWatchedColumnAndCoercesToBool(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT favorite, rating, like_level, watched FROM user_item_data'),
                $this->equalTo([self::USER, self::PROFILE, self::ITEM])
            )
            ->willReturn([['favorite' => '0', 'rating' => null, 'like_level' => '0', 'watched' => '1']]);

        $data = $this->repo($db)->getItemData(self::USER, self::ITEM);

        $this->assertNotNull($data);
        $this->assertTrue($data['watched'], 'string "1" watched column coerces to bool true');
    }

    public function testGetItemDataWatchedDefaultsToFalseWhenColumnAbsentOrNull(): void
    {
        // Column missing entirely → default false.
        $dbAbsent = $this->createMock(Connection::class);
        $dbAbsent->method('query')->willReturn([['favorite' => '1', 'rating' => '5', 'like_level' => '0']]);
        $absent = $this->repo($dbAbsent)->getItemData(self::USER, self::ITEM);
        $this->assertNotNull($absent);
        $this->assertFalse($absent['watched']);

        // Column present but NULL → default false.
        $dbNull = $this->createMock(Connection::class);
        $dbNull->method('query')->willReturn([
            ['favorite' => '1', 'rating' => '5', 'like_level' => '0', 'watched' => null],
        ]);
        $null = $this->repo($dbNull)->getItemData(self::USER, self::ITEM);
        $this->assertNotNull($null);
        $this->assertFalse($null['watched']);
    }

    public function testGetItemDataSelectsLikeLevelColumn(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT favorite, rating, like_level, watched FROM user_item_data'),
                $this->equalTo([self::USER, self::PROFILE, self::ITEM])
            )
            ->willReturn([['favorite' => '0', 'rating' => null, 'like_level' => '-2']]);

        $data = $this->repo($db)->getItemData(self::USER, self::ITEM);

        // Signed thumbs axis: the driver returns the column as a string; a
        // negative dislike value must coerce back to a negative int.
        $this->assertNotNull($data);
        $this->assertSame(-2, $data['like_level']);
    }

    public function testGetItemDataLikeLevelDefaultsToZeroWhenColumnAbsentOrNull(): void
    {
        // Column missing from the row entirely → default 0.
        $dbAbsent = $this->createMock(Connection::class);
        $dbAbsent->method('query')->willReturn([['favorite' => '1', 'rating' => '5']]);
        $absent = $this->repo($dbAbsent)->getItemData(self::USER, self::ITEM);
        $this->assertNotNull($absent);
        $this->assertSame(0, $absent['like_level']);

        // Column present but NULL → default 0.
        $dbNull = $this->createMock(Connection::class);
        $dbNull->method('query')->willReturn([['favorite' => '1', 'rating' => '5', 'like_level' => null]]);
        $null = $this->repo($dbNull)->getItemData(self::USER, self::ITEM);
        $this->assertNotNull($null);
        $this->assertSame(0, $null['like_level']);
    }

    public function testSetFavoriteUpsertsWithOneAsTrue(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO user_item_data (user_id, profile_id, item_id, favorite)'),
                    $this->stringContains('ON DUPLICATE KEY UPDATE favorite = VALUES(favorite)')
                ),
                $this->equalTo([self::USER, self::PROFILE, self::ITEM, 1])
            )
            ->willReturn(1);

        $this->repo($db)->setFavorite(self::USER, self::ITEM, true);
    }

    public function testSetFavoriteFalseBindsZero(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->equalTo([self::USER, self::PROFILE, self::ITEM, 0]))
            ->willReturn(1);

        $this->repo($db)->setFavorite(self::USER, self::ITEM, false);
    }

    public function testSetWatchedTrueBindsOne(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO user_item_data'),
                    $this->stringContains('watched'),
                    $this->stringContains('ON DUPLICATE KEY UPDATE watched = VALUES(watched)')
                ),
                $this->equalTo([self::USER, self::PROFILE, self::ITEM, 1])
            )
            ->willReturn(1);

        $this->repo($db)->setWatched(self::USER, self::ITEM, true);
    }

    public function testSetWatchedFalseBindsZero(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->equalTo([self::USER, self::PROFILE, self::ITEM, 0]))
            ->willReturn(1);

        $this->repo($db)->setWatched(self::USER, self::ITEM, false);
    }

    public function testSetRatingUpsertsValidRating(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO user_item_data (user_id, profile_id, item_id, rating)'),
                    $this->stringContains('ON DUPLICATE KEY UPDATE rating = VALUES(rating)')
                ),
                $this->equalTo([self::USER, self::PROFILE, self::ITEM, 5])
            )
            ->willReturn(1);

        $this->repo($db)->setRating(self::USER, self::ITEM, 5);
    }

    public function testSetRatingNullClearsRating(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->equalTo([self::USER, self::PROFILE, self::ITEM, null]))
            ->willReturn(1);

        $this->repo($db)->setRating(self::USER, self::ITEM, null);
    }

    public function testSetRatingAcceptsBoundaryValues(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(1);
        $repo = $this->repo($db);

        $repo->setRating(self::USER, self::ITEM, UserItemDataRepository::MIN_RATING);
        $repo->setRating(self::USER, self::ITEM, UserItemDataRepository::MAX_RATING);

        $this->addToAssertionCount(1);
    }

    public function testSetRatingRejectsBelowRange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $this->expectException(\InvalidArgumentException::class);
        $this->repo($db)->setRating(self::USER, self::ITEM, 0);
    }

    public function testSetRatingRejectsAboveRange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $this->expectException(\InvalidArgumentException::class);
        $this->repo($db)->setRating(self::USER, self::ITEM, 11);
    }

    /**
     * @return list<array{0:int}>
     */
    public static function validLikeLevelProvider(): array
    {
        return [[-2], [-1], [0], [1], [2]];
    }

    /**
     * @dataProvider validLikeLevelProvider
     */
    public function testSetLikeLevelUpsertsEachValidLevel(int $level): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO user_item_data'),
                    $this->stringContains('like_level'),
                    $this->stringContains('ON DUPLICATE KEY UPDATE like_level = VALUES(like_level)')
                ),
                $this->equalTo([self::USER, self::PROFILE, self::ITEM, $level])
            )
            ->willReturn(1);

        $this->repo($db)->setLikeLevel(self::USER, self::ITEM, $level);
    }

    public function testSetLikeLevelAcceptsBoundaryConstants(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(1);
        $repo = $this->repo($db);

        $repo->setLikeLevel(self::USER, self::ITEM, UserItemDataRepository::MIN_LIKE);
        $repo->setLikeLevel(self::USER, self::ITEM, UserItemDataRepository::MAX_LIKE);

        $this->addToAssertionCount(1);
    }

    public function testSetLikeLevelRejectsAboveRange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('like_level must be between -2 and 2 (inclusive), got 3');
        $this->repo($db)->setLikeLevel(self::USER, self::ITEM, 3);
    }

    public function testSetLikeLevelRejectsBelowRange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('like_level must be between -2 and 2 (inclusive), got -3');
        $this->repo($db)->setLikeLevel(self::USER, self::ITEM, -3);
    }

    public function testGetFavoritesJoinsMediaItemsAndPaginates(): void
    {
        $rows = [
            ['item_id' => 'item-1', 'rating' => '7', 'media_name' => 'A'],
            ['item_id' => 'item-2', 'rating' => null, 'media_name' => 'B'],
        ];

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('JOIN media_items mi ON uid.item_id = mi.id'),
                    $this->stringContains('uid.profile_id = ?'),
                    $this->stringContains('uid.favorite = 1'),
                    $this->stringContains('uid.like_level'),
                    $this->stringContains('uid.watched'),
                    $this->stringContains('LIMIT ? OFFSET ?')
                ),
                $this->equalTo([self::USER, self::PROFILE, 25, 50])
            )
            ->willReturn($rows);

        $result = $this->repo($db)->getFavorites(self::USER, 25, 50);

        $this->assertCount(2, $result);
        $this->assertSame('A', $result[0]['media_name']);
    }

    public function testGetFavoritesReturnsEmptyListWhenNoRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(false);

        $this->assertSame([], $this->repo($db)->getFavorites(self::USER));
    }

    public function testDeleteByItemRemovesAllRowsForItem(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM user_item_data WHERE item_id = ?'),
                $this->equalTo([self::ITEM])
            )
            ->willReturn(1);

        // deleteByItem is item-wide by design (a deleted media item leaves no
        // per-profile rows behind), so it takes no profile and must not resolve one.
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->never())->method('resolveProfileIdForUser');

        (new UserItemDataRepository($db, $profiles))->deleteByItem(self::ITEM);
    }

    // ---- S79: profile scoping and the ownership boundary ---------------------

    /**
     * Every read and write hands the caller-supplied `$profileId` to the resolver
     * and binds what the RESOLVER returned — not what the caller asked for.
     *
     * This is the unit-level statement of the horizontal-privilege rule. The
     * resolver here deliberately returns a DIFFERENT id from the one requested, so
     * a repository that passed the request value straight through to SQL would
     * bind `'attacker-supplied'` and fail.
     *
     * @dataProvider scopedOperationProvider
     */
    public function testEveryOperationBindsTheResolvedProfileNotTheRequestedOne(string $operation): void
    {
        $bound = null;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param list<mixed> $params */
            function (string $sql, array $params = []) use (&$bound): array {
                // Record only; the assertion runs OUTSIDE this callback so a
                // failure cannot be swallowed (S120 assertion-escape rule).
                $bound = $params;

                return [];
            }
        );

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->once())
            ->method('resolveProfileIdForUser')
            ->with(self::USER, 'attacker-supplied')
            ->willReturn('resolved-profile');

        $repo = new UserItemDataRepository($db, $profiles);

        switch ($operation) {
            case 'getItemData':
                $repo->getItemData(self::USER, self::ITEM, 'attacker-supplied');
                break;
            case 'setFavorite':
                $repo->setFavorite(self::USER, self::ITEM, true, 'attacker-supplied');
                break;
            case 'setRating':
                $repo->setRating(self::USER, self::ITEM, 5, 'attacker-supplied');
                break;
            case 'setLikeLevel':
                $repo->setLikeLevel(self::USER, self::ITEM, 1, 'attacker-supplied');
                break;
            case 'setWatched':
                $repo->setWatched(self::USER, self::ITEM, true, 'attacker-supplied');
                break;
            case 'getFavorites':
                $repo->getFavorites(self::USER, 10, 0, 'attacker-supplied');
                break;
            default:
                self::fail('unknown operation ' . $operation);
        }

        $this->assertIsArray($bound, $operation . ' must issue a query');
        $this->assertContains(
            'resolved-profile',
            $bound,
            $operation . ' must bind the RESOLVED profile id'
        );
        $this->assertNotContains(
            'attacker-supplied',
            $bound,
            $operation . ' must never bind a caller-supplied profile id directly'
        );
    }

    /**
     * @return list<array{0:string}>
     */
    public static function scopedOperationProvider(): array
    {
        return [
            ['getItemData'],
            ['setFavorite'],
            ['setRating'],
            ['setLikeLevel'],
            ['setWatched'],
            ['getFavorites'],
        ];
    }

    /**
     * A refusal from the resolver propagates and NO SQL runs.
     *
     * Paired with {@see self::testAnOwnedProfileIsAcceptedAndQueried()} below,
     * which is the succeeding control: a refusal on its own would also be produced
     * by a repository that never queried at all, so the two must sit together.
     */
    public function testARefusedProfileThrowsAndIssuesNoQuery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('resolveProfileIdForUser')
            ->willThrowException(ProfileNotOwnedException::forRequestedProfile(self::USER, 'other-users-profile'));

        $this->expectException(ProfileNotOwnedException::class);

        (new UserItemDataRepository($db, $profiles))
            ->setFavorite(self::USER, self::ITEM, true, 'other-users-profile');
    }

    /**
     * The succeeding control for {@see self::testARefusedProfileThrowsAndIssuesNoQuery()}:
     * the SAME call shape with an OWNED profile does reach SQL and binds it.
     */
    public function testAnOwnedProfileIsAcceptedAndQueried(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO user_item_data'),
                $this->equalTo([self::USER, 'own-profile', self::ITEM, 1])
            )
            ->willReturn(1);

        (new UserItemDataRepository($db, $this->resolverReturning('own-profile')))
            ->setFavorite(self::USER, self::ITEM, true, 'own-profile');
    }

    /**
     * Passing no profile still resolves one — the pre-S79 call shape keeps working
     * and lands on the account's active/first profile.
     */
    public function testOmittingTheProfileResolvesTheAccountDefault(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(1);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects($this->once())
            ->method('resolveProfileIdForUser')
            ->with(self::USER, null)
            ->willReturn('default-profile');

        (new UserItemDataRepository($db, $profiles))->setFavorite(self::USER, self::ITEM, true);
    }
}
