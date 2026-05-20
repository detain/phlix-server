<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\Dto;

use Phlix\Auth\Dto\UserProfileRow;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the UserProfileRow DTO.
 */
class UserProfileRowTest extends TestCase
{
    public function testFromRowHydratesCoreColumns(): void
    {
        $row = UserProfileRow::fromRow([
            'id' => 'prof-1',
            'user_id' => 'u-1',
            'name' => 'Alice',
            'avatar_url' => 'https://x/a.jpg',
            'is_active' => 1,
            'is_admin' => 0,
            'created_at' => '2026-05-18',
            'updated_at' => '2026-05-19',
        ]);

        $this->assertSame('prof-1', $row->id);
        $this->assertSame('u-1', $row->userId);
        $this->assertSame('Alice', $row->name);
        $this->assertSame('https://x/a.jpg', $row->avatarUrl);
        $this->assertTrue($row->isActive);
        $this->assertFalse($row->isAdmin);
        $this->assertNull($row->settings);
    }

    public function testFromRowAttachesSettingsWhenJoined(): void
    {
        $row = UserProfileRow::fromRow([
            'id' => 'prof-1',
            'user_id' => 'u-1',
            'name' => 'Kids',
            'avatar_url' => null,
            'is_active' => true,
            'is_admin' => false,
            'created_at' => null,
            'updated_at' => null,
            'content_rating' => 'G',
            'pin_required_for_admin' => 1,
            'max_daily_watch_time' => '3600',
            'allow_unrated' => 0,
            'allowed_genres' => '["Animation","Family"]',
            'blocked_genres' => null,
        ]);

        $this->assertNotNull($row->settings);
        $this->assertSame('G', $row->settings['content_rating']);
        $this->assertTrue($row->settings['pin_required_for_admin']);
        $this->assertSame(3600, $row->settings['max_daily_watch_time']);
        $this->assertFalse($row->settings['allow_unrated']);
        $this->assertSame(['Animation', 'Family'], $row->settings['allowed_genres'] ?? null);
        $this->assertArrayNotHasKey('blocked_genres', $row->settings);
    }

    public function testToArrayMatchesShape(): void
    {
        $row = UserProfileRow::fromRow([
            'id' => 'p-1',
            'user_id' => 'u-1',
            'name' => 'Alice',
            'is_active' => true,
            'is_admin' => false,
            'content_rating' => 'R',
            'allow_unrated' => true,
        ]);

        $arr = $row->toArray();
        $this->assertSame('p-1', $arr['id']);
        $this->assertArrayHasKey('settings', $arr);
        $this->assertSame('R', $arr['settings']['content_rating']);
    }

    public function testFromRowToleratesMissingColumns(): void
    {
        $row = UserProfileRow::fromRow([]);

        $this->assertSame('', $row->id);
        $this->assertNull($row->avatarUrl);
        $this->assertNull($row->settings);
    }
}
