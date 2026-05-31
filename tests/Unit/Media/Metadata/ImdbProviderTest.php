<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\ImdbProvider;
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see ImdbProvider}: search/getDetails return shapes against a
 * mocked {@see ImdbLookup} (itself backed by a mocked Connection).
 *
 * @since 0.21.0
 */
class ImdbProviderTest extends TestCase
{
    /**
     * Build an ImdbLookup whose mocked Connection returns the given row(s).
     *
     * @param list<array<string, mixed>> $rows
     */
    private function lookupReturning(array $rows): ImdbLookup
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($rows);
        return new ImdbLookup($db);
    }

    public function testGetProvidersAndSourceName(): void
    {
        $provider = new ImdbProvider($this->lookupReturning([]));
        $this->assertSame(['imdb'], $provider->getProviders());
        $this->assertSame('imdb', $provider->getSourceName());
    }

    public function testSearchReturnsSingleResult(): void
    {
        $lookup = $this->lookupReturning([[
            'tconst' => 'tt0133093',
            'primary_title' => 'The Matrix',
            'start_year' => 1999,
            'genres' => 'Action,Sci-Fi',
            'average_rating' => '8.7',
            'num_votes' => '1900000',
            'runtime_minutes' => 136,
        ]]);

        $provider = new ImdbProvider($lookup);
        $results = $provider->search('The Matrix', ['year' => 1999]);

        $this->assertCount(1, $results);
        $this->assertSame('tt0133093', $results[0]['id']);
        $this->assertSame('The Matrix', $results[0]['title']);
        $this->assertSame('', $results[0]['overview']);
        $this->assertSame(1999, $results[0]['year']);
        $this->assertSame(8.7, $results[0]['vote_average']);
        $this->assertSame(1900000, $results[0]['vote_count']);
    }

    public function testSearchReturnsEmptyWhenNoMatch(): void
    {
        $provider = new ImdbProvider($this->lookupReturning([]));
        $this->assertSame([], $provider->search('No Such Movie', []));
    }

    public function testGetDetailsMapsToDetailsShape(): void
    {
        $lookup = $this->lookupReturning([[
            'tconst' => 'tt0133093',
            'primary_title' => 'The Matrix',
            'start_year' => 1999,
            'genres' => 'Action,Sci-Fi',
            'average_rating' => '8.7',
            'num_votes' => '1900000',
            'runtime_minutes' => 136,
        ]]);

        $provider = new ImdbProvider($lookup);
        $details = $provider->getDetails('tt0133093');

        $this->assertSame('tt0133093', $details['imdb_id']);
        $this->assertSame('The Matrix', $details['title']);
        $this->assertSame(['imdb' => 'tt0133093'], $details['external_ids']);
        $this->assertSame(8.7, $details['rating']);
        $this->assertSame(['Action', 'Sci-Fi'], $details['genres']);
        $this->assertSame(1999, $details['year']);
        $this->assertSame(136, $details['runtime']);
    }

    public function testGetDetailsReturnsEmptyWhenNotFound(): void
    {
        $provider = new ImdbProvider($this->lookupReturning([]));
        $this->assertSame([], $provider->getDetails('tt0000000'));
    }

    public function testGetImagesReturnsEmpty(): void
    {
        $provider = new ImdbProvider($this->lookupReturning([]));
        $this->assertSame([], $provider->getImages('tt0133093'));
    }
}
