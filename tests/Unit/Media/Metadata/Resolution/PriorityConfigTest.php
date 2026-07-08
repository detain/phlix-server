<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Resolution;

use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\PriorityFieldResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Metadata\Resolution\PriorityConfig
 */
final class PriorityConfigTest extends TestCase
{
    /** The canonical config/metadata.php default map. */
    private const CONFIG_DEFAULT = [
        'movie'  => ['tmdb', 'imdb'],
        'series' => ['tmdb', 'imdb'],
        'anime'  => ['anidb', 'myanimelist', 'tvdb', 'fanart', 'local'],
    ];

    public function testOrderForReturnsConfigDefault(): void
    {
        $config = new PriorityConfig(self::CONFIG_DEFAULT);

        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('movie'));
        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('series'));
        $this->assertSame(
            ['anidb', 'myanimelist', 'tvdb', 'fanart', 'local'],
            $config->orderFor('anime'),
        );
    }

    public function testSeriesDefaultHasNoTvdb(): void
    {
        $config = new PriorityConfig(self::CONFIG_DEFAULT);

        $this->assertNotContains('tvdb', $config->orderFor('series'));
    }

    public function testOverrideChangesOrderForMovie(): void
    {
        // The provider merges the override over the default before construction;
        // a movie override flips the order.
        $merged = self::CONFIG_DEFAULT;
        $merged['movie'] = ['imdb', 'tmdb'];

        $config = new PriorityConfig($merged);

        $this->assertSame(['imdb', 'tmdb'], $config->orderFor('movie'));
        // Untouched types keep their default.
        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('series'));
    }

    public function testUnknownTypeFallsBackToMovieOrder(): void
    {
        $config = new PriorityConfig(self::CONFIG_DEFAULT);

        // No 'documentary' entry → falls back to the 'movie' order.
        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('documentary'));
    }

    public function testUnknownTypeWithoutMovieFallsBackToBaseline(): void
    {
        // A map with neither the requested type nor a 'movie' entry falls back
        // to the hard baseline constant.
        $config = new PriorityConfig(['anime' => ['anidb']]);

        $this->assertSame(PriorityConfig::DEFAULT_ORDER, $config->orderFor('movie'));
        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('episode'));
    }

    public function testEmptyMapFallsBackToBaselineForEveryType(): void
    {
        $config = new PriorityConfig([]);

        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('movie'));
        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('series'));
        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('anything'));
    }

    public function testEmptyTypeListFallsBackToMovieOrder(): void
    {
        // A type present but with an empty list is treated as absent.
        $config = new PriorityConfig(['movie' => ['tmdb', 'imdb'], 'series' => []]);

        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('series'));
    }

    public function testGenresModeDefaultsToFirst(): void
    {
        $config = new PriorityConfig(self::CONFIG_DEFAULT);

        $this->assertSame('first', $config->genresMode());
        $this->assertSame(PriorityFieldResolver::GENRES_FIRST, $config->genresMode());
    }

    public function testGenresModeOverrideToUnion(): void
    {
        $config = new PriorityConfig(self::CONFIG_DEFAULT, 'union');

        $this->assertSame('union', $config->genresMode());
        $this->assertSame(PriorityFieldResolver::GENRES_UNION, $config->genresMode());
    }

    public function testInvalidGenresModeCoercesToDefault(): void
    {
        $config = new PriorityConfig(self::CONFIG_DEFAULT, 'nonsense');

        $this->assertSame('first', $config->genresMode());
    }

    public function testEmptyGenresModeCoercesToDefault(): void
    {
        $config = new PriorityConfig(self::CONFIG_DEFAULT, '');

        $this->assertSame('first', $config->genresMode());
    }

    public function testOrderForReturnsListNotPreservingStringKeys(): void
    {
        // orderFor must return a clean list (re-indexed) so it is a valid
        // PriorityFieldResolver sourceOrder. Decoding the JSON object yields the
        // gapped integer keys [2 => 'tmdb', 5 => 'imdb'] at runtime, deliberately
        // exercising that re-indexing path.
        /** @var array<string, list<string>> $priority */
        $priority = json_decode('{"movie":{"2":"tmdb","5":"imdb"}}', true);
        $config = new PriorityConfig($priority);

        $this->assertSame(['tmdb', 'imdb'], $config->orderFor('movie'));
    }

    public function testConfigDefaultMirrorsSharedSchemaDefault(): void
    {
        // The config/metadata.php default loaded by the provider must byte-mirror
        // the shared schema default so schema validation and GET defaults agree.
        $config = include dirname(__DIR__, 5) . '/config/metadata.php';

        $this->assertSame(self::CONFIG_DEFAULT, $config['provider_priority']);
        $this->assertSame('first', $config['genres_mode']);
    }
}
