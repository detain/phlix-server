<?php

/**
 * Phlix media server component: Debug Logging.
 *
 * Provides consistent debug logging format for tracing slowdowns.
 * Format: [DEBUG] {timestamp} {location} Starting/Completed task: {task_name} in {duration}ms
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Debug;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Debug logging utility for tracing task execution and diagnosing slowdowns.
 *
 * Provides consistent debug logging format:
 * - [DEBUG] {timestamp} {location} Starting task: {task_name}
 * - [DEBUG] {timestamp} {location} Completed task: {task_name} in {duration}ms
 *
 * Usage:
 * ```php
 * $debug = DebugLogger::create('MyService');
 * $debug->start('process_item');
 * // ... work ...
 * $debug->end('process_item');
 * ```
 */
class DebugLogger
{
    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var string Location identifier (e.g., class name or file) */
    private string $location;

    /** @var array<string, float> Start times for active tasks */
    private array $taskStarts = [];

    /** @var array<string, int> Task execution counts */
    private array $taskCounts = [];

    /** @var array<string, float> Task durations for last N executions */
    private array $recentDurations = [];

    /** @var int Maximum recent durations to track per task */
    private const MAX_RECENT_DURATIONS = 10;

    /**
     * @param LoggerInterface $logger   Logger instance
     * @param string          $location Location identifier
     */
    public function __construct(LoggerInterface $logger, string $location)
    {
        $this->logger = $logger;
        $this->location = $location;
    }

    /**
     * Create a DebugLogger instance.
     *
     * @param string          $location Location identifier
     * @param LoggerInterface|null $logger Logger instance (optional, uses NullLogger if not provided)
     * @return self
     */
    public static function create(string $location, ?LoggerInterface $logger = null): self
    {
        return new self($logger ?? new NullLogger(), $location);
    }

    /**
     * Start timing a task.
     *
     * @param string $taskName Name of the task
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    public function start(string $taskName, array $context = []): void
    {
        $taskId = $this->getTaskId($taskName);
        $this->taskStarts[$taskId] = hrtime(true);
        $this->taskCounts[$taskId] = ($this->taskCounts[$taskId] ?? 0) + 1;

        $this->log('info', "Starting task: {$taskName}", array_merge(
            ['task' => $taskName, 'uid' => $this->generateUid()],
            $context
        ));
    }

    /**
     * End timing a task and log completion.
     *
     * @param string $taskName Name of the task
     * @param array<string, mixed> $context Additional context
     * @return float Duration in milliseconds
     */
    public function end(string $taskName, array $context = []): float
    {
        $taskId = $this->getTaskId($taskName);
        $startTime = $this->taskStarts[$taskId] ?? null;

        if ($startTime === null) {
            $this->logger->warning("[DEBUG] {$this->timestamp()} {$this->location} end() called for unknown task: {$taskName}");
            return 0.0;
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000.0;
        unset($this->taskStarts[$taskId]);

        // Track recent durations for this task
        if (!isset($this->recentDurations[$taskId])) {
            $this->recentDurations[$taskId] = [];
        }
        $this->recentDurations[$taskId][] = $durationMs;
        if (count($this->recentDurations[$taskId]) > self::MAX_RECENT_DURATIONS) {
            array_shift($this->recentDurations[$taskId]);
        }

        $this->log('info', "Completed task: {$taskName} in " . round($durationMs, 2) . "ms", array_merge(
            [
                'task' => $taskName,
                'duration_ms' => round($durationMs, 2),
                'count' => $this->taskCounts[$taskId] ?? 0,
                'avg_recent_ms' => $this->getAverageRecentDuration($taskId),
            ],
            $context
        ));

        return $durationMs;
    }

    /**
     * Log a debug message at the start of a loop iteration.
     *
     * @param string $loopName Name of the loop
     * @param int    $index    Current iteration index
     * @param int    $total    Total iterations
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    public function loopProgress(string $loopName, int $index, int $total, array $context = []): void
    {
        $this->log('debug', "Loop progress: {$loopName} ({$index}/{$total})", array_merge(
            [
                'loop' => $loopName,
                'index' => $index,
                'total' => $total,
                'percent' => $total > 0 ? round(($index / $total) * 100, 1) : 0,
            ],
            $context
        ));
    }

    /**
     * Log the start of a batch operation.
     *
     * @param string $batchName Name of the batch
     * @param int    $count     Number of items in batch
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    public function startBatch(string $batchName, int $count, array $context = []): void
    {
        $this->log('info', "Starting batch: {$batchName} ({$count} items)", array_merge(
            [
                'batch' => $batchName,
                'count' => $count,
            ],
            $context
        ));
    }

    /**
     * Log the completion of a batch operation.
     *
     * @param string $batchName Name of the batch
     * @param float  $durationMs Duration in milliseconds
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    public function endBatch(string $batchName, float $durationMs, array $context = []): void
    {
        $this->log('info', "Completed batch: {$batchName} in " . round($durationMs, 2) . "ms", array_merge(
            [
                'batch' => $batchName,
                'duration_ms' => round($durationMs, 2),
                'items_per_sec' => $durationMs > 0 ? round(($context['count'] ?? 0) / ($durationMs / 1000.0), 1) : 0,
            ],
            $context
        ));
    }

    /**
     * Log an error during task execution.
     *
     * @param string $taskName Name of the task
     * @param string $error    Error message
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    public function error(string $taskName, string $error, array $context = []): void
    {
        $taskId = $this->getTaskId($taskName);
        unset($this->taskStarts[$taskId]);

        $this->log('error', "Task error: {$taskName} - {$error}", array_merge(
            [
                'task' => $taskName,
                'error' => $error,
                'count' => $this->taskCounts[$taskId] ?? 0,
            ],
            $context
        ));
    }

    /**
     * Log a memory usage snapshot.
     *
     * @param string $label     Label for this snapshot
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    public function memorySnapshot(string $label, array $context = []): void
    {
        $memoryBytes = memory_get_usage(true);
        $peakBytes = memory_get_peak_usage(true);

        $this->log('debug', "Memory snapshot: {$label}", array_merge(
            [
                'label' => $label,
                'memory_mb' => round($memoryBytes / 1024 / 1024, 2),
                'peak_mb' => round($peakBytes / 1024 / 1024, 2),
            ],
            $context
        ));
    }

    /**
     * Get task statistics for a given task.
     *
     * @param string $taskName Name of the task
     * @return array{total_count: int, recent_avg_ms: float, recent_min_ms: float, recent_max_ms: float}
     */
    public function getTaskStats(string $taskName): array
    {
        $taskId = $this->getTaskId($taskName);
        $durations = $this->recentDurations[$taskId] ?? [];

        if (empty($durations)) {
            return [
                'total_count' => $this->taskCounts[$taskId] ?? 0,
                'recent_avg_ms' => 0.0,
                'recent_min_ms' => 0.0,
                'recent_max_ms' => 0.0,
            ];
        }

        return [
            'total_count' => $this->taskCounts[$taskId] ?? 0,
            'recent_avg_ms' => round(array_sum($durations) / count($durations), 2),
            'recent_min_ms' => round(min($durations), 2),
            'recent_max_ms' => round(max($durations), 2),
        ];
    }

    /**
     * Generate a unique ID for this task execution.
     *
     * @return string
     */
    private function generateUid(): string
    {
        return sprintf('%08x-%04x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffff));
    }

    /**
     * Get current timestamp in ISO format.
     *
     * @return string
     */
    private function timestamp(): string
    {
        return date('Y-m-d H:i:s.v');
    }

    /**
     * Get task ID from task name.
     *
     * @param string $taskName
     * @return string
     */
    private function getTaskId(string $taskName): string
    {
        return $taskName;
    }

    /**
     * Get average duration from recent executions.
     *
     * @param string $taskId
     * @return float
     */
    private function getAverageRecentDuration(string $taskId): float
    {
        $durations = $this->recentDurations[$taskId] ?? [];
        if (empty($durations)) {
            return 0.0;
        }
        return round(array_sum($durations) / count($durations), 2);
    }

    /**
     * Log a message.
     *
     * @param string $level   Log level
     * @param string $message Message
     * @param array<string, mixed> $context Context
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $logMessage = "[DEBUG] {$this->timestamp()} {$this->location} {$message}";
        $this->logger->log($level, $logMessage, $context);
    }
}
