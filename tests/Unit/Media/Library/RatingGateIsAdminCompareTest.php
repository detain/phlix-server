<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use PHPUnit\Framework\TestCase;

/**
 * R18-16 pin: the account-owner shortcut in RatingGate reads users.is_admin with
 * the estate `!empty()` idiom. The TINYINT arrives as int 1 OR string "1"
 * depending on driver prepare-emulation, so both representations must gate
 * identically — and every other falsy-shaped value (0, "0", null, missing)
 * must NOT bypass the cap.
 */
class RatingGateIsAdminCompareTest extends TestCase
{
    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     */
    private function gateWithAdminFlag(mixed $flag, ?array $filter): ?array
    {
        $items = $this->createMock(ItemRepository::class);
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        $users = $this->createMock(UserRepository::class);
        $row = ['id' => 'u1'];
        if ($flag !== null) {
            $row['is_admin'] = $flag;
        }
        $users->method('findById')->willReturn($row);

        $gate = new RatingGate($items, $pm, $users);

        return $gate->resolveFilterForUser('u1');
    }

    public function testIntAdminFlagBypassesTheCap(): void
    {
        $this->assertNull($this->gateWithAdminFlag(1, ['allowedRatings' => ['G'], 'allowUnrated' => false]));
    }

    public function testStringAdminFlagBypassesTheCap(): void
    {
        $this->assertNull($this->gateWithAdminFlag('1', ['allowedRatings' => ['G'], 'allowUnrated' => false]));
    }

    public function testIntZeroIsNotAdmin(): void
    {
        $filter = ['allowedRatings' => ['G'], 'allowUnrated' => false];
        $this->assertSame($filter, $this->gateWithAdminFlag(0, $filter));
    }

    public function testStringZeroIsNotAdmin(): void
    {
        $filter = ['allowedRatings' => ['G'], 'allowUnrated' => false];
        $this->assertSame($filter, $this->gateWithAdminFlag('0', $filter));
    }

    public function testMissingAdminColumnIsNotAdmin(): void
    {
        $filter = ['allowedRatings' => ['G'], 'allowUnrated' => false];
        $this->assertSame($filter, $this->gateWithAdminFlag(null, $filter));
    }
}
