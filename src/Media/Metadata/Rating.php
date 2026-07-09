<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use InvalidArgumentException;

/**
 * Represents a single rating record from a source (TMDB, IMDb, or user).
 */
final readonly class Rating
{
    public function __construct(
        public int $id,
        public string $mediaItemId,
        public RatingSource $source,
        public RatingType $ratingType,
        public float $score,
        public ?int $votes,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDbRow(array $row): self
    {
        $id = is_int($row['id'] ?? null) ? $row['id'] : 0;
        $mediaItemId = is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '';
        $sourceStr = is_string($row['source'] ?? null) ? $row['source'] : 'tmdb';
        $ratingTypeStr = is_string($row['rating_type'] ?? null) ? $row['rating_type'] : 'average';
        $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
        $votes = isset($row['votes']) && is_int($row['votes']) ? $row['votes'] : null;
        $createdAtStr = is_string($row['created_at'] ?? null) ? $row['created_at'] : 'now';
        $updatedAtStr = is_string($row['updated_at'] ?? null) ? $row['updated_at'] : 'now';

        return new self(
            id: $id,
            mediaItemId: $mediaItemId,
            source: RatingSource::from($sourceStr),
            ratingType: RatingType::from($ratingTypeStr),
            score: $score,
            votes: $votes,
            createdAt: new \DateTimeImmutable($createdAtStr),
            updatedAt: new \DateTimeImmutable($updatedAtStr),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mediaItemId' => $this->mediaItemId,
            'source' => $this->source->value,
            'type' => $this->ratingType->value,
            'score' => $this->score,
            'votes' => $this->votes,
        ];
    }
}

enum RatingSource: string
{
    case Tmdb = 'tmdb';
    case Imdb = 'imdb';
    case User = 'user';
}

enum RatingType: string
{
    case Average = 'average';
    case User = 'user';
    case Critic = 'critic';
    case Meta = 'meta';
}
