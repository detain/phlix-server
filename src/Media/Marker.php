<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media;

use Phlix\Common\Util\RowMap;

/**
 * Represents a media marker (intro, outro, credits, or ad) with timing information.
 */
final readonly class Marker
{
    public function __construct(
        public int $id,
        public string $mediaItemId,
        public MarkerType $type,
        public int $startTimeMs,
        public int $endTimeMs,
        public string $label,
        public ?string $userId,
        public ?string $thumbnailPath,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Create a Marker from a database row.
     *
     * @param array<string, mixed> $row Database row from media_markers table
     */
    public static function fromDbRow(array $row): self
    {
        $id = RowMap::int($row, 'id', 0);
        $mediaItemId = RowMap::str($row, 'media_item_id', '');
        $markerTypeStr = RowMap::str($row, 'marker_type', 'intro');
        $startTimeMs = RowMap::int($row, 'start_time_ms', 0);
        $endTimeMs = RowMap::int($row, 'end_time_ms', 0);
        $label = RowMap::str($row, 'label', '');
        // P3-S1: user_id is nullable; NULL = legacy system marker
        $userId = RowMap::nullableNonEmptyStr($row, 'user_id');
        $thumbnailPath = RowMap::nullableNonEmptyStr($row, 'thumbnail_path');
        // E-09 cohort 4 T1H half-fold: str() replaces the narrowing, the RAW
        // ctor is deliberately KEPT — it THROWS on unparseable timestamps
        // where a nullableDateTime accessor would hydrate null. That strict
        // divergence is pinned by the legacy-oracle suite (T1 ≠ T3).
        $createdAtStr = RowMap::str($row, 'created_at', 'now');
        $updatedAtStr = RowMap::str($row, 'updated_at', 'now');

        return new self(
            id: $id,
            mediaItemId: $mediaItemId,
            type: MarkerType::from($markerTypeStr),
            startTimeMs: $startTimeMs,
            endTimeMs: $endTimeMs,
            label: $label,
            userId: $userId,
            thumbnailPath: $thumbnailPath,
            createdAt: new \DateTimeImmutable($createdAtStr),
            updatedAt: new \DateTimeImmutable($updatedAtStr),
        );
    }

    /**
     * Convert marker to array representation for API responses.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type->value,
            'startMs' => $this->startTimeMs,
            'endMs' => $this->endTimeMs,
            'label' => $this->label,
        ];

        if ($this->thumbnailPath !== null) {
            $result['thumbnailUrl'] = '/api/v1/markers/thumbnails/' . urlencode($this->thumbnailPath);
        }

        return $result;
    }
}
