<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\Connection;
use Phlix\Server\WebSocket\ConnectionInterface;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Server\WebSocket\SyncPlay\SyncPlayRoom;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;
use Workerman\Connection\TcpConnection;

/**
 * S417 per-frame outbound guard.
 *
 * Every SyncPlay frame that leaves the server must be stamped by the
 * Messages factory, i.e. carry `protocol_version: 1` and a MILLISECOND
 * `timestamp`, flat (payload keys at top level, no `data` wrapper).
 * Before S417 the emit sites hand-merged `['timestamp' => time()]`
 * (seconds, no protocol version) around the choke points, bypassing the
 * factory whose shape MessagesFrameShapeTest pins.
 *
 * Each outbound site named in the S417 enumeration gets its own test that
 * drives the REAL codepath and asserts the captured frame conforms: re-wire
 * any one of them to a hand-built frame and its test turns red. The source
 * tripwires at the bottom are the second net — they re-blacklist the exact
 * bypass spellings that produced the bug class.
 */
final class OutboundFrameShapeGuardTest extends TestCase
{
    /**
     * Sentinel proving this guard file is the code home of the S417 audit.
     */
    public const GUARD_TOKEN = 'S417FRAMESHAPEX9Q1';

    /**
     * Anything below this is a seconds-based timestamp, not milliseconds.
     */
    private const MIN_MILLIS = 1_000_000_000_000;

    private TestableSyncPlayManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        $this->manager = new TestableSyncPlayManager(new MessageHandler($pool));
    }

    protected function tearDown(): void
    {
        ConnectionPool::getInstance()->clear();
        parent::tearDown();
    }

    public function testGuardTokenIsCodeResident(): void
    {
        $this->assertSame('S417FRAMESHAPEX9Q1', self::GUARD_TOKEN);
    }

    // ── SyncPlayManager broadcastToGroup choke (11 call sites) ───────────

    public function testSite01JoinNoticeFrameConforms(): void
    {
        [$a] = $this->seedTwoMembers();

        $frame = $this->latestFrame($a, Messages::TYPE_INFO, 'site01-join-notice');
        $this->assertFrameConforms($frame, 'site01-join-notice (SyncPlayManager join notice)');
        $this->assertArrayHasKey('member_id', $frame);
        $this->assertArrayHasKey('member_name', $frame);
    }

    public function testSite14GroupStateOnCreateFrameConforms(): void
    {
        [$a] = $this->seedTwoMembers();

        $frame = $this->latestFrame($a, Messages::TYPE_GROUP_STATE, 'site14-group-state-create');
        $this->assertFrameConforms($frame, 'site14 (SyncPlayManager group_state on create)');
        $this->assertArrayHasKey('your_id', $frame);
    }

    public function testSite15GroupStateOnJoinFrameConforms(): void
    {
        [, $b] = $this->seedTwoMembers();

        $frame = $this->latestFrame($b, Messages::TYPE_GROUP_STATE, 'site15-group-state-join');
        $this->assertFrameConforms($frame, 'site15 (SyncPlayManager group_state on join)');
        $this->assertArrayHasKey('your_id', $frame);
    }

    public function testSite02HostElectFrameConforms(): void
    {
        [$a, $b] = $this->seedTwoMembers();

        // Host leaving an occupied group elects the survivor.
        $this->manager->publicHandleMessage($a, ['type' => Messages::TYPE_GROUP_LEAVE]);

        $frame = $this->latestFrame($b, Messages::TYPE_HOST_ELECT, 'site02-host-elect');
        $this->assertFrameConforms($frame, 'site02 (SyncPlayManager host_elect)');
        $this->assertArrayHasKey('elected_id', $frame);
    }

    public function testSite16LeaveAckFrameIsFlatCanonical(): void
    {
        [$a] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($a, ['type' => Messages::TYPE_GROUP_LEAVE]);

        $frame = $this->latestFrame($a, Messages::TYPE_INFO, 'site16-leave-ack');
        // Pre-S417 this ack was a nested sendMessage envelope.
        $this->assertFrameConforms($frame, 'site16 (SyncPlayManager leave ack)');
        $this->assertArrayHasKey('message', $frame);
    }

    public function testSite03PlayAckAndBroadcastFramesConform(): void
    {
        [$a, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_PLAYBACK_PLAY,
            'position' => 1000,
        ]);

        $ack = $this->latestFrame($a, Messages::TYPE_PLAYBACK_PLAY, 'site03-play-ack');
        $this->assertFrameConforms($ack, 'site03 (SyncPlayManager play ack)');

        $echo = $this->latestFrame($b, Messages::TYPE_PLAYBACK_PLAY, 'site04-play-broadcast');
        $this->assertFrameConforms($echo, 'site04 (SyncPlayManager playback_play broadcast)');
        $this->assertSame('user-a', $echo['member_id'] ?? null);
    }

    public function testSite05PauseBroadcastFrameConforms(): void
    {
        [$a, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_PLAYBACK_PAUSE,
            'position' => 2000,
        ]);

        $frame = $this->latestFrame($b, Messages::TYPE_PLAYBACK_PAUSE, 'site05-pause');
        $this->assertFrameConforms($frame, 'site05 (SyncPlayManager playback_pause broadcast)');
    }

    public function testSite06SeekBroadcastFrameConforms(): void
    {
        [$a, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_PLAYBACK_SEEK,
            'from_position' => 1000,
            'to_position' => 5000,
        ]);

        $frame = $this->latestFrame($b, Messages::TYPE_PLAYBACK_SEEK, 'site06-seek');
        $this->assertFrameConforms($frame, 'site06 (SyncPlayManager playback_seek broadcast)');
    }

    public function testSite07QueueBroadcastFrameConforms(): void
    {
        [$a, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_PLAYBACK_QUEUE,
            'queue' => ['media-1', 'media-2'],
        ]);

        $frame = $this->latestFrame($b, Messages::TYPE_PLAYBACK_QUEUE, 'site07-queue');
        $this->assertFrameConforms($frame, 'site07 (SyncPlayManager playback_queue broadcast)');
    }

    public function testSite08ChatFrameCarriesSingleMillisTimestamp(): void
    {
        [$a, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_CHAT_MESSAGE,
            'message' => 'hello group',
        ]);

        $frame = $this->latestFrame($a, Messages::TYPE_CHAT_MESSAGE, 'site08-chat');
        $this->assertFrameConforms($frame, 'site08 (SyncPlayManager chat_message broadcast)');
        // The retired seconds-level payload duplicate is gone; the factory's
        // own envelope timestamp is the single surviving value and it is ms.
        $this->assertGreaterThanOrEqual(self::MIN_MILLIS, $frame['timestamp']);
    }

    public function testSite09TypingBroadcastFrameConforms(): void
    {
        [$a, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_CHAT_TYPING,
            'is_typing' => true,
        ]);

        $frame = $this->latestFrame($a, Messages::TYPE_CHAT_TYPING, 'site09-typing');
        $this->assertFrameConforms($frame, 'site09 (SyncPlayManager chat_typing broadcast)');
    }

    public function testSite10GroupStateTransferFrameConforms(): void
    {
        [$a, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_HOST_TRANSFER,
            'new_host_id' => 'user-b',
        ]);

        $frame = $this->latestFrame($b, Messages::TYPE_GROUP_STATE, 'site10-transfer');
        $this->assertFrameConforms($frame, 'site10 (SyncPlayManager group_state transfer)');
        $this->assertArrayHasKey('group', $frame);
    }

    public function testSite11PlaybackSyncBroadcastFrameConforms(): void
    {
        [$a, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($b, ['type' => Messages::TYPE_PLAYBACK_SYNC]);

        $frame = $this->latestFrame($a, Messages::TYPE_PLAYBACK_SYNC, 'site11-sync');
        $this->assertFrameConforms($frame, 'site11 (SyncPlayManager playback_sync broadcast)');
    }

    public function testSite12StaleTimeoutFrameConforms(): void
    {
        [$a] = $this->seedTwoMembers();

        // Any negative timeout marks every group stale immediately.
        $removed = $this->manager->cleanupStaleGroups(-1);
        $this->assertSame(1, $removed);

        $frame = $this->latestFrame($a, Messages::TYPE_INFO, 'site12-stale-timeout');
        $this->assertFrameConforms($frame, 'site12 (SyncPlayManager stale inactivity notice)');
    }

    // ── SyncPlayManager direct reply sites ────────────────────────────────

    public function testSite13TimeSyncReplyFrameConforms(): void
    {
        [, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($b, ['type' => Messages::TYPE_TIME_SYNC]);

        $frame = $this->latestFrame($b, Messages::TYPE_TIME_SYNC, 'site13-time-sync');
        $this->assertFrameConforms($frame, 'site13 (SyncPlayManager time_sync reply)');
        $this->assertArrayHasKey('offset_ms', $frame);
    }

    public function testSite13bGroupListReplyFrameConforms(): void
    {
        [, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($b, ['type' => Messages::TYPE_GROUP_LIST]);

        $frame = $this->latestFrame($b, Messages::TYPE_GROUP_LIST, 'site13b-group-list');
        $this->assertFrameConforms($frame, 'site13b (SyncPlayManager group_list reply)');
        $this->assertArrayHasKey('groups', $frame);
    }

    public function testSite17SendErrorFrameConforms(): void
    {
        [, $b] = $this->seedTwoMembers();

        // b is not the host; a play command must bounce as NOT_HOST.
        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_PLAYBACK_PLAY,
            'position' => 1,
        ]);

        $frame = $this->latestFrame($b, Messages::TYPE_ERROR, 'site17-send-error');
        $this->assertFrameConforms($frame, 'site17 (SyncPlayManager sendError)');
        $this->assertSame('NOT_HOST', $frame['error_code'] ?? null);
    }

    // ── MessageHandler gate rejections ────────────────────────────────────

    public function testSite18AuthGateRejectionFrameConforms(): void
    {
        $sent = [];
        $handler = $this->createWireMessageHandler();
        $connection = $this->createRealConnection($sent, false);

        $handler->handle($connection, (string) json_encode([
            'type' => Messages::TYPE_GROUP_CREATE,
            'protocol_version' => 1,
        ], JSON_THROW_ON_ERROR));

        $frame = $this->pickFrame($sent, Messages::TYPE_ERROR, 'site18-auth-gate');
        $this->assertFrameConforms($frame, 'site18 (MessageHandler NOT_AUTHENTICATED rejection)');
        $this->assertSame('NOT_AUTHENTICATED', $frame['error_code'] ?? null);
    }

    public function testSite19VersionMismatchRejectionFrameConforms(): void
    {
        $sent = [];
        $handler = $this->createWireMessageHandler();
        $connection = $this->createRealConnection($sent, true);

        $handler->handle($connection, (string) json_encode([
            'type' => Messages::TYPE_GROUP_CREATE,
            'protocol_version' => 999,
        ], JSON_THROW_ON_ERROR));

        $frame = $this->pickFrame($sent, Messages::TYPE_ERROR, 'site19-version-mismatch');
        $this->assertFrameConforms($frame, 'site19 (MessageHandler PROTOCOL_VERSION_MISMATCH rejection)');
        $this->assertSame('PROTOCOL_VERSION_MISMATCH', $frame['error_code'] ?? null);
    }

    // ── SyncPlayRoom (dormant JSON senders, conformed anyway) ─────────────

    public function testSite20SyncPlayRoomBroadcastFrameConforms(): void
    {
        $room = new SyncPlayRoom('sp_room_guard', 'Guard Room');
        $m1 = new TestConnection('room-conn-1');
        $m2 = new TestConnection('room-conn-2');
        $room->addMember('member-1', $m1);
        $room->addMember('member-2', $m2);

        $sent = $room->broadcast(Messages::TYPE_PLAYBACK_SYNC, ['position' => 42], 'member-1');
        $this->assertSame(1, $sent);

        $frame = $this->pickFrame($m2->getSentMessages(), Messages::TYPE_PLAYBACK_SYNC, 'site20-room-broadcast');
        $this->assertFrameConforms($frame, 'site20 (SyncPlayRoom::broadcast)');
        $this->assertSame(0, count($m1->framesOfType(Messages::TYPE_PLAYBACK_SYNC)));
    }

    public function testSite21SyncPlayRoomSendToMemberFrameConforms(): void
    {
        $room = new SyncPlayRoom('sp_room_guard2', 'Guard Room 2');
        $m1 = new TestConnection('room-conn-a');
        $room->addMember('member-1', $m1);

        $this->assertTrue($room->sendToMember('member-1', Messages::TYPE_INFO, ['message' => 'ping']));

        $frame = $this->pickFrame($m1->getSentMessages(), Messages::TYPE_INFO, 'site21-room-send-to-member');
        $this->assertFrameConforms($frame, 'site21 (SyncPlayRoom::sendToMember)');
    }

    // ── The already-conforming exemplar stays conforming ──────────────────

    public function testExemplarTimePongFrameConforms(): void
    {
        [, $b] = $this->seedTwoMembers();

        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_TIME_PING,
            'client_time' => 123456789,
        ]);

        $frame = $this->latestFrame($b, Messages::TYPE_TIME_PONG, 'exemplar-time-pong');
        $this->assertFrameConforms($frame, 'exemplar (Messages::timePong via handleTimePing)');
    }

    // ── Structural pins ───────────────────────────────────────────────────

    public function testSecondsBasedSendFlatTransportMethodIsRemoved(): void
    {
        $this->assertFalse(
            method_exists(Connection::class, 'sendFlat'),
            'Connection::sendFlat (seconds-based flat merge) must stay removed'
        );
        $this->assertFalse(
            method_exists(ConnectionInterface::class, 'sendFlat'),
            'ConnectionInterface::sendFlat must stay removed'
        );
    }

    public function testFactoryOwnsEnvelopeKeysAndRejectsUnknownTypes(): void
    {
        $frame = Messages::frame(Messages::TYPE_INFO, [
            'type' => 'evil-override',
            'protocol_version' => 99,
            'timestamp' => 1,
            'message' => 'ok',
        ]);

        $this->assertSame(Messages::TYPE_INFO, $frame['type']);
        $this->assertSame(1, $frame['protocol_version']);
        $this->assertGreaterThanOrEqual(self::MIN_MILLIS, $frame['timestamp']);

        $this->expectException(\InvalidArgumentException::class);
        Messages::frame('no_such_type', []);
    }

    /**
     * Second net against the exact bypass spellings: the seconds-based flat
     * merges that made the wire format non-conformant.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function tripwireProvider(): array
    {
        $root = dirname(__DIR__, 4);

        return [
            'SyncPlayManager' => [
                $root . '/src/Session/SyncPlay/SyncPlayManager.php',
                ["'timestamp' => time()", 'sendFlat(', "['type' => \$type]"],
            ],
            'SyncPlayRoom' => [
                $root . '/src/Server/WebSocket/SyncPlay/SyncPlayRoom.php',
                ["'timestamp' => time()", 'sendFlat(', "['type' => \$type]"],
            ],
            'MessageHandler' => [
                $root . '/src/Server/WebSocket/MessageHandler.php',
                ['sendFlat('],
            ],
            'Connection' => [
                $root . '/src/Server/WebSocket/Connection.php',
                ['sendFlat('],
            ],
            'ConnectionInterface' => [
                $root . '/src/Server/WebSocket/ConnectionInterface.php',
                ['sendFlat('],
            ],
        ];
    }

    /**
     * @param string $file        absolute path of the source under guard
     * @param list<string> $banned literals that must not appear again
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tripwireProvider')]
    public function testSourceTripwiresRejectBypassSpellings(string $file, array $banned): void
    {
        $this->assertFileExists($file);
        $source = (string) file_get_contents($file);

        foreach ($banned as $literal) {
            $this->assertStringNotContainsString(
                $literal,
                $source,
                basename($file) . ' must not contain the bypass spelling ' . $literal
                    . ' — outbound SyncPlay frames go through the Messages factory (S417).'
            );
        }
    }

    // ── Harness ───────────────────────────────────────────────────────────

    /**
     * @return array{0: TestConnection, 1: TestConnection, 2: string}
     */
    private function seedTwoMembers(): array
    {
        $a = new TestConnection('conn-a');
        $a->setAuthenticated(true, 'user-a');
        $b = new TestConnection('conn-b');
        $b->setAuthenticated(true, 'user-b');

        $pool = ConnectionPool::getInstance();
        $pool->add($a);
        $pool->add($b);

        $this->manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'group_name' => 'Guard Group',
            'member_name' => 'A',
        ]);
        $createFrames = $a->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertNotEmpty($createFrames, 'seed: create must answer with group_state');
        /** @var array<string, mixed> $group */
        $group = $createFrames[count($createFrames) - 1]['group'] ?? [];
        /** @var string $groupId */
        $groupId = (string) ($group['group_id'] ?? '');
        $this->assertNotSame('', $groupId, 'seed: group_id must be present');

        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'member_name' => 'B',
        ]);

        return [$a, $b, $groupId];
    }

    /**
     * @param array<array-key, mixed> $frame
     */
    private function assertFrameConforms(array $frame, string $site): void
    {
        $this->assertSame(
            Messages::PROTOCOL_VERSION,
            $frame['protocol_version'] ?? null,
            $site . ': frame must carry protocol_version ' . Messages::PROTOCOL_VERSION
        );
        $this->assertIsInt($frame['timestamp'] ?? null, $site . ': frame must carry an int timestamp');
        $this->assertGreaterThanOrEqual(
            self::MIN_MILLIS,
            (int) $frame['timestamp'],
            $site . ': frame timestamp must be milliseconds, not seconds'
        );
        $this->assertArrayNotHasKey('data', $frame, $site . ': frame must be flat canonical (no data wrapper)');
    }

    /**
     * @param list<array<array-key, mixed>> $frames
     * @return array<array-key, mixed>
     */
    private function pickFrame(array $frames, string $type, string $site): array
    {
        foreach (array_reverse($frames) as $frame) {
            if (($frame['type'] ?? null) === $type) {
                return $frame;
            }
        }

        $this->fail($site . ": expected an outbound {$type} frame; none was captured");
    }

    /**
     * @return array<array-key, mixed>
     */
    private function latestFrame(TestConnection $connection, string $type, string $site): array
    {
        return $this->pickFrame($connection->getSentMessages(), $type, $site);
    }

    private function createWireMessageHandler(): MessageHandler
    {
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        return new MessageHandler($pool);
    }

    /**
     * Real Connection over a mocked TcpConnection so MessageHandler::handle()
     * (which types the concrete class) can be driven end to end; frames the
     * transport emits are JSON-encoded then decoded back into $sent.
     *
     * @param list<array<array-key, mixed>> $sent
     */
    private function createRealConnection(array &$sent, bool $authenticated): Connection
    {
        $mockTcp = $this->createMock(TcpConnection::class);
        $mockTcp->method('send')->willReturnCallback(function ($data) use (&$sent): void {
            $decoded = is_string($data) ? json_decode($data, true) : $data;
            if (is_array($decoded)) {
                /** @var array<array-key, mixed> $decoded */
                $sent[] = $decoded;
            }
        });

        $connection = new class ($mockTcp) extends Connection {
            public function __construct(TcpConnection $connection)
            {
                parent::__construct($connection);
            }
        };
        $connection->setAuthenticated($authenticated, $authenticated ? 'guard-user' : null);

        return $connection;
    }
}
