<?php

declare(strict_types=1);

namespace Phlex\LiveTv;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * Channel Manager - Handles channel CRUD operations and lineup management.
 *
 * Provides functionality for:
 * - Channel creation, retrieval, update, and deletion
 * - Channel lineup management
 * - Favorite channels per user
 * - Channel grouping and sorting
 *
 * ## Channel Types
 *
 * - TYPE_TV: Standard television broadcast
 * - TYPE_RADIO: Audio-only broadcast
 * - TYPE_DATA: Data service (MHP, MHEG, etc.)
 *
 * ## Visibility States
 *
 * - VISIBILITY_VISIBLE: Channel is shown in listings
 * - VISIBILITY_HIDDEN: Channel is hidden but not deleted
 * - VISIBILITY_DELETED: Soft-deleted channel (excluded from queries)
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @see LiveTvManager For tuner integration
 */
class ChannelManager
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /**
     * Television channel type.
     *
     * @var string
     */
    public const TYPE_TV = 'tv';

    /**
     * Radio channel type.
     *
     * @var string
     */
    public const TYPE_RADIO = 'radio';

    /**
     * Data service channel type.
     *
     * @var string
     */
    public const TYPE_DATA = 'data';

    /**
     * Channel is visible in listings.
     *
     * @var string
     */
    public const VISIBILITY_VISIBLE = 'visible';

    /**
     * Channel is hidden but retained.
     *
     * @var string
     */
    public const VISIBILITY_HIDDEN = 'hidden';

    /**
     * Channel is soft-deleted.
     *
     * @var string
     */
    public const VISIBILITY_DELETED = 'deleted';

    /**
     * Creates a new ChannelManager instance.
     *
     * @param Connection $db Database connection
     * @param StructuredLogger|null $logger Optional logger, defaults to Livetv channel
     */
    public function __construct(Connection $db, ?StructuredLogger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
    }

    /**
     * Create a new channel.
     *
     * @param array<string, mixed> $data Channel data including:
     *   - name: string Channel display name (default: 'Unknown Channel')
     *   - number: int Channel number (default: 0)
     *   - type: string Channel type (default: TYPE_TV)
     *   - frequency: int Frequency in Hz
     *   - tuner_id: string|null Associated tuner ID
     *   - service_id: string|null DVB service ID
     *   - visual_id: string|null CAS visual ID
     *   - description: string|null Channel description
     *   - icon_url: string|null Channel icon URL
     * @return array<string, mixed>|null The created channel or null on failure
     *
     * @example
     * ```php
     * $channel = $manager->createChannel([
     *     'name' => 'BBC One',
     *     'number' => 1,
     *     'type' => ChannelManager::TYPE_TV,
     *     'frequency' => 474000000,
     * ]);
     * ```
     */
    public function createChannel(array $data): ?array
    {
        $channelId = $this->generateUuid();

        $this->db->query(
            "INSERT INTO livetv_channels
             (channel_id, name, number, type, frequency, tuner_id, service_id,
              visual_id, description, icon_url, visibility, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $channelId,
                $data['name'] ?? 'Unknown Channel',
                $data['number'] ?? 0,
                $data['type'] ?? self::TYPE_TV,
                $data['frequency'] ?? 0,
                $data['tuner_id'] ?? null,
                $data['service_id'] ?? null,
                $data['visual_id'] ?? null,
                $data['description'] ?? null,
                $data['icon_url'] ?? null,
                self::VISIBILITY_VISIBLE,
            ]
        );

        $this->logger->info('Channel created', ['channel_id' => $channelId, 'name' => $data['name'] ?? 'Unknown Channel']);

        return $this->getChannel($channelId);
    }

    /**
     * Get a channel by its ID.
     *
     * @param string $channelId The unique channel identifier
     * @return array<string, mixed>|null The channel data or null if not found
     */
    public function getChannel(string $channelId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_channels WHERE channel_id = ? AND visibility != ?",
            [$channelId, self::VISIBILITY_DELETED]
        );

        if (empty($result)) {
            return null;
        }

        return $this->mapChannel($result[0]);
    }

    /**
     * Get a channel by its number.
     *
     * @param int $number The channel number
     * @return array<string, mixed>|null The channel data or null if not found
     */
    public function getChannelByNumber(int $number): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_channels WHERE number = ? AND visibility = ?",
            [$number, self::VISIBILITY_VISIBLE]
        );

        if (empty($result)) {
            return null;
        }

        return $this->mapChannel($result[0]);
    }

    /**
     * Get all visible channels.
     *
     * @param string $sortBy Sort field: 'number', 'name', or 'created_at' (default: 'number')
     * @param string $sortOrder Sort order: 'ASC' or 'DESC' (default: 'ASC')
     * @return array<int, array<string, mixed>> List of visible channels
     */
    public function getAllChannels(string $sortBy = 'number', string $sortOrder = 'ASC'): array
    {
        $allowedSorts = ['number', 'name', 'created_at'];
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'number';
        $sortOrder = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';

        $result = $this->db->query(
            "SELECT * FROM livetv_channels WHERE visibility = ? ORDER BY $sortBy $sortOrder",
            [self::VISIBILITY_VISIBLE]
        );

        $channels = [];
        foreach ($result as $row) {
            $channels[] = $this->mapChannel($row);
        }

        return $channels;
    }

    /**
     * Get channels filtered by type.
     *
     * @param string $type One of TYPE_TV, TYPE_RADIO, or TYPE_DATA
     * @return array<int, array<string, mixed>> List of channels of the specified type
     */
    public function getChannelsByType(string $type): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_channels WHERE type = ? AND visibility = ? ORDER BY number ASC",
            [$type, self::VISIBILITY_VISIBLE]
        );

        $channels = [];
        foreach ($result as $row) {
            $channels[] = $this->mapChannel($row);
        }

        return $channels;
    }

    /**
     * Update channel properties.
     *
     * Only updates fields that are present in the $data array.
     *
     * @param string $channelId The channel to update
     * @param array<string, mixed> $data Update data with keys:
     *   - name: string New channel name
     *   - number: int New channel number
     *   - description: string New description
     *   - icon_url: string New icon URL
     *   - visual_id: string New visual ID
     * @return array<string, mixed>|null Updated channel or null if not found
     */
    public function updateChannel(string $channelId, array $data): ?array
    {
        $channel = $this->getChannel($channelId);
        if (!$channel) {
            return null;
        }

        $updates = [];
        $values = [];

        if (isset($data['name'])) {
            $updates[] = 'name = ?';
            $values[] = $data['name'];
        }

        if (isset($data['number'])) {
            $updates[] = 'number = ?';
            $values[] = $data['number'];
        }

        if (isset($data['description'])) {
            $updates[] = 'description = ?';
            $values[] = $data['description'];
        }

        if (isset($data['icon_url'])) {
            $updates[] = 'icon_url = ?';
            $values[] = $data['icon_url'];
        }

        if (isset($data['visual_id'])) {
            $updates[] = 'visual_id = ?';
            $values[] = $data['visual_id'];
        }

        if (empty($updates)) {
            return $channel;
        }

        $updates[] = 'updated_at = NOW()';
        $values[] = $channelId;

        $this->db->query(
            "UPDATE livetv_channels SET " . implode(', ', $updates) . " WHERE channel_id = ?",
            $values
        );

        $this->logger->info('Channel updated', ['channel_id' => $channelId]);

        return $this->getChannel($channelId);
    }

    /**
     * Delete a channel (soft delete).
     *
     * Sets visibility to VISIBILITY_DELETED rather than removing the record.
     *
     * @param string $channelId The channel to delete
     * @return bool True if deleted, false if not found
     */
    public function deleteChannel(string $channelId): bool
    {
        $channel = $this->getChannel($channelId);
        if (!$channel) {
            return false;
        }

        $this->db->query(
            "UPDATE livetv_channels SET visibility = ?, updated_at = NOW() WHERE channel_id = ?",
            [self::VISIBILITY_DELETED, $channelId]
        );

        $this->logger->info('Channel deleted', ['channel_id' => $channelId]);

        return true;
    }

    /**
     * Hide a channel from listings.
     *
     * Sets visibility to VISIBILITY_HIDDEN. Channel can be restored.
     *
     * @param string $channelId The channel to hide
     * @return bool True if hidden, false if not found
     */
    public function hideChannel(string $channelId): bool
    {
        $channel = $this->getChannel($channelId);
        if (!$channel) {
            return false;
        }

        $this->db->query(
            "UPDATE livetv_channels SET visibility = ?, updated_at = NOW() WHERE channel_id = ?",
            [self::VISIBILITY_HIDDEN, $channelId]
        );

        $this->logger->info('Channel hidden', ['channel_id' => $channelId]);

        return true;
    }

    /**
     * Restore a hidden or deleted channel.
     *
     * @param string $channelId The channel to restore
     * @return bool True if restored, false if not found
     */
    public function restoreChannel(string $channelId): bool
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_channels WHERE channel_id = ?",
            [$channelId]
        );

        if (empty($result)) {
            return false;
        }

        $this->db->query(
            "UPDATE livetv_channels SET visibility = ?, updated_at = NOW() WHERE channel_id = ?",
            [self::VISIBILITY_VISIBLE, $channelId]
        );

        $this->logger->info('Channel restored', ['channel_id' => $channelId]);

        return true;
    }

    /**
     * Add a channel to user's favorites.
     *
     * @param string $channelId The channel to favorite
     * @param string $userId The user ID
     * @return bool True if added, false if channel doesn't exist
     */
    public function addToFavorites(string $channelId, string $userId): bool
    {
        $channel = $this->getChannel($channelId);
        if (!$channel) {
            return false;
        }

        $this->db->query(
            "INSERT INTO livetv_favorites (channel_id, user_id, added_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE added_at = NOW()",
            [$channelId, $userId]
        );

        $this->logger->debug('Channel added to favorites', ['channel_id' => $channelId, 'user_id' => $userId]);

        return true;
    }

    /**
     * Remove a channel from user's favorites.
     *
     * @param string $channelId The channel to unfavorite
     * @param string $userId The user ID
     * @return bool Always returns true
     */
    public function removeFromFavorites(string $channelId, string $userId): bool
    {
        $this->db->query(
            "DELETE FROM livetv_favorites WHERE channel_id = ? AND user_id = ?",
            [$channelId, $userId]
        );

        $this->logger->debug('Channel removed from favorites', ['channel_id' => $channelId, 'user_id' => $userId]);

        return true;
    }

    /**
     * Get user's favorite channels.
     *
     * @param string $userId The user ID
     * @return array<int, array<string, mixed>> List of favorite channels sorted by number
     */
    public function getFavoriteChannels(string $userId): array
    {
        $result = $this->db->query(
            "SELECT c.* FROM livetv_channels c
             INNER JOIN livetv_favorites f ON c.channel_id = f.channel_id
             WHERE f.user_id = ? AND c.visibility = ?
             ORDER BY c.number ASC",
            [$userId, self::VISIBILITY_VISIBLE]
        );

        $channels = [];
        foreach ($result as $row) {
            $channels[] = $this->mapChannel($row);
        }

        return $channels;
    }

    /**
     * Check if a channel is in user's favorites.
     *
     * @param string $channelId The channel ID
     * @param string $userId The user ID
     * @return bool True if favorited, false otherwise
     */
    public function isFavorite(string $channelId, string $userId): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM livetv_favorites WHERE channel_id = ? AND user_id = ?",
            [$channelId, $userId]
        );

        return !empty($result);
    }

    /**
     * Create a new channel lineup.
     *
     * A lineup is an ordered list of channels for a user.
     *
     * @param string $name Lineup name
     * @param string $userId Owner user ID
     * @param array<string> $channelIds Channel IDs to include in order
     * @return array<string, mixed>|null Created lineup with channels or null on failure
     *
     * @example
     * ```php
     * $lineup = $manager->createLineup('My TV', 'user_123', ['ch_1', 'ch_2', 'ch_3']);
     * ```
     */
    public function createLineup(string $name, string $userId, array $channelIds = []): ?array
    {
        $lineupId = $this->generateUuid();

        $this->db->query(
            "INSERT INTO livetv_lineups (lineup_id, name, user_id, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())",
            [$lineupId, $name, $userId]
        );

        // Add channels to lineup
        $position = 0;
        foreach ($channelIds as $channelId) {
            $this->addChannelToLineup($lineupId, $channelId, $position++);
        }

        $this->logger->info('Lineup created', ['lineup_id' => $lineupId, 'name' => $name]);

        return $this->getLineup($lineupId);
    }

    /**
     * Get a lineup by ID with its channels.
     *
     * @param string $lineupId The lineup identifier
     * @return array<string, mixed>|null Lineup with channels or null if not found
     */
    public function getLineup(string $lineupId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_lineups WHERE lineup_id = ?",
            [$lineupId]
        );

        if (empty($result)) {
            return null;
        }

        $lineup = $result[0];
        $lineup['channels'] = $this->getLineupChannels($lineupId);

        return $lineup;
    }

    /**
     * Get all lineups for a user.
     *
     * @param string $userId The user ID
     * @return array<int, array<string, mixed>> User's lineups
     */
    public function getUserLineups(string $userId): array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_lineups WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );

        return $result;
    }

    /**
     * Add a channel to a lineup.
     *
     * @param string $lineupId The lineup ID
     * @param string $channelId The channel ID to add
     * @param int $position Position in the lineup (default: 0)
     * @return bool True on success
     */
    public function addChannelToLineup(string $lineupId, string $channelId, int $position = 0): bool
    {
        $this->db->query(
            "INSERT INTO livetv_lineup_channels (lineup_id, channel_id, position)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE position = VALUES(position)",
            [$lineupId, $channelId, $position]
        );

        return true;
    }

    /**
     * Remove a channel from a lineup.
     *
     * @param string $lineupId The lineup ID
     * @param string $channelId The channel ID to remove
     * @return bool True on success
     */
    public function removeChannelFromLineup(string $lineupId, string $channelId): bool
    {
        $this->db->query(
            "DELETE FROM livetv_lineup_channels WHERE lineup_id = ? AND channel_id = ?",
            [$lineupId, $channelId]
        );

        return true;
    }

    /**
     * Get channels in a lineup in order.
     *
     * @param string $lineupId The lineup ID
     * @return array<int, array<string, mixed>> Ordered list of channels
     */
    public function getLineupChannels(string $lineupId): array
    {
        $result = $this->db->query(
            "SELECT c.*, lc.position FROM livetv_channels c
             INNER JOIN livetv_lineup_channels lc ON c.channel_id = lc.channel_id
             WHERE lc.lineup_id = ? AND c.visibility = ?
             ORDER BY lc.position ASC",
            [$lineupId, self::VISIBILITY_VISIBLE]
        );

        $channels = [];
        foreach ($result as $row) {
            $channels[] = $this->mapChannel($row);
        }

        return $channels;
    }

    /**
     * Delete a lineup and its channel associations.
     *
     * @param string $lineupId The lineup to delete
     * @return bool True on success
     */
    public function deleteLineup(string $lineupId): bool
    {
        $this->db->query("DELETE FROM livetv_lineup_channels WHERE lineup_id = ?", [$lineupId]);
        $this->db->query("DELETE FROM livetv_lineups WHERE lineup_id = ?", [$lineupId]);

        $this->logger->info('Lineup deleted', ['lineup_id' => $lineupId]);

        return true;
    }

    /**
     * Get total count of visible channels.
     *
     * @return int Number of visible channels
     */
    public function getChannelCount(): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM livetv_channels WHERE visibility = ?",
            [self::VISIBILITY_VISIBLE]
        );

        return (int) ($result[0]['cnt'] ?? 0);
    }

    /**
     * Map a database row to a channel array.
     *
     * @param array<string, mixed> $row Raw database row
     * @return array<string, mixed> Normalized channel data
     */
    private function mapChannel(array $row): array
    {
        return [
            'id' => $row['channel_id'],
            'channel_id' => $row['channel_id'],
            'name' => $row['name'],
            'number' => (int) $row['number'],
            'type' => $row['type'],
            'frequency' => (int) $row['frequency'],
            'tuner_id' => $row['tuner_id'],
            'service_id' => $row['service_id'],
            'visual_id' => $row['visual_id'],
            'description' => $row['description'],
            'icon_url' => $row['icon_url'],
            'visibility' => $row['visibility'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Generate a unique UUID v4 string.
     *
     * @return string A UUID in the format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
