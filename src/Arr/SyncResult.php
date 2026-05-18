<?php

declare(strict_types=1);

namespace Phlex\Arr;

use DateTimeImmutable;

/**
 * Value object representing the result of a TRaSH-Guides sync operation.
 *
 * @package Phlex\Arr
 * @since 0.12.0
 */
class SyncResult
{
    /**
     * Creates a new SyncResult instance.
     *
     * @param int $customFormatsAdded Number of custom formats added.
     * @param int $customFormatsUpdated Number of custom formats updated.
     * @param int $qualityProfilesAdded Number of quality profiles added.
     * @param int $qualityProfilesUpdated Number of quality profiles updated.
     * @param string $version The TRaSH-Guides version synced.
     * @param DateTimeImmutable $syncedAt Timestamp of the sync.
     */
    public function __construct(
        public readonly int $customFormatsAdded,
        public readonly int $customFormatsUpdated,
        public readonly int $qualityProfilesAdded,
        public readonly int $qualityProfilesUpdated,
        public readonly string $version,
        public readonly DateTimeImmutable $syncedAt,
    ) {
    }

    /**
     * Returns the total number of custom formats changed.
     *
     * @return int Total custom formats added + updated.
     */
    public function getTotalCustomFormatsChanged(): int
    {
        return $this->customFormatsAdded + $this->customFormatsUpdated;
    }

    /**
     * Returns the total number of quality profiles changed.
     *
     * @return int Total quality profiles added + updated.
     */
    public function getTotalQualityProfilesChanged(): int
    {
        return $this->qualityProfilesAdded + $this->qualityProfilesUpdated;
    }

    /**
     * Returns the total number of all items changed.
     *
     * @return int Total of all changes.
     */
    public function getTotalChanges(): int
    {
        return $this->getTotalCustomFormatsChanged() + $this->getTotalQualityProfilesChanged();
    }

    /**
     * Returns true if no changes were made.
     *
     * @return bool True if no items were added or updated.
     */
    public function isEmpty(): bool
    {
        return $this->getTotalChanges() === 0;
    }

    /**
     * Converts the result to an array representation.
     *
     * @return array<string, mixed> Array representation.
     */
    public function toArray(): array
    {
        return [
            'custom_formats_added' => $this->customFormatsAdded,
            'custom_formats_updated' => $this->customFormatsUpdated,
            'quality_profiles_added' => $this->qualityProfilesAdded,
            'quality_profiles_updated' => $this->qualityProfilesUpdated,
            'version' => $this->version,
            'synced_at' => $this->syncedAt->format('c'),
            'total_changes' => $this->getTotalChanges(),
        ];
    }
}
