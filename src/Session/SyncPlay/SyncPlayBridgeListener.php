<?php

/**
 * Phlix media server component: SyncPlay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Session\SyncPlay;

use Phlix\Common\Logger\StructuredLogger;
use Workerman\Events\EventInterface;

/**
 * SyncPlayBridgeListener - the WS-side half of the S445 write-through publish
 * transport (lives ONLY in the single authoritative WebSocket worker; the WS
 * worker stays a non-DB-reader — it ingests REST mutations as bridge frames).
 *
 * Binds a private unix socket (0600 — same-user supervisor topology; no new
 * public port), accepts one NDJSON frame per short-lived connection, validates
 * it through {@see SyncPlayBridge::parse()} (op allowlist, version, token,
 * shape — malformed or unauthorized lines are dropped with a warning, never
 * partially trusted), and hands the typed frame to the applier
 * (`SyncPlayManager::applyBridgeFrame()` in production).
 *
 * The read core is loop-agnostic: `poll()` drains whatever is pending without
 * a loop (tests, the smoke script), while `attachToLoop()` registers the same
 * internals as `onReadable` callbacks on the worker's live Workerman event
 * loop (`Worker::getEventLoop()` — verified non-null inside onWorkerStart;
 * the Swoole loop implementation registers arbitrary streams via Swoole\Event).
 *
 * @package Phlix\Session\SyncPlay
 * @since 3.5
 */
final class SyncPlayBridgeListener
{
    private string $socketPath;

    /** @var resource|null */
    private $server = null;

    /** @var callable(array<string, mixed>): void|null */
    private $applier = null;

    /** @var array<int, resource> connected publisher streams by resource id */
    private array $clients = [];

    /** @var array<int, string> per-client partial-line buffers */
    private array $buffers = [];

    /** @var array<int, true> streams currently registered on the loop */
    private array $loopRegistered = [];

    private ?EventInterface $loop = null;

    private ?StructuredLogger $logger;

    public function __construct(?string $socketPath = null, ?StructuredLogger $logger = null)
    {
        $this->socketPath = $socketPath ?? SyncPlayBridge::defaultSocketPath();
        $this->logger = $logger;
    }

    /**
     * Route validated frames to the live-state applier.
     *
     * @param callable(array<string, mixed>): void $applier
     */
    public function setApplier(callable $applier): void
    {
        $this->applier = $applier;
    }

    public function socketPath(): string
    {
        return $this->socketPath;
    }

    public function isListening(): bool
    {
        return $this->server !== null;
    }

    /**
     * Bind the unix socket, clearing a stale socket file left by a dead worker.
     *
     * @throws \RuntimeException when another LIVE listener already owns the path
     *                           or the bind itself fails
     */
    public function listen(): void
    {
        if ($this->server !== null) {
            return;
        }

        if (file_exists($this->socketPath)) {
            if ($this->probeLiveListener()) {
                throw new \RuntimeException(
                    "SyncPlayBridgeListener: a live listener already owns {$this->socketPath}"
                );
            }
            @unlink($this->socketPath);
        }

        $dir = dirname($this->socketPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if ($server === false) {
            throw new \RuntimeException(
                "SyncPlayBridgeListener: cannot bind {$this->socketPath}: ({$errno}) {$errstr}"
            );
        }

        // Same-user auth boundary: owner-only access to the socket file.
        @chmod($this->socketPath, 0600);
        stream_set_blocking($server, false);

        $this->server = $server;
        $this->logger?->info('[SyncPlayBridge] listener bound', ['socket' => $this->socketPath]);
    }

    /**
     * Drain pending accepts/reads once, without an event loop.
     *
     * @param int $maxFrames frame cap per call (a flood can never starve the loop)
     * @return int number of frames handed to the applier
     */
    public function poll(int $maxFrames = 64): int
    {
        if ($this->server === null) {
            return 0;
        }

        $applied = 0;

        foreach ($this->acceptPending() as $id) {
            $applied += $this->consume($id);
        }

        foreach ($this->clients as $id => $client) {
            if (!isset($this->loopRegistered[$id])) {
                // Non-loop venue: readiness-probe each client with a zero-timeout select.
                $read = [$client];
                $write = null;
                $except = null;
                if (@stream_select($read, $write, $except, 0) <= 0) {
                    continue;
                }
            }
            if (isset($this->clients[$id])) {
                $applied += $this->consume($id);
            }
            if ($applied >= $maxFrames) {
                break;
            }
        }

        return $applied;
    }

    /**
     * Register the listener on a Workerman event loop (the WS worker's live one).
     *
     * The caller passes `$worker->getEventLoop()` from inside `onWorkerStart`;
     * the static is null pre-fork (the vendor phpdoc under-types it), so the
     * parameter stays nullable and null simply declines registration — the
     * caller then drives poll() from a Timer.
     *
     * @return bool false when not attached (server not listening yet, or no loop)
     */
    public function attachToLoop(?EventInterface $loop): bool
    {
        if ($this->server === null || $loop === null) {
            return false;
        }
        $this->loop = $loop;

        $server = $this->server;
        $this->loopRegistered[(int) $server] = true;
        $loop->onReadable($server, function (): void {
            foreach ($this->acceptPending() as $id) {
                $this->consume($id);
            }
        });

        return true;
    }

    /**
     * Close the listener and every open publisher connection.
     */
    public function close(): void
    {
        foreach (array_keys($this->clients) as $id) {
            $this->dropClient($id);
        }

        if ($this->server !== null) {
            // Hand the property back to null BEFORE closing: a closed resource
            // must never be the property's value, not even for a statement
            // (Psalm models fclose() as writing closed-resource through the
            // property type; the ordering is also just honest).
            $server = $this->server;
            $this->server = null;
            if ($this->loop !== null) {
                $this->loop->offReadable($server);
            }
            @fclose($server);
        }

        if ($this->socketPath !== '' && file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    /**
     * Accept all currently pending connections; each becomes a tracked client.
     *
     * @return list<int> client resource ids
     */
    private function acceptPending(): array
    {
        $accepted = [];
        if ($this->server === null) {
            return $accepted;
        }

        while (true) {
            $client = @stream_socket_accept($this->server, 0);
            if ($client === false) {
                break;
            }

            stream_set_blocking($client, false);
            stream_set_read_buffer($client, 0);

            $id = (int) $client;
            $this->clients[$id] = $client;
            $accepted[] = $id;

            if ($this->loop !== null) {
                $this->loopRegistered[$id] = true;
                $this->loop->onReadable($client, function () use ($id): void {
                    $this->consume($id);
                });
            }
        }

        return $accepted;
    }

    /**
     * Read everything pending from one client and dispatch complete lines.
     *
     * @param int $id client resource id
     * @return int frames applied
     */
    private function consume(int $id): int
    {
        $stream = $this->clients[$id] ?? null;
        if ($stream === null) {
            return 0;
        }

        $applied = 0;
        while (true) {
            $chunk = @fread($stream, 65535);
            if ($chunk === false || $chunk === '') {
                // EOF (client closed after the line) vs. a drained non-blocking
                // read: the stream metadata distinguishes them.
                if (stream_get_meta_data($stream)['eof']) {
                    $this->dropClient($id);
                }
                break;
            }

            if (strlen($this->buffers[$id] ?? '') + strlen($chunk) > SyncPlayBridge::MAX_LINE_BYTES) {
                $this->warn('dropping oversized bridge stream', ['bytes' => strlen($chunk)]);
                $this->dropClient($id);
                break;
            }

            $this->buffers[$id] = ($this->buffers[$id] ?? '') . $chunk;

            while (true) {
                $newline = strpos($this->buffers[$id], "\n");
                if ($newline === false) {
                    break;
                }
                $line = substr($this->buffers[$id], 0, $newline);
                $this->buffers[$id] = substr($this->buffers[$id], $newline + 1);
                $applied += $this->dispatch($line) ? 1 : 0;
            }
        }

        return $applied;
    }

    /**
     * Parse one NDJSON line and hand the typed frame to the applier.
     */
    private function dispatch(string $line): bool
    {
        $frame = SyncPlayBridge::parse($line);
        if ($frame === null) {
            $this->warn('dropped malformed/unauthorized bridge line', ['length' => strlen(trim($line))]);

            return false;
        }

        $applier = $this->applier;
        if ($applier === null) {
            $this->warn('dropped bridge frame: no applier wired');

            return false;
        }

        try {
            $applier($frame);
        } catch (\Throwable $e) {
            $this->warn('bridge applier threw', ['error' => $e->getMessage(), 'op' => $frame['op']]);

            return false;
        }

        return true;
    }

    /**
     * Close one client and forget its buffer/loop registration.
     */
    private function dropClient(int $id): void
    {
        $client = $this->clients[$id] ?? null;
        if ($client !== null) {
            if ($this->loop !== null && isset($this->loopRegistered[$id])) {
                $this->loop->offReadable($client);
            }
            @fclose($client);
        }
        unset($this->clients[$id], $this->buffers[$id], $this->loopRegistered[$id]);
    }

    /**
     * Is another live process already accepting on this socket path?
     */
    private function probeLiveListener(): bool
    {
        $errno = 0;
        $errstr = '';
        $probe = @stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, 0.25);
        if ($probe === false) {
            return false;
        }
        @fclose($probe);

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warn(string $message, array $context = []): void
    {
        $this->logger?->warning("[SyncPlayBridge] {$message}", $context);
    }
}
