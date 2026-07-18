<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the shared parental-control access gate: filter resolution
 * (owner/admin/no-profile/no-cap no-ops), the effective-rating allow decision
 * (own rating + inherited series rating + allow_unrated), and batch list
 * filtering — all with mocked collaborators.
 */
class RatingGateTest extends TestCase
{
    /**
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    private function pg13Filter(bool $allowUnrated = true): array
    {
        return [
            'allowedRatings' => ['G', 'TV-Y', 'TV-G', 'TV-Y7', 'PG', 'TV-PG', 'PG-13', 'TV-14'],
            'allowUnrated' => $allowUnrated,
        ];
    }

    /**
     * @param array<string, string|null> $effective id => effective rating stub
     * @return ItemRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function itemsWithEffective(array $effective = [])
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('effectiveContentRatingsForIds')->willReturnCallback(
            static function (array $ids) use ($effective): array {
                $out = [];
                foreach ($ids as $id) {
                    $out[$id] = $effective[$id] ?? null;
                }
                return $out;
            }
        );
        return $repo;
    }

    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     * @return UserProfileManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function profiles(?array $filter)
    {
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        return $pm;
    }

    /**
     * @return UserRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function users(bool $isAdmin)
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);
        return $repo;
    }

    public function testResolveFilterNullForEmptyUser(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles($this->pg13Filter()), $this->users(false));
        $this->assertNull($gate->resolveFilterForUser(''));
    }

    public function testResolveFilterNullForAdminOwner(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles($this->pg13Filter()), $this->users(true));
        $this->assertNull($gate->resolveFilterForUser('u1'));
    }

    public function testResolveFilterReturnsCapForCappedNonAdmin(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles($this->pg13Filter()), $this->users(false));
        $this->assertSame($this->pg13Filter(), $gate->resolveFilterForUser('u1'));
    }

    public function testResolveFilterNullWhenProfileHasNoCap(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles(null), $this->users(false));
        $this->assertNull($gate->resolveFilterForUser('u1'));
    }

    public function testIsAllowedNullFilterIsAlwaysTrue(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles(null));
        $this->assertTrue($gate->isAllowed(['id' => 'x', 'content_rating' => 'NC-17'], null));
    }

    public function testIsAllowedRowOwnRatingWithinCap(): void
    {
        // Own rating present → decided with zero repository calls.
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('effectiveContentRatingsForIds');
        $gate = new RatingGate($repo, $this->profiles($this->pg13Filter()));

        $this->assertTrue($gate->isAllowed(['id' => 'x', 'content_rating' => 'PG'], $this->pg13Filter()));
    }

    public function testIsAllowedRowOwnRatingOverCap(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('effectiveContentRatingsForIds');
        $gate = new RatingGate($repo, $this->profiles($this->pg13Filter()));

        $this->assertFalse($gate->isAllowed(['id' => 'x', 'content_rating' => 'R'], $this->pg13Filter()));
    }

    public function testIsAllowedEpisodeInheritsAllowedSeriesRating(): void
    {
        // Null own rating + parent → walk to the series (PG) → allowed.
        $gate = new RatingGate(
            $this->itemsWithEffective(['show-1' => 'PG']),
            $this->profiles($this->pg13Filter())
        );

        $row = ['id' => 'ep-1', 'content_rating' => null, 'parent_id' => 'show-1'];
        $this->assertTrue($gate->isAllowed($row, $this->pg13Filter()));
    }

    public function testIsAllowedEpisodeInheritsBlockedSeriesRating(): void
    {
        $gate = new RatingGate(
            $this->itemsWithEffective(['show-1' => 'R']),
            $this->profiles($this->pg13Filter())
        );

        $row = ['id' => 'ep-1', 'content_rating' => null, 'parent_id' => 'show-1'];
        $this->assertFalse($gate->isAllowed($row, $this->pg13Filter()));
    }

    public function testIsAllowedGenuinelyUnratedBlockedWhenAllowUnratedFalse(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles($this->pg13Filter(false)));

        $row = ['id' => 'x', 'content_rating' => null];
        $this->assertFalse($gate->isAllowed($row, $this->pg13Filter(false)));
    }

    public function testIsAllowedGenuinelyUnratedAllowedWhenAllowUnratedTrue(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles($this->pg13Filter(true)));

        $row = ['id' => 'x', 'content_rating' => null];
        $this->assertTrue($gate->isAllowed($row, $this->pg13Filter(true)));
    }

    public function testIsAllowedByIdUsesEffectiveLookup(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(['ep-9' => 'R']), $this->profiles($this->pg13Filter()));
        $this->assertFalse($gate->isAllowed('ep-9', $this->pg13Filter()));
    }

    public function testFilterItemsNullFilterIsIdentity(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles(null));
        $items = [['id' => 'a'], ['id' => 'b']];
        $this->assertSame($items, $gate->filterItems($items, null));
    }

    public function testFilterItemsDropsOverCapByIdLookup(): void
    {
        // Rows without a content_rating column → resolved by id in one batch.
        $gate = new RatingGate(
            $this->itemsWithEffective(['a' => 'PG', 'b' => 'R', 'c' => null]),
            $this->profiles($this->pg13Filter())
        );

        $items = [['id' => 'a'], ['id' => 'b'], ['id' => 'c']];
        $result = $gate->filterItems($items, $this->pg13Filter(), 'id');

        $ids = array_column($result, 'id');
        $this->assertSame(['a', 'c'], $ids); // R dropped; null kept (allow_unrated)
    }

    public function testFilterItemsHonorsCustomIdKeyAndOwnRating(): void
    {
        // Rows carry their own rating → decided without a repo lookup; the id key
        // is `media_item_id` (continue-watching shape).
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('effectiveContentRatingsForIds');
        $gate = new RatingGate($repo, $this->profiles($this->pg13Filter()));

        $items = [
            ['media_item_id' => 'a', 'content_rating' => 'PG'],
            ['media_item_id' => 'b', 'content_rating' => 'NC-17'],
        ];
        $result = $gate->filterItems($items, $this->pg13Filter(), 'media_item_id');

        $this->assertSame(['a'], array_column($result, 'media_item_id'));
    }

    public function testFilterItemsFailsClosedForUnidentifiableRow(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles($this->pg13Filter()));

        $items = [['name' => 'no id here'], ['id' => 'ok', 'content_rating' => 'G']];
        $result = $gate->filterItems($items, $this->pg13Filter(), 'id');

        $this->assertSame(['ok'], array_column($result, 'id'));
    }
}
