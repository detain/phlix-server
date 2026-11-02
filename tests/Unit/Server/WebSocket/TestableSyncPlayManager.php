<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use Phlix\Auth\UserRepository;
use Phlix\Server\WebSocket\ConnectionInterface;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\SyncPlayManager;

/**
 * {@see SyncPlayManager} double that exposes the protected `handleMessage()`
 * as public so the authentication paths can be driven directly in tests.
 *
 * @internal For testing only
 */
class TestableSyncPlayManager extends SyncPlayManager
{
    /**
     * Frozen clock for the playback_sync echo path (incident playjump cure
     * tests); null keeps the production uniform Messages::nowMs() clock.
     */
    public ?int $syncNowMsOverride = null;

    public function __construct(MessageHandler $handler, ?UserRepository $adminUsers = null)
    {
        parent::__construct(adminUsers: $adminUsers);
        $this->initialize($handler);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publicHandleMessage(ConnectionInterface $connection, array $payload): void
    {
        $this->handleMessage($connection, $payload);
    }

    protected function syncNowMs(): int
    {
        return $this->syncNowMsOverride ?? Messages::nowMs();
    }
}
