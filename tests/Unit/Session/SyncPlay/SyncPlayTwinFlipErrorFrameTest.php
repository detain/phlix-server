<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Tests\Support\Contracts\ErrorCodeScan;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;

/**
 * SyncPlay reserved-twin FLIP — wire evidence for the three fallback wraps.
 *
 * The coarse SyncPlay WS fallbacks used to emit the SCREAMING aliases
 * CREATE_FAILED / JOIN_FAILED / LEAVE_FAILED. Those aliases were reserved
 * dotted twins in the @phlix/contracts registry (v0.5.1), every client maps
 * BOTH shapes to the same key (console #167, roku #90), so the server flipped
 * the three literals to `syncplay.create_failed` / `syncplay.join_failed` /
 * `syncplay.leave_failed`. The human `message` prose is byte-identical before
 * and after — only the machine code value changed.
 *
 * The create/join fallback arms are defensive (every current in-manager failure
 * already carries a specific code, so the `??` never fires on those paths); the
 * emit law in ErrorCodesContractTest pins their literals to the registry
 * statically. The leave fallback IS reachable (an authenticated member with no
 * group), so it is pinned here on the wire, together with the promote arm that
 * keeps the specific twin winning.
 *
 * Legacy note for readers: old servers speak SCREAMING and pre-flip clients must
 * keep accepting it — that guarantee lives in the CLIENT repos' mappings (both
 * shapes → one key), not in this server's emission set.
 */
final class SyncPlayTwinFlipErrorFrameTest extends TestCase
{
    /** The three SCREAMING aliases that are legacy wire values as of this flip. */
    private const LEGACY_SCREAMING_TRIO = ['CREATE_FAILED', 'JOIN_FAILED', 'LEAVE_FAILED'];

    /** The three dotted twins the server now emits as its coarse fallbacks. */
    private const DOTTED_TRIO = ['syncplay.create_failed', 'syncplay.join_failed', 'syncplay.leave_failed'];

    private function createManager(): TestableSyncPlayManager
    {
        return new TestableSyncPlayManager(new MessageHandler(ConnectionPool::getInstance()));
    }

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionPool::getInstance()->clear();
    }

    /**
     * Reachable fallback frame: group_leave from an authenticated member who is
     * in no group. This is THE frame whose error_code flipped from the SCREAMING
     * alias to the dotted twin; the message prose is pinned byte-identical.
     */
    public function testLeaveFallbackFrameCarriesTheDottedTwinAndIdenticalProse(): void
    {
        $manager = $this->createManager();
        $connection = new TestConnection('twinflip-leave-conn');
        $connection->setAuthenticated(true, 'twinflip-leave-member');

        $manager->publicHandleMessage($connection, ['type' => Messages::TYPE_GROUP_LEAVE]);

        $frames = $connection->framesOfType(Messages::TYPE_ERROR);
        $this->assertCount(1, $frames, 'Leave with no group must produce exactly one error frame');

        $frame = $frames[0];
        $this->assertSame(
            'syncplay.leave_failed',
            $frame['error_code'] ?? null,
            'The leave fallback must now speak the dotted registry twin'
        );
        $this->assertSame(
            'Not in any group',
            $frame['message'] ?? null,
            'Message prose is byte-identical across the flip — only the code changed'
        );
        $this->assertSame(Messages::PROTOCOL_VERSION, $frame['protocol_version'] ?? null);
    }

    /**
     * The promote arm of the `??` wrap still forwards the specific twin: the
     * coarse fallback fires only when the result carries no code, so this frame
     * proves the fallback remains a fallback after the flip.
     */
    public function testJoinMissingGroupStillPromotesTheSpecificTwin(): void
    {
        $manager = $this->createManager();
        $connection = new TestConnection('twinflip-join-conn');
        $connection->setAuthenticated(true, 'twinflip-join-member');

        $manager->publicHandleMessage($connection, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => 'twinflip-no-such-group',
            'member_name' => 'Late Guest',
        ]);

        $frames = $connection->framesOfType(Messages::TYPE_ERROR);
        $this->assertCount(1, $frames);
        $this->assertSame(
            'syncplay.group_not_found',
            $frames[0]['error_code'] ?? null,
            'A specific registered code must still win over the coarse join fallback'
        );
        $this->assertSame('Group not found', $frames[0]['message'] ?? null);
    }

    /**
     * Legacy-shape guard (labeled as such): the SCREAMING trio is the shape OLD
     * servers speak — this server must no longer place any of the three aliases
     * on a code channel. The positional emit scan is the same machinery the
     * registry law uses, so a re-introduced SCREAMING literal reddens here.
     */
    public function testScreamingWrapTrioIsNoLongerEmittedFromThisServer(): void
    {
        $emitted = [];
        foreach (ErrorCodeScan::run(dirname(__DIR__, 4) . '/src') as $site) {
            if ($site['kind'] === 'literal' || $site['kind'] === 'const') {
                $emitted[] = $site['value'];
            }
        }

        foreach (self::LEGACY_SCREAMING_TRIO as $legacy) {
            $this->assertNotContains(
                $legacy,
                $emitted,
                "'{$legacy}' is the pre-flip wire shape — this server must speak only the dotted twin"
            );
        }
    }

    /**
     * Positive anchor of the flip: each dotted twin must actually sit on a code
     * channel in src/ (the two `??` wraps and the leave wrap), so the law above
     * cannot pass vacuously by the fallbacks simply vanishing.
     */
    public function testEachDottedTwinIsAnEmittedLiteral(): void
    {
        $emitted = [];
        foreach (ErrorCodeScan::run(dirname(__DIR__, 4) . '/src') as $site) {
            if ($site['kind'] === 'literal' || $site['kind'] === 'const') {
                $emitted[$site['value']] = true;
            }
        }

        foreach (self::DOTTED_TRIO as $twin) {
            $this->assertArrayHasKey(
                $twin,
                $emitted,
                "the flip is only real if '{$twin}' is actually on a code channel"
            );
        }
    }
}
