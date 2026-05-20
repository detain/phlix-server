# Step 2.2: Metadata Fetching

**Phase:** 2 - Media Library & Metadata System  
**Plan File:** step-2.2-metadata-fetching.md  
**Objective:** Implement metadata providers (TMDB/TVDB), metadata manager, and external metadata fetching

---

## Overview

This step implements external metadata fetching from TMDB/TVDB providers, metadata caching, and the metadata refresh system.

**Prerequisites:** Step 2.1 must be completed first.

---

## Tasks

### 2.2.1 Create Metadata Provider Interface

Create `src/Media/Metadata/MetadataProviderInterface.php`:
```php
<?php

namespace Phlex\Media\Metadata;

interface MetadataProviderInterface
{
    public function search(string $query, array $options = []): array;
    public function getDetails(string $externalId, array $options = []): array;
    public function getImages(string $externalId): array;
    public function getProviders(): array;
}
```

### 2.2.2 Create HTTP Client for Metadata

Create `src/Media/Metadata/MetadataHttpClient.php`:
```php
<?php

namespace Phlex\Media\Metadata;

use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

class MetadataHttpClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private StructuredLogger $logger;
    private array $cache = [];

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 10)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->logger = LoggerFactory::get(LogChannels::MEDIA);
    }

    public function get(string $endpoint, array $params = []): ?array
    {
        $cacheKey = md5($endpoint . json_encode($params));
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $params['api_key'] = $this->apiKey;
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->error('Metadata HTTP request failed', [
                'url' => $url,
                'error' => error_get_last()['message'] ?? 'Unknown error',
            ]);
            return null;
        }

        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON response from metadata API', [
                'url' => $url,
                'json_error' => json_last_error_msg(),
            ]);
            return null;
        }

        $this->cache[$cacheKey] = $data;
        return $data;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
```

### 2.2.3 Create TMDB Provider

Create `src/Media/Metadata/TmdbProvider.php`:
```php
<?php

namespace Phlex\Media\Metadata;

class TmdbProvider implements MetadataProviderInterface
{
    private MetadataHttpClient $http;
    private string $imageBaseUrl;
    private array $cache = [];

    public function __construct(string $apiKey)
    {
        $this->http = new MetadataHttpClient(
            'https://api.themoviedb.org/3',
            $apiKey
        );
        $this->imageBaseUrl = 'https://image.tmdb.org/t/p';
    }

    public function search(string $query, array $options = []): array
    {
        $language = $options['language'] ?? 'en-US';
        $includeAdult = $options['include_adult'] ?? false;

        $params = [
            'query' => $query,
            'language' => $language,
            'include_adult' => $includeAdult,
        ];

        $response = $this->http->get('/search/movie', $params);

        if (!$response || !isset($response['results'])) {
            return [];
        }

        return array_map(function ($result) {
            return [
                'id' => $result['id'],
                'title' => $result['title'] ?? $result['name'] ?? '',
                'original_title' => $result['original_title'] ?? '',
                'overview' => $result['overview'] ?? '',
                'poster_path' => $result['poster_path'] ?? null,
                'backdrop_path' => $result['backdrop_path'] ?? null,
                'release_date' => $result['release_date'] ?? '',
                'vote_average' => $result['vote_average'] ?? 0,
                'vote_count' => $result['vote_count'] ?? 0,
            ];
        }, $response['results']);
    }

    public function getDetails(string $externalId, array $options = []): array
    {
        $language = $options['language'] ?? 'en-US';
        
        $response = $this->http->get("/movie/{$externalId}", [
            'language' => $language,
            'append_to_response' => 'credits,genres,production_companies',
        ]);

        if (!$response) {
            return [];
        }

        return $this->formatMovieDetails($response);
    }

    public function getImages(string $externalId): array
    {
        $response = $this->http->get("/movie/{$externalId}/images");

        if (!$response) {
            return [];
        }

        return [
            'posters' => $this->formatImages($response['posters'] ?? []),
            'backdrops' => $this->formatImages($response['backdrops'] ?? []),
            'logos' => $this->formatImages($response['logos'] ?? []),
        ];
    }

    public function getProviders(): array
    {
        return ['tmdb'];
    }

    private function formatMovieDetails(array $data): array
    {
        return [
            'name' => $data['title'] ?? $data['name'] ?? '',
            'original_name' => $data['original_title'] ?? $data['original_name'] ?? '',
            'overview' => $data['overview'] ?? '',
            'official_rating' => null,
            'vote_average' => $data['vote_average'] ?? 0,
            'vote_count' => $data['vote_count'] ?? 0,
            'year' => isset($data['release_date']) ? date('Y', strtotime($data['release_date'])) : null,
            'runtime_ticks' => ($data['runtime'] ?? 0) * 600000000, // Convert minutes to ticks
            'genres' => array_map(fn($g) => $g['name'], $data['genres'] ?? []),
            'studio' => $data['production_companies'][0]['name'] ?? null,
            'tagline' => $data['tagline'] ?? '',
            'budget' => $data['budget'] ?? 0,
            'revenue' => $data['revenue'] ?? 0,
            'imdb_id' => $data['imdb_id'] ?? null,
            'tmdb_id' => $data['id'] ?? null,
            'actors' => array_map(fn($c) => [
                'name' => $c['name'] ?? '',
                'role' => $c['character'] ?? '',
                'order' => $c['order'] ?? 0,
            ], array_slice($data['credits']['cast'] ?? [], 0, 20)),
            'director' => $this->findDirector($data['credits']['crew'] ?? []),
        ];
    }

    private function findDirector(array $crew): ?string
    {
        foreach ($crew as $member) {
            if (($member['job'] ?? '') === 'Director') {
                return $member['name'] ?? null;
            }
        }
        return null;
    }

    private function formatImages(array $images): array
    {
        return array_map(function ($image) {
            return [
                'url' => $this->imageBaseUrl . '/w500' . $image['file_path'],
                'url_original' => $this->imageBaseUrl . '/original' . $image['file_path'],
                'width' => $image['width'] ?? 0,
                'height' => $image['height'] ?? 0,
                'language' => $image['iso_639_1'] ?? null,
            ];
        }, $images);
    }
}
```

### 2.2.4 Create Metadata Manager

Create `src/Media/Metadata/MetadataManager.php`:
```php
<?php

namespace Phlex\Media\Metadata;

use Phlex\Common\Database\Connection;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;
use Phlex\Media\Library\ItemRepository;

class MetadataManager
{
    private Connection $db;
    private ItemRepository $itemRepository;
    private array $providers = [];
    private StructuredLogger $logger;

    public function __construct(Connection $db, ItemRepository $itemRepository)
    {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->logger = LoggerFactory::get(LogChannels::MEDIA);
    }

    public function registerProvider(string $type, MetadataProviderInterface $provider): void
    {
        $this->providers[$type] = $provider;
        $this->logger->info('Registered metadata provider', ['type' => $type]);
    }

    public function refreshItemMetadata(string $itemId): bool
    {
        $item = $this->itemRepository->findById($itemId);
        if (!$item) {
            $this->logger->warning('Cannot refresh metadata - item not found', ['item_id' => $itemId]);
            return false;
        }

        $type = $this->getProviderType($item['type']);
        if (!isset($this->providers[$type])) {
            $this->logger->debug('No provider for item type', ['type' => $item['type']]);
            return false;
        }

        $provider = $this->providers[$type];
        $metadata = $this->parseMetadataJson($item['metadata_json'] ?? '{}');

        // Search for match
        $searchQuery = $metadata['name'] ?? $item['name'];
        $year = $metadata['year'] ?? null;

        $results = $provider->search($searchQuery, ['year' => $year]);
        if (empty($results)) {
            $this->logger->info('No metadata results found', ['item' => $searchQuery]);
            return false;
        }

        // Get best match (first result)
        $match = $results[0];
        $externalId = $match['id'];

        // Fetch full details
        $details = $provider->getDetails($externalId);
        if (empty($details)) {
            return false;
        }

        // Fetch images
        $images = $provider->getImages($externalId);

        // Update item with metadata
        $this->itemRepository->update($itemId, [
            'name' => $details['name'] ?? $item['name'],
            'metadata_json' => json_encode(array_merge($metadata, [
                'external_ids' => [
                    'tmdb' => $externalId,
                ],
                'details' => $details,
                'images' => $images,
                'metadata_refreshed_at' => date('c'),
            ])),
        ]);

        $this->logger->info('Metadata refreshed', ['item_id' => $itemId, 'external_id' => $externalId]);
        return true;
    }

    public function refreshLibraryMetadata(string $libraryId, callable $progressCallback = null): int
    {
        $items = $this->db->query(
            "SELECT id, name, metadata_json FROM media_items WHERE library_id = ?",
            [$libraryId]
        );

        $refreshed = 0;
        $total = count($items);

        foreach ($items as $index => $item) {
            if ($this->refreshItemMetadata($item['id'])) {
                $refreshed++;
            }

            if ($progressCallback) {
                $progressCallback($index + 1, $total);
            }
        }

        return $refreshed;
    }

    private function getProviderType(string $mediaType): string
    {
        return match($mediaType) {
            'movie' => 'tmdb',
            'series' => 'tvdb',
            default => 'local',
        };
    }

    private function parseMetadataJson(?string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
```

### 2.2.5 Create Unit Tests

Create `tests/unit/Media/Metadata/TmdbProviderTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Metadata\TmdbProvider;

class TmdbProviderTest extends TestCase
{
    public function testCanCreateTmdbProvider(): void
    {
        // Use a mock or test API key
        $provider = new TmdbProvider('test-api-key');
        $this->assertInstanceOf(TmdbProvider::class, $provider);
    }

    public function testGetProvidersReturnsTmdb(): void
    {
        $provider = new TmdbProvider('test-api-key');
        $providers = $provider->getProviders();
        
        $this->assertContains('tmdb', $providers);
    }
}
```

---

## Verification

After completing all tasks:

1. Run unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Media/Metadata/ --testdox
```

2. Verify classes exist:
```bash
ls -la /home/sites/phlex/src/Media/Metadata/
```

---

## Git Workflow

After verification, commit your changes:

```bash
cd /home/sites/phlex
git checkout -b step-2.2-metadata-fetching
git add .
git commit -m "Step 2.2: Implement metadata fetching with TMDB provider"
unset GITHUB_TOKEN
gh pr create --title "Step 2.2: Metadata Fetching" --body "Implements metadata providers including TmdbProvider, MetadataManager, and MetadataHttpClient."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 2.3: Item Repository** (`plans/phase-2/step-2.3-item-repository.md`).
