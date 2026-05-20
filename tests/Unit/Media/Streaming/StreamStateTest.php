<?php

namespace Phlix\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\StreamState;

class StreamStateTest extends TestCase
{
    public function testCanCreateStreamState(): void
    {
        $state = new StreamState();
        $this->assertInstanceOf(StreamState::class, $state);
        $this->assertEquals('stopped', $state->status);
    }

    public function testPlayChangesStatus(): void
    {
        $state = new StreamState();
        $state->play();
        $this->assertEquals('playing', $state->status);
    }

    public function testPauseChangesStatus(): void
    {
        $state = new StreamState();
        $state->play();
        $state->pause();
        $this->assertEquals('paused', $state->status);
    }

    public function testSeekClampsPosition(): void
    {
        $state = new StreamState();
        $state->durationTicks = 100000000;
        
        $state->seek(50000000);
        $this->assertEquals(50000000, $state->positionTicks);
        
        // Test clamping at boundaries
        $state->seek(-10000000);
        $this->assertEquals(0, $state->positionTicks);
        
        $state->seek(200000000);
        $this->assertEquals(100000000, $state->positionTicks);
    }

    public function testToArray(): void
    {
        $state = new StreamState();
        $state->id = 'test-id';
        $state->mediaItemId = 'media-1';
        
        $arr = $state->toArray();
        $this->assertEquals('test-id', $arr['id']);
        $this->assertEquals('media-1', $arr['media_item_id']);
    }
}