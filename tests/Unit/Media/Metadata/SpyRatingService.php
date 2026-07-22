<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use DateTimeImmutable;
use Phlix\Media\Metadata\Rating;
use Phlix\Media\Metadata\RatingService;
use Phlix\Media\Metadata\RatingSource;
use Phlix\Media\Metadata\RatingType;

/**
 * DB-free {@see RatingService} spy for the F2 caller-side rating-persistence
 * tests. Records every upsert/aggregate call instead of touching the database.
 *
 * Not a PHPUnit test (no `Test` suffix) — a shared fixture.
 */
final class SpyRatingService extends RatingService
{
    /** @var list<array{id: string, source: string, type: string, score: float, votes: int|null}> */
    public array $upserts = [];

    public int $aggregateCalls = 0;

    public function __construct()
    {
        // Deliberately skip the parent ctor — no DB connection in this spy.
    }

    public function upsert(
        string $mediaItemId,
        RatingSource $source,
        RatingType $ratingType,
        float $score,
        ?int $votes = null,
    ): Rating {
        $this->upserts[] = [
            'id' => $mediaItemId,
            'source' => $source->value,
            'type' => $ratingType->value,
            'score' => $score,
            'votes' => $votes,
        ];
        return new Rating(
            1,
            $mediaItemId,
            $source,
            $ratingType,
            $score,
            $votes,
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );
    }

    public function aggregate(string $mediaItemId): void
    {
        $this->aggregateCalls++;
    }
}
