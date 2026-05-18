<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Epg\SchedulesDirect;

use Phlex\Common\Logger\StructuredLogger;
use Phlex\LiveTv\GuideManager;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the full Schedules Direct EPG sync cycle.
 *
 * Coordinates fetching schedules and programs from SD API,
 * mapping data to GuideManager format, and persisting
 * via upsertProgram().
 *
 * @since 0.12.0
 */
class SdEpgService
{
    /** @var SdApiClient SD API client */
    private SdApiClient $client;

    /** @var SdLineupHandler SD lineup handler */
    private SdLineupHandler $lineupHandler;

    /** @var SdProgramMapper SD program mapper */
    private SdProgramMapper $mapper;

    /** @var GuideManager Phlex guide manager */
    private GuideManager $guideManager;

    /** @var StructuredLogger|null Optional logger */
    private ?StructuredLogger $logger;

    /**
     * Creates a new SdEpgService instance.
     *
     * @param SdApiClient $client SD API client
     * @param SdLineupHandler $lineupHandler SD lineup/channel handler
     * @param SdProgramMapper $mapper SD program-to-guide mapper
     * @param GuideManager $guideManager Phlex guide manager
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        SdApiClient $client,
        SdLineupHandler $lineupHandler,
        SdProgramMapper $mapper,
        GuideManager $guideManager,
        StructuredLogger|LoggerInterface|null $logger = null
    ) {
        $this->client = $client;
        $this->lineupHandler = $lineupHandler;
        $this->mapper = $mapper;
        $this->guideManager = $guideManager;
        $this->logger = $logger instanceof StructuredLogger ? $logger : null;
    }

    /**
     * Full EPG sync for a list of station IDs.
     *
     * Fetches schedules and programs for all stations, maps them,
     * and upserts to GuideManager.
     *
     * @param array<int, string> $stationIds SD station IDs to sync
     * @param int $daysAhead Number of days ahead to fetch (default: 14)
     * @return array{imported: int, errors: int} Import statistics
     */
    public function syncEpg(array $stationIds, int $daysAhead = 14): array
    {
        $this->logger?->info('Starting full SD EPG sync', [
            'station_count' => count($stationIds),
            'days_ahead' => $daysAhead,
        ]);

        if (empty($stationIds)) {
            $this->logger?->warning('No station IDs provided for SD EPG sync');
            return ['imported' => 0, 'errors' => 0];
        }

        $imported = 0;
        $errors = 0;

        // Calculate time window
        $startDate = time();
        $endDate = $startDate + ($daysAhead * 86400);

        try {
            // Fetch schedules for all stations
            $schedules = $this->client->getSchedules($stationIds, $startDate, $endDate);

            if (empty($schedules)) {
                $this->logger?->warning('No schedule data returned from SD API');
                return ['imported' => 0, 'errors' => 1];
            }

            // Collect unique program IDs
            /** @var array<int, string> $programIds */
            $programIds = [];
            foreach ($schedules as $schedule) {
                if (is_array($schedule) && array_key_exists('programID', $schedule)) {
                    $programId = $schedule['programID'];
                    if (is_string($programId)) {
                        $programIds[] = $programId;
                    }
                }
            }

            $programIds = array_values(array_unique($programIds));
            $this->logger?->debug('Collected unique program IDs', ['count' => count($programIds)]);

            // Fetch program metadata in batch
            $programs = [];
            if (!empty($programIds)) {
                $programs = $this->client->getPrograms($programIds);
            }

            // Index programs by ID for quick lookup
            /** @var array<string, array<string, mixed>> $programIndex */
            $programIndex = [];
            foreach ($programs as $program) {
                if (is_array($program) && array_key_exists('programID', $program)) {
                    $programId = $program['programID'];
                    if (is_string($programId)) {
                        $programIndex[$programId] = $program;
                    }
                }
            }

            // Map and upsert each schedule entry
            foreach ($schedules as $schedule) {
                if (!is_array($schedule)) {
                    continue;
                }

                try {
                    if (!array_key_exists('programID', $schedule)) {
                        continue;
                    }

                    $programId = $schedule['programID'];
                    if (!is_string($programId) || !isset($programIndex[$programId])) {
                        $this->logger?->debug('Skipping schedule - no program data', [
                            'program_id' => $programId,
                        ]);
                        continue;
                    }

                    /** @var array<string, mixed> $mappedData */
                    $mappedData = $this->mapper->map($schedule, $programIndex[$programId]);

                    if (empty($mappedData)) {
                        continue;
                    }

                    $this->guideManager->upsertProgram($mappedData);
                    // upsertProgram returns array on success (per its type signature)
                    $imported++;
                } catch (\Throwable $e) {
                    $this->logger?->error('Failed to import SD schedule entry', [
                        'error' => $e->getMessage(),
                        'entry' => $schedule['programID'] ?? 'unknown',
                    ]);
                    $errors++;
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->error('SD EPG sync failed', [
                'error' => $e->getMessage(),
            ]);
            $errors++;
        }

        $this->logger?->info('Completed SD EPG sync', [
            'imported' => $imported,
            'errors' => $errors,
        ]);

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * Quick EPG sync for a single station.
     *
     * @param string $stationId SD station ID to sync
     * @param int $daysAhead Number of days ahead to fetch (default: 14)
     * @return array{imported: int, errors: int} Import statistics
     */
    public function syncStation(string $stationId, int $daysAhead = 14): array
    {
        $this->logger?->info('Syncing SD station', [
            'station_id' => $stationId,
            'days_ahead' => $daysAhead,
        ]);

        return $this->syncEpg([$stationId], $daysAhead);
    }

    /**
     * Import a SD lineup and sync all its program data.
     *
     * Convenience method that fetches stations for a lineup,
     * imports them as channels, then syncs EPG data.
     *
     * @param string $lineupId SD lineup ID
     * @param int $daysAhead Number of days ahead to fetch (default: 14)
     * @return array{channels: array<int, array<string, mixed>>, stats: array{imported: int, errors: int}}
     */
    public function importLineupAndSync(string $lineupId, int $daysAhead = 14): array
    {
        $channels = $this->lineupHandler->importLineup($lineupId);

        // Extract station IDs from created channels
        /** @var array<int, string> $stationIds */
        $stationIds = [];
        foreach ($channels as $channel) {
            if (is_array($channel) && array_key_exists('service_id', $channel)) {
                $serviceId = $channel['service_id'];
                if (is_string($serviceId)) {
                    $stationIds[] = $serviceId;
                }
            }
        }

        $stats = $this->syncEpg($stationIds, $daysAhead);

        return [
            'channels' => $channels,
            'stats' => $stats,
        ];
    }
}
