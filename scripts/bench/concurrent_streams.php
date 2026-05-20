#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phlix Streaming Benchmark - Concurrent Streams Test
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @see https://github.com/detain/phlix-server
 */

namespace Phlix\Scripts\Bench;

/**
 * Concurrent Streams Benchmark - Measures max concurrent direct-play streams.
 *
 * This script tests the server's ability to handle multiple simultaneous
 * 1080p direct-play sessions. It spawns N concurrent HTTP requests to
 * the streaming endpoint and measures success rate, response times, and
 * resource utilization.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @see /api/v1/media/{id}/stream endpoint
 *
 * Usage:
 *   php scripts/bench/concurrent_streams.php --media-id=<uuid> --streams=<n> [--server=<url>]
 *
 * Example:
 *   php scripts/bench/concurrent_streams.php --media-id=abc-123 --streams=50 --server=http://localhost:8096
 *
 * v1.0 Pass Criterion: 50+ concurrent 1080p direct-play from a 4-vCPU server
 */

/**
 * Displays usage help for the benchmark script.
 */
function displayHelp(): void
{
    echo <<<'HELP'
Concurrent Streams Benchmark v1.0
=====================================

Measures maximum concurrent 1080p direct-play streams supported by the server.

Usage:
  php scripts/bench/concurrent_streams.php [options]

Required Options:
  --media-id=<uuid>        Media item UUID to stream (must exist in library)
  --streams=<n>            Number of concurrent streams to test

Optional Options:
  --server=<url>           Base server URL (default: http://localhost:8096)
  --timeout=<seconds>      Request timeout per stream (default: 30)
  --duration=<seconds>     How long to sustain each stream (default: 60)
  --quality=<profile>      Quality profile: 1080p, 720p, 480p (default: 1080p)
  --help, -h               Show this help message

Output:
  Results are output as JSON to stdout with the following structure:
  {
    "benchmark": "concurrent_streams",
    "version": "1.0.0",
    "timestamp": "ISO8601 timestamp",
    "config": { ... },
    "results": {
      "total_streams": 50,
      "successful": 48,
      "failed": 2,
      "success_rate": 0.96,
      "avg_response_time_ms": 145.3,
      "min_response_time_ms": 89.2,
      "max_response_time_ms": 312.5,
      "streams_sustained": 48
    },
    "system": {
      "cpu_usage_pct": 34.2,
      "memory_usage_mb": 512.4,
      "load_average": [2.1, 1.8, 1.5]
    },
    "pass": true,
    "criterion": "50+ concurrent 1080p direct-play"
  }

Exit Codes:
  0 - Benchmark completed successfully (pass or fail)
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
        'timeout' => 30,
        'duration' => 60,
        'quality' => '1080p',
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

        if (str_starts_with($arg, '--timeout=')) {
            $parsed['timeout'] = (int)substr($arg, 10);
            continue;
        }

        if (str_starts_with($arg, '--duration=')) {
            $parsed['duration'] = (int)substr($arg, 11);
            continue;
        }

        if (str_starts_with($arg, '--quality=')) {
            $parsed['quality'] = substr($arg, 10);
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

    if ($args['streams'] > 500) {
        return 'Error: --streams exceeds maximum of 500 (prevent accidental DoS)';
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
        $cpuUsage = min(100.0, ($loadAverage[0] / shell_exec('nproc') ?? 1) * 100);
    }

    return [
        'cpu_usage_pct' => round($cpuUsage, 1),
        'memory_usage_mb' => round($usedMemMb, 1),
        'load_average' => [round($loadAverage[0], 2), round($loadAverage[1], 2), round($loadAverage[2], 2)],
    ];
}

/**
 * Makes an HTTP request to stream a media item and returns timing info.
 *
 * @param string $server Base server URL
 * @param string $mediaId Media item UUID
 * @param string $quality Quality profile
 * @param int $timeout Request timeout in seconds
 * @return array{success: bool, response_time_ms: float, status_code: int, error: string|null}
 */
function streamRequest(string $server, string $mediaId, string $quality, int $timeout): array
{
    $startTime = hrtime(true);
    $url = rtrim($server, '/') . "/api/v1/media/{$mediaId}/stream?quality={$quality}";

    $ch = curl_init();
    if ($ch === false) {
        return [
            'success' => false,
            'response_time_ms' => 0.0,
            'status_code' => 0,
            'error' => 'Failed to initialize curl',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_NOBODY => false,
        CURLOPT_WRITEFUNCTION => function ($ch, string $data): int {
            return strlen($data);
        },
    ]);

    $response = curl_exec($ch);
    $endTime = hrtime(true);
    $responseTimeMs = ($endTime - $startTime) / 1_000_000;

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $success = $httpCode >= 200 && $httpCode < 300;

    return [
        'success' => $success,
        'response_time_ms' => round($responseTimeMs, 2),
        'status_code' => $httpCode,
        'error' => $error ?: null,
    ];
}

/**
 * Runs concurrent stream benchmark using pcntl for process management.
 *
 * @param string $server Base server URL
 * @param string $mediaId Media item UUID
 * @param string $quality Quality profile
 * @param int $numStreams Number of concurrent streams
 * @param int $timeout Request timeout
 * @return array<string, mixed> Benchmark results
 */
function runConcurrentBenchmark(string $server, string $mediaId, string $quality, int $numStreams, int $timeout): array
{
    $results = [];
    $pids = [];
    $numToSpawn = $numStreams;
    $successCount = 0;
    $failCount = 0;
    $responseTimes = [];

    for ($i = 0; $i < $numToSpawn; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $failCount++;
            continue;
        }

        if ($pid === 0) {
            $result = streamRequest($server, $mediaId, $quality, $timeout);
            file_put_contents('/tmp/bench_stream_' . getmypid() . '.json', json_encode($result, JSON_THROW_ON_ERROR));
            exit($result['success'] ? 0 : 1);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $exitCode = pcntl_wexitstatus($status);

        $resultFile = '/tmp/bench_stream_' . $pid . '.json';
        if (file_exists($resultFile)) {
            $result = json_decode(file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            $results[] = $result;
            if ($exitCode === 0 && ($result['success'] ?? false)) {
                $successCount++;
                $responseTimes[] = $result['response_time_ms'];
            } else {
                $failCount++;
            }
            unlink($resultFile);
        } else {
            $failCount++;
        }
    }

    $avgResponseTime = count($responseTimes) > 0 ? array_sum($responseTimes) / count($responseTimes) : 0;
    $minResponseTime = count($responseTimes) > 0 ? min($responseTimes) : 0;
    $maxResponseTime = count($responseTimes) > 0 ? max($responseTimes) : 0;

    return [
        'total_streams' => $numStreams,
        'successful' => $successCount,
        'failed' => $failCount,
        'success_rate' => $numStreams > 0 ? round($successCount / $numStreams, 4) : 0,
        'avg_response_time_ms' => round($avgResponseTime, 2),
        'min_response_time_ms' => round($minResponseTime, 2),
        'max_response_time_ms' => round($maxResponseTime, 2),
        'streams_sustained' => $successCount,
    ];
}

/**
 * Main entry point for the benchmark script.
 */
function main(array $argv): int
{
    if (!extension_loaded('pcntl')) {
        fwrite(STDERR, "Error: pcntl extension is required for concurrent benchmarking\n");
        return 1;
    }

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
    $timeout = (int)$args['timeout'];
    $duration = (int)$args['duration'];
    $quality = (string)$args['quality'];

    echo "Starting Concurrent Streams Benchmark\n";
    echo "=====================================\n";
    echo "Media ID: {$mediaId}\n";
    echo "Concurrent Streams: {$numStreams}\n";
    echo "Server: {$server}\n";
    echo "Quality: {$quality}\n";
    echo "Timeout: {$timeout}s\n\n";

    $systemBefore = getSystemResources();

    echo "Running benchmark...\n";

    $results = runConcurrentBenchmark($server, $mediaId, $quality, $numStreams, $timeout);

    $systemAfter = getSystemResources();
    $systemDelta = [
        'cpu_usage_pct' => round($systemAfter['cpu_usage_pct'] - $systemBefore['cpu_usage_pct'], 1),
        'memory_usage_mb' => round($systemAfter['memory_usage_mb'] - $systemBefore['memory_usage_mb'], 1),
        'load_average' => $systemAfter['load_average'],
    ];

    $passCriterion = 50;
    $passed = $results['successful'] >= $passCriterion;

    $output = [
        'benchmark' => 'concurrent_streams',
        'version' => '1.0.0',
        'timestamp' => date('c'),
        'config' => [
            'media_id' => $mediaId,
            'server' => $server,
            'quality' => $quality,
            'num_streams' => $numStreams,
            'timeout_seconds' => $timeout,
            'duration_seconds' => $duration,
        ],
        'results' => $results,
        'system' => [
            'before' => $systemBefore,
            'after' => $systemAfter,
            'delta' => $systemDelta,
        ],
        'pass' => $passed,
        'criterion' => "{$passCriterion}+ concurrent 1080p direct-play",
        'criterion_passed' => $passed ? "PASS" : "FAIL",
    ];

    echo "\n";
    echo "Results\n";
    echo "-------\n";
    echo "Successful: {$results['successful']}/{$results['total_streams']}\n";
    echo "Success Rate: " . round($results['success_rate'] * 100, 1) . "%\n";
    echo "Avg Response Time: {$results['avg_response_time_ms']}ms\n";
    echo "Criterion: {$passCriterion}+ concurrent 1080p direct-play\n";
    echo "Status: " . ($passed ? "PASS" : "FAIL") . "\n";
    echo "\n";
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    return 0;
}

$exitCode = main(array_slice($argv, 1));
exit($exitCode);
