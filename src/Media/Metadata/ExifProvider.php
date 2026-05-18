<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

use Phlex\Media\Library\ItemRepository;
use Psr\Log\LoggerInterface;

/**
 * ExifProvider provides local EXIF metadata for photos.
 *
 * This metadata provider reads EXIF data that was extracted during
 * photo scanning and stored in the media_items metadata_json field.
 * It does not make external API calls.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Local EXIF metadata provider for photos
 * @see MetadataProviderInterface For the provider contract
 * @since 0.16.0
 */
class ExifProvider implements MetadataProviderInterface
{
    /** Photo media type constant */
    public const MEDIA_TYPE_PHOTO = 'photo';

    /** @var ItemRepository Repository for media item access */
    private ItemRepository $itemRepo;

    /**
     * Constructor for ExifProvider.
     *
     * @param ItemRepository $itemRepo Repository for accessing media items
     */
    public function __construct(ItemRepository $itemRepo)
    {
        $this->itemRepo = $itemRepo;
    }

    /**
     * {@inheritdoc}
     *
     * Not applicable for photos - returns empty array.
     * Photo browsing is done through albums, not search.
     *
     * @since 0.16.0
     */
    public function search(string $query, array $options = []): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     *
     * @since 0.16.0
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     *
     * @since 0.16.0
     */
    public function getImages(string $externalId): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     *
     * @since 0.16.0
     */
    public function getProviders(): array
    {
        return ['exif'];
    }

    /**
     * {@inheritdoc}
     *
     * @since 0.16.0
     */
    public function getSourceName(): string
    {
        return 'exif';
    }

    /**
     * Checks if this provider supports the given media type.
     *
     * @param string $mediaType The media type to check
     * @return bool True if this provider supports photos
     *
     * @since 0.16.0
     */
    public function supports(string $mediaType): bool
    {
        return $mediaType === self::MEDIA_TYPE_PHOTO;
    }

    /**
     * Gets full EXIF metadata for a photo.
     *
     * Retrieves the EXIF data that was stored in the media item's
     * metadata_json field during scanning.
     *
     * @param string $photoId The photo's unique identifier
     * @return array<string, mixed>|null Full EXIF metadata array or null if not found
     *
     * @since 0.16.0
     */
    public function getPhotoMetadata(string $photoId): ?array
    {
        $item = $this->itemRepo->findById($photoId);

        if ($item === null || $item['type'] !== 'photo') {
            return null;
        }

        /** @var array<string, mixed> */
        $metadata = $item['metadata'] ?? [];

        // Add computed fields
        if (isset($metadata['date_taken_unix']) && is_numeric($metadata['date_taken_unix'])) {
            /** @var int */
            $timestamp = (int)$metadata['date_taken_unix'];
            $metadata['date_taken_formatted'] = date('Y-m-d H:i:s', $timestamp);
            $metadata['date_taken_year'] = date('Y', $timestamp);
            $metadata['date_taken_month'] = date('F', $timestamp);
        }

        // Add display-ready GPS coordinates
        if (isset($metadata['gps_lat']) && isset($metadata['gps_lng'])
            && is_numeric($metadata['gps_lat']) && is_numeric($metadata['gps_lng'])
        ) {
            $lat = (float)$metadata['gps_lat'];
            $lng = (float)$metadata['gps_lng'];
            $metadata['gps_display'] = sprintf('%.6f, %.6f', $lat, $lng);
        }

        /** @var array<string, mixed> */
        return $metadata;
    }
}
