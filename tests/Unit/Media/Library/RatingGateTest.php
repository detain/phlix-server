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

    /**
     * S235 — the whole point of the step. This test REPLACES
     * `testResolveFilterNullForEmptyUser`, which asserted the defect: while an
     * empty user id resolved to `null`, "no user" and "no cap" shared one
     * representation and every `if ($filter !== null && …)` guard in the server
     * skipped the check for an anonymous caller.
     */
    public function testResolveFilterForAnEmptyUserIsADenyAllCapAndNotNull(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles($this->pg13Filter()), $this->users(false));

        $filter = $gate->resolveFilterForUser('');

        $this->assertNotNull($filter, '"no user" must not be represented as "no cap"');
        $this->assertSame(RatingGate::denyAll(), $filter);
    }

    /**
     * The deny-all cap must actually deny — both a rated item whose rating is
     * well inside any ordinary cap, and a genuinely-unrated one.
     */
    public function testTheNoUserCapDeniesRatedAndUnratedItemsAlike(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(['m-null' => null]), $this->profiles(null));
        $deny = RatingGate::denyAll();

        $this->assertFalse($gate->isAllowed(['id' => 'm1', 'content_rating' => 'G'], $deny));
        $this->assertFalse($gate->isAllowed(['id' => 'm2', 'content_rating' => 'TV-Y'], $deny));
        $this->assertFalse($gate->isAllowed(['id' => 'm-null', 'content_rating' => null], $deny));
        $this->assertSame([], $gate->filterItems(
            [['id' => 'm1', 'content_rating' => 'G'], ['id' => 'm-null', 'content_rating' => null]],
            $deny
        ));
    }

    /**
     * 🔬 The trap this representation exists to avoid. The obvious spelling of
     * "deny everything" — an EMPTY allow-list — is a silent FAIL-OPEN in the SQL
     * enforcement path: {@see ItemRepository::ratingCapClause()} documents and
     * implements "an empty allow-list yields an empty clause (no filtering)",
     * and `WebPortalRouter::applyRatingFilter()` merges the resolved cap straight
     * into those query params. So the cap MUST carry a non-empty allow-list,
     * whose single entry matches no real `content_rating`.
     *
     * Measured end-to-end below rather than asserted structurally: the cap is
     * threaded through the real `ItemRepository::query()` and the emitted SQL is
     * captured.
     */
    public function testTheNoUserCapProducesARealSqlClauseSoTheBrowsePathCannotFailOpen(): void
    {
        $this->assertNotSame([], RatingGate::denyAll()['allowedRatings']);
        $this->assertFalse(RatingGate::denyAll()['allowUnrated']);

        $captured = [];
        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use (&$captured): array {
                $captured[] = $sql;
                return [];
            }
        );

        $repo = new ItemRepository($db);
        $repo->query(RatingGate::denyAll() + ['limit' => 10, 'offset' => 0]);

        $selects = array_filter($captured, static fn (string $s): bool => str_contains($s, 'content_rating IN'));
        $this->assertNotSame([], $selects, 'the deny-all cap must reach the SQL as a real, narrowing clause');
        foreach ($selects as $sql) {
            $this->assertStringNotContainsString(
                'content_rating IS NULL',
                $sql,
                'the deny-all cap must not re-admit unrated rows'
            );
        }
    }

    /**
     * The explicitly-named opt-out used by the signed-URL serve paths (HLS/DASH
     * files, OPDS/book bytes), where "no userId" means "a valid signature was
     * presented" rather than "nobody asked". Failing closed there would 404 every
     * `<video>`/e-reader fetch, so it stays permissive — and only there.
     */
    public function testResolveFilterForASignedRequestStaysPermissiveForAnEmptyUser(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles($this->pg13Filter()), $this->users(false));
        $this->assertNull($gate->resolveFilterForSignedRequest(''));
    }

    public function testResolveFilterForASignedRequestStillCapsAnIdentifiedUser(): void
    {
        $gate = new RatingGate($this->itemsWithEffective(), $this->profiles($this->pg13Filter()), $this->users(false));
        $this->assertSame($this->pg13Filter(), $gate->resolveFilterForSignedRequest('u1'));
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
