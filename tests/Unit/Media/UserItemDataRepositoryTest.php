<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Media\UserItemDataRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see UserItemDataRepository} (E10 favorites/ratings).
 *
 * The Workerman MySQL connection is mocked; assertions cover the SQL shape,
 * positional binding order (flat `[$a, $b]` arrays — the codebase idiom),
 * upsert clauses, rating-range validation, null handling, and row coercion.
 */
class UserItemDataRepositoryTest extends TestCase
{
    private const USER = 'user-1';
    private const ITEM = 'item-1';

    public function testGetItemDataReturnsNullWhenNoRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT favorite, rating, like_level, watched FROM user_item_data'),
                $this->equalTo([self::USER, self::ITEM])
            )
            ->willReturn([]);

        $repo = new UserItemDataRepository($db);

        $this->assertNull($repo->getItemData(self::USER, self::ITEM));
    }

    public function testGetItemDataCoercesFavoriteAndRating(): void
    {
        $db = $this->createMock(Connection::class);
        // The driver returns string column values; the repo must coerce.
        $db->method('query')->willReturn([
            ['favorite' => '1', 'rating' => '7', 'like_level' => '2', 'watched' => '1'],
        ]);

        $repo = new UserItemDataRepository($db);
        $data = $repo->getItemData(self::USER, self::ITEM);

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

        $repo = new UserItemDataRepository($db);
        $data = $repo->getItemData(self::USER, self::ITEM);

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
                $this->equalTo([self::USER, self::ITEM])
            )
            ->willReturn([['favorite' => '0', 'rating' => null, 'like_level' => '0', 'watched' => '1']]);

        $data = (new UserItemDataRepository($db))->getItemData(self::USER, self::ITEM);

        $this->assertNotNull($data);
        $this->assertTrue($data['watched'], 'string "1" watched column coerces to bool true');
    }

    public function testGetItemDataWatchedDefaultsToFalseWhenColumnAbsentOrNull(): void
    {
        // Column missing entirely → default false.
        $dbAbsent = $this->createMock(Connection::class);
        $dbAbsent->method('query')->willReturn([['favorite' => '1', 'rating' => '5', 'like_level' => '0']]);
        $absent = (new UserItemDataRepository($dbAbsent))->getItemData(self::USER, self::ITEM);
        $this->assertNotNull($absent);
        $this->assertFalse($absent['watched']);

        // Column present but NULL → default false.
        $dbNull = $this->createMock(Connection::class);
        $dbNull->method('query')->willReturn([
            ['favorite' => '1', 'rating' => '5', 'like_level' => '0', 'watched' => null],
        ]);
        $null = (new UserItemDataRepository($dbNull))->getItemData(self::USER, self::ITEM);
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
                $this->equalTo([self::USER, self::ITEM])
            )
            ->willReturn([['favorite' => '0', 'rating' => null, 'like_level' => '-2']]);

        $data = (new UserItemDataRepository($db))->getItemData(self::USER, self::ITEM);

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
        $absent = (new UserItemDataRepository($dbAbsent))->getItemData(self::USER, self::ITEM);
        $this->assertNotNull($absent);
        $this->assertSame(0, $absent['like_level']);

        // Column present but NULL → default 0.
        $dbNull = $this->createMock(Connection::class);
        $dbNull->method('query')->willReturn([['favorite' => '1', 'rating' => '5', 'like_level' => null]]);
        $null = (new UserItemDataRepository($dbNull))->getItemData(self::USER, self::ITEM);
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
                    $this->stringContains('INSERT INTO user_item_data'),
                    $this->stringContains('ON DUPLICATE KEY UPDATE favorite = VALUES(favorite)')
                ),
                $this->equalTo([self::USER, self::ITEM, 1])
            )
            ->willReturn(1);

        (new UserItemDataRepository($db))->setFavorite(self::USER, self::ITEM, true);
    }

    public function testSetFavoriteFalseBindsZero(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->equalTo([self::USER, self::ITEM, 0]))
            ->willReturn(1);

        (new UserItemDataRepository($db))->setFavorite(self::USER, self::ITEM, false);
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
                $this->equalTo([self::USER, self::ITEM, 1])
            )
            ->willReturn(1);

        (new UserItemDataRepository($db))->setWatched(self::USER, self::ITEM, true);
    }

    public function testSetWatchedFalseBindsZero(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->equalTo([self::USER, self::ITEM, 0]))
            ->willReturn(1);

        (new UserItemDataRepository($db))->setWatched(self::USER, self::ITEM, false);
    }

    public function testSetRatingUpsertsValidRating(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO user_item_data'),
                    $this->stringContains('ON DUPLICATE KEY UPDATE rating = VALUES(rating)')
                ),
                $this->equalTo([self::USER, self::ITEM, 5])
            )
            ->willReturn(1);

        (new UserItemDataRepository($db))->setRating(self::USER, self::ITEM, 5);
    }

    public function testSetRatingNullClearsRating(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->equalTo([self::USER, self::ITEM, null]))
            ->willReturn(1);

        (new UserItemDataRepository($db))->setRating(self::USER, self::ITEM, null);
    }

    public function testSetRatingAcceptsBoundaryValues(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(1);
        $repo = new UserItemDataRepository($db);

        $repo->setRating(self::USER, self::ITEM, UserItemDataRepository::MIN_RATING);
        $repo->setRating(self::USER, self::ITEM, UserItemDataRepository::MAX_RATING);

        $this->addToAssertionCount(1);
    }

    public function testSetRatingRejectsBelowRange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $this->expectException(\InvalidArgumentException::class);
        (new UserItemDataRepository($db))->setRating(self::USER, self::ITEM, 0);
    }

    public function testSetRatingRejectsAboveRange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $this->expectException(\InvalidArgumentException::class);
        (new UserItemDataRepository($db))->setRating(self::USER, self::ITEM, 11);
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
                $this->equalTo([self::USER, self::ITEM, $level])
            )
            ->willReturn(1);

        (new UserItemDataRepository($db))->setLikeLevel(self::USER, self::ITEM, $level);
    }

    public function testSetLikeLevelAcceptsBoundaryConstants(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(1);
        $repo = new UserItemDataRepository($db);

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
        (new UserItemDataRepository($db))->setLikeLevel(self::USER, self::ITEM, 3);
    }

    public function testSetLikeLevelRejectsBelowRange(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('like_level must be between -2 and 2 (inclusive), got -3');
        (new UserItemDataRepository($db))->setLikeLevel(self::USER, self::ITEM, -3);
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
                    $this->stringContains('uid.favorite = 1'),
                    $this->stringContains('uid.like_level'),
                    $this->stringContains('uid.watched'),
                    $this->stringContains('LIMIT ? OFFSET ?')
                ),
                $this->equalTo([self::USER, 25, 50])
            )
            ->willReturn($rows);

        $result = (new UserItemDataRepository($db))->getFavorites(self::USER, 25, 50);

        $this->assertCount(2, $result);
        $this->assertSame('A', $result[0]['media_name']);
    }

    public function testGetFavoritesReturnsEmptyListWhenNoRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(false);

        $this->assertSame([], (new UserItemDataRepository($db))->getFavorites(self::USER));
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

        (new UserItemDataRepository($db))->deleteByItem(self::ITEM);
    }
}
