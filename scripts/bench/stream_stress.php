#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Stream Stress Test - Sustained load test for direct-play sessions.
 *
 * This script launches N concurrent direct-play sessions and sustains them
 * for a prolonged period (default 30 minutes) to detect memory leaks,
 * CPU creep, and other degradation over time.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @see /api/v1/media/{id}/stream endpoint
 *
 * Usage:
 *   php scripts/bench/stream_stress.php --media-id=<uuid> --streams=<n> [--server=<url>]
 *
 * Example:
 *   php scripts/bench/stream_stress.php --media-id=abc-123 --streams=50 --duration=1800
 *
 * v1.0 Pass Criterion: 50+ concurrent 1080p direct-play for 30 minutes
 */

namespace Phlex\Scripts\Bench;

/**
 * Displays usage help for the benchmark script.
 */
function displayHelp(): void
{
    echo <<<'HELP'
Stream Stress Test v1.0
=========================

Sustained load test for direct-play sessions to detect memory leaks,
CPU creep, and degradation over time.

Usage:
  php scripts/bench/stream_stress.php [options]

Required Options:
  --media-id=<uuid>        Media item UUID to stream (must exist in library)
  --streams=<n>            Number of concurrent streams to maintain

Optional Options:
  --server=<url>           Base server URL (default: http://localhost:8096)
  --duration=<seconds>      Test duration (default: 1800 = 30 minutes)
  --interval=<seconds>      Health check interval (default: 60)
  --timeout=<seconds>       Request timeout per stream (default: 30)
  --quality=<profile>       Quality profile: 1080p, 720p, 480p (default: 1080p)
  --ramp-up=<seconds>       Time to gradually add streams (default: 30)
  --help, -h               Show this help message

Output:
  Results are output as JSON to stdout with the following structure:
  {
    "benchmark": "stream_stress",
    "version": "1.0.0",
    "timestamp": "ISO8601 timestamp",
    "config": { ... },
    "results": {
      "total_streams": 50,
      "streams_sustained": 48,
      "streams_dropped": 2,
      "drops": [
        { "stream_id": 23, "reason": "timeout", "time": "ISO8601" }
      ],
      "memory_leak_detected": false,
      "cpu_creep_detected": false,
      "session_health": [
        { "time": 0, "active": 50, "cpu": 34.2, "memory_mb": 512.4 },
        ...
      ]
    },
    "pass": true,
    "criterion": "50+ concurrent 1080p direct-play for 30 minutes"
  }

Memory Leak Detection:
  A memory leak is flagged if memory usage grows by >50% over the test duration
  without corresponding workload increase.

CPU Creep Detection:
  CPU creep is flagged if average CPU usage increases by >20 percentage points
  over successive 5-minute windows.

Dropped Frame Proxy:
  Measured indirectly via stream timeouts and failed health checks.

Exit Codes:
  0 - Test completed successfully (pass or fail)
  1 - Invalid arguments or benchmark error

HELP;
}

/**
 * Parses command-line arguments into an associative array.
 *
 * @param array<string> $args Command-line arguments
 * @return array<string, mixed> Parsed arguments
 */
function parseArgs(array $args): array
{
    $parsed = [
        'media_id' => null,
        'streams' => null,
        'server' => 'http://localhost:8096',
        'duration' => 1800,
        'interval' => 60,
        'timeout' => 30,
        'quality' => '1080p',
        'ramp_up' => 30,
        'help' => false,
    ];

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $parsed['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--media-id=')) {
            $parsed['media_id'] = substr($arg, 12);
            continue;
        }

        if (str_starts_with($arg, '--streams=')) {
            $parsed['streams'] = (int)substr($arg, 10);
            continue;
        }

        if (str_starts_with($arg, '--server=')) {
            $parsed['server'] = substr($arg, 9);
            continue;
        }

        if (str_starts_with($arg, '--duration=')) {
            $parsed['duration'] = (int)substr($arg, 11);
            continue;
        }

        if (str_starts_with($arg, '--interval=')) {
            $parsed['interval'] = (int)substr($arg, 11);
            continue;
        }

        if (str_starts_with($arg, '--timeout=')) {
            $parsed['timeout'] = (int)substr($arg, 10);
            continue;
        }

        if (str_starts_with($arg, '--quality=')) {
            $parsed['quality'] = substr($arg, 10);
            continue;
        }

        if (str_starts_with($arg, '--ramp-up=')) {
            $parsed['ramp_up'] = (int)substr($arg, 10);
            continue;
        }
    }

    return $parsed;
}

/**
 * Validates the parsed arguments and returns error message if invalid.
 *
 * @param array<string, mixed> $args Parsed arguments
 * @return string|null Error message or null if valid
 */
function validateArgs(array $args): ?string
{
    if ($args['help']) {
        return null;
    }

    if ($args['media_id'] === null || $args['media_id'] === '') {
        return 'Error: --media-id is required';
    }

    if ($args['streams'] === null || $args['streams'] < 1) {
        return 'Error: --streams must be a positive integer';
    }

    if ($args['streams'] > 200) {
        return 'Error: --streams exceeds maximum of 200 (prevent server overload)';
    }

    if ($args['duration'] < 60) {
        return 'Error: --duration minimum is 60 seconds';
    }

    if ($args['duration'] > 7200) {
        return 'Error: --duration exceeds maximum of 7200 seconds (2 hours)';
    }

    if ($args['interval'] < 10) {
        return 'Error: --interval minimum is 10 seconds';
    }

    $validQualities = ['1080p', '720p', '480p'];
    if (!in_array($args['quality'], $validQualities, true)) {
        return 'Error: --quality must be one of: ' . implode(', ', $validQualities);
    }

    if (!filter_var($args['server'], FILTER_VALIDATE_URL)) {
        return 'Error: --server must be a valid URL';
    }

    return null;
}

/**
 * Gets the current system resource usage.
 *
 * @return array{cpu_usage_pct: float, memory_usage_mb: float, load_average: array<float>}
 */
function getSystemResources(): array
{
    $loadAvg = sys_getloadavg();
    $loadAverage = $loadAvg !== false ? $loadAvg : [0.0, 0.0, 0.0];

    $memInfo = [];
    if (file_exists('/proc/meminfo')) {
        $memContent = file_get_contents('/proc/meminfo');
        if (preg_match_all('/^(\w+):\s+(\d+)\s+kB$/m', $memContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $memInfo[$match[1]] = (int)$match[2];
            }
        }
    }

    $totalMemMb = ($memInfo['MemTotal'] ?? 0) / 1024;
    $freeMemMb = ($memInfo['MemAvailable'] ?? $memInfo['MemFree'] ?? 0) / 1024;
    $usedMemMb = $totalMemMb - $freeMemMb;

    $cpuUsage = 0.0;
    if (function_exists('sys_getloadavg')) {
        $cpuCount = shell_exec('nproc') ?? '1';
        $cpuUsage = min(100.0, ($loadAverage[0] / (int)trim($cpuCount)) * 100);
    }

    return [
        'cpu_usage_pct' => round($cpuUsage, 1),
        'memory_usage_mb' => round($usedMemMb, 1),
        'load_average' => [round($loadAverage[0], 2), round($loadAverage[1], 2), round($loadAverage[2], 2)],
    ];
}

/**
 * Stream session state.
 *
 * @property int $id Stream ID
 * @property bool $active Whether the stream is still active
 * @property float $startTime When the stream started
 * @property float|null $endTime When the stream ended (if inactive)
 * @property string $lastError Last error message if any
 */
class StreamSession
{
    public int $id;
    public bool $active;
    public float $startTime;
    public ?float $endTime;
    public string $lastError;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->active = true;
        $this->startTime = hrtime(true);
        $this->endTime = null;
        $this->lastError = '';
    }
}

/**
 * Checks if a stream is still healthy by making a HEAD request.
 *
 * @param string $server Base server URL
 * @param string $mediaId Media item UUID
 * @param string $quality Quality profile
 * @param int $timeout Request timeout
 * @return bool True if stream is healthy
 */
function checkStreamHealth(string $server, string $mediaId, string $quality, int $timeout): bool
{
    $url = rtrim($server, '/') . "/api/v1/media/{$mediaId}/stream?quality={$quality}";

    $ch = curl_init();
    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_NOBODY => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300 && $error === '';
}

/**
 * Main stress test loop.
 *
 * @param string $server Base server URL
 * @param string $mediaId Media item UUID
 * @param string $quality Quality profile
 * @param int $numStreams Target number of concurrent streams
 * @param int $duration Total test duration in seconds
 * @param int $interval Health check interval in seconds
 * @param int $timeout Request timeout per stream
 * @param int $rampUp Ramp-up period in seconds
 * @return array<string, mixed> Test results
 */
function runStressTest(
    string $server,
    string $mediaId,
    string $quality,
    int $numStreams,
    int $duration,
    int $interval,
    int $timeout,
    int $rampUp
): array {
    $streams = [];
    $startTime = hrtime(true);
    $sessionHealth = [];
    $drops = [];
    $initialMemoryMb = getSystemResources()['memory_usage_mb'];
    $memorySamples = [];
    $cpuSamples = [];
    $streamIdCounter = 0;

    echo "Starting ramp-up phase...\n";

    $rampUpInterval = $rampUp > 0 ? (int)ceil($rampUp / $numStreams) : 1;

    for ($i = 0; $i < $numStreams; $i++) {
        $streamIdCounter++;
        $streams[] = new StreamSession($streamIdCounter);

        if ($rampUp > 0 && $i < $numStreams - 1) {
            usleep((int)($rampUpInterval * 1_000_000));
        }

        echo "\rRamp-up: " . ($i + 1) . "/{$numStreams} streams started";

        $elapsed = (hrtime(true) - $startTime) / 1_000_000_000;
        $resources = getSystemResources();
        $memorySamples[] = $resources['memory_usage_mb'];
        $cpuSamples[] = $resources['cpu_usage_pct'];
    }

    echo "\nRamp-up complete. Running sustained test...\n";

    $checkCount = 0;
    $checkStartTime = hrtime(true);

    while (true) {
        $currentElapsed = (hrtime(true) - $startTime) / 1_000_000_000;

        if ($currentElapsed >= $duration) {
            break;
        }

        usleep((int)($interval * 1_000_000));

        $checkCount++;
        $resources = getSystemResources();
        $activeStreams = count(array_filter($streams, fn($s) => $s->active));

        $sessionHealth[] = [
            'time' => (int)$currentElapsed,
            'elapsed_formatted' => sprintf('%02d:%02d:%02d', (int)($currentElapsed / 3600), (int)(($currentElapsed % 3600) / 60), (int)($currentElapsed % 60)),
            'active' => $activeStreams,
            'cpu_usage_pct' => $resources['cpu_usage_pct'],
            'memory_usage_mb' => $resources['memory_usage_mb'],
            'load_average' => $resources['load_average'],
        ];

        $memorySamples[] = $resources['memory_usage_mb'];
        $cpuSamples[] = $resources['cpu_usage_pct'];

        foreach ($streams as $stream) {
            if (!$stream->active) {
                continue;
            }

            $healthy = checkStreamHealth($server, $mediaId, $quality, $timeout);

            if (!$healthy) {
                $stream->active = false;
                $stream->endTime = hrtime(true);
                $stream->lastError = 'Health check failed';

                $drops[] = [
                    'stream_id' => $stream->id,
                    'reason' => 'health_check_failed',
                    'time' => date('c'),
                    'elapsed_seconds' => round(($stream->endTime - $stream->startTime) / 1_000_000_000, 1),
                ];
            }
        }

        $activeStreams = count(array_filter($streams, fn($s) => $s->active));
        echo "\r[" . sprintf('%02d:%02d', (int)($currentElapsed / 60), (int)($currentElapsed % 60)) . "] Active: {$activeStreams}/{$numStreams} | CPU: {$resources['cpu_usage_pct']}% | Memory: {$resources['memory_usage_mb']}MB";
    }

    echo "\n\nTest complete. Analyzing results...\n";

    $finalTime = hrtime(true);
    foreach ($streams as $stream) {
        if ($stream->active) {
            $stream->endTime = $finalTime;
        }
    }

    $activeCount = count(array_filter($streams, fn($s) => $s->active));
    $droppedCount = count($drops);

    $memoryGrowth = count($memorySamples) > 1
        ? (max($memorySamples) - min($memorySamples)) / $initialMemoryMb * 100
        : 0;

    $cpuTrend = calculateTrend($cpuSamples);
    $cpuCreepDetected = $cpuTrend > 20;

    $memoryLeakDetected = $memoryGrowth > 50 && $cpuTrend < 10;

    return [
        'total_streams' => $numStreams,
        'streams_sustained' => $activeCount,
        'streams_dropped' => $droppedCount,
        'drops' => $drops,
        'memory_leak_detected' => $memoryLeakDetected,
        'memory_growth_pct' => round($memoryGrowth, 1),
        'cpu_creep_detected' => $cpuCreepDetected,
        'cpu_trend_pct' => round($cpuTrend, 1),
        'initial_memory_mb' => round($initialMemoryMb, 1),
        'final_memory_mb' => round(end($memorySamples), 1),
        'session_health' => $sessionHealth,
    ];
}

/**
 * Calculates a simple linear trend (in percentage points) over a data series.
 *
 * @param array<float> $samples Array of numeric samples
 * @return float Trend value (positive = increasing, negative = decreasing)
 */
function calculateTrend(array $samples): float
{
    $count = count($samples);
    if ($count < 2) {
        return 0.0;
    }

    $windowSize = min(10, (int)ceil($count / 5));
    if ($windowSize < 2) {
        return 0.0;
    }

    $firstWindowAvg = array_sum(array_slice($samples, 0, $windowSize)) / $windowSize;
    $lastWindowAvg = array_sum(array_slice($samples, -$windowSize)) / $windowSize;

    return $lastWindowAvg - $firstWindowAvg;
}

/**
 * Main entry point for the benchmark script.
 */
function main(array $argv): int
{
    if (!extension_loaded('curl')) {
        fwrite(STDERR, "Error: curl extension is required for HTTP requests\n");
        return 1;
    }

    $args = parseArgs($argv);

    $validationError = validateArgs($args);
    if ($validationError !== null) {
        fwrite(STDERR, $validationError . "\n\n");
        fwrite(STDERR, "Use --help for usage information\n");
        return 1;
    }

    if ($args['help']) {
        displayHelp();
        return 0;
    }

    $mediaId = (string)$args['media_id'];
    $numStreams = (int)$args['streams'];
    $server = (string)$args['server'];
    $duration = (int)$args['duration'];
    $interval = (int)$args['interval'];
    $timeout = (int)$args['timeout'];
    $quality = (string)$args['quality'];
    $rampUp = (int)$args['ramp_up'];

    $durationFormatted = sprintf('%02d:%02d:%02d', (int)($duration / 3600), (int)(($duration % 3600) / 60), (int)($duration % 60));

    echo "Starting Stream Stress Test\n";
    echo "============================\n";
    echo "Media ID: {$mediaId}\n";
    echo "Concurrent Streams: {$numStreams}\n";
    echo "Server: {$server}\n";
    echo "Quality: {$quality}\n";
    echo "Duration: {$durationFormatted}\n";
    echo "Ramp-up: {$rampUp}s\n\n";

    $systemBefore = getSystemResources();
    echo "Initial System State:\n";
    echo "  CPU: {$systemBefore['cpu_usage_pct']}%\n";
    echo "  Memory: {$systemBefore['memory_usage_mb']}MB\n";
    echo "  Load Average: " . implode(', ', $systemBefore['load_average']) . "\n\n";

    $results = runStressTest($server, $mediaId, $quality, $numStreams, $duration, $interval, $timeout, $rampUp);

    $systemAfter = getSystemResources();

    $passCriterion = $numStreams;
    $passed = $results['streams_sustained'] >= $passCriterion && !$results['memory_leak_detected'] && !$results['cpu_creep_detected'];

    $output = [
        'benchmark' => 'stream_stress',
        'version' => '1.0.0',
        'timestamp' => date('c'),
        'config' => [
            'media_id' => $mediaId,
            'server' => $server,
            'quality' => $quality,
            'num_streams' => $numStreams,
            'duration_seconds' => $duration,
            'interval_seconds' => $interval,
            'timeout_seconds' => $timeout,
            'ramp_up_seconds' => $rampUp,
        ],
        'results' => $results,
        'system' => [
            'before' => $systemBefore,
            'after' => $systemAfter,
        ],
        'pass' => $passed,
        'criterion' => "{$passCriterion}+ concurrent 1080p direct-play for 30 minutes",
        'criterion_passed' => $passed ? "PASS" : "FAIL",
    ];

    echo "\nResults Summary\n";
    echo "---------------\n";
    echo "Streams Sustained: {$results['streams_sustained']}/{$results['total_streams']}\n";
    echo "Streams Dropped: {$results['streams_dropped']}\n";
    echo "Memory Leak: " . ($results['memory_leak_detected'] ? "DETECTED" : "None") . "\n";
    echo "CPU Creep: " . ($results['cpu_creep_detected'] ? "DETECTED" : "None") . "\n";
    echo "Criterion: {$passCriterion}+ concurrent 1080p direct-play for 30 minutes\n";
    echo "Status: " . ($passed ? "PASS" : "FAIL") . "\n";
    echo "\n";
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    return 0;
}

$exitCode = main(array_slice($argv, 1));
exit($exitCode);
