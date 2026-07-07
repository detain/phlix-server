<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Throwable;

/**
 * Admin log viewer — lists the server log files and tails them.
 *
 * Mounted under the admin route group (gated by AdminMiddleware), so no
 * per-action admin check is needed here.
 *
 *  - `GET /api/v1/admin/logs` — list `*.log` files in the configured log dir
 *    (name, size, modified time).
 *  - `GET /api/v1/admin/logs/tail?file=app.log&lines=N` — last N lines of one
 *    file.
 *  - `GET /api/v1/admin/logs/tail-all?lines=N` — last N lines across *every*
 *    `*.log` file, each line tagged with its source file and merged into one
 *    chronological stream (so the admin can watch all logs at once).
 *
 * SECURITY: the requested `file` is reduced to its {@see basename()} and must
 * match a strict `*.log` allowlist pattern, then is resolved with
 * {@see realpath()} and confirmed to live directly inside the log directory —
 * so `..`, absolute paths and symlinks cannot escape the log dir.
 *
 * @since 1.7
 */
final class LogController
{
    /** Canonical log directory (no trailing slash), or '' if it doesn't resolve. */
    private string $logDir;

    /**
     * Cached glob results, keyed by the exact directory queried so that
     * different LogController instances (different log dirs — e.g. distinct
     * PHPUnit fixture directories within the same process) never collide.
     *
     * @var array<string, list<string>>
     */
    private static array $cachedGlobResults = [];

    /**
     * Timestamp when each keyed glob cache entry was loaded.
     *
     * @var array<string, int>
     */
    private static array $globCacheTimestamps = [];

    /** @var int Cache TTL in seconds (30 seconds - logs can be rotated) */
    private const GLOB_CACHE_TTL = 30;

    /** @var int Hard cap on lines returned by tail(). */
    private const MAX_LINES = 2000;

    /** @var int Default lines returned when `lines` is absent/invalid. */
    private const DEFAULT_LINES = 200;

    /** @var int Bytes read from the end of the file when tailing (bounds memory). */
    private const TAIL_BYTES = 512 * 1024;

    public function __construct(string $logDir)
    {
        $real = realpath($logDir);
        $this->logDir = ($real !== false && is_dir($real)) ? $real : '';
    }

    /**
     * List the available `*.log` files.
     *
     * @param Request $request Unused.
     * @param array<string, string> $params Unused.
     */
    public function index(Request $request, array $params): Response
    {
        if ($this->logDir === '') {
            return (new Response())->json(['files' => []]);
        }

        $paths = $this->globLogFiles();

        $files = [];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $files[] = [
                'name' => basename($path),
                'size' => (int) (filesize($path) ?: 0),
                'modified_at' => date('c', (int) (filemtime($path) ?: 0)),
            ];
        }
        // Most-recently-modified first.
        usort(
            $files,
            static fn (array $a, array $b): int => strcmp((string) $b['modified_at'], (string) $a['modified_at']),
        );

        return (new Response())->json(['files' => $files]);
    }

    /**
     * Return the last N lines of a log file.
     *
     * @param Request $request `file` (required) + `lines` (optional) query params.
     * @param array<string, string> $params Unused.
     */
    public function tail(Request $request, array $params): Response
    {
        $rawFile = $request->queryString('file', '') ?? '';
        $file = basename($rawFile);
        if ($file === '' || preg_match('/^[A-Za-z0-9._-]+\.log$/', $file) !== 1) {
            return (new Response())->status(400)->json(['error' => 'Invalid log file name']);
        }

        $lines = $request->queryInt('lines', self::DEFAULT_LINES);
        $lines = max(1, min(self::MAX_LINES, $lines));

        if ($this->logDir === '') {
            return (new Response())->status(404)->json(['error' => 'Log directory not available']);
        }

        $real = realpath($this->logDir . '/' . $file);
        if ($real === false || !is_file($real) || dirname($real) !== $this->logDir) {
            return (new Response())->status(404)->json(['error' => 'Log file not found']);
        }

        try {
            $content = $this->readTail($real, self::TAIL_BYTES);
        } catch (Throwable) {
            return (new Response())->status(500)->json(['error' => 'Failed to read log file']);
        }

        $all = $content === '' ? [] : explode("\n", rtrim($content, "\n"));
        $tail = array_slice($all, -$lines);

        return (new Response())->json([
            'file' => $file,
            'lines' => array_values($tail),
            'truncated' => count($all) > count($tail),
        ]);
    }

    /**
     * Return the last N lines across *all* `*.log` files, merged into one
     * chronological stream.
     *
     * Every line is prefixed with its source file name so the combined view
     * stays readable, and lines are ordered by their leading Monolog timestamp
     * (`[2026-05-15T22:11:44.893642-04:00] …`). Continuation lines (stack
     * traces and the like, which carry no timestamp) inherit the timestamp of
     * the line above them so they stay attached in order.
     *
     * Memory is bounded regardless of how many rotated files exist: the
     * per-file read window is scaled down by the file count so the total bytes
     * read stays near {@see self::TAIL_BYTES}.
     *
     * @param Request $request `lines` (optional) query param.
     * @param array<string, string> $params Unused.
     */
    public function tailAll(Request $request, array $params): Response
    {
        $lines = $request->queryInt('lines', self::DEFAULT_LINES);
        $lines = max(1, min(self::MAX_LINES, $lines));

        if ($this->logDir === '') {
            return (new Response())->json(['files' => [], 'lines' => [], 'truncated' => false]);
        }

        // Use cached glob results if still valid (same cache as index method,
        // keyed by log dir so this never collides with a different instance).
        $paths = $this->globLogFiles();

        $paths = array_values(array_filter($paths, 'is_file'));
        $fileCount = count($paths);
        if ($fileCount === 0) {
            return (new Response())->json(['files' => [], 'lines' => [], 'truncated' => false]);
        }

        // Scale the per-file read window down by the file count so the total
        // bytes read stays bounded even with many rotated daily files.
        $perFileBytes = max(64 * 1024, intdiv(self::TAIL_BYTES, $fileCount));

        /** @var list<array{ts: string, seq: int, text: string}> $entries */
        $entries = [];
        $seq = 0;
        $names = [];
        foreach ($paths as $path) {
            $name = basename($path);
            $names[] = $name;
            try {
                $content = $this->readTail($path, $perFileBytes);
            } catch (Throwable) {
                continue;
            }
            if ($content === '') {
                continue;
            }
            $fileLines = array_slice(explode("\n", rtrim($content, "\n")), -$lines);
            $lastTs = '';
            foreach ($fileLines as $line) {
                $ts = $this->parseTimestamp($line);
                if ($ts === '') {
                    $ts = $lastTs; // continuation line keeps its predecessor's order
                } else {
                    $lastTs = $ts;
                }
                $entries[] = [
                    'ts' => $ts,
                    'seq' => $seq++,
                    'text' => sprintf('%-22s %s', $name, $line),
                ];
            }
        }

        // Chronological merge: by leading timestamp, then stable read order.
        usort(
            $entries,
            static fn (array $a, array $b): int => [$a['ts'], $a['seq']] <=> [$b['ts'], $b['seq']],
        );

        $kept = array_slice($entries, -$lines);
        sort($names);

        return (new Response())->json([
            'files' => $names,
            'lines' => array_values(array_map(static fn (array $e): string => $e['text'], $kept)),
            'truncated' => count($entries) > count($kept),
        ]);
    }

    /**
     * Extract the leading Monolog timestamp from a log line for sorting, or ''
     * when the line has none (e.g. a stack-trace continuation line).
     *
     * Matches the default `[Y-m-d\TH:i:s.uP]` format Phlix writes, e.g.
     * `[2026-05-15T22:11:44.893642-04:00]`. ISO-8601 strings written on one
     * host share a timezone offset, so a lexicographic compare orders them
     * correctly.
     */
    private function parseTimestamp(string $line): string
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*)\]/', $line, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * glob() the configured log dir for `*.log` files, using a small
     * per-directory TTL cache so a busy "view logs" admin page doesn't hit
     * the filesystem on every request.
     *
     * The cache is keyed by {@see self::$logDir} (not a fixed string) so
     * distinct {@see LogController} instances pointed at different
     * directories — e.g. separate PHPUnit fixture dirs created within the
     * same long-lived process — never read or clobber each other's results.
     *
     * @return list<string>
     */
    private function globLogFiles(): array
    {
        $key = $this->logDir;
        $now = time();

        if (
            isset(self::$cachedGlobResults[$key], self::$globCacheTimestamps[$key])
            && ($now - self::$globCacheTimestamps[$key]) < self::GLOB_CACHE_TTL
        ) {
            return self::$cachedGlobResults[$key];
        }

        $paths = glob($key . '/*.log') ?: [];
        self::$cachedGlobResults[$key] = $paths;
        self::$globCacheTimestamps[$key] = $now;

        return $paths;
    }

    /**
     * Read up to the last $maxBytes of a file (bounded memory). When the file
     * is larger, the first (partial) line is dropped so callers only see whole
     * lines.
     */
    private function readTail(string $path, int $maxBytes): string
    {
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('open failed');
        }
        try {
            $offset = $size > $maxBytes ? $size - $maxBytes : 0;
            if ($offset > 0) {
                fseek($handle, $offset);
            }
            $data = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        if ($data === false) {
            return '';
        }
        // Drop the leading partial line when we seeked into the middle.
        if ($size > $maxBytes) {
            $nl = strpos($data, "\n");
            $data = $nl === false ? '' : substr($data, $nl + 1);
        }
        return $data;
    }
}
