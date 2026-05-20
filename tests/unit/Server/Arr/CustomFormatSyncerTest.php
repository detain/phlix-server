<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Arr;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Arr\CustomFormatSyncer;
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\SyncResult;
use Phlix\Shared\Arr\TrashGuidesProvider;
use Phlix\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;
use DateTimeImmutable;

/**
 * Unit tests for CustomFormatSyncer.
 *
 * @package Phlix\Tests\Unit\Arr
 * @since 0.12.0
 */
class CustomFormatSyncerTest extends TestCase
{
    private CustomFormatSyncerTestRadarrClient $radarr;
    private MockableTrashGuidesProvider $provider;
    private Connection $db;
    private ?StructuredLogger $logger;
    private CustomFormatSyncer $syncer;

    protected function setUp(): void
    {
        $this->radarr = new CustomFormatSyncerTestRadarrClient('http://localhost:7878', 'test-api-key');
        $this->provider = new MockableTrashGuidesProvider();
        $this->db = $this->createMock(Connection::class);
        $this->logger = null;

        $this->syncer = new CustomFormatSyncer(
            $this->radarr,
            $this->provider,
            $this->db,
            $this->logger
        );
    }

    public function testSyncCustomFormatsCreatesNew(): void
    {
        // Data must be in nested format as TRaSH-Guides provides it
        $this->provider->setCustomFormats([
            'formats' => [
                [
                    'name' => 'BR-Dish',
                    'includeCustomFormatWhenRenaming' => true,
                    'Specifications' => [],
                ],
            ],
        ]);
        $this->provider->setVersion('abc123');

        $this->radarr->setMockCustomFormats([]);
        $this->radarr->setMockCreateCustomFormatId(42);

        $this->db->method('query')->willReturn([]);

        $count = $this->syncer->syncCustomFormats();

        $this->assertEquals(1, $count);
        $this->assertEquals('create_custom_format', $this->radarr->getLastMethodCalled());
    }

    public function testSyncCustomFormatsUpdatesExisting(): void
    {
        // Data must be in nested format as TRaSH-Guides provides it
        $this->provider->setCustomFormats([
            'formats' => [
                [
                    'name' => 'BR-Dish',
                    'includeCustomFormatWhenRenaming' => true,
                    'Specifications' => [],
                ],
            ],
        ]);
        $this->provider->setVersion('abc123');

        $this->radarr->setMockCustomFormats([
            ['id' => 10, 'name' => 'BR-Dish'],
        ]);
        $this->radarr->setMockUpdateCustomFormatId(10);

        $this->db->method('query')->willReturn([]);

        $count = $this->syncer->syncCustomFormats();

        $this->assertEquals(1, $count);
        $this->assertEquals('update_custom_format', $this->radarr->getLastMethodCalled());
    }

    public function testSyncAllReturnsSyncResult(): void
    {
        $this->provider->setCustomFormats([
            'formats' => [
                ['name' => 'BR-Dish', 'includeCustomFormatWhenRenaming' => true, 'Specifications' => []],
            ],
        ]);
        $this->provider->setQualityProfiles([
            'collections' => [
                ['name' => 'HD-720p', 'items' => []],
            ],
        ]);
        $this->provider->setVersion('abc123');

        $this->radarr->setMockCustomFormats([]);
        $this->radarr->setMockQualityProfiles([]);
        $this->radarr->setMockCreateCustomFormatId(1);
        $this->radarr->setMockCreateQualityProfileId(1);

        $this->db->method('query')->willReturn([]);

        $result = $this->syncer->syncAll();

        $this->assertInstanceOf(SyncResult::class, $result);
        $this->assertEquals('abc123', $result->version);
        $this->assertEquals(1, $result->customFormatsAdded);
        $this->assertEquals(1, $result->qualityProfilesAdded);
    }

    public function testGetLastSyncTime(): void
    {
        // After syncing, it should return a timestamp
        $this->provider->setCustomFormats(['formats' => []]);
        $this->provider->setQualityProfiles(['collections' => []]);
        $this->provider->setVersion('abc123');

        $this->radarr->setMockCustomFormats([]);
        $this->radarr->setMockQualityProfiles([]);

        $this->db->method('query')->willReturn([]);

        $this->syncer->syncAll();

        $this->assertIsInt($this->syncer->getLastSyncTime());
    }

    public function testSyncIsIdempotentForSameVersion(): void
    {
        $version = 'same-version-123';

        $this->provider->setCustomFormats([
            'formats' => [
                ['name' => 'BR-Dish', 'includeCustomFormatWhenRenaming' => true, 'Specifications' => []],
            ],
        ]);
        $this->provider->setVersion($version);

        $this->radarr->setMockCustomFormats([
            ['id' => 10, 'name' => 'BR-Dish'],
        ]);

        // Set up DB to return existing entry with same version (simulates already synced)
        $this->db->method('query')
            ->willReturnCallback(function (string $sql) use ($version) {
                if (str_contains($sql, 'SELECT') && str_contains($sql, 'custom_format_sync')) {
                    return [['trash_version' => $version]];
                }
                return [];
            });

        $count = $this->syncer->syncCustomFormats();

        // Should not call create or update since already synced
        $this->assertEquals(0, $count);
    }

    public function testSetEnabledFalseSkipsSync(): void
    {
        $this->syncer->setEnabled(false);

        $this->provider->setCustomFormats([
            'formats' => [['name' => 'BR-Dish', 'Specifications' => []]],
        ]);
        $this->provider->setQualityProfiles([
            'collections' => [['name' => 'HD-720p', 'items' => []]],
        ]);
        $this->provider->setVersion('abc123');

        $result = $this->syncer->syncAll();

        $this->assertEquals(0, $result->customFormatsAdded);
        $this->assertEquals(0, $result->qualityProfilesAdded);
    }

    public function testSetEnabledTrueAllowsSync(): void
    {
        $this->syncer->setEnabled(false);
        $this->syncer->setEnabled(true);

        $this->provider->setCustomFormats(['formats' => []]);
        $this->provider->setQualityProfiles(['collections' => []]);
        $this->provider->setVersion('abc123');

        $this->radarr->setMockCustomFormats([]);
        $this->radarr->setMockQualityProfiles([]);

        $this->db->method('query')->willReturn([]);

        $result = $this->syncer->syncAll();

        // Should return result with 0 counts (empty data) but not blocked by enabled=false
        $this->assertInstanceOf(SyncResult::class, $result);
    }

    public function testSyncResultToArray(): void
    {
        $syncedAt = new DateTimeImmutable('2024-01-15 12:00:00');
        $result = new SyncResult(
            customFormatsAdded: 5,
            customFormatsUpdated: 2,
            qualityProfilesAdded: 1,
            qualityProfilesUpdated: 3,
            version: 'abc123',
            syncedAt: $syncedAt
        );

        $array = $result->toArray();

        $this->assertEquals(5, $array['custom_formats_added']);
        $this->assertEquals(2, $array['custom_formats_updated']);
        $this->assertEquals(1, $array['quality_profiles_added']);
        $this->assertEquals(3, $array['quality_profiles_updated']);
        $this->assertEquals('abc123', $array['version']);
        $this->assertEquals(11, $array['total_changes']);
    }

    public function testSyncResultIsEmpty(): void
    {
        $result = new SyncResult(
            customFormatsAdded: 0,
            customFormatsUpdated: 0,
            qualityProfilesAdded: 0,
            qualityProfilesUpdated: 0,
            version: 'abc123',
            syncedAt: new DateTimeImmutable()
        );

        $this->assertTrue($result->isEmpty());

        $resultWithChanges = new SyncResult(
            customFormatsAdded: 1,
            customFormatsUpdated: 0,
            qualityProfilesAdded: 0,
            qualityProfilesUpdated: 0,
            version: 'abc123',
            syncedAt: new DateTimeImmutable()
        );

        $this->assertFalse($resultWithChanges->isEmpty());
    }

    public function testSyncCustomFormatsHandlesMultiple(): void
    {
        $this->provider->setCustomFormats([
            'formats' => [
                ['name' => 'BR-Dish', 'Specifications' => []],
                ['name' => 'HD-Audio', 'Specifications' => []],
                ['name' => 'EAC3', 'Specifications' => []],
            ],
        ]);
        $this->provider->setVersion('abc123');

        $this->radarr->setMockCustomFormats([]);
        $this->radarr->setMockCreateCustomFormatId(1);

        $this->db->method('query')->willReturn([]);

        $count = $this->syncer->syncCustomFormats();

        $this->assertEquals(3, $count);
    }
}

/**
 * Mockable TrashGuidesProvider for testing.
 *
 * @internal For testing only
 */
class MockableTrashGuidesProvider extends TrashGuidesProvider
{
    /** @var array<int, array<string, mixed>> */
    private array $customFormats = [];

    /** @var array<int, array<string, mixed>> */
    private array $qualityProfiles = [];

    private string $version = 'test-version-123';

    public function setCustomFormats(array $formats): void
    {
        $this->customFormats = $formats;
    }

    public function setQualityProfiles(array $profiles): void
    {
        $this->qualityProfiles = $profiles;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function getCustomFormats(): array
    {
        return $this->customFormats;
    }

    public function getQualityProfiles(): array
    {
        return $this->qualityProfiles;
    }

    public function getVersion(): string
    {
        return $this->version;
    }
}

/**
 * Mockable RadarrClient for testing CustomFormatSyncer.
 *
 * @internal For testing only
 */
class CustomFormatSyncerTestRadarrClient extends RadarrClient
{
    /** @var array<int, array<string, mixed>> */
    private array $customFormats = [];

    /** @var array<int, array<string, mixed>> */
    private array $qualityProfiles = [];

    private ?string $lastMethodCalled = null;
    private ?int $createCustomFormatId = null;
    private ?int $updateCustomFormatId = null;
    private ?int $createQualityProfileId = null;
    private ?int $updateQualityProfileId = null;

    public function setMockCustomFormats(array $formats): void
    {
        $this->customFormats = $formats;
    }

    public function setMockQualityProfiles(array $profiles): void
    {
        $this->qualityProfiles = $profiles;
    }

    public function setMockCreateCustomFormatId(int $id): void
    {
        $this->createCustomFormatId = $id;
    }

    public function setMockUpdateCustomFormatId(int $id): void
    {
        $this->updateCustomFormatId = $id;
    }

    public function setMockCreateQualityProfileId(int $id): void
    {
        $this->createQualityProfileId = $id;
    }

    public function setMockUpdateQualityProfileId(int $id): void
    {
        $this->updateQualityProfileId = $id;
    }

    public function getLastMethodCalled(): ?string
    {
        return $this->lastMethodCalled;
    }

    public function getCustomFormats(): array
    {
        return $this->customFormats;
    }

    public function getQualityProfiles(): array
    {
        return $this->qualityProfiles;
    }

    public function createCustomFormat(array $payload): int
    {
        $this->lastMethodCalled = 'create_custom_format';
        return $this->createCustomFormatId ?? 0;
    }

    public function updateCustomFormat(int $id, array $payload): bool
    {
        $this->lastMethodCalled = 'update_custom_format';
        $this->updateCustomFormatId = $id;
        return true;
    }

    public function createQualityProfile(array $payload): int
    {
        $this->lastMethodCalled = 'create_quality_profile';
        return $this->createQualityProfileId ?? 0;
    }

    public function updateQualityProfile(int $id, array $payload): bool
    {
        $this->lastMethodCalled = 'update_quality_profile';
        $this->updateQualityProfileId = $id;
        return true;
    }
}
