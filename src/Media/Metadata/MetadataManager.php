<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Metadata\RatingSource;
use Phlix\Media\Metadata\RatingType;

/**
 * MetadataManager coordinates metadata fetching from multiple providers.
 *
 * This class manages registration of metadata providers (TMDB, TVDB, Fanart.tv, local NFO),
 * prioritizes them by media type, and handles the refresh workflow for items.
 * It supports cascading provider fallback when one provider fails to return results.
 *
 * **Memory safety:** The library-refresh methods page through items in fixed-size
 * batches so a huge library (10K+ items) never loads every row into memory at once.
 * Use {@see refreshLibraryMetadataBatched()} for a generator-based stream that
 * yields items as they are processed, or {@see refreshLibraryMetadata()} for the
 * classic all-at-once count return.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Metadata fetching coordination with provider prioritization and fallback
 * @see MetadataProviderInterface For provider implementation contract
 * @see TmdbProvider For TMDB movie metadata
 * @see TvdbProvider For TVDB series metadata
 */
class MetadataManager
{
    /** @var ItemRepository Repository for media item persistence */
    private ItemRepository $itemRepository;

    /**
     * Page size for batched library refresh operations.
     *
     * Keeps individual query result sets bounded so a huge library
     * never exhausts PHP memory during a metadata refresh run.
     *
     * @var int
     */
    private const PAGE_SIZE = 100;

    /** @var array<string, array<string, MetadataProviderInterface>> Provider type => [name => provider] */
    private array $providersByType = [];

    /** @var array<string, array<int, string>> Media type => Provider types in priority order */
    private array $providerPriority = [
        'movie' => ['tmdb', 'local'],
        'series' => ['tvdb', 'fanart', 'local'],
        'episode' => ['tvdb', 'local'],
        'anime' => ['anidb', 'myanimelist', 'tvdb', 'fanart', 'local'],
        'artist' => ['musicbrainz', 'audiodb', 'local'],
        'album' => ['musicbrainz', 'audiodb', 'local'],
        'track' => ['musicbrainz', 'audiodb', 'local'],
    ];

    /** @var array<string, MetadataProviderInterface> Flat provider lookup by name */
    private array $providers = [];

    /** @var \Phlix\Common\Logger\StructuredLogger Structured logger instance */
    private \Phlix\Common\Logger\StructuredLogger $logger;

    /**
     * Library data access used to load a library's `options.image_types`
     * selection (M5) so {@see tryProvider()} can filter the stored per-provider
     * image blob to the enabled artwork types. Nullable for back-compat: when
     * null (legacy construction / unit tests) NO image filtering happens and the
     * full provider image set is stored, exactly as before.
     *
     * @var LibraryManager|null
     */
    private ?LibraryManager $libraries;

    /**
     * Rating persistence. Nullable for back-compat: when null (legacy / unit
     * tests) no rating capture happens after a TMDB fetch.
     *
     * @var RatingService|null
     */
    private ?RatingService $ratingService;

    /**
     * Constructor for MetadataManager.
     *
     * @param ItemRepository $itemRepository Repository for media item operations
     * @param LibraryManager|null $libraries Library data access used to load the
     *     per-library `options.image_types` selection (M5); when null, provider
     *     image sets are stored unfiltered (back-compat).
     * @param RatingService|null $ratingService Rating persistence; when null
     *     (legacy / unit tests) rating capture is skipped silently.
     */
    public function __construct(
        ItemRepository $itemRepository,
        ?LibraryManager $libraries = null,
        ?RatingService $ratingService = null,
    ) {
        $this->itemRepository = $itemRepository;
        $this->libraries = $libraries;
        $this->ratingService = $ratingService;
        $this->logger = LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Register a metadata provider.
     *
     * @param string $name Provider name (e.g., 'tmdb', 'tvdb', 'fanart', 'local')
     * @param MetadataProviderInterface $provider The provider instance
     * @param array<string> $supportedTypes Media types this provider supports (e.g., ['movie', 'series'])
     * @return void
     */
    public function registerProvider(
        string $name,
        MetadataProviderInterface $provider,
        array $supportedTypes = []
    ): void {
        $this->providers[$name] = $provider;

        foreach ($supportedTypes as $type) {
            if (!isset($this->providersByType[$type])) {
                $this->providersByType[$type] = [];
            }
            $this->providersByType[$type][$name] = $provider;
        }

        $this->logger->info('Registered metadata provider', [
            'name' => $name,
            'supported_types' => $supportedTypes
        ]);
    }

    /**
     * Set provider priority for a media type.
     *
     * @param string $mediaType e.g., 'movie', 'series', 'episode'
     * @param array<string> $priority Ordered list of provider names (highest priority first)
     * @return void
     *
     * @example
     * ```php
     * $manager->setProviderPriority('movie', ['local', 'tmdb', 'fanart']);
     * ```
     */
    public function setProviderPriority(string $mediaType, array $priority): void
    {
        $this->providerPriority[$mediaType] = $priority;
        $this->logger->info('Updated provider priority', [
            'media_type' => $mediaType,
            'priority' => $priority
        ]);
    }

    /**
     * Get providers for a specific media type in priority order.
     *
     * @param string $mediaType The media type to get providers for
     * @return list<MetadataProviderInterface> Array of providers ordered by priority
     */
    public function getProvidersForType(string $mediaType): array
    {
        $priority = $this->providerPriority[$mediaType] ?? ['local'];
        $result = [];

        foreach ($priority as $providerName) {
            if (isset($this->providers[$providerName])) {
                $result[] = $this->providers[$providerName];
            }
        }

        return $result;
    }

    /**
     * Refresh metadata for a single item, trying providers in priority order.
     *
     * @param string $itemId The media item's unique identifier
     * @param bool $force Force refresh even if recent metadata exists
     * @return bool True if metadata was successfully refreshed from any provider
     */
    public function refreshItemMetadata(string $itemId, bool $force = false): bool
    {
        $item = $this->itemRepository->findById($itemId);
        if (!$item) {
            $this->logger->warning('Cannot refresh metadata - item not found', ['item_id' => $itemId]);
            return false;
        }

        $mediaType = MetadataValue::asString($item['type'] ?? null);
        $providers = $this->getProvidersForType($mediaType);

        if (empty($providers)) {
            $this->logger->debug('No providers for item type', ['type' => $mediaType]);
            return false;
        }

        $metadata = $this->parseMetadataJson(MetadataValue::asNullableString($item['metadata_json'] ?? null));
        $searchQuery = MetadataValue::asString(
            $metadata['name'] ?? ($item['name'] ?? null)
        );
        $year = MetadataValue::asNullableString($metadata['year'] ?? null);

        // Try each provider in priority order
        foreach ($providers as $provider) {
            $providerName = $this->getProviderName($provider);

            $this->logger->debug('Attempting metadata refresh', [
                'item_id' => $itemId,
                'provider' => $providerName,
            ]);

            $result = $this->tryProvider(
                $provider,
                $providerName,
                $itemId,
                $item,
                $searchQuery,
                $year,
                $force
            );

            if ($result) {
                return true;
            }

            $this->logger->info('Provider failed, trying next', [
                'item_id' => $itemId,
                'provider' => $providerName,
            ]);
        }

        $this->logger->info('No provider succeeded for item', [
            'item_id' => $itemId,
            'media_type' => $mediaType,
        ]);

        return false;
    }

    /**
     * Try a specific provider to refresh metadata for an item.
     *
     * @param MetadataProviderInterface $provider The provider to try
     * @param string $providerName The provider's name for logging
     * @param string $itemId The media item's unique identifier
     * @param array<string, mixed> $item The media item data
     * @param string $searchQuery The search query string
     * @param string|null $year Optional year to filter search
     * @param bool $force Force refresh even if recent metadata exists
     * @return bool True if metadata was successfully fetched and saved
     */
    private function tryProvider(
        MetadataProviderInterface $provider,
        string $providerName,
        string $itemId,
        array $item,
        string $searchQuery,
        ?string $year,
        bool $force
    ): bool {
        $metadata = $this->parseMetadataJson(MetadataValue::asNullableString($item['metadata_json'] ?? null));

        // Check if we already have recent metadata from this provider
        if (!$force && $this->hasRecentMetadata($metadata, $providerName)) {
            $this->logger->debug('Skipping - recent metadata exists', [
                'item_id' => $itemId,
                'provider' => $providerName,
            ]);
            return true;
        }

        // Search for match
        $results = $provider->search($searchQuery, ['year' => $year]);
        if (empty($results)) {
            $this->logger->debug('No search results', [
                'item' => $searchQuery,
                'provider' => $providerName,
            ]);
            return false;
        }

        // Get best match (first result)
        $match = $results[0];
        $externalId = MetadataValue::asString($match['id'] ?? null);

        // Fetch full details, passing preferred_locale for multi-language fallback (P1-S4)
        $preferredLocale = MetadataValue::asNullableString($metadata['preferred_locale'] ?? null);
        $detailOptions = $preferredLocale !== null ? ['preferred_locale' => $preferredLocale] : [];
        $details = $provider->getDetails($externalId, $detailOptions);
        if (empty($details)) {
            $this->logger->debug('No details from provider', [
                'external_id' => $externalId,
                'provider' => $providerName,
            ]);
            return false;
        }

        // Fetch images, then filter the per-provider image set to the artwork
        // types enabled for this item's library (M5). A disabled type's image
        // list is dropped before storage; unmapped keys pass through. When no
        // LibraryManager is wired (back-compat) the full set is kept.
        $images = $this->filterProviderImages($item, $provider->getImages($externalId));

        // Build external IDs tracking
        $externalIds = MetadataValue::asAssoc($metadata['external_ids'] ?? null);
        $externalIds[$providerName] = $externalId;

        // If we have IDs from other providers, preserve them
        $existingExternalIds = MetadataValue::asAssoc($metadata['external_ids'] ?? null);
        foreach ($existingExternalIds as $key => $value) {
            if ($key !== $providerName && !isset($externalIds[$key])) {
                $externalIds[$key] = $value;
            }
        }

        // Update item with metadata
        $existingDetails = MetadataValue::asAssoc($metadata['details'] ?? null);
        $existingImages = MetadataValue::asAssoc($metadata['images'] ?? null);

        $this->itemRepository->update($itemId, [
            'name' => MetadataValue::asString(
                $details['name'] ?? ($item['name'] ?? null)
            ),
            'metadata_json' => json_encode(array_merge($metadata, [
                'external_ids' => $externalIds,
                'details' => array_merge($existingDetails, [
                    $providerName => $details,
                ]),
                'images' => array_merge($existingImages, [
                    $providerName => $images,
                ]),
                'metadata_refreshed_at' => date('c'),
                'metadata_provider' => $providerName,
            ])),
        ]);

        $this->logger->info('Metadata refreshed', [
            'item_id' => $itemId,
            'external_id' => $externalId,
            'provider' => $providerName,
        ]);

        // Capture TMDB ratings (P1-S1): extract vote_average and vote_count from
        // the TMDB details payload and persist them so the aggregate is kept
        // current after every metadata refresh. Skip silently when the service
        // is not wired (back-compat / unit tests).
        if ($this->ratingService !== null && $providerName === 'tmdb') {
            $score = MetadataValue::asNullableFloat($details['vote_average'] ?? null);
            $votes = MetadataValue::asNullableInt($details['vote_count'] ?? null);
            if ($score !== null && $score >= 0.0) {
                $this->ratingService->upsert(
                    $itemId,
                    RatingSource::Tmdb,
                    RatingType::User,
                    $score,
                    $votes,
                );
                $this->ratingService->aggregate($itemId);
            }
        }

        return true;
    }

    /**
     * Filter a per-provider image-set blob to the artwork types enabled for the
     * item's library (M5).
     *
     * Reads the item's `library_id`, loads that library's `options.image_types`
     * selection via the injected {@see LibraryManager} (defaults when the key is
     * absent), and drops disabled mapped image keys via
     * {@see ImageType::filterProviderImages()}. Best-effort: when no
     * LibraryManager is wired, the library has no id, or loading fails, the FULL
     * image set is returned unchanged (behaviour exactly as before).
     *
     * @param array<string, mixed> $item   The media item being refreshed.
     * @param array<string, mixed> $images The provider image-set blob.
     *
     * @return array<string, mixed> The blob with disabled mapped keys removed.
     */
    private function filterProviderImages(array $item, array $images): array
    {
        if ($this->libraries === null || $images === []) {
            return $images;
        }

        $libraryId = MetadataValue::asNullableString($item['library_id'] ?? null);
        if ($libraryId === null || $libraryId === '') {
            return $images;
        }

        try {
            $library = $this->libraries->getLibrary($libraryId);
        } catch (\Throwable $e) {
            $this->logger->warning('MetadataManager: image-type load failed; storing full image set', [
                'library_id' => $libraryId,
                'error' => $e->getMessage(),
            ]);
            return $images;
        }

        if (!is_array($library)) {
            return $images;
        }
        $options = MetadataValue::asAssoc($library['options'] ?? null);
        $enabled = ImageType::enabledForOptions($options);

        return ImageType::filterProviderImages($images, $enabled);
    }

    /**
     * Check if metadata was recently refreshed from a specific provider.
     *
     * @param array<string, mixed> $metadata The current metadata array
     * @param string $providerName The provider name to check
     * @return bool True if recent metadata exists from this provider
     */
    private function hasRecentMetadata(array $metadata, string $providerName): bool
    {
        // Check if we have details from this provider
        $details = MetadataValue::asAssoc($metadata['details'] ?? null);
        if (!isset($details[$providerName])) {
            return false;
        }

        // Check refresh timestamp (within last 24 hours)
        $refreshedAtRaw = MetadataValue::asNullableString($metadata['metadata_refreshed_at'] ?? null);
        if ($refreshedAtRaw === null) {
            return false;
        }

        $refreshedAt = strtotime($refreshedAtRaw);
        if ($refreshedAt === false) {
            return false;
        }

        return (time() - $refreshedAt) < 86400; // 24 hours
    }

    /**
     * Refresh metadata for entire library with optional progress callback.
     *
     * Pages through the library in fixed-size batches ({@see PAGE_SIZE}) so a
     * huge library (10K+ items) never loads every row into memory at once.
     *
     * @param string $libraryId The library's unique identifier
     * @param callable|null $progressCallback Optional callback(current, total) for progress updates
     * @return int Number of items successfully refreshed
     */
    public function refreshLibraryMetadata(string $libraryId, ?callable $progressCallback = null): int
    {
        $refreshed = 0;
        $processed = 0;

        foreach ($this->refreshLibraryMetadataBatched($libraryId) as $itemRefreshed) {
            if ($itemRefreshed) {
                $refreshed++;
            }
            $processed++;

            if ($progressCallback !== null) {
                $progressCallback($processed, 0, $refreshed);
            }
        }

        return $refreshed;
    }

    /**
     * Generator that refreshes metadata for entire library in batches.
     *
     * Streams items through a generator so the caller can process them
     * one-at-a-time without ever holding the full library in memory.
     * Each iteration yields `true` if the item was refreshed, `false` otherwise.
     *
     * @param string $libraryId The library's unique identifier
     * @return \Generator<int, bool, mixed, void> Yields bool per item (true=refreshed)
     */
    public function refreshLibraryMetadataBatched(string $libraryId): \Generator
    {
        $offset = 0;

        while (true) {
            $batch = $this->itemRepository->getByLibrary($libraryId, self::PAGE_SIZE, $offset);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $item) {
                $itemId = MetadataValue::asString($item['id'] ?? null);
                $refreshed = false;

                if ($itemId !== '') {
                    $refreshed = $this->refreshItemMetadata($itemId);
                }

                yield $refreshed;
            }

            // Stop when we receive a short page (end of results).
            if (count($batch) < self::PAGE_SIZE) {
                break;
            }

            $offset += self::PAGE_SIZE;
        }
    }

    /**
     * Get provider name from provider instance.
     *
     * @param MetadataProviderInterface $provider The provider instance
     * @return string The provider name or 'unknown' if not found
     */
    private function getProviderName(MetadataProviderInterface $provider): string
    {
        foreach ($this->providers as $name => $p) {
            if ($p === $provider) {
                return $name;
            }
        }
        return 'unknown';
    }

    /**
     * Get all registered provider names.
     *
     * @return array<int, string> Array of provider names
     */
    public function getRegisteredProviders(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Check if a specific provider is registered.
     *
     * @param string $name The provider name to check
     * @return bool True if provider is registered
     */
    public function hasProvider(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * Get provider by name.
     *
     * @param string $name The provider name
     * @return MetadataProviderInterface|null The provider instance or null if not found
     */
    public function getProvider(string $name): ?MetadataProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Parse metadata JSON string to array.
     *
     * @param string|null $json JSON string to parse
     * @return array<string, mixed> Parsed metadata array or empty array on failure
     */
    private function parseMetadataJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return MetadataValue::asAssoc($data);
    }
}
