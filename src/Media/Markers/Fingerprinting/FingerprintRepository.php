<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Fingerprinting;

use Phlix\Media\Library\ItemRepository;

/**
 * Repository for storing and retrieving audio fingerprints.
 *
 * Persists fingerprints in the media_items metadata_json column,
 * avoiding schema changes at this stage.
 *
 * @since 0.12.0
 */
class FingerprintRepository
{
    /** @var ItemRepository The item repository for database access */
    private ItemRepository $itemRepo;

    /** Metadata key for storing the fingerprint */
    public const FINGERPRINT_KEY = 'fingerprint';

    /**
     * Creates a new FingerprintRepository.
     *
     * @param ItemRepository $itemRepo The item repository
     *
     * @since 0.12.0
     */
    public function __construct(ItemRepository $itemRepo)
    {
        $this->itemRepo = $itemRepo;
    }

    /**
     * Store a raw fingerprint string on a media item's metadata_json.
     *
     * @param string $mediaItemId The media item ID
     * @param string $fingerprint The fingerprint data to store
     *
     * @return void
     *
     * @throws \InvalidArgumentException If media item not found
     *
     * @since 0.12.0
     */
    public function storeFingerprint(string $mediaItemId, string $fingerprint): void
    {
        $item = $this->itemRepo->findById($mediaItemId);

        if ($item === null) {
            throw new \InvalidArgumentException(
                sprintf('Media item not found: %s', $mediaItemId)
            );
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $item['metadata'] ?? [];
        $metadata[self::FINGERPRINT_KEY] = $fingerprint;

        $this->itemRepo->update($mediaItemId, [
            'metadata_json' => $metadata,
        ]);
    }

    /**
     * Retrieve stored fingerprint for a media item.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return string The fingerprint or empty string if none stored
     *
     * @since 0.12.0
     */
    public function getFingerprint(string $mediaItemId): string
    {
        $item = $this->itemRepo->findById($mediaItemId);

        if ($item === null) {
            return '';
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $item['metadata'] ?? [];
        /** @var string */
        $fingerprint = $metadata[self::FINGERPRINT_KEY] ?? '';
        return $fingerprint;
    }

    /**
     * Return all media_item_ids for a given show that already have fingerprints.
     *
     * @param string $showId The show/series ID to query
     *
     * @return array<string> Array of media item IDs with fingerprints
     *
     * @since 0.12.0
     */
    public function getFingerprintedIdsForShow(string $showId): array
    {
        $children = $this->itemRepo->findByParent($showId);

        /** @var array<string> $fingerprintedIds */
        $fingerprintedIds = [];
        foreach ($children as $child) {
            /** @var array<string, mixed> $childMetadata */
            $childMetadata = $child['metadata'] ?? [];
            $fingerprint = $childMetadata[self::FINGERPRINT_KEY] ?? '';
            if ($fingerprint !== '') {
                /** @var string $childId */
                $childId = $child['id'];
                $fingerprintedIds[] = $childId;
            }
        }

        return $fingerprintedIds;
    }
}
