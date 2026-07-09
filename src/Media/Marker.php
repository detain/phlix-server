<?php
declare(strict_types=1);
namespace Phlix\Media;

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
        public ?string $thumbnailPath,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a Marker from a database row.
     *
     * @param array<string, mixed> $row Database row from media_markers table
     */
    public static function fromDbRow(array $row): self
    {
        $id = is_int($row['id'] ?? null) ? $row['id'] : 0;
        $mediaItemId = is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '';
        $markerTypeStr = is_string($row['marker_type'] ?? null) ? $row['marker_type'] : 'intro';
        $startTimeMs = is_int($row['start_time_ms'] ?? null) ? $row['start_time_ms'] : 0;
        $endTimeMs = is_int($row['end_time_ms'] ?? null) ? $row['end_time_ms'] : 0;
        $label = is_string($row['label'] ?? null) ? $row['label'] : '';
        $thumbnailPath = is_string($row['thumbnail_path'] ?? null) && $row['thumbnail_path'] !== ''
            ? $row['thumbnail_path']
            : null;
        $createdAtStr = is_string($row['created_at'] ?? null) ? $row['created_at'] : 'now';
        $updatedAtStr = is_string($row['updated_at'] ?? null) ? $row['updated_at'] : 'now';

        return new self(
            id: $id,
            mediaItemId: $mediaItemId,
            type: MarkerType::from($markerTypeStr),
            startTimeMs: $startTimeMs,
            endTimeMs: $endTimeMs,
            label: $label,
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
