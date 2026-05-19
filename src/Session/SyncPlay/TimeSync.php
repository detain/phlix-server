<?php

declare(strict_types=1);

namespace Phlex\Session\SyncPlay;

/**
 * TimeSync - Network time synchronization for synchronized playback
 *
 * Implements NTP-style time synchronization with latency compensation
 * and drift correction for SyncPlay group watching functionality.
 *
 * ## Time Synchronization Protocol
 *
 * The protocol works as follows:
 * 1. Client sends ping with its local timestamp (client_time)
 * 2. Server responds with pong containing both timestamps
 * 3. Client calculates round-trip time (RTT) and one-way latency
 * 4. Client calculates clock offset: offset = server_time - client_time + latency
 * 5. Multiple samples are averaged to reduce noise
 *
 * ## Drift Correction
 *
 * Over time, the client's local clock may drift relative to the server.
 * The TimeSync component tracks this drift rate and can apply corrections
 * to predicted playback positions to maintain sync accuracy.
 *
 * ## Stability Detection
 *
 * Time sync is considered "stable" when:
 * - At least OFFSET_SAMPLE_COUNT samples have been collected
 * - The variance of recent offset samples is less than 50ms
 *
 * @author Phlex Development Team
 * @copyright 2024 Phlex Media Server
 * @license Proprietary
 *
 * @see SyncPlayManager For how TimeSync is used in group sync
 * @see https://en.wikipedia.org/wiki/Network_Time_Protocol For NTP protocol details
 */
class TimeSync
{
    /** Default port for time sync (used if host is specified) */
    private const DEFAULT_PORT = 8098;

    /** Protocol version for time sync messages */
    private const PROTOCOL_VERSION = 1;

    /** Maximum acceptable round-trip time in milliseconds */
    private const MAX_ACCEPTABLE_RTT = 1000;

    /** Number of samples to average for time offset calculation */
    private const OFFSET_SAMPLE_COUNT = 5;

    /** Drift correction factor (lower = smoother but slower to adapt) */
    private const DRIFT_CORRECTION_FACTOR = 0.1;

    /** @var array<int, array{offset: int, rtt: int, timestamp: float}> Recent offset samples */
    private array $offsetSamples = [];

    /** @var float Unix timestamp of last sync operation */
    private float $lastSyncTimestamp = 0;

    /** @var float Local clock drift rate (1.0 = no drift, >1 = gaining, <1 = losing) */
    private float $localDriftRate = 1.0;

    /**
     * Create a new TimeSync instance.
     *
     * @param string|null $host Optional time sync server host (currently unused but reserved)
     * @param int $port Optional port (default: 8098)
     */
    public function __construct(
        private readonly ?string $host = null,
        private readonly int $port = self::DEFAULT_PORT
    ) {
    }

    /**
     * Get the protocol version for time sync messages.
     *
     * @return int Protocol version (currently 1)
     */
    public function getProtocolVersion(): int
    {
        return self::PROTOCOL_VERSION;
    }

    /**
     * Get the default port for time sync.
     *
     * @return int Port number
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Get the host for time sync server.
     *
     * @return string|null The host address or null if not configured
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * Process an incoming ping message and return pong response data.
     *
     * Called by the server when receiving a time sync ping from a client.
     * Records the server receive time to calculate round-trip time later.
     *
     * @param array<string, mixed> $payload The ping payload containing client_time
     * @return array{type: string, client_time: int, server_time: int, protocol_version: int} Pong response
     *
     * @example
     * ```php
     * $pong = $timeSync->processPing(['client_time' => 1700000000000]);
     * // ['type' => 'pong', 'client_time' => 1700000000000, 'server_time' => 1700000000015, 'protocol_version' => 1]
     * ```
     */
    public function processPing(array $payload): array
    {
        $clientTimestamp = self::intFromMixed($payload['client_time'] ?? 0);
        $serverReceiveTime = (int)(microtime(true) * 1000);

        return [
            'type' => 'pong',
            'client_time' => $clientTimestamp,
            'server_time' => $serverReceiveTime,
            'protocol_version' => self::PROTOCOL_VERSION,
        ];
    }

    /**
     * Process a pong response from server and calculate time offset.
     *
     * Called by the client after receiving a pong from the server.
     * Calculates the round-trip time, one-way latency, and clock offset.
     * Results are stored internally and returned for immediate use.
     *
     * @param array<string, mixed> $payload The pong payload containing client_time, server_time, server_receive_time
     * @return array{offset: int, latency: int, rtt: int, is_stable: bool} Time sync result
     *
     * @example
     * ```php
     * $result = $timeSync->processPong([
     *     'client_time' => 1700000000000,
     *     'server_time' => 1700000000015,
     *     'server_receive_time' => 1700000000010,
     * ]);
     * ```
     */
    public function processPong(array $payload): array
    {
        $clientSendTime = self::intFromMixed($payload['client_time'] ?? 0);
        $serverTime = self::intFromMixed($payload['server_time'] ?? 0);
        $serverReceiveTime = self::intFromMixed($payload['server_receive_time'] ?? 0);
        $clientReceiveTime = (int)(microtime(true) * 1000);

        // Calculate round-trip time
        $rtt = $clientReceiveTime - $clientSendTime - ($serverReceiveTime - $serverTime);
        $oneWayLatency = $rtt / 2;

        // Calculate time offset (how far client clock is from server clock)
        // offset = server_time - client_time + estimated_latency
        $offset = $serverTime - $clientSendTime + (int)$oneWayLatency;

        // Add sample to collection
        $this->addOffsetSample($offset, $rtt);

        // Update drift rate based on recent samples
        $this->updateDriftRate();

        return [
            'offset' => $this->getTimeOffset(),
            'latency' => $this->getEstimatedLatency(),
            'rtt' => $rtt,
            'is_stable' => $this->isSyncStable(),
        ];
    }

    /**
     * Add an offset sample to the collection.
     *
     * Samples with RTT above MAX_ACCEPTABLE_RTT are discarded as unreliable.
     * The collection is maintained as a rolling buffer of up to 2x sample count.
     *
     * @param int $offset Calculated time offset in milliseconds
     * @param int $rtt Measured round-trip time in milliseconds
     * @return void
     */
    private function addOffsetSample(int $offset, int $rtt): void
    {
        // Only use samples with acceptable RTT
        if ($rtt > self::MAX_ACCEPTABLE_RTT) {
            return;
        }

        $this->offsetSamples[] = [
            'offset' => $offset,
            'rtt' => $rtt,
            'timestamp' => microtime(true),
        ];

        // Keep only recent samples
        if (count($this->offsetSamples) > self::OFFSET_SAMPLE_COUNT * 2) {
            array_shift($this->offsetSamples);
        }
    }

    /**
     * Calculate and update the local clock drift rate.
     *
     * Uses exponential moving average of recent offset changes to estimate
     * how fast the local clock drifts relative to the server.
     *
     * @return void
     */
    private function updateDriftRate(): void
    {
        if (count($this->offsetSamples) < 2) {
            return;
        }

        $recent = array_slice($this->offsetSamples, -self::OFFSET_SAMPLE_COUNT);

        if (count($recent) < 2) {
            return;
        }

        $first = $recent[0];
        $last = $recent[count($recent) - 1];

        $timeDelta = $last['timestamp'] - $first['timestamp'];
        if ($timeDelta <= 0) {
            return;
        }

        $offsetDelta = $last['offset'] - $first['offset'];
        // Drift rate: how much does offset change per second
        $driftRate = $offsetDelta / $timeDelta;

        // Smooth the drift rate with EMA
        $this->localDriftRate = 1.0 + (self::DRIFT_CORRECTION_FACTOR * $driftRate / 1000);
    }

    /**
     * Get the current estimated time offset from server.
     *
     * Returns a weighted average of recent offset samples, with lower RTT
     * samples given higher weight for better accuracy.
     *
     * @return int Estimated offset in milliseconds (add to local time to get server time)
     */
    public function getTimeOffset(): int
    {
        if (empty($this->offsetSamples)) {
            return 0;
        }

        // Return weighted average of recent samples (favor lower RTT)
        $weightedSum = 0;
        $weightSum = 0;

        $recent = array_slice($this->offsetSamples, -self::OFFSET_SAMPLE_COUNT);

        foreach ($recent as $sample) {
            $weight = 1 / max(1, $sample['rtt']);
            $weightedSum += $sample['offset'] * $weight;
            $weightSum += $weight;
        }

        return (int)($weightedSum / max(1, $weightSum));
    }

    /**
     * Get the estimated one-way latency to server.
     *
     * @return int Estimated latency in milliseconds
     */
    public function getEstimatedLatency(): int
    {
        if (empty($this->offsetSamples)) {
            return 0;
        }

        $recent = array_slice($this->offsetSamples, -self::OFFSET_SAMPLE_COUNT);

        $totalLatency = 0;
        $count = 0;

        foreach ($recent as $sample) {
            $totalLatency += $sample['rtt'] / 2;
            $count++;
        }

        return (int)($totalLatency / max(1, $count));
    }

    /**
     * Check if time synchronization is stable.
     *
     * Time sync is considered stable when at least OFFSET_SAMPLE_COUNT
     * samples have been collected and the variance of recent offsets
     * is less than 50ms.
     *
     * @return bool True if synchronization is stable
     */
    public function isSyncStable(): bool
    {
        if (count($this->offsetSamples) < self::OFFSET_SAMPLE_COUNT) {
            return false;
        }

        $recent = array_slice($this->offsetSamples, -self::OFFSET_SAMPLE_COUNT);

        $offsets = array_column($recent, 'offset');
        $mean = array_sum($offsets) / count($offsets);

        $varianceSum = 0;
        foreach ($offsets as $offset) {
            $diff = $offset - $mean;
            $varianceSum += $diff * $diff;
        }
        $variance = $varianceSum / count($offsets);

        // Consider stable if variance is less than 50ms
        return $variance < 50;
    }

    /**
     * Get the local clock drift rate.
     *
     * A drift rate of 1.0 means no drift. Values greater than 1.0 indicate
     * the local clock is gaining time relative to the server. Values less than
     * 1.0 indicate the local clock is losing time.
     *
     * @return float Drift rate multiplier
     */
    public function getDriftRate(): float
    {
        return $this->localDriftRate;
    }

    /**
     * Get estimated synchronized time (local time adjusted by offset).
     *
     * @return int Synchronized timestamp in milliseconds
     */
    public function getSynchronizedTime(): int
    {
        $localTime = (int)(microtime(true) * 1000);
        return $localTime + $this->getTimeOffset();
    }

    /**
     * Convert a local timestamp to synchronized timestamp.
     *
     * @param int $localTimestamp Local timestamp in milliseconds
     * @return int Synchronized timestamp in milliseconds
     */
    public function localToSynchronized(int $localTimestamp): int
    {
        return $localTimestamp + $this->getTimeOffset();
    }

    /**
     * Convert a synchronized timestamp to local time.
     *
     * @param int $synchronizedTimestamp Synchronized timestamp in milliseconds
     * @return int Local timestamp in milliseconds
     */
    public function synchronizedToLocal(int $synchronizedTimestamp): int
    {
        return $synchronizedTimestamp - $this->getTimeOffset();
    }

    /**
     * Apply drift correction to a predicted playback position.
     *
     * Adjusts a target time based on measured drift rate to improve
     * prediction accuracy over longer playback sessions.
     *
     * @param int $targetTime The target synchronized time in milliseconds
     * @param int $currentTime The current local time in milliseconds
     * @return int Corrected target time accounting for drift
     */
    public function applyDriftCorrection(int $targetTime, int $currentTime): int
    {
        $timeDelta = $targetTime - $currentTime;
        return (int)($targetTime + ($timeDelta * (1 - $this->localDriftRate)));
    }

    /**
     * Calculate the expected playback position with time sync.
     *
     * Adjusts a local playback position based on current time synchronization
     * state to account for clock drift since playback started.
     *
     * @param int $playbackPosition Local playback position in milliseconds
     * @param int $mediaDuration Total media duration in milliseconds
     * @return int Adjusted position accounting for sync, clamped to valid range
     */
    public function adjustPlaybackPosition(int $playbackPosition, int $mediaDuration): int
    {
        $synchronizedTime = $this->getSynchronizedTime();
        $driftAdjustment = (int)(($synchronizedTime - time() * 1000) * $this->localDriftRate);

        $adjustedPosition = $playbackPosition + $driftAdjustment;

        // Clamp to valid range
        return max(0, min($adjustedPosition, $mediaDuration));
    }

    /**
     * Reset time sync state to initial values.
     *
     * Clears all offset samples, resets drift rate, and clears last sync timestamp.
     * Use this when rejoining a group or after a significant network change.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->offsetSamples = [];
        $this->localDriftRate = 1.0;
        $this->lastSyncTimestamp = 0;
    }

    /**
     * Get time sync status information.
     *
     * @return array{offset: int, latency: int, drift_rate: float, is_stable: bool, sample_count: int, last_sync: float} Status info
     */
    public function getStatus(): array
    {
        return [
            'offset' => $this->getTimeOffset(),
            'latency' => $this->getEstimatedLatency(),
            'drift_rate' => $this->localDriftRate,
            'is_stable' => $this->isSyncStable(),
            'sample_count' => count($this->offsetSamples),
            'last_sync' => $this->lastSyncTimestamp,
        ];
    }

    /**
     * Serialize time sync state for persistence.
     *
     * @return array<string, mixed> Serialized state
     *
     * @see unserialize() For restoring serialized state
     */
    public function serialize(): array
    {
        return [
            'offset_samples' => $this->offsetSamples,
            'drift_rate' => $this->localDriftRate,
            'last_sync' => $this->lastSyncTimestamp,
        ];
    }

    /**
     * Restore time sync state from serialized data.
     *
     * @param array<string, mixed> $data Previously serialized state
     * @return void
     *
     * @see serialize() For creating serializable state
     */
    public function unserialize(array $data): void
    {
        $rawSamples = $data['offset_samples'] ?? [];
        $samples = [];
        if (is_array($rawSamples)) {
            foreach ($rawSamples as $sample) {
                if (
                    is_array($sample)
                    && isset($sample['offset'], $sample['rtt'], $sample['timestamp'])
                    && is_int($sample['offset'])
                    && is_int($sample['rtt'])
                    && (is_float($sample['timestamp']) || is_int($sample['timestamp']))
                ) {
                    $samples[] = [
                        'offset' => $sample['offset'],
                        'rtt' => $sample['rtt'],
                        'timestamp' => (float) $sample['timestamp'],
                    ];
                }
            }
        }
        $this->offsetSamples = $samples;

        $driftRate = $data['drift_rate'] ?? 1.0;
        $this->localDriftRate = is_float($driftRate) || is_int($driftRate) ? (float) $driftRate : 1.0;

        $lastSync = $data['last_sync'] ?? 0;
        $this->lastSyncTimestamp = is_float($lastSync) || is_int($lastSync) ? (float) $lastSync : 0.0;
    }

    /**
     * Coerce a mixed value into an int. Numeric strings/floats are accepted;
     * anything else becomes 0.
     *
     * @param mixed $value Untyped value, typically pulled from a WebSocket payload.
     */
    private static function intFromMixed(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return 0;
    }
}
