<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Util\RowMap;

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
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDbRow(array $row): self
    {
        // E-09 cohort 5a: RowMap fold of the pre-fold ??-guarded strict
        // ternaries (fallbacks transcribed); T1H arms: created_at/updated_at
        // keep the raw DateTimeImmutable ctor so a garbage string still throws
        // exactly like the pre-fold block. Byte-identity pinned by the
        // legacy-oracle vectors in
        // tests/Unit/Media/Metadata/LibraryMetadataMatcherPluginRatingsTest.php.
        $id = RowMap::int($row, 'id', 0);
        $mediaItemId = RowMap::str($row, 'media_item_id', '');
        $sourceStr = RowMap::str($row, 'source', 'tmdb');
        $ratingTypeStr = RowMap::str($row, 'rating_type', 'average');
        $score = RowMap::floatFromNumeric($row, 'score', 0.0);
        $votes = RowMap::nullableInt($row, 'votes');
        $createdAtStr = RowMap::str($row, 'created_at', 'now');
        $updatedAtStr = RowMap::str($row, 'updated_at', 'now');

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
