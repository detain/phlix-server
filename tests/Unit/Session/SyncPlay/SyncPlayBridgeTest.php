<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Session\SyncPlay\SyncPlayBridge;
use Phlix\Session\SyncPlay\SyncPlayBridgeListener;
use Phlix\Session\SyncPlay\SyncPlayBridgePublisher;

/**
 * S445 write-through bridge — envelope contract + real unix-socket transport.
 *
 * The envelope is the private REST→WS channel (NOT a Messages::frame client
 * type — see SyncPlayBridge's docblock); these tests pin its parse/validate
 * gate and prove the publisher→listener pair over a REAL filesystem unix
 * socket in this venue. The cross-process apply semantics live in
 * {@see SyncPlayBridgeApplyTest}; the end-to-end served-state AC lives in
 * tests/Integration/Session/SyncPlay/SyncPlayWriteThroughBridgeTest.php.
 */
final class SyncPlayBridgeTest extends TestCase
{
    private string $socketPath = '';

    private ?SyncPlayBridgeListener $listener = null;

    /**
     * The lane token is CODE-RESIDENT by contract: the const in SyncPlayBridge
     * plus this guard. Markdown never carries it.
     */
    public function testTheBridgeTokenIsTheS445LaneToken(): void
    {
        $this->assertSame('S445XWORKPUBX9Q3', SyncPlayBridge::TOKEN);
    }

    public function testFrameCarriesTheEnvelopeAndPayload(): void
    {
        $frame = SyncPlayBridge::frame(SyncPlayBridge::OP_GROUP_DELETE, ['group_id' => 'sp_x'], 123456789);

        $this->assertSame(SyncPlayBridge::OP_GROUP_DELETE, $frame['op']);
        $this->assertSame(SyncPlayBridge::VERSION, $frame['bridge_version']);
        $this->assertSame(SyncPlayBridge::TOKEN, $frame['token']);
        $this->assertSame(123456789, $frame['issued_at_ms']);
        $this->assertSame('sp_x', $frame['group_id']);
    }

    public function testPayloadCanNeverShadowEnvelopeKeys(): void
    {
        $frame = SyncPlayBridge::frame(SyncPlayBridge::OP_GROUP_DELETE, [
            'group_id' => 'sp_x',
            'op' => 'evil',
            'token' => 'evil',
            'bridge_version' => 99,
            'issued_at_ms' => -5,
        ], 42);

        $this->assertSame(SyncPlayBridge::OP_GROUP_DELETE, $frame['op']);
        $this->assertSame(SyncPlayBridge::TOKEN, $frame['token']);
        $this->assertSame(SyncPlayBridge::VERSION, $frame['bridge_version']);
        $this->assertSame(42, $frame['issued_at_ms']);
    }

    public function testParseRoundTripsBothOps(): void
    {
        $upsert = SyncPlayBridge::parse(SyncPlayBridge::encode(SyncPlayBridge::frame(
            SyncPlayBridge::OP_GROUP_UPSERT,
            ['group' => ['id' => 'sp_a', 'name' => 'A']],
            1000
        )));
        $this->assertIsArray($upsert);
        $this->assertSame(SyncPlayBridge::OP_GROUP_UPSERT, $upsert['op']);
        $this->assertSame(1000, $upsert['issued_at_ms']);
        $this->assertSame('sp_a', $upsert['group']['id']);

        $delete = SyncPlayBridge::parse(SyncPlayBridge::encode(SyncPlayBridge::frame(
            SyncPlayBridge::OP_GROUP_DELETE,
            ['group_id' => 'sp_a'],
            1001
        )));
        $this->assertIsArray($delete);
        $this->assertSame('sp_a', $delete['group_id'] ?? null);
    }

    /**
     * parse() is the trust boundary: every malformed or unauthorized shape
     * collapses to null, never to a partially trusted frame.
     */
    public function testParseRejectsEveryMalformedOrUnauthorizedShape(): void
    {
        $cases = [
            'not json' => 'definitely not json',
            'json scalar' => '"just a string"',
            'unknown op' => (string) json_encode(['op' => 'rm_rf', 'bridge_version' => 1, 'token' => SyncPlayBridge::TOKEN, 'issued_at_ms' => 5, 'group_id' => 'sp_a']),
            'wrong token' => (string) json_encode(['op' => SyncPlayBridge::OP_GROUP_DELETE, 'bridge_version' => 1, 'token' => 'nope', 'issued_at_ms' => 5, 'group_id' => 'sp_a']),
            'missing token' => (string) json_encode(['op' => SyncPlayBridge::OP_GROUP_DELETE, 'bridge_version' => 1, 'issued_at_ms' => 5, 'group_id' => 'sp_a']),
            'wrong version' => (string) json_encode(['op' => SyncPlayBridge::OP_GROUP_DELETE, 'bridge_version' => 2, 'token' => SyncPlayBridge::TOKEN, 'issued_at_ms' => 5, 'group_id' => 'sp_a']),
            'negative stamp' => (string) json_encode(['op' => SyncPlayBridge::OP_GROUP_DELETE, 'bridge_version' => 1, 'token' => SyncPlayBridge::TOKEN, 'issued_at_ms' => -1, 'group_id' => 'sp_a']),
            'delete without id' => (string) json_encode(['op' => SyncPlayBridge::OP_GROUP_DELETE, 'bridge_version' => 1, 'token' => SyncPlayBridge::TOKEN, 'issued_at_ms' => 5]),
            'upsert without group' => (string) json_encode(['op' => SyncPlayBridge::OP_GROUP_UPSERT, 'bridge_version' => 1, 'token' => SyncPlayBridge::TOKEN, 'issued_at_ms' => 5]),
            'upsert group without id' => (string) json_encode(['op' => SyncPlayBridge::OP_GROUP_UPSERT, 'bridge_version' => 1, 'token' => SyncPlayBridge::TOKEN, 'issued_at_ms' => 5, 'group' => ['name' => 'x']]),
            'oversized line' => str_repeat('x', SyncPlayBridge::MAX_LINE_BYTES + 1),
            'blank' => "   \n",
        ];

        foreach ($cases as $label => $line) {
            $this->assertNull(SyncPlayBridge::parse($line), "parse must reject: {$label}");
        }
    }

    // -----------------------------------------------------------------
    // Real unix-socket transport (in-venue, real filesystem socket pair)
    // -----------------------------------------------------------------

    protected function tearDown(): void
    {
        $this->listener?->close();
        $this->listener = null;
        if ($this->socketPath !== '' && file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
        $this->socketPath = '';
    }

    private function boundListener(): SyncPlayBridgeListener
    {
        $this->socketPath = sys_get_temp_dir() . '/s445-test-' . bin2hex(random_bytes(6)) . '.sock';
        $listener = new SyncPlayBridgeListener($this->socketPath);
        $listener->listen();
        $this->listener = $listener;

        return $listener;
    }

    /**
     * @param list<array<string, mixed>> $sink
     */
    private function collectFrames(SyncPlayBridgeListener $listener, array &$sink): void
    {
        $listener->setApplier(static function (array $frame) use (&$sink): void {
            $sink[] = $frame;
        });
    }

    /**
     * Wait for `$expected` frames to have been applied (kernel buffering makes
     * this a formality; the bound keeps a regression from hanging the suite).
     *
     * @param list<mixed> $sink
     */
    private function waitForFrames(SyncPlayBridgeListener $listener, array $sink, int $expected, int $maxMs = 2000): void
    {
        $waited = 0;
        while (count($sink) < $expected && $waited < $maxMs) {
            $listener->poll();
            if (count($sink) < $expected) {
                usleep(10_000);
                $waited += 10;
            }
        }
    }

    public function testPublishUpsertAndDeleteCrossTheSocketAndParseCleanly(): void
    {
        $listener = $this->boundListener();
        $sink = [];
        $this->collectFrames($listener, $sink);

        $group = GroupState::deserialize([
            'id' => 'sp_transport',
            'name' => 'Transport Room',
            'members' => ['u1' => ['name' => 'One', 'connection_id' => null, 'joined_at' => 5, 'is_active' => true]],
            'host_id' => 'u1',
        ]);

        $publisher = new SyncPlayBridgePublisher($this->socketPath, 250);
        $this->assertTrue($publisher->publishUpsert($group));
        $this->assertTrue($publisher->publishDelete('sp_gone'));

        $this->waitForFrames($listener, $sink, 2);
        $this->assertCount(2, $sink);
        $this->assertSame(SyncPlayBridge::OP_GROUP_UPSERT, $sink[0]['op']);
        $this->assertSame('sp_transport', $sink[0]['group']['id']);
        $this->assertSame(SyncPlayBridge::OP_GROUP_DELETE, $sink[1]['op']);
        $this->assertSame('sp_gone', $sink[1]['group_id']);
    }

    public function testTwoFramesOnOneConnectionAreSplitOnTheNewline(): void
    {
        $listener = $this->boundListener();
        $sink = [];
        $this->collectFrames($listener, $sink);

        $bytes = SyncPlayBridge::encode(SyncPlayBridge::frame(SyncPlayBridge::OP_GROUP_DELETE, ['group_id' => 'sp_one'], 10))
            . SyncPlayBridge::encode(SyncPlayBridge::frame(SyncPlayBridge::OP_GROUP_DELETE, ['group_id' => 'sp_two'], 11));

        $client = @stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, 2.0);
        $this->assertIsResource($client, "connect failed: {$errstr}");
        fwrite($client, $bytes);
        fclose($client);

        $this->waitForFrames($listener, $sink, 2);
        $this->assertSame(['sp_one', 'sp_two'], array_map(static fn (array $f): string => $f['group_id'], $sink));
    }

    public function testAFrameWithTheWrongTokenNeverReachesTheApplier(): void
    {
        $listener = $this->boundListener();
        $sink = [];
        $this->collectFrames($listener, $sink);

        $forged = (string) json_encode([
            'op' => SyncPlayBridge::OP_GROUP_DELETE,
            'bridge_version' => SyncPlayBridge::VERSION,
            'token' => 'not-the-lane-token',
            'issued_at_ms' => 5,
            'group_id' => 'sp_any',
        ]);
        $client = @stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, 2.0);
        $this->assertIsResource($client, "connect failed: {$errstr}");
        fwrite($client, $forged . "\n");
        fclose($client);

        $listener->poll();
        $listener->poll();
        $this->assertSame([], $sink, 'a token-mismatch frame must be dropped at the parse gate');
    }

    public function testTheSocketFileIsOwnerOnlyAndThePublisherStillFailsFastWhenNoListenerExists(): void
    {
        $listener = $this->boundListener();
        $this->assertSame(0600, fileperms($this->socketPath) & 0777, 'the bridge socket must be owner-only');

        $orphanPath = sys_get_temp_dir() . '/s445-orphan-' . bin2hex(random_bytes(6)) . '.sock';
        $publisher = new SyncPlayBridgePublisher($orphanPath, 250);
        $started = hrtime(true);
        $this->assertFalse($publisher->publishDelete('sp_x'), 'publishing to a missing listener must report false');
        $elapsedMs = (hrtime(true) - $started) / 1_000_000.0;
        $this->assertLessThan(250.0, $elapsedMs, 'connect-to-absent-file must not spend the budget');
    }

    /**
     * The anti-hang pair that replaced the select-retry write loop (whose
     * second fwrite provably blocks under the swoole unix hook against a
     * wedged listener — measurement in docs/dev/BLOCKING_IO_EXCEPTIONS.md):
     *  - a normal frame to a listener that NEVER drains must publish true
     *    instantly (single in-kernel write), and
     *  - an oversized frame must be REFUSED instantly, never chunked.
     */
    public function testPublishAgainstAWedgedListenerIsBoundedNotBlocked(): void
    {
        $listener = $this->boundListener(); // deliberately never polled

        $publisher = new SyncPlayBridgePublisher($this->socketPath, 250);

        $group = GroupState::deserialize([
            'id' => 'sp_wedged',
            'name' => 'Wedged But Fine',
            'members' => ['u1' => ['name' => 'One', 'connection_id' => null, 'joined_at' => 1, 'is_active' => true]],
            'host_id' => 'u1',
        ]);
        $started = hrtime(true);
        $this->assertTrue($publisher->publishUpsert($group), 'a capped frame must be accepted by the kernel buffer without the listener draining');
        $this->assertLessThan(250.0, (hrtime(true) - $started) / 1_000_000.0, 'the write must not stall');

        $huge = GroupState::deserialize([
            'id' => 'sp_huge',
            'name' => str_repeat('X', SyncPlayBridge::MAX_PUBLISH_FRAME_BYTES),
            'members' => [],
            'host_id' => null,
        ]);
        $started = hrtime(true);
        $this->assertFalse($publisher->publishUpsert($huge), 'oversized frames are refused, never chunked');
        $this->assertLessThan(100.0, (hrtime(true) - $started) / 1_000_000.0, 'the refusal costs no I/O at all');
    }

    public function testListenClearsAStaleSocketFileButRefusesASecondLiveListener(): void
    {
        $path = sys_get_temp_dir() . '/s445-stale-' . bin2hex(random_bytes(6)) . '.sock';
        $this->socketPath = $path;
        // A dead worker's leftover file: plain non-socket content at the path.
        file_put_contents($path, 'stale');
        $staleListener = new SyncPlayBridgeListener($path);
        $staleListener->listen();
        $this->listener = $staleListener;
        $this->assertTrue($staleListener->isListening(), 'a stale socket file must be cleared and bound over');

        $secondListener = new SyncPlayBridgeListener($path);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('live listener already owns');
        $secondListener->listen();
    }
}
