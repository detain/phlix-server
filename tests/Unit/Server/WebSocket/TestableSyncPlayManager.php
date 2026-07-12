<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use Phlix\Server\WebSocket\ConnectionInterface;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Session\SyncPlay\SyncPlayManager;

/**
 * {@see SyncPlayManager} double that exposes the protected `handleMessage()`
 * as public so the authentication paths can be driven directly in tests.
 *
 * @internal For testing only
 */
class TestableSyncPlayManager extends SyncPlayManager
{
    public function __construct(MessageHandler $handler)
    {
        parent::__construct();
        $this->initialize($handler);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publicHandleMessage(ConnectionInterface $connection, array $payload): void
    {
        $this->handleMessage($connection, $payload);
    }
}
