<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\Dto;

use Phlix\Auth\Dto\WatchHistoryRow;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the WatchHistoryRow DTO.
 */
class WatchHistoryRowTest extends TestCase
{
    public function testFromRowHydratesCoreColumns(): void
    {
        $row = WatchHistoryRow::fromRow([
            'id' => 'wh-1',
            'profile_id' => 'p-1',
            'media_item_id' => 'm-1',
            'position_ticks' => 12345,
            'duration_ticks' => 67890,
            'playback_status' => 'playing',
            'progress_percent' => 18.18,
            'last_watched_at' => '2026-05-19 12:00:00',
            'created_at' => '2026-05-18 12:00:00',
            'completed_at' => null,
        ]);

        $this->assertSame('wh-1', $row->id);
        $this->assertSame('p-1', $row->profileId);
        $this->assertSame('m-1', $row->mediaItemId);
        $this->assertSame(12345, $row->positionTicks);
        $this->assertSame(67890, $row->durationTicks);
        $this->assertSame('playing', $row->playbackStatus);
        $this->assertSame(18.18, $row->progressPercent);
        $this->assertSame('2026-05-19 12:00:00', $row->lastWatchedAt);
        $this->assertNull($row->completedAt);
        $this->assertNull($row->mediaName);
    }

    public function testToArrayIncludesMediaWhenJoined(): void
    {
        $row = WatchHistoryRow::fromRow([
            'id' => 'wh-1',
            'profile_id' => 'p-1',
            'media_item_id' => 'm-1',
            'position_ticks' => 0,
            'duration_ticks' => null,
            'playback_status' => 'paused',
            'progress_percent' => 0,
            'last_watched_at' => '2026-05-19',
            'created_at' => null,
            'completed_at' => null,
            'media_name' => 'My Movie',
            'media_type' => 'movie',
            'metadata_json' => '{"poster_url":"/p.jpg","thumbnail_url":"/t.jpg"}',
        ]);

        $arr = $row->toArray();
        $this->assertSame('My Movie', $arr['media_name']);
        $this->assertSame('movie', $arr['media_type']);
        $this->assertSame('/p.jpg', $arr['poster_url']);
        $this->assertSame('/t.jpg', $arr['thumbnail_url']);
        $this->assertSame(['poster_url' => '/p.jpg', 'thumbnail_url' => '/t.jpg'], $arr['metadata']);
    }

    public function testFromRowToleratesMissingColumns(): void
    {
        $row = WatchHistoryRow::fromRow([]);

        $this->assertSame('', $row->id);
        $this->assertSame(0, $row->positionTicks);
        $this->assertNull($row->durationTicks);
        $this->assertSame([], $row->metadata);
    }

    public function testDurationTicksAcceptsNumericString(): void
    {
        $row = WatchHistoryRow::fromRow([
            'id' => 'wh-1',
            'profile_id' => 'p-1',
            'media_item_id' => 'm-1',
            'position_ticks' => '500',
            'duration_ticks' => '10000',
            'playback_status' => 'playing',
            'progress_percent' => '5.0',
            'last_watched_at' => '2026-05-19',
            'created_at' => null,
            'completed_at' => null,
        ]);

        $this->assertSame(500, $row->positionTicks);
        $this->assertSame(10000, $row->durationTicks);
        $this->assertSame(5.0, $row->progressPercent);
    }

    public function testMetadataAcceptsAlreadyDecodedArray(): void
    {
        $row = WatchHistoryRow::fromRow([
            'id' => 'wh-1',
            'profile_id' => 'p-1',
            'media_item_id' => 'm-1',
            'position_ticks' => 0,
            'duration_ticks' => null,
            'playback_status' => 'paused',
            'progress_percent' => 0,
            'last_watched_at' => '2026-05-19',
            'created_at' => null,
            'completed_at' => null,
            'media_name' => 'Show',
            'media_type' => 'episode',
            'metadata_json' => ['poster_url' => '/x.jpg'],
        ]);

        $this->assertSame(['poster_url' => '/x.jpg'], $row->metadata);
        $arr = $row->toArray();
        $this->assertSame('/x.jpg', $arr['poster_url']);
    }
}
