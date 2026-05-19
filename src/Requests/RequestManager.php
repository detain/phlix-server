<?php

declare(strict_types=1);

namespace Phlex\Requests;

use Phlex\Shared\Arr\ArrClientFactory;
use Phlex\Shared\Arr\RadarrClient;
use Phlex\Shared\Arr\SonarrClient;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;
use Workerman\MySQL\Connection;

/**
 * RequestManager handles user media requests (movies/series).
 *
 * Manages the full request lifecycle: create, approve, reject, list.
 * Integrates with Radarr/Sonarr via ArrClientFactory when approving.
 *
 * @since 0.12.0
 */
class RequestManager
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var ArrClientFactory Factory for creating Arr clients */
    private ArrClientFactory $arrClientFactory;

    /**
     * Creates a new RequestManager instance.
     *
     * @param Connection $db Database connection
     * @param ArrClientFactory $arrClientFactory Factory for Radarr/Sonarr clients
     */
    public function __construct(Connection $db, ArrClientFactory $arrClientFactory)
    {
        $this->db = $db;
        $this->arrClientFactory = $arrClientFactory;
    }

    /**
     * Creates a new media request.
     *
     * @param string $userId The user making the request
     * @param string $type The request type ('movie' or 'series')
     * @param int $tmdbId The TMDB ID for the requested media
     * @param string $title The title of the requested media
     * @param string|null $posterUrl Optional poster URL
     * @param int|null $season Optional season number (for series)
     * @param int|null $episode Optional episode number (for series)
     *
     * @return array The created request data
     */
    /**
     * Creates a new media request.
     *
     * @param string $userId The user making the request
     * @param string $type The request type ('movie' or 'series')
     * @param int $tmdbId The TMDB ID for the requested media
     * @param string $title The title of the requested media
     * @param string|null $posterUrl Optional poster URL
     * @param int|null $season Optional season number (for series)
     * @param int|null $episode Optional episode number (for series)
     *
     * @return array{id: string, user_id: string, type: string, tmdb_id: int,
     *              title: string, poster_url: ?string, season: ?int, episode: ?int,
     *              status: string, rejection_reason: ?string, created_at: string,
     *              updated_at: string} The created request data
     */
    public function createRequest(
        string $userId,
        string $type,
        int $tmdbId,
        string $title,
        ?string $posterUrl = null,
        ?int $season = null,
        ?int $episode = null
    ): array {
        $id = $this->generateUuid();

        $this->db->query(
            "INSERT INTO requests (id, user_id, type, tmdb_id, title, poster_url, season, episode, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [$id, $userId, $type, $tmdbId, $title, $posterUrl, $season, $episode]
        );

        $rows = $this->db->query("SELECT * FROM requests WHERE id = ?", [$id]);
        if (!is_array($rows) || count($rows) === 0) {
            throw new \RuntimeException('Failed to create request: could not retrieve newly created request');
        }
        $firstRow = $rows[0];
        if (!is_array($firstRow)) {
            throw new \RuntimeException('Failed to create request: database returned invalid row');
        }

        return $this->hydrateRequest($firstRow);
    }

    /**
     * Approves a pending request and triggers Sonarr/Radarr add.
     *
     * @param string $requestId The request ID to approve
     *
     * @return bool True if approved successfully, false otherwise
     */
    public function approveRequest(string $requestId): bool
    {
        $request = $this->getRequestById($requestId);

        if ($request === null) {
            return false;
        }

        if ($request['status'] !== 'pending') {
            return false;
        }

        $success = false;

        if ($request['type'] === 'movie') {
            $success = $this->approveMovieRequest($request);
        } elseif ($request['type'] === 'series') {
            $success = $this->approveSeriesRequest($request);
        }

        if ($success) {
            $this->db->query(
                "UPDATE requests SET status = 'approved' WHERE id = ?",
                [$requestId]
            );
        }

        return $success;
    }

    /**
     * Approves a movie request via Radarr.
     *
     * @param array<string, mixed> $request The request data
     *
     * @return bool True if approved successfully
     */
    private function approveMovieRequest(array $request): bool
    {
        $logger = LoggerFactory::get(LogChannels::MEDIA);
        $radarrClient = $this->arrClientFactory->createRadarrClient($logger);

        if ($radarrClient === null) {
            return false;
        }

        try {
            $qualityProfiles = $radarrClient->getQualityProfiles();
            if (empty($qualityProfiles)) {
                return false;
            }

            $firstProfile = $qualityProfiles[0];
            $qualityProfileId = is_array($firstProfile) && isset($firstProfile['id']) && is_numeric($firstProfile['id'])
                ? (int) $firstProfile['id']
                : 1;
            $rootFolder = $this->getRadarrRootFolder($radarrClient);

            $movieId = is_int($request['tmdb_id']) || is_string($request['tmdb_id']) ? (int) $request['tmdb_id'] : 0;
            $radarrClient->addMovie($movieId, $qualityProfileId, $rootFolder);
            return true;
        } catch (\Throwable $e) {
            $logger->warning('Radarr add movie failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Approves a series request via Sonarr.
     *
     * @param array<string, mixed> $request The request data
     *
     * @return bool True if approved successfully
     */
    private function approveSeriesRequest(array $request): bool
    {
        $logger = LoggerFactory::get(LogChannels::MEDIA);
        $sonarrClient = $this->arrClientFactory->createSonarrClient($logger);

        if ($sonarrClient === null) {
            return false;
        }

        try {
            $qualityProfiles = $sonarrClient->getQualityProfiles();
            if (empty($qualityProfiles)) {
                return false;
            }

            $firstProfile = $qualityProfiles[0] ?? null;
            $qualityProfileId = is_array($firstProfile) && isset($firstProfile['id']) && is_numeric($firstProfile['id'])
                ? (int) $firstProfile['id']
                : 1;
            $rootFolder = $this->getSonarrRootFolder($sonarrClient);

            $tvdbId = is_int($request['tmdb_id']) || is_string($request['tmdb_id']) ? (int) $request['tmdb_id'] : 0;
            $sonarrClient->addSeries($tvdbId, $qualityProfileId, $rootFolder);
            return true;
        } catch (\Throwable $e) {
            $logger->warning('Sonarr add series failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets the first root folder from Radarr.
     *
     * @param RadarrClient $radarrClient The Radarr client
     *
     * @return string The root folder path
     */
    private function getRadarrRootFolder(RadarrClient $radarrClient): string
    {
        try {
            $movies = $radarrClient->getMovies();
            if (!empty($movies) && isset($movies[0]['movieFile']) && is_array($movies[0])) {
                $movieFile = $movies[0]['movieFile'];
                if (is_array($movieFile) && isset($movieFile['path'])) {
                    $path = $movieFile['path'];
                    if (is_string($path) && $path !== '') {
                        return dirname($path);
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        return '/movies';
    }

    /**
     * Gets the first root folder from Sonarr.
     *
     * @param SonarrClient $sonarrClient The Sonarr client
     *
     * @return int The root folder index or 0
     */
    private function getSonarrRootFolder(SonarrClient $sonarrClient): int
    {
        try {
            $series = $sonarrClient->getSeries();
            if (!empty($series) && isset($series[0]['path'])) {
                return 0;
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        return 0;
    }

    /**
     * Rejects a pending request.
     *
     * @param string $requestId The request ID to reject
     * @param string $reason The rejection reason
     *
     * @return bool True if rejected successfully, false otherwise
     */
    public function rejectRequest(string $requestId, string $reason = ''): bool
    {
        $request = $this->getRequestById($requestId);

        if ($request === null) {
            return false;
        }

        if ($request['status'] !== 'pending') {
            return false;
        }

        $this->db->query(
            "UPDATE requests SET status = 'rejected', rejection_reason = ? WHERE id = ?",
            [$reason, $requestId]
        );

        return true;
    }

    /**
     * Gets the status of a request.
     *
     * @param string $requestId The request ID
     *
     * @return string The status ('pending', 'approved', 'available', 'rejected') or 'unknown'
     */
    public function getRequestStatus(string $requestId): string
    {
        $request = $this->getRequestById($requestId);

        if ($request === null) {
            return 'unknown';
        }

        $status = $request['status'] ?? null;
        return is_string($status) ? $status : 'unknown';
    }

    /**
     * Lists pending requests, optionally filtered by user.
     *
     * @param string|null $userId Optional user ID to filter by
     *
     * @return array<array{id: string, user_id: string, type: string, tmdb_id: int,
     *              title: string, poster_url: ?string, season: ?int, episode: ?int,
     *              status: string, rejection_reason: ?string, created_at: string,
     *              updated_at: string}> Array of pending requests
     */
    public function listPendingRequests(?string $userId = null): array
    {
        $rows = [];
        if ($userId !== null) {
            $rows = $this->db->query(
                "SELECT * FROM requests WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC",
                [$userId]
            );
        } else {
            $rows = $this->db->query(
                "SELECT * FROM requests WHERE status = 'pending' ORDER BY created_at DESC"
            );
        }

        return $this->hydrateRequests($rows);
    }

    /**
     * Lists all available (fulfilled) requests.
     *
     * @return array<array{id: string, user_id: string, type: string, tmdb_id: int,
     *              title: string, poster_url: ?string, season: ?int, episode: ?int,
     *              status: string, rejection_reason: ?string, created_at: string,
     *              updated_at: string}> Array of available requests
     */
    public function listAvailableRequests(): array
    {
        $result = $this->db->query(
            "SELECT * FROM requests WHERE status = 'available' ORDER BY updated_at DESC"
        );

        if (!is_array($result)) {
            return [];
        }

        return $this->hydrateRequests($result);
    }

    /**
     * Lists all requests for a specific user.
     *
     * @param string $userId The user ID
     *
     * @return array<array{id: string, user_id: string, type: string, tmdb_id: int,
     *              title: string, poster_url: ?string, season: ?int, episode: ?int,
     *              status: string, rejection_reason: ?string, created_at: string,
     *              updated_at: string}> Array of requests for the user
     */
    public function listUserRequests(string $userId): array
    {
        $result = $this->db->query(
            "SELECT * FROM requests WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );

        if (!is_array($result)) {
            return [];
        }

        /** @var array<array<string, mixed>> $rows */
        $rows = $result;
        return $this->hydrateRequests($rows);
    }

    /**
     * Gets a request by its ID.
     *
     * @param string $requestId The request ID
     *
     * @return array<string, mixed>|null The request data or null if not found
     */
    public function getRequestById(string $requestId): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM requests WHERE id = ?",
            [$requestId]
        );

        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }

        $firstRow = $rows[0];
        if (!is_array($firstRow)) {
            return null;
        }

        return $this->hydrateRequest($firstRow);
    }

    /**
     * Deletes a request.
     *
     * @param string $requestId The request ID to delete
     *
     * @return bool True if deleted successfully
     */
    public function deleteRequest(string $requestId): bool
    {
        $request = $this->getRequestById($requestId);

        if ($request === null) {
            return false;
        }

        $this->db->query(
            "DELETE FROM requests WHERE id = ?",
            [$requestId]
        );

        return true;
    }

    /**
     * Updates a request's status to available.
     *
     * @param string $requestId The request ID
     *
     * @return bool True if updated successfully
     */
    public function markAvailable(string $requestId): bool
    {
        $request = $this->getRequestById($requestId);

        if ($request === null) {
            return false;
        }

        if ($request['status'] !== 'approved') {
            return false;
        }

        $this->db->query(
            "UPDATE requests SET status = 'available' WHERE id = ?",
            [$requestId]
        );

        return true;
    }

    /**
     * Hydrates a single request row.
     *
     * @param array<mixed, mixed> $row The database row
     *
     * @return array{id: string, user_id: string, type: string, tmdb_id: int,
     *              title: string, poster_url: ?string, season: ?int, episode: ?int,
     *              status: string, rejection_reason: ?string, created_at: string,
     *              updated_at: string} The hydrated request
     */
    private function hydrateRequest(array $row): array
    {
        return [
            'id' => $this->extractString($row, 'id'),
            'user_id' => $this->extractString($row, 'user_id'),
            'type' => $this->extractString($row, 'type'),
            'tmdb_id' => $this->extractInt($row, 'tmdb_id', 0),
            'title' => $this->extractString($row, 'title'),
            'poster_url' => $this->extractNullableString($row, 'poster_url'),
            'season' => $this->extractNullableInt($row, 'season'),
            'episode' => $this->extractNullableInt($row, 'episode'),
            'status' => $this->extractString($row, 'status', 'pending'),
            'rejection_reason' => $this->extractNullableString($row, 'rejection_reason'),
            'created_at' => $this->extractString($row, 'created_at'),
            'updated_at' => $this->extractString($row, 'updated_at'),
        ];
    }

    /**
     * Hydrates multiple request rows.
     *
     * @param mixed $rows The database rows (from query())
     *
     * @return array<array{id: string, user_id: string, type: string, tmdb_id: int,
     *              title: string, poster_url: ?string, season: ?int, episode: ?int,
     *              status: string, rejection_reason: ?string, created_at: string,
     *              updated_at: string}> The hydrated requests
     */
    private function hydrateRequests(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        /** @var array<array<mixed, mixed>> $typedRows */
        $typedRows = $rows;
        /** @var array<array{id: string, user_id: string, type: string, tmdb_id: int, title: string, poster_url: ?string, season: ?int, episode: ?int, status: string, rejection_reason: ?string, created_at: string, updated_at: string}> $result */
        $result = [];
        foreach ($typedRows as $row) {
            if (is_array($row)) {
                $result[] = $this->hydrateRequest($row);
            }
        }
        return $result;
    }

    /**
     * Safely extracts a string value from a row.
     *
     * @param array<string, mixed> $row
     */
    private function extractString(array $row, string $key, string $default = ''): string
    {
        $val = $row[$key] ?? null;
        return is_string($val) ? $val : $default;
    }

    /**
     * Safely extracts an int value from a row.
     *
     * @param array<string, mixed> $row
     */
    private function extractInt(array $row, string $key, int $default = 0): int
    {
        $val = $row[$key] ?? null;
        return is_int($val) || (is_string($val) && is_numeric($val)) ? (int) $val : $default;
    }

    /**
     * Safely extracts a nullable string value from a row.
     *
     * @param array<string, mixed> $row
     */
    private function extractNullableString(array $row, string $key): ?string
    {
        $val = $row[$key] ?? null;
        return is_string($val) ? $val : null;
    }

    /**
     * Safely extracts a nullable int value from a row.
     *
     * @param array<string, mixed> $row
     */
    private function extractNullableInt(array $row, string $key): ?int
    {
        $val = $row[$key] ?? null;
        return is_int($val) ? $val : null;
    }

    /**
     * Generates a new UUID v4 string.
     *
     * @return string The generated UUID
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
