<?php

declare(strict_types=1);

namespace Phlix\Server\Arr;

use DateTimeImmutable;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\SyncResult;
use Phlix\Shared\Arr\TrashGuidesProvider;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Syncs TRaSH-Guides custom formats and quality profiles to Radarr.
 *
 * @package Phlix\Server\Arr
 * @since 0.12.0
 */
class CustomFormatSyncer
{
    private const string SYNC_TYPE_CUSTOM_FORMAT = 'custom_format';
    private const string SYNC_TYPE_QUALITY_PROFILE = 'quality_profile';

    private ?StructuredLogger $logger;
    private bool $enabled = true;
    private ?int $lastSyncTime = null;
    private bool $lastSyncTimeLoaded = false;

    /**
     * Creates a new CustomFormatSyncer instance.
     *
     * @param RadarrClient $radarr The Radarr API client.
     * @param TrashGuidesProvider $provider The TRaSH-Guides data provider.
     * @param Connection $db Database connection.
     * @param StructuredLogger|null $logger Optional logger instance.
     */
    public function __construct(
        private readonly RadarrClient $radarr,
        private readonly TrashGuidesProvider $provider,
        private readonly Connection $db,
        ?StructuredLogger $logger = null,
    ) {
        $this->logger = $logger;
        // lastSyncTime is loaded lazily on first call to getLastSyncTime()
        // to avoid database I/O in the constructor which causes issues
        // when the database schema isn't ready yet.
    }

    /**
     * Syncs all TRaSH-Guides custom formats and quality profiles.
     *
     * @return SyncResult The result of the sync operation.
     */
    public function syncAll(): SyncResult
    {
        if (!$this->enabled) {
            $this->logger?->info('TRaSH-Guides sync is disabled');
            return new SyncResult(
                customFormatsAdded: 0,
                customFormatsUpdated: 0,
                qualityProfilesAdded: 0,
                qualityProfilesUpdated: 0,
                version: $this->provider->getVersion(),
                syncedAt: new DateTimeImmutable(),
            );
        }

        $customFormatsAdded = $this->syncCustomFormats();
        $qualityProfilesAdded = $this->syncQualityProfiles();

        $version = $this->provider->getVersion();
        $syncedAt = new DateTimeImmutable();

        // Log the sync
        $this->logSync(
            customFormatsAdded: $customFormatsAdded,
            customFormatsUpdated: 0,
            qualityProfilesAdded: $qualityProfilesAdded,
            qualityProfilesUpdated: 0,
            version: $version,
            errorMessage: null
        );

        $this->lastSyncTime = time();

        return new SyncResult(
            customFormatsAdded: $customFormatsAdded,
            customFormatsUpdated: 0,
            qualityProfilesAdded: $qualityProfilesAdded,
            qualityProfilesUpdated: 0,
            version: $version,
            syncedAt: $syncedAt,
        );
    }

    /**
     * Syncs only custom formats from TRaSH-Guides.
     *
     * @return int Number of custom formats added/updated.
     */
    public function syncCustomFormats(): int
    {
        $trashData = $this->provider->getCustomFormats();
        $version = $this->provider->getVersion();

        // TRaSH-Guides JSON has 'formats' or 'collections' key with the actual items
        /** @var array<int, array<string, mixed>> $trashFormats */
        $trashFormats = $trashData['formats'] ?? $trashData['collections'] ?? [];

        $this->logger?->info('Starting custom formats sync', ['count' => count($trashFormats)]);

        $radarrFormats = $this->radarr->getCustomFormats();
        $radarrFormatMap = [];
        foreach ($radarrFormats as $format) {
            $formatName = $format['name'] ?? null;
            if (is_string($formatName)) {
                $radarrFormatMap[$formatName] = $format;
            }
        }

        $count = 0;

        foreach ($trashFormats as $trashFormat) {
            if (!is_array($trashFormat)) {
                continue;
            }

            $name = is_string($trashFormat['name'] ?? null) ? $trashFormat['name'] : null;
            if ($name === null) {
                continue;
            }

            $nameHash = crc32($name);

            // Check if already synced with same version
            $existing = $this->getSyncEntry(self::SYNC_TYPE_CUSTOM_FORMAT, $nameHash);
            $existingVersion = $existing['trash_version'] ?? null;
            if ($existing !== null && is_string($existingVersion) && $existingVersion === $version) {
                $this->logger?->debug('Custom format already synced', ['name' => $name]);
                continue;
            }

            // Create or update the custom format in Radarr
            $payload = $this->buildCustomFormatPayload($trashFormat);

            if (isset($radarrFormatMap[$name]) && is_array($radarrFormatMap[$name])) {
                $remoteData = $radarrFormatMap[$name];
                $remoteId = is_int($remoteData['id'] ?? null) ? $remoteData['id'] : 0;
                if ($remoteId > 0) {
                    $this->radarr->updateCustomFormat($remoteId, $payload);
                    $this->logger?->info('Updated custom format', ['name' => $name, 'id' => $remoteId]);
                }
            } else {
                $newId = $this->radarr->createCustomFormat($payload);
                $this->logger?->info('Created custom format', ['name' => $name, 'id' => $newId]);
            }

            // Record sync state
            $this->recordSyncEntry(
                syncType: self::SYNC_TYPE_CUSTOM_FORMAT,
                remoteId: $nameHash,
                remoteName: $name,
                version: $version
            );

            $count++;
        }

        $this->logger?->info('Custom formats sync complete', ['processed' => $count]);

        return $count;
    }

    /**
     * Syncs only quality profiles from TRaSH-Guides.
     *
     * @return int Number of quality profiles added/updated.
     */
    public function syncQualityProfiles(): int
    {
        $trashData = $this->provider->getQualityProfiles();
        $version = $this->provider->getVersion();

        // TRaSH-Guides JSON has 'collections' or similar key with quality profiles
        /** @var array<int, array<string, mixed>> $trashProfiles */
        $trashProfiles = $trashData['collections'] ?? $trashData['profiles'] ?? [];

        $this->logger?->info('Starting quality profiles sync', ['count' => count($trashProfiles)]);

        $radarrProfiles = $this->radarr->getQualityProfiles();
        $radarrProfileMap = [];
        foreach ($radarrProfiles as $profile) {
            $profileName = $profile['name'] ?? null;
            if (is_string($profileName)) {
                $radarrProfileMap[$profileName] = $profile;
            }
        }

        $count = 0;

        foreach ($trashProfiles as $trashProfile) {
            if (!is_array($trashProfile)) {
                continue;
            }

            $name = is_string($trashProfile['name'] ?? null) ? $trashProfile['name'] : null;
            if ($name === null) {
                continue;
            }

            $nameHash = crc32($name);

            // Check if already synced with same version
            $existing = $this->getSyncEntry(self::SYNC_TYPE_QUALITY_PROFILE, $nameHash);
            $existingVersion = $existing['trash_version'] ?? null;
            if ($existing !== null && is_string($existingVersion) && $existingVersion === $version) {
                $this->logger?->debug('Quality profile already synced', ['name' => $name]);
                continue;
            }

            // Create or update the quality profile in Radarr
            $payload = $this->buildQualityProfilePayload($trashProfile);

            if (isset($radarrProfileMap[$name]) && is_array($radarrProfileMap[$name])) {
                $remoteData = $radarrProfileMap[$name];
                $remoteId = is_int($remoteData['id'] ?? null) ? $remoteData['id'] : 0;
                if ($remoteId > 0) {
                    $this->radarr->updateQualityProfile($remoteId, $payload);
                    $this->logger?->info('Updated quality profile', ['name' => $name, 'id' => $remoteId]);
                }
            } else {
                $newId = $this->radarr->createQualityProfile($payload);
                $this->logger?->info('Created quality profile', ['name' => $name, 'id' => $newId]);
            }

            // Record sync state
            $this->recordSyncEntry(
                syncType: self::SYNC_TYPE_QUALITY_PROFILE,
                remoteId: $nameHash,
                remoteName: $name,
                version: $version
            );

            $count++;
        }

        $this->logger?->info('Quality profiles sync complete', ['processed' => $count]);

        return $count;
    }

    /**
     * Returns the Unix timestamp of the last successful sync.
     *
     * @return int|null Unix timestamp or null if never synced.
     */
    public function getLastSyncTime(): ?int
    {
        if (!$this->lastSyncTimeLoaded) {
            $this->lastSyncTimeLoaded = true;
            $this->loadLastSyncTime();
        }
        return $this->lastSyncTime;
    }

    /**
     * Enables or disables auto-sync.
     *
     * @param bool $enabled True to enable, false to disable.
     * @return void
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->logger?->info('TRaSH-Guides sync enabled', ['enabled' => $enabled]);
    }

    /**
     * Checks if sync is enabled.
     *
     * @return bool True if enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Loads the last sync time from the database.
     *
     * @return void
     */
    private function loadLastSyncTime(): void
    {
        $result = $this->db->query(
            'SELECT MAX(synced_at) as last_sync FROM trash_guides_sync_log WHERE error_message IS NULL'
        );

        if (is_array($result) && isset($result[0]) && is_array($result[0])) {
            $row = $result[0];
            if (is_string($row['last_sync'] ?? null)) {
                $parsed = strtotime($row['last_sync']);
                if ($parsed !== false) {
                    $this->lastSyncTime = $parsed;
                }
            }
        }
    }

    /**
     * Gets a sync entry from the database.
     *
     * @param string $syncType The sync type.
     * @param int $remoteId The remote ID.
     * @return array<string, mixed>|null The sync entry or null.
     */
    private function getSyncEntry(string $syncType, int $remoteId): ?array
    {
        $result = $this->db->query(
            'SELECT * FROM custom_format_sync WHERE sync_type = ? AND remote_id = ?',
            [$syncType, $remoteId]
        );

        if (is_array($result) && isset($result[0]) && is_array($result[0])) {
            /** @var array<string, mixed> $entry */
            $entry = $result[0];
            return $entry;
        }

        return null;
    }

    /**
     * Records a sync entry in the database.
     *
     * @param string $syncType The sync type.
     * @param int $remoteId The remote ID.
     * @param string $remoteName The remote name.
     * @param string $version The TRaSH-Guides version.
     * @return void
     */
    private function recordSyncEntry(
        string $syncType,
        int $remoteId,
        string $remoteName,
        string $version
    ): void {
        $id = $this->generateUuid();

        $this->db->query(
            'INSERT INTO custom_format_sync '
                . '(id, sync_type, remote_id, remote_name, trash_version, synced_at) '
                . 'VALUES (?, ?, ?, ?, ?, NOW()) '
                . 'ON DUPLICATE KEY UPDATE remote_name = VALUES(remote_name), '
                . 'trash_version = VALUES(trash_version), synced_at = NOW()',
            [$id, $syncType, $remoteId, $remoteName, $version]
        );
    }

    /**
     * Logs a sync operation to the trash_guides_sync_log table.
     *
     * @param int $customFormatsAdded Number added.
     * @param int $customFormatsUpdated Number updated.
     * @param int $qualityProfilesAdded Number added.
     * @param int $qualityProfilesUpdated Number updated.
     * @param string $version Version synced.
     * @param string|null $errorMessage Error message if failed.
     * @return void
     */
    private function logSync(
        int $customFormatsAdded,
        int $customFormatsUpdated,
        int $qualityProfilesAdded,
        int $qualityProfilesUpdated,
        string $version,
        ?string $errorMessage
    ): void {
        $id = $this->generateUuid();

        $this->db->query(
            'INSERT INTO trash_guides_sync_log '
                . '(id, synced_at, custom_formats_added, custom_formats_updated, '
                . 'quality_profiles_added, quality_profiles_updated, version, error_message) '
                . 'VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $customFormatsAdded,
                $customFormatsUpdated,
                $qualityProfilesAdded,
                $qualityProfilesUpdated,
                $version,
                $errorMessage,
            ]
        );
    }

    /**
     * Builds the payload for creating/updating a custom format.
     *
     * @param array<string, mixed> $trashFormat The TRaSH-Guides format data.
     * @return array<string, mixed> The payload for Radarr API.
     */
    private function buildCustomFormatPayload(array $trashFormat): array
    {
        return [
            'name' => $trashFormat['name'] ?? 'Unknown',
            'includeCustomFormatWhenRenaming' => $trashFormat['includeCustomFormatWhenRenaming'] ?? false,
            'Specifications' => $trashFormat['Specifications'] ?? [],
        ];
    }

    /**
     * Builds the payload for creating/updating a quality profile.
     *
     * @param array<string, mixed> $trashProfile The TRaSH-Guides profile data.
     * @return array<string, mixed> The payload for Radarr API.
     */
    private function buildQualityProfilePayload(array $trashProfile): array
    {
        return [
            'name' => $trashProfile['name'] ?? 'Unknown',
            'upgradeUntil' => $trashProfile['upgradeUntil'] ?? null,
            'cutoff' => $trashProfile['cutoff'] ?? null,
            'items' => $trashProfile['items'] ?? [],
        ];
    }

    /**
     * Generates a UUID string.
     *
     * @return string UUID in standard format.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            (mt_rand(0, 0x0fff) | 0x4000) & 0x4fff,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
