<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Recording;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Recording\RecordingMediaRegistrar;
use Phlix\Media\Library\ItemRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * SV-3.1d: registering a completed DVR recording as a media_items row and
 * persisting the media_item_id linkage.
 *
 *   - a completed recording with a non-empty .ts inserts a media_items row
 *     (via ItemRepository::upsertByPath) and UPDATEs livetv_recordings with the
 *     resulting media_item_id;
 *   - a missing / zero-length capture file is NEVER registered;
 *   - a non-completed (e.g. failed) recording — as fired by resumeActiveRecordings
 *     — is NEVER registered;
 *   - an already-linked recording is idempotent (no second insert);
 *   - the recordings library is find-or-created.
 *
 * @covers \Phlix\LiveTv\Recording\RecordingMediaRegistrar
 *
 * @since SV-3.1d
 */
final class RecordingMediaRegistrarTest extends TestCase
{
    /** @var array<int, string> Temp files created for a test, cleaned up in tearDown. */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    /**
     * Create a temp .ts file with the given size (bytes). size 0 → empty file.
     */
    private function tempTs(int $size = 16): string
    {
        $path = sys_get_temp_dir() . '/phlix_rec_' . uniqid('', true) . '.ts';
        file_put_contents($path, $size > 0 ? str_repeat('x', $size) : '');
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * A recording row matching the livetv_recordings schema.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function recordingRow(array $overrides = []): array
    {
        return array_merge([
            'recording_id'  => 'rec-1',
            'channel_id'    => 'ch-1',
            'program_id'    => 'prog-9',
            'user_id'       => null,
            'title'         => 'The Evening News',
            'description'   => 'Nightly bulletin',
            'start_time'    => 1_700_000_000,
            'end_time'      => 1_700_003_600,
            'status'        => 'completed',
            'media_item_id' => null,
            'storage_path'  => '/var/recordings/rec-1.ts',
        ], $overrides);
    }

    public function testRegistersCompletedRecordingAndPersistsLinkage(): void
    {
        $path = $this->tempTs(64);
        $row = $this->recordingRow();

        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($row, &$captured) {
                if (str_contains($sql, 'FROM livetv_recordings')) {
                    return [$row];
                }
                if (str_contains($sql, 'FROM libraries')) {
                    return [['id' => 'lib-video-1']];
                }
                if (str_starts_with($sql, 'UPDATE livetv_recordings')) {
                    $captured['update_sql'] = $sql;
                    $captured['update_params'] = $params;
                    return 1;
                }
                return null;
            }
        );

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())
            ->method('upsertByPath')
            ->willReturnCallback(function (array $data) use ($path, &$captured) {
                $captured['upsert'] = $data;
                $this->assertSame('lib-video-1', $data['library_id']);
                $this->assertSame('The Evening News', $data['name']);
                $this->assertSame('video', $data['type']);
                $this->assertSame($path, $data['path']);
                $this->assertIsArray($data['metadata_json']);
                $this->assertSame('livetv_dvr', $data['metadata_json']['source']);
                $this->assertSame('ch-1', $data['metadata_json']['channel_id']);
                $this->assertSame('prog-9', $data['metadata_json']['program_id']);
                $this->assertSame(3600, $data['metadata_json']['duration_seconds']);
                return 'media-1';
            });

        $registrar = new RecordingMediaRegistrar(
            $db,
            $items,
            'DVR Recordings',
            $this->createMock(StructuredLogger::class)
        );

        $result = $registrar->register('rec-1', $path);

        $this->assertSame('media-1', $result);
        // Linkage persisted: UPDATE livetv_recordings SET media_item_id = ?, ... WHERE recording_id = ?
        $this->assertArrayHasKey('update_params', $captured);
        $this->assertSame(['media-1', 'rec-1'], $captured['update_params']);
        $this->assertStringContainsString('media_item_id', $captured['update_sql']);
    }

    public function testMissingFileIsNotRegistered(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) {
                if (str_contains($sql, 'FROM livetv_recordings')) {
                    return [$this->recordingRow()];
                }
                return null;
            }
        );

        $items = $this->createMock(ItemRepository::class);
        // Never insert a broken item for a vanished capture.
        $items->expects($this->never())->method('upsertByPath');

        $registrar = new RecordingMediaRegistrar(
            $db,
            $items,
            'DVR Recordings',
            $this->createMock(StructuredLogger::class)
        );

        $missing = sys_get_temp_dir() . '/phlix_rec_absent_' . uniqid('', true) . '.ts';
        $this->assertNull($registrar->register('rec-1', $missing));
    }

    public function testZeroLengthFileIsNotRegistered(): void
    {
        $empty = $this->tempTs(0);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) {
                if (str_contains($sql, 'FROM livetv_recordings')) {
                    return [$this->recordingRow()];
                }
                return null;
            }
        );

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('upsertByPath');

        $registrar = new RecordingMediaRegistrar(
            $db,
            $items,
            'DVR Recordings',
            $this->createMock(StructuredLogger::class)
        );

        $this->assertNull($registrar->register('rec-1', $empty));
    }

    public function testFailedRecordingIsNotRegistered(): void
    {
        // onComplete also fires for rows resumeActiveRecordings() marks FAILED —
        // those must not be registered even with a real file present.
        $path = $this->tempTs(64);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) {
                if (str_contains($sql, 'FROM livetv_recordings')) {
                    return [$this->recordingRow(['status' => 'failed'])];
                }
                return null;
            }
        );

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('upsertByPath');

        $registrar = new RecordingMediaRegistrar(
            $db,
            $items,
            'DVR Recordings',
            $this->createMock(StructuredLogger::class)
        );

        $this->assertNull($registrar->register('rec-1', $path));
    }

    public function testAlreadyLinkedRecordingIsIdempotent(): void
    {
        $path = $this->tempTs(64);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) {
                if (str_contains($sql, 'FROM livetv_recordings')) {
                    return [$this->recordingRow(['media_item_id' => 'media-existing'])];
                }
                return null;
            }
        );

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('upsertByPath');

        $registrar = new RecordingMediaRegistrar(
            $db,
            $items,
            'DVR Recordings',
            $this->createMock(StructuredLogger::class)
        );

        $this->assertSame('media-existing', $registrar->register('rec-1', $path));
    }

    public function testMissingRecordingRowIsSkipped(): void
    {
        $path = $this->tempTs(64);

        $db = $this->createMock(Connection::class);
        // Empty result set → recording not found.
        $db->method('query')->willReturn([]);

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('upsertByPath');

        $registrar = new RecordingMediaRegistrar(
            $db,
            $items,
            'DVR Recordings',
            $this->createMock(StructuredLogger::class)
        );

        $this->assertNull($registrar->register('rec-missing', $path));
    }

    public function testCreatesRecordingsLibraryWhenAbsent(): void
    {
        $path = $this->tempTs(64);
        $row = $this->recordingRow();

        $insertedLibrary = null;
        $upsertLibraryId = null;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($row, &$insertedLibrary) {
                if (str_contains($sql, 'FROM livetv_recordings')) {
                    return [$row];
                }
                if (str_contains($sql, 'FROM libraries')) {
                    // No existing recordings library.
                    return [];
                }
                if (str_starts_with($sql, 'INSERT INTO libraries')) {
                    $insertedLibrary = $params;
                    return null;
                }
                if (str_starts_with($sql, 'UPDATE livetv_recordings')) {
                    return 1;
                }
                return null;
            }
        );

        $items = $this->createMock(ItemRepository::class);
        $items->method('upsertByPath')->willReturnCallback(
            function (array $data) use (&$upsertLibraryId) {
                $upsertLibraryId = $data['library_id'];
                return 'media-2';
            }
        );

        $registrar = new RecordingMediaRegistrar(
            $db,
            $items,
            'My Recordings',
            $this->createMock(StructuredLogger::class)
        );

        $result = $registrar->register('rec-1', $path);

        $this->assertSame('media-2', $result);
        // A library row was inserted with the configured name + video type...
        $this->assertNotNull($insertedLibrary);
        $this->assertSame('My Recordings', $insertedLibrary[1]);
        $this->assertSame('video', $insertedLibrary[2]);
        // ...and the media item was created under that exact new library id.
        $this->assertSame($insertedLibrary[0], $upsertLibraryId);
    }

    /**
     * SV-3.1-rowquery finding #2 (library find-or-create race).
     *
     * Two DIFFERENT recordings completing concurrently before the DVR library
     * first exists can each miss the initial SELECT and both INSERT (there is no
     * UNIQUE(type, name) constraint on `libraries`). The find-or-create must still
     * converge every caller on ONE canonical library id: after its own INSERT the
     * registrar re-SELECTs with a deterministic ORDER BY, so it reuses the
     * canonical row (here 'lib-canonical') rather than its own just-inserted id —
     * recordings never split across duplicate DVR libraries.
     */
    public function testConcurrentLibraryCreateConvergesOnCanonicalId(): void
    {
        $path = $this->tempTs(64);
        $row = $this->recordingRow();

        $selectSql = null;
        $insertedId = null;
        $upsertLibraryId = null;
        $selectCount = 0;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($row, &$selectSql, &$insertedId, &$selectCount) {
                if (str_contains($sql, 'FROM livetv_recordings')) {
                    return [$row];
                }
                if (str_contains($sql, 'FROM libraries')) {
                    $selectSql = $sql;
                    $selectCount++;
                    // 1st SELECT: library absent → proceed to INSERT.
                    // Re-SELECT (after INSERT): a concurrent completer already
                    // created the canonical row, ordered earlier.
                    return $selectCount === 1 ? [] : [['id' => 'lib-canonical']];
                }
                if (str_starts_with($sql, 'INSERT INTO libraries')) {
                    $insertedId = $params[0];
                    return null;
                }
                if (str_starts_with($sql, 'UPDATE livetv_recordings')) {
                    return 1;
                }
                return null;
            }
        );

        $items = $this->createMock(ItemRepository::class);
        $items->method('upsertByPath')->willReturnCallback(
            function (array $data) use (&$upsertLibraryId) {
                $upsertLibraryId = $data['library_id'];
                return 'media-3';
            }
        );

        $registrar = new RecordingMediaRegistrar(
            $db,
            $items,
            'DVR Recordings',
            $this->createMock(StructuredLogger::class)
        );

        $result = $registrar->register('rec-1', $path);

        $this->assertSame('media-3', $result);
        // The library lookup is deterministic (not a bare LIMIT 1 with undefined
        // order) so racing callers converge on the same row.
        $this->assertIsString($selectSql);
        $this->assertStringContainsString('ORDER BY created_at', $selectSql);
        // We DID insert our own row, but converged on the canonical one for the item.
        $this->assertNotNull($insertedId);
        $this->assertNotSame('lib-canonical', $insertedId);
        $this->assertSame('lib-canonical', $upsertLibraryId, 'item registered under the single canonical library');
    }

    /**
     * Forward-compat: if a UNIQUE(type, name) index is ever added, a racing INSERT
     * raises a duplicate-key error. The registrar must swallow it and reconcile via
     * the re-SELECT to the canonical id rather than propagating the exception.
     */
    public function testDuplicateKeyOnLibraryInsertReconcilesToCanonicalId(): void
    {
        $path = $this->tempTs(64);
        $row = $this->recordingRow();

        $upsertLibraryId = null;
        $selectCount = 0;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($row, &$selectCount) {
                if (str_contains($sql, 'FROM livetv_recordings')) {
                    return [$row];
                }
                if (str_contains($sql, 'FROM libraries')) {
                    $selectCount++;
                    return $selectCount === 1 ? [] : [['id' => 'lib-canonical']];
                }
                if (str_starts_with($sql, 'INSERT INTO libraries')) {
                    throw new \RuntimeException('Duplicate entry for key libraries.uniq_type_name');
                }
                if (str_starts_with($sql, 'UPDATE livetv_recordings')) {
                    return 1;
                }
                return null;
            }
        );

        $items = $this->createMock(ItemRepository::class);
        $items->method('upsertByPath')->willReturnCallback(
            function (array $data) use (&$upsertLibraryId) {
                $upsertLibraryId = $data['library_id'];
                return 'media-4';
            }
        );

        $registrar = new RecordingMediaRegistrar(
            $db,
            $items,
            'DVR Recordings',
            $this->createMock(StructuredLogger::class)
        );

        $result = $registrar->register('rec-1', $path);

        $this->assertSame('media-4', $result, 'duplicate-key INSERT does not propagate');
        $this->assertSame('lib-canonical', $upsertLibraryId, 'reconciled to the canonical library after the dup-key INSERT');
    }
}
