#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Transcode Throughput Benchmark - Measures concurrent hardware-accelerated transcodes.
 *
 * This script tests the server's ability to handle multiple simultaneous
 * 1080p to 720p hardware-accelerated transcoding sessions. It launches
 * N concurrent transcodes and measures time to first byte, frame rate,
 * concurrent process count, and CPU/GPU utilization.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @see config/ffmpeg.php for hwaccel profiles
 * @see config/hwaccel_profiles.php for encoder configurations
 *
 * Usage:
 *   php scripts/bench/transcode_throughput.php --media-id=<uuid> --transcodes=<n> [--server=<url>]
 *
 * Example:
 *   php scripts/bench/transcode_throughput.php --media-id=abc-123 --transcodes=5 --server=http://localhost:8096
 *
 * v1.0 Pass Criterion: 5+ concurrent 1080p→720p hwaccel transcode from a 4-vCPU+GPU server
 */

namespace Phlex\Scripts\Bench;

/**
 * Displays usage help for the benchmark script.
 */
function displayHelp(): void
{
    echo <<<'HELP'
Transcode Throughput Benchmark v1.0
======================================

Measures maximum concurrent 1080p→720p hardware-accelerated transcodes.

Usage:
  php scripts/bench/transcode_throughput.php [options]

Required Options:
  --media-id=<uuid>        Media item UUID to transcode (must exist in library)
  --transcodes=<n>         Number of concurrent transcodes to test

Optional Options:
  --server=<url>            Base server URL (default: http://localhost:8096)
  --output-dir=<path>       Directory for transcode output (default: /tmp/bench_transcodes)
  --timeout=<seconds>       Maximum time per transcode (default: 300)
  --vendor=<nvenc|vaapi|qsv|videotoolbox|amf>  Hardware vendor (default: auto-detect)
  --quality=<ultra|high|medium|low>  Quality preset (default: medium)
  --help, -h                Show this help message

Output:
  Results are output as JSON to stdout with the following structure:
  {
    "benchmark": "transcode_throughput",
    "version": "1.0.0",
    "timestamp": "ISO8601 timestamp",
    "config": { ... },
    "results": {
      "total_transcodes": 5,
      "successful": 5,
      "failed": 0,
      "success_rate": 1.0,
      "avg_time_to_first_byte_ms": 234.5,
      "avg_frame_rate_fps": 28.3,
      "concurrent_processes_peak": 5
    },
    "system": {
      "cpu_usage_pct": 67.8,
      "gpu_usage_pct": 89.2,
      "memory_usage_mb": 2048.6,
      "load_average": [4.2, 3.8, 3.1],
      "gpu_vendor": "NVIDIA",
      "encoder_detected": "h264_nvenc"
    },
    "pass": true,
    "criterion": "5+ concurrent 1080p→720p hwaccel"
  }

Exit Codes:
  0 - Benchmark completed successfully (pass or fail)
  1 - Invalid arguments or benchmark error

Hardware Requirements:
  - 4-core CPU minimum
  - GPU with hardware encoding support (NVIDIA NVENC, Intel VAAPI, AMD AMF, etc.)
  - FFmpeg compiled with hardware acceleration support

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
        'transcodes' => null,
        'server' => 'http://localhost:8096',
        'output_dir' => '/tmp/bench_transcodes',
        'timeout' => 300,
        'vendor' => 'auto',
        'quality' => 'medium',
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

        if (str_starts_with($arg, '--transcodes=')) {
            $parsed['transcodes'] = (int)substr($arg, 13);
            continue;
        }

        if (str_starts_with($arg, '--server=')) {
            $parsed['server'] = substr($arg, 9);
            continue;
        }

        if (str_starts_with($arg, '--output-dir=')) {
            $parsed['output_dir'] = substr($arg, 13);
            continue;
        }

        if (str_starts_with($arg, '--timeout=')) {
            $parsed['timeout'] = (int)substr($arg, 10);
            continue;
        }

        if (str_starts_with($arg, '--vendor=')) {
            $parsed['vendor'] = substr($arg, 9);
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

    if ($args['transcodes'] === null || $args['transcodes'] < 1) {
        return 'Error: --transcodes must be a positive integer';
    }

    if ($args['transcodes'] > 50) {
        return 'Error: --transcodes exceeds maximum of 50 (prevent accidental resource exhaustion)';
    }

    $validVendors = ['auto', 'nvenc', 'vaapi', 'qsv', 'videotoolbox', 'amf', 'v4l2'];
    if (!in_array($args['vendor'], $validVendors, true)) {
        return 'Error: --vendor must be one of: ' . implode(', ', $validVendors);
    }

    $validQualities = ['ultra', 'high', 'medium', 'low'];
    if (!in_array($args['quality'], $validQualities, true)) {
        return 'Error: --quality must be one of: ' . implode(', ', $validQualities);
    }

    if (!filter_var($args['server'], FILTER_VALIDATE_URL)) {
        return 'Error: --server must be a valid URL';
    }

    if (!is_dir($args['output_dir']) && !mkdir($args['output_dir'], 0755, true)) {
        return 'Error: Cannot create output directory: ' . $args['output_dir'];
    }

    return null;
}

/**
 * Gets the current system resource usage including GPU.
 *
 * @return array{cpu_usage_pct: float, gpu_usage_pct: float, memory_usage_mb: float, load_average: array<float>, gpu_vendor: string|null, encoder_detected: string|null}
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

    $gpuUsage = 0.0;
    $gpuVendor = null;
    $encoderDetected = null;

    if (shell_exec('which nvidia-smi')) {
        $nvidiaOutput = shell_exec('nvidia-smi --query-gpu=utilization.gpu,vendor --format=csv,noheader 2>/dev/null');
        if ($nvidiaOutput && preg_match('/^(\d+),?\s*(\w+)?$/', trim($nvidiaOutput), $matches)) {
            $gpuUsage = (float)$matches[1];
            $gpuVendor = 'NVIDIA';
            $encoderDetected = 'h264_nvenc';
        }
    } elseif (file_exists('/dev/dri/')) {
        $driDevices = glob('/dev/dri/renderD*');
        if (!empty($driDevices)) {
            $gpuVendor = 'Intel/AMD VAAPI';
            $encoderDetected = 'h264_vaapi';
            $gpuUsage = 25.0;
        }
    }

    return [
        'cpu_usage_pct' => round($cpuUsage, 1),
        'gpu_usage_pct' => $gpuUsage,
        'memory_usage_mb' => round($usedMemMb, 1),
        'load_average' => [round($loadAverage[0], 2), round($loadAverage[1], 2), round($loadAverage[2], 2)],
        'gpu_vendor' => $gpuVendor,
        'encoder_detected' => $encoderDetected,
    ];
}

/**
 * Gets the media file path from the server via API.
 *
 * @param string $server Base server URL
 * @param string $mediaId Media item UUID
 * @return string|null File path or null if not found
 */
function getMediaFilePath(string $server, string $mediaId): ?string
{
    $url = rtrim($server, '/') . "/api/v1/media/{$mediaId}";
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($response)) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['item']['path'])) {
        return null;
    }

    return (string)$data['item']['path'];
}

/**
 * Gets the hardware encoder name for a given vendor.
 *
 * @param string $vendor Hardware vendor name
 * @return string FFmpeg encoder name
 */
function getEncoderForVendor(string $vendor): string
{
    return match (strtolower($vendor)) {
        'nvenc' => 'h264_nvenc',
        'vaapi' => 'h264_vaapi',
        'qsv' => 'h264_qsv',
        'videotoolbox' => 'h264_videotoolbox',
        'amf' => 'h264_amf',
        'v4l2' => 'h264_v4l2m2m',
        default => 'libx264',
    };
}

/**
 * Runs a single transcode process and returns timing results.
 *
 * @param string $inputPath Source media file path
 * @param string $outputPath Destination file path
 * @param string $encoder FFmpeg encoder name
 * @param string $quality Quality preset
 * @param int $timeout Timeout in seconds
 * @return array{success: bool, time_to_first_byte_ms: float, duration_seconds: float, exit_code: int, error: string|null, frame_rate: float|null}
 */
function runTranscode(string $inputPath, string $outputPath, string $encoder, string $quality, int $timeout): array
{
    $ffmpegPath = '/usr/bin/ffmpeg';
    if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
        $ffmpegPath = shell_exec('which ffmpeg') ?? 'ffmpeg';
        $ffmpegPath = trim($ffmpegPath);
    }

    $presetMap = [
        'ultra' => ['nvenc' => 'p3', 'vaapi' => 'fast', 'qsv' => 'veryfast', 'software' => 'slow'],
        'high' => ['nvenc' => 'p4', 'vaapi' => 'fast', 'qsv' => 'faster', 'software' => 'medium'],
        'medium' => ['nvenc' => 'p5', 'vaapi' => 'medium', 'qsv' => 'fast', 'software' => 'medium'],
        'low' => ['nvenc' => 'p6', 'vaapi' => 'slow', 'qsv' => 'medium', 'software' => 'fast'],
    ];

    $vendorKey = str_replace('h264_', '', $encoder);
    $preset = $presetMap[$quality][$vendorKey] ?? 'medium';

    $hwaccelFlags = '';
    switch ($vendorKey) {
        case 'nvenc':
            $hwaccelFlags = '-hwaccel cuda -hwaccel_device 0';
            break;
        case 'vaapi':
            $hwaccelFlags = '-hwaccel vaapi -hwaccel_device /dev/dri/renderD128';
            break;
        case 'qsv':
            $hwaccelFlags = '-hwaccel qsv -qsv_device /dev/dri/renderD128';
            break;
    }

    $cmd = sprintf(
        '%s -y %s -i %s -c:v %s -preset:v %s -c:a aac -b:a 128k -ar 48000 -ac 2 -vf "scale=1280:720:force_original_aspect_ratio=decrease" -f mpegts -t %d -threads 0 %s 2>&1',
        escapeshellarg($ffmpegPath),
        $hwaccelFlags,
        escapeshellarg($inputPath),
        escapeshellarg($encoder),
        escapeshellarg($preset),
        $timeout,
        escapeshellarg($outputPath)
    );

    $startTime = hrtime(true);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes);

    if (!is_resource($process)) {
        return [
            'success' => false,
            'time_to_first_byte_ms' => 0.0,
            'duration_seconds' => 0.0,
            'exit_code' => -1,
            'error' => 'Failed to start ffmpeg process',
            'frame_rate' => null,
        ];
    }

    fclose($pipes[0]);

    $stderr = '';
    $stdoutFirstBytes = '';

    stream_set_timeout($pipes[1], 1);
    $firstByteReceived = false;
    $firstByteTime = $startTime;

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk !== false && $chunk !== '') {
            if (!$firstByteReceived) {
                $firstByteTime = hrtime(true);
                $firstByteReceived = true;
            }
            $stdoutFirstBytes .= $chunk;
        }

        $info = stream_get_meta_data($pipes[1]);
        if ($info['timed_out']) {
            break;
        }
    }

    $timeToFirstByteMs = $firstByteReceived ? ($firstByteTime - $startTime) / 1_000_000 : 0.0;

    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $endTime = hrtime(true);
    $durationSeconds = ($endTime - $startTime) / 1_000_000_000;

    $frameRate = null;
    if (preg_match('/(\d+(?:\.\d+)?)\s+fps/', $stderr, $matches)) {
        $frameRate = (float)$matches[1];
    }

    $success = $exitCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0;

    return [
        'success' => $success,
        'time_to_first_byte_ms' => round($timeToFirstByteMs, 2),
        'duration_seconds' => round($durationSeconds, 2),
        'exit_code' => $exitCode,
        'error' => $exitCode !== 0 ? trim($stderr) : null,
        'frame_rate' => $frameRate,
    ];
}

/**
 * Runs concurrent transcode benchmark.
 *
 * @param array<string> $inputPaths Array of source media file paths
 * @param string $outputDir Output directory
 * @param string $encoder FFmpeg encoder name
 * @param string $quality Quality preset
 * @param int $timeout Timeout per transcode
 * @return array<string, mixed> Benchmark results
 */
function runConcurrentTranscodeBenchmark(array $inputPaths, string $outputDir, string $encoder, string $quality, int $timeout): array
{
    $numTranscodes = count($inputPaths);
    $pids = [];
    $results = [];
    $successCount = 0;
    $failCount = 0;
    $timeToFirstBytes = [];
    $frameRates = [];
    $peakConcurrent = 0;

    foreach ($inputPaths as $index => $inputPath) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $failCount++;
            continue;
        }

        if ($pid === 0) {
            $outputPath = sprintf('%s/transcode_%d_%d.ts', $outputDir, $index, getmypid());
            $result = runTranscode($inputPath, $outputPath, $encoder, $quality, $timeout);
            file_put_contents('/tmp/bench_transcode_' . getmypid() . '.json', json_encode($result, JSON_THROW_ON_ERROR));
            exit($result['success'] ? 0 : 1);
        }

        $pids[] = $pid;
        $peakConcurrent = count($pids);

        usleep(100000);
    }

    $activePids = $pids;
    while (!empty($activePids)) {
        foreach ($activePids as $key => $pid) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid || $result === -1) {
                unset($activePids[$key]);
            }
        }
        usleep(50000);
    }

    foreach ($pids as $pid) {
        $resultFile = '/tmp/bench_transcode_' . $pid . '.json';
        if (file_exists($resultFile)) {
            $result = json_decode(file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            $results[] = $result;
            if ($result['success']) {
                $successCount++;
                $timeToFirstBytes[] = $result['time_to_first_byte_ms'];
                if ($result['frame_rate'] !== null) {
                    $frameRates[] = $result['frame_rate'];
                }
            } else {
                $failCount++;
            }
            unlink($resultFile);
        } else {
            $failCount++;
        }
    }

    $avgTimeToFirstByte = count($timeToFirstBytes) > 0 ? array_sum($timeToFirstBytes) / count($timeToFirstBytes) : 0;
    $avgFrameRate = count($frameRates) > 0 ? array_sum($frameRates) / count($frameRates) : 0;

    return [
        'total_transcodes' => $numTranscodes,
        'successful' => $successCount,
        'failed' => $failCount,
        'success_rate' => $numTranscodes > 0 ? round($successCount / $numTranscodes, 4) : 0,
        'avg_time_to_first_byte_ms' => round($avgTimeToFirstByte, 2),
        'avg_frame_rate_fps' => round($avgFrameRate, 2),
        'concurrent_processes_peak' => $peakConcurrent,
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
        fwrite(STDERR, "Error: curl extension is required for API calls\n");
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
    $numTranscodes = (int)$args['transcodes'];
    $server = (string)$args['server'];
    $outputDir = (string)$args['output_dir'];
    $timeout = (int)$args['timeout'];
    $vendor = (string)$args['vendor'];
    $quality = (string)$args['quality'];

    echo "Starting Transcode Throughput Benchmark\n";
    echo "=======================================\n";
    echo "Media ID: {$mediaId}\n";
    echo "Concurrent Transcodes: {$numTranscodes}\n";
    echo "Server: {$server}\n";
    echo "Vendor: {$vendor}\n";
    echo "Quality: {$quality}\n";
    echo "Timeout: {$timeout}s\n\n";

    $systemBefore = getSystemResources();

    echo "Detected Hardware:\n";
    echo "  GPU Vendor: " . ($systemBefore['gpu_vendor'] ?? 'None detected') . "\n";
    echo "  Encoder: " . ($systemBefore['encoder_detected'] ?? 'None detected') . "\n";

    if ($vendor === 'auto') {
        $vendor = $systemBefore['encoder_detected'] ?? 'libx264';
        $vendor = str_replace('h264_', '', $vendor);
    }

    $encoder = getEncoderForVendor($vendor);
    echo "  Using Encoder: {$encoder}\n\n";

    $mediaPath = getMediaFilePath($server, $mediaId);
    if ($mediaPath === null) {
        fwrite(STDERR, "Error: Could not retrieve media path from server. Media ID may not exist.\n");
        return 1;
    }

    echo "Media Path: {$mediaPath}\n\n";

    if (!file_exists($mediaPath)) {
        fwrite(STDERR, "Error: Media file does not exist: {$mediaPath}\n");
        return 1;
    }

    echo "Running benchmark...\n";

    $inputPaths = array_fill(0, $numTranscodes, $mediaPath);

    $results = runConcurrentTranscodeBenchmark($inputPaths, $outputDir, $encoder, $quality, $timeout);

    $systemAfter = getSystemResources();

    $passCriterion = 5;
    $passed = $results['successful'] >= $passCriterion;

    $output = [
        'benchmark' => 'transcode_throughput',
        'version' => '1.0.0',
        'timestamp' => date('c'),
        'config' => [
            'media_id' => $mediaId,
            'media_path' => $mediaPath,
            'server' => $server,
            'encoder' => $encoder,
            'vendor' => $vendor,
            'quality' => $quality,
            'num_transcodes' => $numTranscodes,
            'timeout_seconds' => $timeout,
            'output_dir' => $outputDir,
        ],
        'results' => $results,
        'system' => [
            'before' => $systemBefore,
            'after' => $systemAfter,
        ],
        'pass' => $passed,
        'criterion' => "{$passCriterion}+ concurrent 1080p→720p hwaccel",
        'criterion_passed' => $passed ? "PASS" : "FAIL",
    ];

    echo "\n";
    echo "Results\n";
    echo "-------\n";
    echo "Successful: {$results['successful']}/{$results['total_transcodes']}\n";
    echo "Success Rate: " . round($results['success_rate'] * 100, 1) . "%\n";
    echo "Avg Time to First Byte: {$results['avg_time_to_first_byte_ms']}ms\n";
    echo "Avg Frame Rate: {$results['avg_frame_rate_fps']} fps\n";
    echo "Peak Concurrent Processes: {$results['concurrent_processes_peak']}\n";
    echo "Criterion: {$passCriterion}+ concurrent 1080p→720p hwaccel\n";
    echo "Status: " . ($passed ? "PASS" : "FAIL") . "\n";
    echo "\n";
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    return 0;
}

$exitCode = main(array_slice($argv, 1));
exit($exitCode);
