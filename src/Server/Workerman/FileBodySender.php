<?php

declare(strict_types=1);

namespace Phlix\Server\Workerman;

use stdClass;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

use function clearstatcache;
use function fclose;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function is_resource;
use function min;
use function strlen;

/**
 * Streams a file-backed Workerman response without reading the window into memory.
 *
 * ## What it replaces
 *
 * Workerman's `Protocols\Http::encode()` handles every `withFile()` response. For a
 * window under 2 MiB it calls `file_get_contents()` on the whole window on the event
 * loop and concatenates the header and the body into one string. Above 2 MiB it
 * sends the header and then streams the rest in 1 MiB reads. Both paths run here
 * instead. The only caller is {@see HttpHandler}, which routes each file-backed reply
 * through its `emit()` helper, so Workerman's default Http protocol is left in place
 * and buffered replies still go through `$connection->send()` as before.
 *
 * ## Wire behaviour
 *
 * The header block is the same string Workerman builds: the same header mutations
 * (`Content-Length`, `Accept-Ranges`, `Content-Range`, the 206 status), the same
 * `(string)$response` head from `createHeadForFile()` (`Connection`, `Content-Type`,
 * `Content-Disposition`, `Last-Modified`), sent once as a raw write. The body is then
 * sent as raw writes of at most {@see self::CHUNK_SIZE} bytes, never more than the
 * advertised `Content-Length`. Nothing is concatenated.
 *
 * ## Backpressure
 *
 * Writes stop while `context->bufferFull` is set by `onBufferFull` and resume from
 * `onBufferDrain`, the same pair Workerman's own `sendStream()` uses. The stream state
 * lives in a plain object captured only by the drain callback, so the connection
 * holds no reference cycle: destroying the connection clears `onBufferDrain`, and the
 * open handle is released with the state.
 *
 * ## Deliberate differences from encode()
 *
 * - A file that cannot be opened (or cannot be seeked to its offset) answers 403 for
 *   every size. Encode only did so above 2 MiB; below that it sent a header promising
 *   bytes that were never read.
 * - A file that shrinks after its size was taken closes the connection rather than
 *   desynchronising keep-alive framing.
 * - A file that grows after its size was taken is never read past `Content-Length`.
 * - A window that starts at or past EOF answers 416 with an unsatisfied-range Content-Range
 *   that names the file size, and an empty body. For a window past EOF with no length, encode()
 *   threw a ValueError from `file_get_contents()` on the negative length and sent nothing.
 *
 * ## Not handled here
 *
 * HEAD is not special-cased: a HEAD never reaches a file-backed response because
 * {@see \Phlix\Server\Http\Response::toWorkermanResponse()} and {@see HttpHandler} answer it with a
 * {@see BodylessResponse}, exactly as before. Workerman has no request method in
 * `encode()`, so this class cannot tell a HEAD apart.
 *
 * @package Phlix\Server\Workerman
 */
final class FileBodySender
{
    /** Bytes read from disk and written to the socket per step. */
    public const CHUNK_SIZE = 262144;

    /**
     * Send the header block and stream the body of a file-backed response.
     *
     * Mutates `$response` exactly as Workerman's encode() does, then streams the reply
     * itself; the caller must not also send it. The 403 path closes the connection with
     * a 403 body and sends no header. The 416 path sends a bodyless 416 and keeps the
     * connection open, again with no file header.
     *
     * @param TcpConnection $connection The client connection.
     * @param WorkermanResponse $response A response whose `$file` is set.
     */
    public static function send(TcpConnection $connection, WorkermanResponse $response): void
    {
        // Mirrors Http::encode(): merge headers queued on the connection, then clear.
        $pending = $connection->headers;
        if ($pending) {
            $response->withHeaders($pending);
            $connection->headers = [];
        }

        $info = $response->file;
        if (
            !is_array($info)
            || !array_key_exists('file', $info)
            || !is_string($info['file'])
            || !array_key_exists('offset', $info)
            || !array_key_exists('length', $info)
        ) {
            // withFile() always sets file, offset and length. Any other shape is not a file-backed reply,
            // so it takes the same 403 as an unreadable file.
            $connection->close(new WorkermanResponse(403, [], '403 Forbidden'));
            return;
        }
        $file = $info['file'];
        $offset = $info['offset'] ?: 0;
        $length = $info['length'] ?: 0;
        clearstatcache();
        // The @ only silences the stat warning for a file removed after withFile(); an unknown
        // size takes the same 403 as a failed open, so a missing file never reaches the 416 below.
        $size = @filesize($file);
        if ($size === false) {
            $connection->close(new WorkermanResponse(403, [], '403 Forbidden'));
            return;
        }
        $fileSize = $size;
        $bodyLen = $length > 0 ? $length : $fileSize - $offset;
        if (($offset > 0 || $length > 0) && $offset >= $fileSize) {
            // The window starts at or past EOF, or asks for bytes of an empty file. With no length,
            // bodyLen is then negative and Content-Length would be malformed; with a zero-length
            // window the range is unsatisfiable. Either way answer 416 before any header is written.
            self::rangeNotSatisfiable($connection, $response, $fileSize);
            return;
        }
        // RFC 7233 2.1: a window that runs past EOF is clamped to the file, so Content-Length and
        // Content-Range describe the bytes that exist. A window ending exactly at EOF is unchanged.
        $bodyLen = min($bodyLen, $fileSize - $offset);
        $response->withHeaders([
            'Content-Length' => $bodyLen,
            'Accept-Ranges' => 'bytes',
        ]);
        if ($offset || $length) {
            $offsetEnd = $offset + $bodyLen - 1;
            $response->header('Content-Range', "bytes $offset-$offsetEnd/$fileSize");
            $response->withStatus(206);
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false || ($offset !== 0 && fseek($handle, $offset) !== 0)) {
            if ($handle !== false) {
                fclose($handle);
            }
            $connection->close(new WorkermanResponse(403, [], '403 Forbidden'));
            return;
        }

        if ($connection->send((string) $response, true) === false) {
            // Same as encode(): a failed header write is left to Workerman's own disconnect
            // handling. Nothing else is written and the connection stays as it is.
            fclose($handle);
            return;
        }
        self::stream($connection, $handle, $bodyLen);
    }

    /**
     * Answer 416 for a window that starts at or past EOF. No body and no file header: the
     * reply is a plain bodyless response, so the connection stays usable.
     *
     * @param TcpConnection $connection The client connection.
     * @param WorkermanResponse $response The file-backed response being replaced; its headers
     *                                    (CORS, caching, decorations) are kept.
     * @param int $fileSize The file size, reported in Content-Range.
     */
    private static function rangeNotSatisfiable(
        TcpConnection $connection,
        WorkermanResponse $response,
        int $fileSize,
    ): void {
        $reply = new WorkermanResponse(416, $response->getHeaders(), '');
        // Assign rather than merge: the copied headers may already carry these, and withHeaders()
        // merges recursively, which would emit a second line for each. Any copied Content-Length is
        // dropped and NOT replaced: Response::__toString() always appends its own "Content-Length: 0"
        // for the empty body, so a mapped copy here would print a second line.
        $reply->header('Accept-Ranges', 'bytes');
        $reply->header('Content-Range', "bytes */$fileSize");
        $reply->withoutHeader('Content-Length');
        $connection->send((string) $reply, true);
    }

    /**
     * Start the body stream: install the buffer callbacks, then write the first chunks.
     *
     * @param TcpConnection $connection The client connection.
     * @param resource $handle The open file, positioned at the window offset.
     * @param int $bodyLen Bytes to send, i.e. the advertised Content-Length.
     */
    private static function stream(TcpConnection $connection, mixed $handle, int $bodyLen): void
    {
        $state = new stdClass();
        $state->handle = $handle;
        $state->remaining = $bodyLen;

        if ($connection->context === null) {
            $connection->context = new stdClass();
        }
        $connection->context->bufferFull = false;
        $connection->context->streamSending = true;
        // Parity with Workerman's sendStream(): a pipelined request on this keep-alive connection
        // that starts another stream replaces these two callbacks while this one is paused, so this
        // body is never resumed and a pipelining client can truncate its own response. The logic is
        // kept as it is; whether to change it is the owner's decision.
        $connection->onBufferFull = static function (TcpConnection $c): void {
            if ($c->context !== null) {
                $c->context->bufferFull = true;
            }
        };
        $connection->onBufferDrain = static function (TcpConnection $c) use ($state): void {
            if ($c->context !== null) {
                $c->context->bufferFull = false;
            }
            self::pump($c, $state);
        };

        self::pump($connection, $state);
    }

    /**
     * Write body chunks until the send buffer is full, the window is done, or the file ends.
     *
     * The handle stays open only while the buffer is full and the drain callback is pending.
     * Every other exit, including an exception from send(), releases the handle and clears
     * the callbacks in the finally block.
     *
     * @param TcpConnection $connection The client connection.
     * @param stdClass $state Holds the open handle and the bytes still to send.
     */
    private static function pump(TcpConnection $connection, stdClass $state): void
    {
        $paused = false;
        try {
            while ($connection->context?->bufferFull === false) {
                if ($state->remaining <= 0) {
                    return;
                }
                $chunk = is_resource($state->handle)
                    ? fread($state->handle, min(self::CHUNK_SIZE, $state->remaining))
                    : false;
                if ($chunk === false || $chunk === '') {
                    // The file is shorter than the Content-Length already sent: the framing is
                    // lost, so drop the connection rather than leave the client waiting.
                    $connection->close();
                    return;
                }
                $state->remaining -= strlen($chunk);
                if ($connection->send($chunk, true) === false) {
                    return;
                }
            }
            // Buffer full: onBufferDrain resumes the stream, so the handle must stay open.
            $paused = true;
        } finally {
            if (!$paused) {
                self::finish($connection, $state);
            }
        }
    }

    /**
     * Release the handle and clear the buffer callbacks.
     *
     * @param TcpConnection $connection The client connection.
     * @param stdClass $state Stream state whose handle is closed.
     */
    private static function finish(TcpConnection $connection, stdClass $state): void
    {
        if (is_resource($state->handle)) {
            fclose($state->handle);
        }
        $state->handle = null;
        $connection->onBufferDrain = null;
        $connection->onBufferFull = null;
        if ($connection->context !== null) {
            $connection->context->streamSending = false;
        }
    }
}
