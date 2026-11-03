<?php

/**
 * Phlix media server test: bridge owner stamp (deferred item #15).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use Phlix\Server\Runtime\BootIdentity;
use Phlix\Session\SyncPlay\SyncPlayBridgeListener;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Deferred-item #15 (devops2 minor 2) pinned end-to-end: the WS bridge bind
 * stamps its boot identity into a 0600 `<socket>.owner` sidecar, a colliding
 * second boot's refusal message NAMES the owning instance (the review's
 * "not warnings only" ask: the loser's log becomes diagnosable instead of
 * anonymous), the winner's close removes only its own sidecar, and a stale
 * sidecar left by a dead owner is simply overwritten by the next bind.
 *
 * Real filesystem unix sockets in the system temp dir — the venue precedent
 * set by SyncPlayBridgeTest.
 */
final class SyncPlayBridgeOwnerStampTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    /** @var list<SyncPlayBridgeListener> */
    private array $listeners = [];

    protected function setUp(): void
    {
        BootIdentity::resetForTesting();
    }

    protected function tearDown(): void
    {
        foreach ($this->listeners as $listener) {
            $listener->close();
        }
        $this->listeners = [];
        foreach ($this->paths as $path) {
            @unlink($path);
            @unlink($path . '.owner');
        }
        $this->paths = [];
        BootIdentity::resetForTesting();
    }

    private function newPath(): string
    {
        $path = sys_get_temp_dir() . '/srv-def15-' . bin2hex(random_bytes(6)) . '.sock';
        $this->paths[] = $path;

        return $path;
    }

    public function test_bind_stamps_the_owner_sidecar_with_the_boot_identity(): void
    {
        BootIdentity::stamp('aaaa1111bbbb2222');
        $path = $this->newPath();
        $listener = new SyncPlayBridgeListener($path);
        $this->listeners[] = $listener;

        $listener->listen();

        $sidecar = $listener->ownerSidecarPath();
        $this->assertFileExists($sidecar);
        $this->assertStringContainsString('instance=aaaa1111bbbb2222', (string) file_get_contents($sidecar));
        $this->assertSame(0600, fileperms($sidecar) & 0777, 'the sidecar shares the socket owner-only boundary');
    }

    public function test_collision_message_names_the_owning_instance(): void
    {
        BootIdentity::stamp('owner000000000001');
        $path = $this->newPath();
        $owner = new SyncPlayBridgeListener($path);
        $this->listeners[] = $owner;
        $owner->listen();

        BootIdentity::stamp('loser000000000002');
        $collider = new SyncPlayBridgeListener($path);
        $this->listeners[] = $collider;

        try {
            $collider->listen();
            $this->fail('a live listener must refuse the second bind');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('already owns', $refusal->getMessage());
            $this->assertStringContainsString('instance=owner000000000001', $refusal->getMessage());
        }
    }

    public function test_winner_close_removes_the_sidecar_and_loser_close_keeps_it(): void
    {
        BootIdentity::stamp('owner000000000003');
        $path = $this->newPath();
        $owner = new SyncPlayBridgeListener($path);
        $owner->listen();
        $sidecar = $owner->ownerSidecarPath();

        // A never-bound listener must not erase the live owner's stamp.
        $loser = new SyncPlayBridgeListener($path);
        $loser->close();
        $this->assertFileExists($sidecar, 'close() without a bind must leave the owner stamp alone');

        $owner->close();
        $this->assertFileDoesNotExist($sidecar, 'the bound owner’s close removes its own stamp');
    }

    public function test_stale_sidecar_without_a_live_listener_is_overwritten_by_the_next_bind(): void
    {
        $path = $this->newPath();
        // Dead owner's leftovers, socket already gone (unlink-on-stop) but the
        // sidecar survived a crash — the probe says no live owner, bind wins.
        file_put_contents($path, '');
        @unlink($path);
        file_put_contents($path . '.owner', "instance=deadbeefdeadbeef pid=1 started_at=1\n");

        BootIdentity::stamp('fresh000000000004');
        $listener = new SyncPlayBridgeListener($path);
        $this->listeners[] = $listener;
        $listener->listen();

        $this->assertStringContainsString(
            'instance=fresh000000000004',
            (string) file_get_contents($path . '.owner')
        );
    }

    public function test_collision_without_any_sidecar_reads_honestly_as_unstamped(): void
    {
        BootIdentity::stamp('owner000000000005');
        $path = $this->newPath();
        $owner = new SyncPlayBridgeListener($path);
        $this->listeners[] = $owner;
        $owner->listen();
        // Simulate the pre-#15 era (a winner that never wrote a sidecar).
        @unlink($owner->ownerSidecarPath());

        $collider = new SyncPlayBridgeListener($path);
        $this->listeners[] = $collider;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('owner stamp absent');

        $collider->listen();
    }
}
