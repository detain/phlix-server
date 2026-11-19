<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\Rating;
use Phlix\Media\Metadata\RatingSource;
use Phlix\Media\Metadata\RatingType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F2 caller-side rating persistence: the matcher (which owns the media_item_id)
 * upserts a resolve() result's `plugin_ratings` via {@see \Phlix\Media\Metadata\RatingService}.
 */
final class LibraryMetadataMatcherPluginRatingsTest extends TestCase
{
    private function makeMatcher(SpyRatingService $ratingService): LibraryMetadataMatcher
    {
        return new LibraryMetadataMatcher(
            items: $this->createMock(ItemRepository::class),
            resolver: $this->createMock(MovieMetadataResolver::class),
            logger: $this->createMock(StructuredLogger::class),
            ratingService: $ratingService,
        );
    }

    /**
     * @param array<string, mixed> $resolved
     */
    private function invokePersist(LibraryMetadataMatcher $matcher, string $id, array $resolved): void
    {
        $method = new ReflectionMethod($matcher, 'persistPluginRatings');
        $method->invoke($matcher, $id, $resolved);
    }

    public function testUpsertsEnumValidRatingsAndDropsInvalidSources(): void
    {
        $spy = new SpyRatingService();
        $matcher = $this->makeMatcher($spy);

        $this->invokePersist($matcher, 'item-1', [
            'plugin_ratings' => [
                ['source' => 'imdb', 'score' => 8.7, 'votes' => 1900000],
                ['source' => 'rt', 'score' => 8.8],
                ['source' => 'metacritic', 'score' => 60.0], // NOT in the DB ENUM → dropped
            ],
        ]);

        // Only the two enum-valid sources were persisted.
        $this->assertCount(2, $spy->upserts);
        $this->assertSame('imdb', $spy->upserts[0]['source']);
        $this->assertSame(8.7, $spy->upserts[0]['score']);
        $this->assertSame(1900000, $spy->upserts[0]['votes']);
        $this->assertSame('rt', $spy->upserts[1]['source']);
        $this->assertNull($spy->upserts[1]['votes']);
        // Aggregate recomputed once after persisting.
        $this->assertSame(1, $spy->aggregateCalls);
    }

    public function testNoPluginRatingsIsANoOp(): void
    {
        $spy = new SpyRatingService();
        $matcher = $this->makeMatcher($spy);

        // A resolve() result from the default (scan) path carries no plugin_ratings.
        $this->invokePersist($matcher, 'item-1', ['sources' => ['tmdb', 'imdb']]);

        $this->assertSame([], $spy->upserts);
        $this->assertSame(0, $spy->aggregateCalls);
    }

    /**
     * E-09 cohort 5a part A: pins the RowMap fold of Rating::fromDbRow against
     * a verbatim transcription of the pre-fold ??-guarded strict ternaries for
     * deterministic vectors (ca91c293 shape). created_at/updated_at must be
     * valid strings here so the DateTimeImmutable outputs compare exactly.
     */
    public function testRatingRowDecodeIsByteIdenticalToLegacyAcrossVectors(): void
    {
        /** @var list<array<string, mixed>> $vectors */
        $vectors = [
            [
                'id' => 5, 'media_item_id' => 'm-1', 'source' => 'imdb', 'rating_type' => 'user',
                'score' => '4.5', 'votes' => 12,
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-02-02 12:30:45',
            ],
            [
                'id' => null, 'media_item_id' => null, 'source' => null, 'rating_type' => null,
                'score' => 3, 'votes' => '12',
                'created_at' => '2026-03-03 03:03:03', 'updated_at' => '2026-04-04 04:04:04',
            ],
            [
                'id' => '7', 'media_item_id' => '', 'source' => 'rt', 'rating_type' => 'critic',
                'score' => 'abc', 'votes' => null,
                'created_at' => '2026-05-05 05:05:05', 'updated_at' => '2026-06-06 06:06:06',
            ],
            [
                'id' => 1.5, 'media_item_id' => true, 'source' => 'aggregate', 'rating_type' => 'meta',
                'score' => '1e2', 'votes' => 0,
                'created_at' => '2026-07-07 07:07:07', 'updated_at' => '2026-08-08 08:08:08',
            ],
            [
                'created_at' => '2026-09-09 09:09:09', 'updated_at' => '2026-10-10 10:10:10',
            ],
        ];

        foreach ($vectors as $row) {
            $legacy = $this->legacyRatingFromDbRow($row);
            $decoded = Rating::fromDbRow($row);

            $this->assertSame($legacy->toArray(), $decoded->toArray());
            $this->assertSame(
                $legacy->createdAt->format(\DateTimeInterface::ATOM),
                $decoded->createdAt->format(\DateTimeInterface::ATOM)
            );
            $this->assertSame(
                $legacy->updatedAt->format(\DateTimeInterface::ATOM),
                $decoded->updatedAt->format(\DateTimeInterface::ATOM)
            );
        }
    }

    /**
     * E-09 cohort 5a part B: the 'now' defaulting and both throwing arms of
     * Rating::fromDbRow survive the fold identically — a non-string or absent
     * created_at still resolves to now; a garbage string still throws
     * DateMalformedStringException; an unknown enum string still throws
     * ValueError. Outcomes (ok-shape / throw class) must match, and the 'now'
     * arms must land within one second of each other.
     */
    public function testRatingRowDecodeDefaultingAndThrowParity(): void
    {
        /** @var list<array<string, mixed>> $vectors */
        $vectors = [
            ['created_at' => 5, 'updated_at' => '2026-01-01 00:00:00'],
            ['created_at' => null, 'updated_at' => 7],
            ['updated_at' => '2026-01-01 00:00:00'],
            ['created_at' => 'zzz-not-a-date', 'updated_at' => '2026-01-01 00:00:00'],
            ['source' => 'bogus', 'rating_type' => 'average'],
            ['source' => 'tmdb', 'rating_type' => 'bogus'],
            [],
        ];

        foreach ($vectors as $row) {
            $legacy = $this->captureRatingOutcome(fn(): Rating => $this->legacyRatingFromDbRow($row));
            $decoded = $this->captureRatingOutcome(fn(): Rating => Rating::fromDbRow($row));

            $this->assertSame($legacy[0], $decoded[0]);
            if ($legacy[0] === 'throw') {
                $this->assertSame($legacy[1], $decoded[1]);
                continue;
            }
            $this->assertSame($legacy[1], $decoded[1]);
            $this->assertLessThanOrEqual(1, abs($legacy[2] - $decoded[2]));
            $this->assertLessThanOrEqual(1, abs($legacy[3] - $decoded[3]));
        }
    }

    /**
     * Verbatim transcription of Rating::fromDbRow as it stood at ca91c293 —
     * the pre-fold reference the two oracle tests above compare against.
     *
     * @param array<string, mixed> $row
     */
    private function legacyRatingFromDbRow(array $row): Rating
    {
        $id = is_int($row['id'] ?? null) ? $row['id'] : 0;
        $mediaItemId = is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '';
        $sourceStr = is_string($row['source'] ?? null) ? $row['source'] : 'tmdb';
        $ratingTypeStr = is_string($row['rating_type'] ?? null) ? $row['rating_type'] : 'average';
        $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
        $votes = isset($row['votes']) && is_int($row['votes']) ? $row['votes'] : null;
        $createdAtStr = is_string($row['created_at'] ?? null) ? $row['created_at'] : 'now';
        $updatedAtStr = is_string($row['updated_at'] ?? null) ? $row['updated_at'] : 'now';

        return new Rating(
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
     * @param callable(): Rating $call
     * @return array{0: string, 1: mixed, 2: int, 3: int} outcome tag, payload
     *             (toArray or throw class), created/updated timestamps.
     */
    private function captureRatingOutcome(callable $call): array
    {
        try {
            $rating = $call();
            return ['ok', $rating->toArray(), $rating->createdAt->getTimestamp(), $rating->updatedAt->getTimestamp()];
        } catch (\Throwable $error) {
            return ['throw', get_class($error), 0, 0];
        }
    }
}
