<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * #6b — resolveFilterForUser() is memoised per (user, effective profile) for 30 s.
 *
 * Staleness bound: a cap, admin flag or active-profile change reaches this worker
 * within FILTER_MEMO_TTL_NS. These cases pin the saving (one lookup per window), the
 * key separation (a switched profile never reads another profile's cap), the cached
 * owner shortcut, expiry, and the memo bound.
 */
final class RatingGateFilterMemoTest extends TestCase
{
    private const CAP = ['allowedRatings' => ['G', 'PG'], 'allowUnrated' => false];

    public function test_a_repeat_resolution_for_the_same_user_and_profile_skips_the_lookups(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::once())->method('getActiveRatingFilter')->willReturn(self::CAP);
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);

        $gate = new RatingGate($this->createMock(ItemRepository::class), $profiles, $users);

        self::assertSame(self::CAP, $gate->resolveFilterForUser('u1', 'kid'));
        self::assertSame(self::CAP, $gate->resolveFilterForUser('u1', 'kid'));
        self::assertSame(self::CAP, $gate->resolveFilterForUser('u1', 'kid'));
    }

    public function test_a_different_profile_of_the_same_user_is_resolved_separately(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::exactly(2))->method('getActiveRatingFilter')->willReturnCallback(
            static fn (string $userId, ?string $profileId): ?array => $profileId === 'kid' ? self::CAP : null,
        );
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);

        $gate = new RatingGate($this->createMock(ItemRepository::class), $profiles, $users);

        self::assertSame(self::CAP, $gate->resolveFilterForUser('u1', 'kid'));
        self::assertNull($gate->resolveFilterForUser('u1', 'adult'), 'a switched profile must never read the kid cap');
    }

    public function test_the_owner_shortcut_is_cached_as_no_gating(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::never())->method('getActiveRatingFilter');
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 1]);

        $gate = new RatingGate($this->createMock(ItemRepository::class), $profiles, $users);

        self::assertNull($gate->resolveFilterForUser('u1', 'kid'));
        self::assertNull($gate->resolveFilterForUser('u1', 'kid'));
    }

    public function test_an_expired_entry_is_resolved_again(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::exactly(2))->method('getActiveRatingFilter')->willReturn(self::CAP);
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);
        $gate = new RatingGate($this->createMock(ItemRepository::class), $profiles, $users);

        $gate->resolveFilterForUser('u1', 'kid');

        // Age the entry past the 30 s window.
        $memo = new ReflectionProperty(RatingGate::class, 'filterMemo');
        /** @var array<string, array{at: int, filter: mixed}> $entries */
        $entries = $memo->getValue($gate);
        foreach ($entries as $key => $entry) {
            $entries[$key]['at'] = hrtime(true) - 31_000_000_000;
        }
        $memo->setValue($gate, $entries);

        $gate->resolveFilterForUser('u1', 'kid');
    }

    public function test_the_memo_is_bounded_and_prunes_aged_entries(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveRatingFilter')->willReturn(self::CAP);
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);
        $gate = new RatingGate($this->createMock(ItemRepository::class), $profiles, $users);

        $memo = new ReflectionProperty(RatingGate::class, 'filterMemo');
        $aged = [];
        for ($i = 0; $i < 600; $i++) {
            $aged['old-' . $i] = ['at' => hrtime(true) - 60_000_000_000, 'filter' => null];
        }
        $memo->setValue($gate, $aged);

        $gate->resolveFilterForUser('u1', 'kid');

        /** @var array<string, mixed> $after */
        $after = $memo->getValue($gate);
        self::assertCount(1, $after, 'the aged-out entries are pruned; only the live key remains');
    }
}
