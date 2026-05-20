<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Extras;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Extras\Extra;

class ExtraTest extends TestCase
{
    public function testExtraTypeConstantsAreCorrect(): void
    {
        $this->assertSame('featurette', Extra::TYPE_FEATURETTE);
        $this->assertSame('behind_the_scenes', Extra::TYPE_BEHIND_THE_SCENES);
        $this->assertSame('interview', Extra::TYPE_INTERVIEW);
        $this->assertSame('clip', Extra::TYPE_CLIP);
        $this->assertSame('deleted_scene', Extra::TYPE_DELETED_SCENE);
        $this->assertSame('trailer', Extra::TYPE_TRAILER);
    }

    public function testValidTypesContainsAllTypes(): void
    {
        $expected = [
            'featurette',
            'behind_the_scenes',
            'interview',
            'clip',
            'deleted_scene',
            'trailer',
        ];

        $this->assertSame($expected, Extra::VALID_TYPES);
    }

    public function testConstructorStoresAllProperties(): void
    {
        $extra = new Extra(
            id: 'extra-123',
            mediaItemId: 'media-456',
            title: 'Behind the Scenes',
            type: Extra::TYPE_BEHIND_THE_SCENES,
            source: 'tmdb',
            url: 'https://www.youtube.com/watch?v=xyz789',
            duration: 300,
            quality: 1080,
            isLocal: false,
            filePath: ''
        );

        $this->assertSame('extra-123', $extra->id);
        $this->assertSame('media-456', $extra->mediaItemId);
        $this->assertSame('Behind the Scenes', $extra->title);
        $this->assertSame('behind_the_scenes', $extra->type);
        $this->assertSame('tmdb', $extra->source);
        $this->assertSame('https://www.youtube.com/watch?v=xyz789', $extra->url);
        $this->assertSame(300, $extra->duration);
        $this->assertSame(1080, $extra->quality);
        $this->assertFalse($extra->isLocal);
        $this->assertSame('', $extra->filePath);
    }

    public function testToArrayReturnsAllFields(): void
    {
        $extra = new Extra(
            id: 'extra-123',
            mediaItemId: 'media-456',
            title: 'Featurette',
            type: Extra::TYPE_FEATURETTE,
            source: 'local',
            url: 'file:///path/to/featurette.mkv',
            duration: 600,
            quality: 720,
            isLocal: true,
            filePath: '/path/to/featurette.mkv'
        );

        $array = $extra->toArray();

        $this->assertIsArray($array);
        $this->assertSame('extra-123', $array['id']);
        $this->assertSame('media-456', $array['media_item_id']);
        $this->assertSame('Featurette', $array['title']);
        $this->assertSame('featurette', $array['type']);
        $this->assertSame('local', $array['source']);
        $this->assertSame('file:///path/to/featurette.mkv', $array['url']);
        $this->assertSame(600, $array['duration']);
        $this->assertSame(720, $array['quality']);
        $this->assertTrue($array['is_local']);
        $this->assertSame('/path/to/featurette.mkv', $array['file_path']);
    }
}
