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

        $files = [];
        foreach (glob($this->logDir . '/*.log') ?: [] as $path) {
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
