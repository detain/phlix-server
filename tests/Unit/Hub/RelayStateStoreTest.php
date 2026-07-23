<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\RelayStateStore;

/**
 * Unit coverage for {@see RelayStateStore} — the S38 cross-process state store.
 *
 * Two single-writer JSON files, atomic writes (tmp + rename, chmod 0600), and
 * best-effort null-safe reads (missing/unparseable → [], never throws).
 */
final class RelayStateStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phlix-relay-state-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_relay_state_round_trip(): void
    {
        $store = new RelayStateStore($this->dir);
        $store->writeRelayState([
            'connected' => true,
            'active' => true,
            'reconnectAttempts' => 0,
            'activeSessions' => 3,
            'lastDisconnectTime' => null,
            'lastConnectError' => null,
            'lastConnectErrorAt' => null,
        ]);

        $read = $store->readRelayState();

        $this->assertTrue($read['connected']);
        $this->assertTrue($read['active']);
        $this->assertSame(0, $read['reconnectAttempts']);
        $this->assertSame(3, $read['activeSessions']);
        $this->assertNull($read['lastConnectError']);
    }

    public function test_write_stamps_updated_at(): void
    {
        $store = new RelayStateStore($this->dir);
        $store->writeRelayState(['connected' => false]);

        $read = $store->readRelayState();
        $this->assertArrayHasKey('updatedAt', $read);
        $this->assertIsString($read['updatedAt']);
        // Parseable ISO-8601.
        $this->assertNotFalse(strtotime($read['updatedAt']));
    }

    public function test_heartbeat_state_round_trip_is_isolated_from_relay(): void
    {
        $store = new RelayStateStore($this->dir);
        $store->writeRelayState(['connected' => true]);
        $store->writeHeartbeatState(['consecutiveFailures' => 5, 'lastLatencyMs' => 42]);

        $this->assertTrue($store->readRelayState()['connected']);
        $this->assertSame(5, $store->readHeartbeatState()['consecutiveFailures']);
        $this->assertSame(42, $store->readHeartbeatState()['lastLatencyMs']);
        // The two files must not bleed into each other.
        $this->assertArrayNotHasKey('connected', $store->readHeartbeatState());
        $this->assertArrayNotHasKey('consecutiveFailures', $store->readRelayState());
    }

    public function test_read_missing_file_returns_empty_array(): void
    {
        $store = new RelayStateStore($this->dir);
        $this->assertSame([], $store->readRelayState());
        $this->assertSame([], $store->readHeartbeatState());
    }

    public function test_read_unparseable_file_returns_empty_array_and_does_not_throw(): void
    {
        file_put_contents($this->dir . '/' . RelayStateStore::RELAY_STATE_FILE, '{not valid json');

        $store = new RelayStateStore($this->dir);
        $this->assertSame([], $store->readRelayState());
    }

    public function test_write_is_atomic_and_leaves_no_tmp_files(): void
    {
        $store = new RelayStateStore($this->dir);
        $store->writeRelayState(['connected' => true]);

        $entries = array_map('basename', glob($this->dir . '/*') ?: []);
        $this->assertContains(RelayStateStore::RELAY_STATE_FILE, $entries);
        foreach ($entries as $name) {
            $this->assertStringEndsNotWith('.tmp', $name, 'atomic write must not leave a .tmp file behind');
        }
    }

    public function test_written_file_is_owner_only_readable(): void
    {
        $store = new RelayStateStore($this->dir);
        $store->writeRelayState(['connected' => true]);

        $path = $this->dir . '/' . RelayStateStore::RELAY_STATE_FILE;
        $perms = fileperms($path) & 0777;
        $this->assertSame(0600, $perms, 'state file must be chmod 0600');
    }

    public function test_write_with_unencodable_payload_is_silently_dropped(): void
    {
        $store = new RelayStateStore($this->dir);
        // NAN is not JSON-encodable → json_encode() returns false; the write is
        // a best-effort no-op that must not throw and must not create a file.
        $store->writeRelayState(['bad' => NAN]);

        $this->assertSame([], $store->readRelayState());
        $this->assertFileDoesNotExist($this->dir . '/' . RelayStateStore::RELAY_STATE_FILE);
    }

    public function test_write_to_unwritable_location_is_silently_dropped(): void
    {
        // Point configDir under an existing FILE so mkdir() and the tmp
        // file_put_contents() both fail — the write must degrade silently.
        $file = $this->dir . '/iam-a-file';
        file_put_contents($file, 'x');

        $store = new RelayStateStore($file . '/cannot');
        $store->writeRelayState(['connected' => true]);

        $this->assertSame([], $store->readRelayState());
    }

    public function test_write_is_dropped_when_target_path_is_a_directory(): void
    {
        // Make the target state-file name an existing directory so the atomic
        // rename() over it fails; the tmp file must be cleaned up and no throw.
        mkdir($this->dir . '/' . RelayStateStore::RELAY_STATE_FILE);

        $store = new RelayStateStore($this->dir);
        $store->writeRelayState(['connected' => true]);

        // Nothing readable (target is a dir), and no leftover .tmp file.
        $this->assertSame([], $store->readRelayState());
        foreach (array_map('basename', glob($this->dir . '/*') ?: []) as $name) {
            $this->assertStringEndsNotWith('.tmp', $name);
        }

        @rmdir($this->dir . '/' . RelayStateStore::RELAY_STATE_FILE);
    }

    public function test_write_creates_missing_config_dir(): void
    {
        $nested = $this->dir . '/nested/config';
        $store = new RelayStateStore($nested);
        $store->writeRelayState(['connected' => false]);

        $this->assertFileExists($nested . '/' . RelayStateStore::RELAY_STATE_FILE);
        // Cleanup the nested tree.
        @unlink($nested . '/' . RelayStateStore::RELAY_STATE_FILE);
        @rmdir($nested);
        @rmdir($this->dir . '/nested');
    }

    // ---------------------------------------------------------------------
    // S39 operator kill-switch (relay-control.json).
    // ---------------------------------------------------------------------

    public function test_relay_disabled_defaults_to_false_when_no_control_file(): void
    {
        $store = new RelayStateStore($this->dir);
        // No relay-control.json → not disabled (behaves as before the lever).
        $this->assertFalse($store->isRelayDisabled());
    }

    public function test_set_relay_disabled_persists_and_reads_back_true(): void
    {
        $store = new RelayStateStore($this->dir);

        $this->assertTrue($store->setRelayDisabled(true), 'write must report success');
        $this->assertFileExists($this->dir . '/' . RelayStateStore::RELAY_CONTROL_FILE);
        $this->assertTrue($store->isRelayDisabled());

        // A fresh instance (mirrors the relay fork reading what the HTTP worker
        // wrote in another process) sees the same persisted flag.
        $freshInstance = new RelayStateStore($this->dir);
        $this->assertTrue($freshInstance->isRelayDisabled());
    }

    public function test_set_relay_disabled_false_clears_the_kill_switch(): void
    {
        $store = new RelayStateStore($this->dir);
        $store->setRelayDisabled(true);
        $this->assertTrue($store->isRelayDisabled());

        $this->assertTrue($store->setRelayDisabled(false));
        $this->assertFalse($store->isRelayDisabled());
    }

    public function test_control_file_is_isolated_from_the_state_files(): void
    {
        $store = new RelayStateStore($this->dir);
        $store->writeRelayState(['connected' => true]);
        $store->setRelayDisabled(true);

        // The control flag must not bleed into the tunnel-state file, nor vice
        // versa — three single-writer files.
        $this->assertTrue($store->readRelayState()['connected']);
        $this->assertArrayNotHasKey('disabled', $store->readRelayState());

        $controlRaw = file_get_contents($this->dir . '/' . RelayStateStore::RELAY_CONTROL_FILE);
        $this->assertIsString($controlRaw);
        /** @var array<string, mixed> $control */
        $control = json_decode($controlRaw, true);
        $this->assertArrayNotHasKey('connected', $control);
        $this->assertTrue($control['disabled']);
    }

    public function test_control_file_is_owner_only_readable(): void
    {
        $store = new RelayStateStore($this->dir);
        $store->setRelayDisabled(true);

        $perms = fileperms($this->dir . '/' . RelayStateStore::RELAY_CONTROL_FILE) & 0777;
        $this->assertSame(0600, $perms, 'control file must be chmod 0600');
    }

    public function test_set_relay_disabled_reports_failure_on_unwritable_location(): void
    {
        // configDir under an existing FILE → mkdir()/tmp write both fail; the
        // load-bearing setter must report the failure (not silently swallow it).
        $file = $this->dir . '/iam-a-file';
        file_put_contents($file, 'x');

        $store = new RelayStateStore($file . '/cannot');
        $this->assertFalse($store->setRelayDisabled(true));
    }

    public function test_relay_disabled_false_for_unparseable_control_file(): void
    {
        file_put_contents($this->dir . '/' . RelayStateStore::RELAY_CONTROL_FILE, '{bad json');

        $store = new RelayStateStore($this->dir);
        $this->assertFalse($store->isRelayDisabled());
    }
}
