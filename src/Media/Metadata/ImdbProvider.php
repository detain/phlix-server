<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Metadata\Imdb\ImdbLookup;

/**
 * ImdbProvider exposes the locally-imported IMDb data layer through the standard
 * {@see MetadataProviderInterface} contract.
 *
 * Unlike the HTTP-backed providers (TMDB, TVDB, Fanart), this provider performs
 * no network I/O: it queries the local `imdb_titles` table via an injected
 * {@see ImdbLookup}. `search()` resolves at most a single best-match title and
 * `getDetails()` fetches one title by its `tconst`.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Local IMDb dataset provider for movie metadata
 * @see https://www.imdb.com/interfaces/
 * @see MetadataProviderInterface For provider contract
 * @since 0.21.0
 */
class ImdbProvider implements MetadataProviderInterface
{
    /** @var ImdbLookup Read-side of the IMDb data layer. */
    private ImdbLookup $lookup;

    /**
     * @param ImdbLookup $lookup IMDb local-table lookup service.
     *
     * @since 0.21.0
     */
    public function __construct(ImdbLookup $lookup)
    {
        $this->lookup = $lookup;
    }

    /**
     * Search for a movie by title, optionally constrained by year.
     *
     * Returns 0 or 1 result (IMDb lookup resolves a single best candidate).
     *
     * @param string               $query   Movie title.
     * @param array<string, mixed> $options Search options; `year` is honoured.
     *
     * @return array<int, array{
     *     id: string,
     *     title: string,
     *     overview: string,
     *     year: int|null,
     *     vote_average: float|null,
     *     vote_count: int|null
     * }> Zero or one result.
     *
     * @since 0.21.0
     */
    public function search(string $query, array $options = []): array
    {
        $year = MetadataValue::asNullableInt($options['year'] ?? null);

        $match = $this->lookup->lookup($query, $year);
        if ($match === null) {
            return [];
        }

        return [[
            'id' => $match['imdb_id'],
            'title' => $match['title'],
            'overview' => '',
            'year' => $match['year'],
            'vote_average' => $match['average_rating'],
            'vote_count' => $match['num_votes'],
        ]];
    }

    /**
     * Get detailed metadata for an IMDb id (`tconst`).
     *
     * @param string               $externalId IMDb id, e.g. `tt0133093`.
     * @param array<string, mixed> $options    Unused (no upstream options).
     *
     * @return array<string, mixed> Details, or an empty array when not found.
     *
     * @since 0.21.0
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        $row = $this->lookup->getByImdbId($externalId);
        if ($row === null) {
            return [];
        }

        return [
            'imdb_id' => $row['imdb_id'],
            'title' => $row['title'],
            'external_ids' => ['imdb' => $row['imdb_id']],
            'rating' => $row['average_rating'],
            'num_votes' => $row['num_votes'],
            'genres' => $row['genres'],
            'year' => $row['year'],
            'runtime' => $row['runtime_minutes'],
        ];
    }

    /**
     * IMDb provides no images (no artwork in the free datasets).
     *
     * @param string $externalId IMDb id (ignored).
     *
     * @return array<string, array<int, array{url: string, width?: int, height?: int}>>
     *
     * @since 0.21.0
     */
    public function getImages(string $externalId): array
    {
        return [];
    }

    /**
     * Get provider name aliases.
     *
     * @return array<int, string> Provider names ['imdb']
     *
     * @since 0.21.0
     */
    public function getProviders(): array
    {
        return ['imdb'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceName(): string
    {
        return 'imdb';
    }
}
