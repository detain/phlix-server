<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\ImageType;

/**
 * Unit tests for {@see ImageType} (M5 image-type catalogue + enablement helpers).
 *
 */
class ImageTypeTest extends TestCase
{
    public function testAllReturnsTheFullCanonicalCatalogue(): void
    {
        $all = ImageType::all();

        $expected = [
            'poster',
            'backdrop',
            'logo',
            'banner',
            'thumb',
            'clearart',
            'disc',
            'season_poster',
            'season_thumb',
            'episode_still',
            'character_art',
            'person_profile',
        ];
        $this->assertSame($expected, $all);
    }

    public function testDefaultsAreTheCommonlyUsefulEnabledSet(): void
    {
        $defaults = ImageType::defaults();

        $this->assertSame(
            ['poster', 'backdrop', 'logo', 'banner', 'thumb', 'season_poster', 'episode_still'],
            $defaults
        );
        // The niche ones default OFF.
        foreach (['clearart', 'disc', 'season_thumb', 'character_art', 'person_profile'] as $off) {
            $this->assertNotContains($off, $defaults, "$off should default OFF");
        }
    }

    public function testDefaultsAreASubsetOfAll(): void
    {
        foreach (ImageType::defaults() as $type) {
            $this->assertContains($type, ImageType::all());
            $this->assertTrue(ImageType::isKnown($type));
        }
    }

    public function testNormalizeAcceptsMapShapeAndDropsUnknowns(): void
    {
        $normalized = ImageType::normalize([
            'poster' => true,
            'backdrop' => false,
            'logo' => '1',
            'disc' => 'yes',
            'bogus' => true,      // unknown type dropped
            'clearart' => 0,      // explicit off
        ]);

        // Catalogue-ordered enabled types only; unknowns + falsey dropped.
        $this->assertSame(['poster', 'logo', 'disc'], $normalized);
    }

    public function testNormalizeAcceptsListShapeAndDropsUnknowns(): void
    {
        $normalized = ImageType::normalize(['banner', 'poster', 'not-a-type', 'poster']);

        // Deduped and catalogue-ordered (poster before banner).
        $this->assertSame(['poster', 'banner'], $normalized);
    }

    public function testNormalizeEmptyGivesEmpty(): void
    {
        $this->assertSame([], ImageType::normalize([]));
    }

    public function testToStorageMapCoversEveryTypeWithExplicitBool(): void
    {
        $map = ImageType::toStorageMap(['poster' => true, 'logo' => true]);

        $this->assertSame(ImageType::all(), array_keys($map));
        $this->assertTrue($map['poster']);
        $this->assertTrue($map['logo']);
        $this->assertFalse($map['backdrop']);
        $this->assertFalse($map['disc']);
    }

    public function testIsEnabledFallsBackToDefaultsWhenKeyAbsent(): void
    {
        // No `image_types` key at all → the default set applies.
        $options = ['metadata_priority' => ['movie' => ['tmdb']]];

        $this->assertTrue(ImageType::isEnabled($options, 'poster'));
        $this->assertTrue(ImageType::isEnabled($options, 'backdrop'));
        $this->assertTrue(ImageType::isEnabled($options, 'episode_still'));
        // Non-default types are OFF under the fallback.
        $this->assertFalse(ImageType::isEnabled($options, 'clearart'));
        $this->assertFalse(ImageType::isEnabled($options, 'disc'));
    }

    public function testIsEnabledReadsStoredSelectionMap(): void
    {
        $options = [
            'image_types' => [
                'poster' => true,
                'backdrop' => false,
                'clearart' => true,
            ],
        ];

        $this->assertTrue(ImageType::isEnabled($options, 'poster'));
        $this->assertFalse(ImageType::isEnabled($options, 'backdrop'));
        $this->assertTrue(ImageType::isEnabled($options, 'clearart'));
        // A type absent from the stored map is treated as disabled (map is explicit).
        $this->assertFalse(ImageType::isEnabled($options, 'logo'));
    }

    public function testIsEnabledUnknownTypeNeverEnabled(): void
    {
        $this->assertFalse(ImageType::isEnabled([], 'not-a-real-type'));
        $this->assertFalse(ImageType::isEnabled(['image_types' => ['not-a-real-type' => true]], 'not-a-real-type'));
    }

    public function testEnabledForOptionsFallsBackToDefaults(): void
    {
        $this->assertSame(ImageType::defaults(), ImageType::enabledForOptions([]));
    }

    public function testEnabledForOptionsEmptyMapDisablesEverything(): void
    {
        // An explicit empty selection is a VALID "disable all" (not a fallback).
        $this->assertSame([], ImageType::enabledForOptions(['image_types' => []]));
    }

    public function testCatalogExposesLabelDefaultAndProviders(): void
    {
        $catalog = ImageType::catalog();
        $this->assertCount(count(ImageType::all()), $catalog);

        $poster = $catalog[0];
        $this->assertSame('poster', $poster['type']);
        $this->assertSame('Poster', $poster['label']);
        $this->assertTrue($poster['default']);
        $this->assertContains('tmdb', $poster['providers']);

        // Find a non-default one.
        $discEntry = null;
        foreach ($catalog as $entry) {
            if ($entry['type'] === 'disc') {
                $discEntry = $entry;
                break;
            }
        }
        $this->assertNotNull($discEntry);
        $this->assertFalse($discEntry['default']);
    }

    public function testTypeForProviderKeyMapsKnownKeys(): void
    {
        $this->assertSame('poster', ImageType::typeForProviderKey('posters'));
        $this->assertSame('backdrop', ImageType::typeForProviderKey('backdrops'));
        $this->assertSame('backdrop', ImageType::typeForProviderKey('show_backdrops'));
        $this->assertSame('logo', ImageType::typeForProviderKey('logos'));
        $this->assertSame('logo', ImageType::typeForProviderKey('hd_tv_logos'));
        $this->assertSame('banner', ImageType::typeForProviderKey('banners'));
        $this->assertSame('season_poster', ImageType::typeForProviderKey('season_posters'));
        $this->assertSame('season_thumb', ImageType::typeForProviderKey('season_thumbs'));
        $this->assertNull(ImageType::typeForProviderKey('unknown_key'));
    }

    public function testFilterProviderImagesDropsDisabledKeepsEnabledAndPassesUnmapped(): void
    {
        $blob = [
            'posters' => [['url' => 'p']],
            'backdrops' => [['url' => 'b']],
            'logos' => [['url' => 'l']],
            'weird_key' => [['url' => 'w']],  // unmapped → pass through
        ];

        // Enable only poster + logo; backdrop disabled.
        $filtered = ImageType::filterProviderImages($blob, ['poster', 'logo']);

        $this->assertArrayHasKey('posters', $filtered);
        $this->assertArrayHasKey('logos', $filtered);
        $this->assertArrayNotHasKey('backdrops', $filtered, 'disabled backdrop must be dropped');
        $this->assertArrayHasKey('weird_key', $filtered, 'unmapped key must pass through');
    }
}
