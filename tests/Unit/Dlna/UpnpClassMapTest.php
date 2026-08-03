<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Dlna\UpnpClassMap;
use Phlix\Media\Library\MediaItemShaper;

class UpnpClassMapTest extends TestCase
{
    /**
     * The EXACT members of the `media_items.type` column ENUM as built up by
     * migrations 001 → 011 → 034.
     *
     * @var list<string>
     */
    private const MEDIA_ITEM_TYPE_ENUM = [
        'movie',
        'series',
        'season',
        'episode',
        'track',
        'music',
        'album',
        'artist',
        'video',
        'audio',
        'book',
        'photo',
        'audiobook',
    ];

    /**
     * UPnP AV ContentDirectory:1 Appendix B classes. A renderer may reject any
     * class outside this set, so every value the map produces must appear here.
     *
     * @var list<string>
     */
    private const SPEC_DEFINED_CLASSES = [
        'object.item',
        'object.item.imageItem',
        'object.item.imageItem.photo',
        'object.item.audioItem',
        'object.item.audioItem.musicTrack',
        'object.item.audioItem.audioBroadcast',
        'object.item.audioItem.audioBook',
        'object.item.videoItem',
        'object.item.videoItem.movie',
        'object.item.videoItem.videoBroadcast',
        'object.item.videoItem.musicVideoClip',
        'object.item.playlistItem',
        'object.item.textItem',
        'object.container',
        'object.container.person',
        'object.container.person.musicArtist',
        'object.container.playlistContainer',
        'object.container.album',
        'object.container.album.musicAlbum',
        'object.container.album.photoAlbum',
        'object.container.genre',
        'object.container.genre.musicGenre',
        'object.container.genre.movieGenre',
        'object.container.storageSystem',
        'object.container.storageVolume',
        'object.container.storageFolder',
    ];

    public function testMapCoversEveryMediaItemTypeEnumMember(): void
    {
        $mapped = array_keys(UpnpClassMap::TYPE_TO_CLASS);
        sort($mapped);

        $expected = self::MEDIA_ITEM_TYPE_ENUM;
        sort($expected);

        $this->assertSame($expected, $mapped);
    }

    public function testMapAgreesWithMediaItemShaperValidTypes(): void
    {
        $shaperTypes = (new \ReflectionClass(MediaItemShaper::class))->getConstant('VALID_TYPES');
        $this->assertIsArray($shaperTypes);

        $shaper = $shaperTypes;
        sort($shaper);

        $mapped = array_keys(UpnpClassMap::TYPE_TO_CLASS);
        sort($mapped);

        $this->assertSame(
            $shaper,
            $mapped,
            'UpnpClassMap and MediaItemShaper::VALID_TYPES enumerate the same column ENUM.'
        );
    }

    /**
     * The whole point of the fix: no invented classes like `object.item.book`
     * or `object.item.unknown`.
     */
    public function testEveryMappedClassIsSpecDefined(): void
    {
        foreach (UpnpClassMap::TYPE_TO_CLASS as $type => $class) {
            $this->assertContains(
                $class,
                self::SPEC_DEFINED_CLASSES,
                sprintf('Type "%s" maps to "%s", which is not a spec-defined UPnP class.', $type, $class)
            );
        }

        foreach (UpnpClassMap::ALIASES as $alias => $class) {
            $this->assertContains(
                $class,
                self::SPEC_DEFINED_CLASSES,
                sprintf('Alias "%s" maps to "%s", which is not a spec-defined UPnP class.', $alias, $class)
            );
        }

        $this->assertContains(UpnpClassMap::FALLBACK, self::SPEC_DEFINED_CLASSES);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function typeClassProvider(): array
    {
        return [
            'movie' => ['movie', 'object.item.videoItem.movie'],
            'video' => ['video', 'object.item.videoItem.movie'],
            'series' => ['series', 'object.item.videoItem.videoBroadcast'],
            'season' => ['season', 'object.item.videoItem.videoBroadcast'],
            'episode' => ['episode', 'object.item.videoItem.videoBroadcast'],
            'track' => ['track', 'object.item.audioItem.musicTrack'],
            'music' => ['music', 'object.item.audioItem.musicTrack'],
            'audio' => ['audio', 'object.item.audioItem.musicTrack'],
            'audiobook' => ['audiobook', 'object.item.audioItem.audioBook'],
            'album' => ['album', 'object.container.album.musicAlbum'],
            'artist' => ['artist', 'object.container.person.musicArtist'],
            'photo' => ['photo', 'object.item.imageItem.photo'],
            'book' => ['book', 'object.item.textItem'],
        ];
    }

    /**
     * @dataProvider typeClassProvider
     */
    public function testForTypeResolvesEachEnumMember(string $type, string $expected): void
    {
        $this->assertSame($expected, UpnpClassMap::forType($type));
    }

    public function testAliasesResolve(): void
    {
        $this->assertSame(UpnpClassMap::CONTAINER, UpnpClassMap::forType('container'));
        $this->assertSame(UpnpClassMap::CONTAINER, UpnpClassMap::forType('folder'));
        $this->assertSame('object.item.videoItem.videoBroadcast', UpnpClassMap::forType('tvshow'));
    }

    /**
     * The scanner emits `photo`; the old `'image', 'photo' =>` arm was dead.
     */
    public function testImageIsNoLongerAKnownType(): void
    {
        $this->assertArrayNotHasKey('image', UpnpClassMap::TYPE_TO_CLASS);
        $this->assertArrayNotHasKey('image', UpnpClassMap::ALIASES);
    }

    public function testUnknownTypeFallsBackToGenericItemNotAnInventedClass(): void
    {
        $this->assertSame('object.item', UpnpClassMap::forType('not-a-real-type'));
        $this->assertSame('object.item', UpnpClassMap::forType(''));
    }

    public function testIsContainerClass(): void
    {
        $this->assertTrue(UpnpClassMap::isContainerClass('object.container'));
        $this->assertTrue(UpnpClassMap::isContainerClass('object.container.album.musicAlbum'));
        $this->assertTrue(UpnpClassMap::isContainerClass('object.container.person.musicArtist'));

        $this->assertFalse(UpnpClassMap::isContainerClass('object.item'));
        $this->assertFalse(UpnpClassMap::isContainerClass('object.item.videoItem.movie'));
    }
}
