<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Epg\SchedulesDirect;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\ChannelManager;
use Psr\Log\LoggerInterface;

/**
 * Handles Schedules Direct lineup fetching and channel creation.
 *
 * Maps SD station data to Phlix channel records via ChannelManager.
 *
 * @since 0.12.0
 */
class SdLineupHandler
{
    /** @var SdApiClient SD API client */
    private SdApiClient $client;

    /** @var ChannelManager Phlix channel manager */
    private ChannelManager $channelManager;

    /** @var StructuredLogger|null Optional logger */
    private ?StructuredLogger $logger;

    /**
     * Creates a new SdLineupHandler instance.
     *
     * @param SdApiClient $client SD API client
     * @param ChannelManager $channelManager Phlix channel manager
     * @param StructuredLogger|LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        SdApiClient $client,
        ChannelManager $channelManager,
        StructuredLogger|LoggerInterface|null $logger = null
    ) {
        $this->client = $client;
        $this->channelManager = $channelManager;
        $this->logger = $logger instanceof StructuredLogger ? $logger : null;
    }

    /**
     * Fetch available lineups for the token's account country.
     *
     * @return array<string, mixed> Available lineups
     */
    public function getAvailableLineups(): array
    {
        $lineups = $this->client->getAvailableLineups();

        $this->logger?->info('Fetched SD lineups', ['count' => count($lineups)]);

        return $lineups;
    }

    /**
     * Fetch the station list for a given lineup and register channels in Phlix.
     *
     * Iterates through SD stations, maps each to channel creation data,
     * and persists via ChannelManager::createChannel().
     *
     * @param string $lineupId SD lineup ID (e.g., "USA-XXX-XXXXX")
     * @return array<int, array<string, mixed>> Created channel records
     */
    public function importLineup(string $lineupId): array
    {
        $this->logger?->info('Importing SD lineup', ['lineup_id' => $lineupId]);

        $stations = $this->client->getStations($lineupId);

        if (empty($stations)) {
            $this->logger?->warning('No stations found for SD lineup', ['lineup_id' => $lineupId]);
            return [];
        }

        $this->logger?->info('Found stations in SD lineup', [
            'lineup_id' => $lineupId,
            'station_count' => count($stations),
        ]);

        $createdChannels = [];
        $mapper = new SdProgramMapper();

        foreach ($stations as $station) {
            if (!is_array($station)) {
                continue;
            }

            /** @var array<string, mixed> $station */
            try {
                $channelData = $mapper->mapStation($station);

                if ($channelData === null) {
                    $stationId = $station['stationID'] ?? 'unknown';
                    $this->logger?->debug('Skipping station - insufficient data', [
                        'station_id' => $stationId,
                    ]);
                    continue;
                }

                $channel = $this->channelManager->createChannel($channelData);

                if ($channel !== null) {
                    $createdChannels[] = $channel;
                    $stationId = $station['stationID'] ?? 'unknown';
                    $this->logger?->debug('Created channel from SD station', [
                        'station_id' => $stationId,
                        'channel_id' => $channel['channel_id'] ?? 'unknown',
                    ]);
                }
            } catch (\Throwable $e) {
                $stationId = $station['stationID'] ?? 'unknown';
                $this->logger?->error('Failed to import SD station', [
                    'station' => $stationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger?->info('Completed SD lineup import', [
            'lineup_id' => $lineupId,
            'channels_created' => count($createdChannels),
        ]);

        return $createdChannels;
    }
}
