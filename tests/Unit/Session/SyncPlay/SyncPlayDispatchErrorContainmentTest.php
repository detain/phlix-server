<?php

/**
 * Phlix media server component: Unit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;

/**
 * R17-03 containment law for the SyncPlay dispatch catch-all (review
 * server__secnet, SRV-M21).
 *
 * `SyncPlayManager::handleMessage()` wraps every subhandler dispatch in one
 * try/catch. Until SRV-M21 that arm sent `$e->getMessage()` verbatim to the
 * sending peer — the exact leak the sibling layer
 * `MessageHandler::reportHandlerFailure()` exists to prevent ("the wire never
 * carries exception text"), because exception strings in this codebase class
 * carry SQL fragments, file paths, and other users' data. Every SyncPlay
 * subhandler is private and wrapped by this inner catch, so the guarded
 * outer layer never sees them. Within the SyncPlay WebSocket dispatch,
 * this catch was that surface's last raw-leak arm.
 *
 * The canary below throws a deliberately secret-shaped message from the
 * lazy admin probe inside `visibleGroupSummaries()` (the only deterministic
 * throw vector reachable through a normal, protocol-valid frame), then pins
 * that the wire carries the fixed generic sentence and NOTHING of the canary,
 * while the error_code stays the stable `HANDLER_ERROR`.
 *
 * Mutation-toothed: restoring `$e->getMessage()` in the catch reddens both
 * tests (wrong sentence / canary leak); dropping the fixed code from the
 * frame would be caught by the shape arm.
 *
 * @package Phlix\Tests\Unit\Session\SyncPlay
 */
class SyncPlayDispatchErrorContainmentTest extends TestCase
{
    /** The secret-shaped canary text that must never reach the wire. */
    private const CANARY = 'SQL[1064] near \'/etc/phlix/secret\' for user bob@example.com';

    /** The fixed generic sentence the guarded layer standardizes on. */
    private const GENERIC = 'An internal error occurred while processing this message.';

    protected function tearDown(): void
    {
        ConnectionPool::getInstance()->clear();
    }

    public function testThrowingSubhandlerAnswersFixedGenericFrameNotExceptionText(): void
    {
        $manager = $this->managerWithThrowingAdminProbe();
        $viewer = new TestConnection('conn-viewer');
        $viewer->setAuthenticated(true, 'member-viewer');

        $manager->publicHandleMessage($viewer, ['type' => Messages::TYPE_GROUP_LIST]);

        $frames = $viewer->getSentMessages();
        $this->assertCount(1, $frames, 'the dispatch error arm must answer exactly one frame');

        $frame = $frames[0];
        $this->assertSame(Messages::TYPE_ERROR, $frame['type']);
        $this->assertSame('HANDLER_ERROR', $frame['error_code']);
        $this->assertSame(
            self::GENERIC,
            $frame['message'],
            'the wire sentence must be the fixed generic copy, byte-identical to '
                . 'MessageHandler::reportHandlerFailure()\'s — never exception text'
        );
        // JSON_UNESCAPED_SLASHES is load-bearing for the pin's teeth: the
        // default encoder escapes the canary's slashes (`\/etc\/...`), so a
        // raw-string needle could never match a leaked frame and this arm
        // would pass pre-cure — measured at the parent before the flags
        // were fixed.
        $this->assertStringNotContainsString(
            self::CANARY,
            json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'R17-03: exception text must never cross the wire boundary'
        );
    }

    public function testWholeFrameCarriesNoTraceOfTheCanaryText(): void
    {
        $manager = $this->managerWithThrowingAdminProbe();
        $viewer = new TestConnection('conn-viewer');
        $viewer->setAuthenticated(true, 'member-viewer');

        $manager->publicHandleMessage($viewer, ['type' => Messages::TYPE_GROUP_LIST]);

        $this->assertStringNotContainsString(
            self::CANARY,
            json_encode($viewer->getSentMessages(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'no frame in the whole exchange may contain the canary — the leak was the '
                . 'message slot, and the cure must keep every slot clean'
        );
    }

    /**
     * A manager wired with one foreign room and an admin repository whose
     * lazy `findAdminById()` probe (reached only through the group_list
     * visibility filter) throws the canary — a protocol-valid request then
     * deterministically exercises the dispatch catch-all.
     */
    private function managerWithThrowingAdminProbe(): TestableSyncPlayManager
    {
        $adminUsers = $this->createMock(UserRepository::class);
        $adminUsers->method('findAdminById')
            ->willThrowException(new \RuntimeException(self::CANARY));

        $manager = new TestableSyncPlayManager(
            new MessageHandler(ConnectionPool::getInstance()),
            $adminUsers
        );
        $manager->createGroup('Foreign Room', null, 'member-host', 'Host');

        return $manager;
    }
}
