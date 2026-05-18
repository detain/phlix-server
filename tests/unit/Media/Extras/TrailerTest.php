<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Extras;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Extras\Trailer;

class TrailerTest extends TestCase
{
    public function testConstructorStoresAllProperties(): void
    {
        $trailer = new Trailer(
            id: 'trailer-123',
            mediaItemId: 'media-456',
            title: 'Official Trailer',
            source: 'tmdb',
            url: 'https://www.youtube.com/watch?v=abc123',
            duration: 150,
            quality: 1080,
            isLocal: false,
            filePath: ''
        );

        $this->assertSame('trailer-123', $trailer->id);
        $this->assertSame('media-456', $trailer->mediaItemId);
        $this->assertSame('Official Trailer', $trailer->title);
        $this->assertSame('tmdb', $trailer->source);
        $this->assertSame('https://www.youtube.com/watch?v=abc123', $trailer->url);
        $this->assertSame(150, $trailer->duration);
        $this->assertSame(1080, $trailer->quality);
        $this->assertFalse($trailer->isLocal);
        $this->assertSame('', $trailer->filePath);
    }

    public function testIsLocalTrueForLocalSource(): void
    {
        $trailer = new Trailer(
            id: 'trailer-123',
            mediaItemId: 'media-456',
            title: 'Local Trailer',
            source: 'local',
            url: 'file:///path/to/trailer.mkv',
            duration: 120,
            quality: 720,
            isLocal: true,
            filePath: '/path/to/trailer.mkv'
        );

        $this->assertTrue($trailer->isLocal);
        $this->assertSame('local', $trailer->source);
        $this->assertSame('/path/to/trailer.mkv', $trailer->filePath);
    }

    public function testDurationAndQualityDefaults(): void
    {
        $trailer = new Trailer(
            id: 'trailer-123',
            mediaItemId: 'media-456',
            title: 'Trailer',
            source: 'tmdb',
            url: 'https://youtube.com/watch?v=xyz',
            duration: 0,
            quality: 0,
            isLocal: false,
            filePath: ''
        );

        $this->assertSame(0, $trailer->duration);
        $this->assertSame(0, $trailer->quality);
    }

    public function testToArrayReturnsAllFields(): void
    {
        $trailer = new Trailer(
            id: 'trailer-123',
            mediaItemId: 'media-456',
            title: 'Official Trailer',
            source: 'local',
            url: 'file:///path/to/trailer.mkv',
            duration: 180,
            quality: 2160,
            isLocal: true,
            filePath: '/path/to/trailer.mkv'
        );

        $array = $trailer->toArray();

        $this->assertIsArray($array);
        $this->assertSame('trailer-123', $array['id']);
        $this->assertSame('media-456', $array['media_item_id']);
        $this->assertSame('Official Trailer', $array['title']);
        $this->assertSame('local', $array['source']);
        $this->assertSame('file:///path/to/trailer.mkv', $array['url']);
        $this->assertSame(180, $array['duration']);
        $this->assertSame(2160, $array['quality']);
        $this->assertTrue($array['is_local']);
        $this->assertSame('/path/to/trailer.mkv', $array['file_path']);
    }
}
