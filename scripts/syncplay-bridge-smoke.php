<?php

/**
 * S445 write-through bridge — TRUE two-process live smoke.
 *
 * Fakes exactly one thing phpunit cannot: the process boundary. A real fork
 * makes the child the WS worker (listener + authoritative manager, draining
 * on its own clock) and the parent an HTTP worker (the publisher half of the
 * write-through rail). The frame crosses a real kernel unix socket between
 * two real processes; the child asserts the bridge-applied room on its
 * SERVED surface (the production `syncplay_group_list` routing over a stub
 * connection), prints the verdict, and exits with it.
 *
 * No database is involved on either side (the WS worker is a non-reader by
 * ruling; the parent publishes a hand-built GroupState the way the controller
 * rail would after persisting). The persisted-rail leg lives in
 * tests/Integration/Session/SyncPlay/SyncPlayWriteThroughBridgeTest.php.
 *
 * Usage: php scripts/syncplay-bridge-smoke.php [socket-path]
 * Exit: 0 PASS / 1 FAIL (loud, never silent).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phlix\Server\WebSocket\ConnectionInterface;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\SyncPlayBridgeListener;
use Phlix\Session\SyncPlay\SyncPlayBridgePublisher;
use Phlix\Session\SyncPlay\SyncPlayManager;

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "FAIL: pcntl_fork unavailable — cannot run the two-process smoke here (record-relied-on-CI).\n");
    exit(1);
}

$socket = $argv[1] ?? (sys_get_temp_dir() . '/syncplay-bridge-smoke-' . getmypid() . '.sock');
@unlink($socket);

$groupId = 'sp_' . bin2hex(random_bytes(8));

$pid = pcntl_fork();
if ($pid < 0) {
    fwrite(STDERR, "FAIL: fork failed\n");
    exit(1);
}

if ($pid === 0) {
    // ---- CHILD = the WS worker: listener + live manager, NO DB anywhere. ----
    $wsManager = new SyncPlayManager();
    try {
        $listener = new SyncPlayBridgeListener($socket);
        $listener->setApplier(static function (array $frame) use ($wsManager): void {
            $wsManager->applyBridgeFrame($frame);
        });
        $listener->listen();
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL [ws-child]: listener: {$e->getMessage()}\n");
        exit(1);
    }

    $fail = static function (string $msg) use ($listener): never {
        $listener->close();
        fwrite(STDERR, "FAIL [ws-child]: {$msg}\n");
        exit(1);
    };

    // SERVED-STATE surface: production group_list routing over a stub conn.
    $connection = new class implements ConnectionInterface {
        /** @var list<array<string, mixed>> */
        private array $sent = [];

        /**
         * Drain the captured frames (also serves as the reset between probes).
         *
         * @return list<array<string, mixed>>
         */
        public function sentFrames(): array
        {
            $frames = $this->sent;
            $this->sent = [];

            return $frames;
        }

        private bool $authenticated = true;

        private ?string $userId = 'smoke-member';

        public function getId(): string
        {
            return 'smoke-probe';
        }

        public function send(string|array $data): bool
        {
            $decoded = is_string($data) ? json_decode($data, true) : $data;
            $this->sent[] = is_array($decoded) ? $decoded : ['raw' => $data];

            return true;
        }

        public function sendMessage(string $type, array $data = []): void
        {
            $this->sent[] = ['type' => $type, 'data' => $data];
        }

        public function close(): void
        {
        }

        public function updateActivity(): void
        {
        }

        public function getLastActivity(): int
        {
            return time();
        }

        public function isAuthenticated(): bool
        {
            return $this->authenticated;
        }

        public function setAuthenticated(bool $authenticated, ?string $userId = null): void
        {
            $this->authenticated = $authenticated;
            $this->userId = $userId;
        }

        public function getUserId(): ?string
        {
            return $this->userId;
        }

        public function setSessionId(?string $sessionId): void
        {
        }

        public function getSessionId(): ?string
        {
            return null;
        }

        public function set(string $key, mixed $value): void
        {
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $default;
        }

        public function has(string $key): bool
        {
            return false;
        }

        public function remove(string $key): void
        {
        }

        /**
         * @return array<string, mixed>
         */
        public function getAll(): array
        {
            return [];
        }
    };

    $handle = new ReflectionMethod(SyncPlayManager::class, 'handleMessage');
    $handle->setAccessible(true);

    /**
     * Read the served room table RIGHT NOW (newest captured frame).
     *
     * @return list<string>
     */
    $servedRoomIds = static function () use ($handle, $wsManager, $connection): array {
        $connection->sentFrames();
        $handle->invoke($wsManager, $connection, ['type' => Messages::TYPE_GROUP_LIST]);
        $latest = null;
        foreach ($connection->sentFrames() as $candidate) {
            if (($candidate['type'] ?? null) === Messages::TYPE_GROUP_LIST) {
                $latest = $candidate;
            }
        }

        if ($latest === null) {
            return [];
        }

        $rows = is_array($latest['groups'] ?? null) ? $latest['groups'] : [];
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_scalar($row['id'])) {
                $ids[] = (string) $row['id'];
            }
        }

        return $ids;
    };

    $deadline = microtime(true) + 10.0;
    while ($servedRoomIds() === [] && microtime(true) < $deadline) {
        $listener->poll(64);
        usleep(20_000);
    }

    $afterUpsert = $servedRoomIds();
    if (!in_array($groupId, $afterUpsert, true)) {
        $fail('served group_list after the upsert frame must contain the published room; got ' . json_encode($afterUpsert));
    }

    // Now wait for the delete frame to land on the same live manager.
    $deadline = microtime(true) + 10.0;
    while (in_array($groupId, $servedRoomIds(), true) && microtime(true) < $deadline) {
        $listener->poll(64);
        usleep(20_000);
    }
    $afterDelete = $servedRoomIds();
    if (in_array($groupId, $afterDelete, true)) {
        $fail('served group_list must NOT contain the room after the delete frame');
    }

    $listener->close();
    echo "[ws-child] served_after_upsert=" . json_encode($afterUpsert)
        . " served_after_delete=" . json_encode($afterDelete) . "\n";
    echo "[ws-child] PASS\n";
    exit(0);
}

// ---- PARENT = an HTTP worker: the publisher half of the write-through rail. ----
// Wait for the child to bind (bounded).
$deadline = microtime(true) + 10.0;
while (!file_exists($socket) && microtime(true) < $deadline) {
    usleep(20_000);
}
if (!file_exists($socket)) {
    fwrite(STDERR, "FAIL [http-parent]: child never bound {$socket}\n");
    posix_kill($pid, SIGTERM);
    exit(1);
}
usleep(200_000);

$group = GroupState::deserialize([
    'id' => $groupId,
    'name' => 'Two-Process Smoke Room',
    'members' => ['u1' => ['name' => 'Solo', 'connection_id' => null, 'joined_at' => time(), 'is_active' => true]],
    'host_id' => 'u1',
]);

$publisher = new SyncPlayBridgePublisher($socket, 250);
$upserted = $publisher->publishUpsert($group);
// Hand the child a beat between frames so ordering is deterministic in the log.
usleep(300_000);
$deleted = $publisher->publishDelete($groupId);

if (!$upserted || !$deleted) {
    fwrite(STDERR, "FAIL [http-parent]: publish upsert=" . var_export($upserted, true)
        . " delete=" . var_export($deleted, true) . "\n");
    posix_kill($pid, SIGTERM);
    exit(1);
}
echo "[http-parent] published upsert({$groupId}) + delete to {$socket} across a REAL process boundary\n";

$status = pcntl_wait($waitStatus);
$code = pcntl_wexitstatus($waitStatus);
echo "[http-parent] child pid={$status} exit={$code}\n";
@unlink($socket);

if ($code !== 0) {
    fwrite(STDERR, "FAIL: two-process smoke child exited {$code}\n");
    exit(1);
}
echo "PASS: S445 write-through bridge live across two real processes\n";
exit(0);
