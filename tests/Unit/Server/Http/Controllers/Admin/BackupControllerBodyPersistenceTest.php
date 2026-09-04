<?php

/**
 * S271 — the backup `label` and schedule options are LIVE.
 *
 * BackupController::create() and ::updateSchedule() used to read
 * `$request->jsonBody`, a property that does not exist on
 * Phlix\Server\Http\Request (the decoded body is `Request::$body`), and the
 * `?? []` swallowed the undefined-property null — so every caller's `label`,
 * `auto_backup_interval_days` and `retention_count` were silently discarded.
 * PHPStan L9 does not flag reads of undefined properties here, so no gate
 * caught it; these tests are what keeps it caught from now on.
 *
 * The assertions land on PERSISTENCE, never on the request object: a label
 * driven through the real BackupManager write path is read back through the
 * real `listBackups()` reader from the simulated `backups` store (and checked
 * against the archive actually on disk), and schedule values are read back
 * from the config file the handler rewrites. Reverting either property name
 * reddens exactly one named test (mutation-verified).
 *
 * End-to-end style follows the suite's own conventions: the stateful
 * query-callback store mirrors `ServerSettingsRoundTripTest`, and the
 * per-test separate process with a scratch `PHLIX_CONFIG_DIR` mirrors
 * `BackupConfigRecursionTest` (a constant can be defined only once, so each
 * case that needs its own config dir runs in its own process).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\BackupManager;
use Phlix\Server\Http\Controllers\Admin\BackupController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

final class BackupControllerBodyPersistenceTest extends TestCase
{
    /** @var list<string> Directories to remove in tearDown. */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $dir) {
            self::rmrf($dir);
        }
        $this->temp = [];
    }

    private function tmpdir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->temp[] = $dir;

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Scratch config dir: `database.php` from the exported DB_* env (same
     * resolution as the CI workflow) so BackupManager's mysqldump step has
     * real credentials, and a caller-supplied `backup.php`.
     *
     * @param array<string, mixed> $backupConfig
     */
    private function scratchConfigDir(string $backupDir, array $backupConfig): string
    {
        $configDir = $this->tmpdir('phlix_s271_cfg_');

        file_put_contents($configDir . '/database.php', "<?php\nreturn " . var_export([
            'connections' => [
                'mysql' => [
                    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
                    'port'     => (int) (getenv('DB_PORT') ?: 3306),
                    'username' => getenv('DB_USER') ?: 'root',
                    'password' => getenv('DB_PASSWORD') ?: '',
                    'database' => getenv('DB_DATABASE') ?: 'phlix_test',
                ],
            ],
        ], true) . ";\n");

        file_put_contents($configDir . '/backup.php', "<?php\nreturn " . var_export($backupConfig, true) . ";\n");

        // Keep the archive out of the repo and the data-scan step out of the tree.
        define('PHLIX_CONFIG_DIR', $configDir);
        define('PHLIX_DATA_DIR', $configDir . '/no-data-dir');

        return $configDir;
    }

    /**
     * A `backups`-table stand-in driven through the REAL Workerman Connection
     * query() contract: query($sql, $params = null, $fetchmode) with params
     * null on the parameterless SELECT.
     *
     * @return array{0: Connection, 1: array<int, array<string, mixed>>} mock + by-ref store
     */
    private function statefulBackupsConnection(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = [];
        $clock = 0;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @return list<array<string, mixed>> */
            function (string $sql, ?array $params = null, $fetchmode = \PDO::FETCH_ASSOC) use (&$rows, &$clock): array {
                $params ??= [];

                if (str_contains($sql, 'INSERT INTO backups')) {
                    // [id, label, file_path, size_bytes, checksum_sha256] — is_s3 FALSE, created_at NOW()
                    $clock++;
                    $rows[] = [
                        'id'               => $params[0],
                        'label'            => $params[1],
                        'file_path'        => $params[2],
                        'size_bytes'       => $params[3],
                        'checksum_sha256'  => $params[4],
                        'is_s3'            => false,
                        'created_at'       => sprintf('2026-01-01 00:00:%02d', $clock),
                        'expires_at'       => null,
                    ];

                    return [];
                }

                usort($rows, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

                if (str_contains($sql, 'LIMIT 1000 OFFSET')) {
                    // cleanupOldBackups(): everything beyond the retention offset.
                    return array_slice($rows, (int) $params[0]);
                }

                if (str_contains($sql, 'FROM backups')) {
                    // listBackups(): the full row set, newest first.
                    return $rows;
                }

                // throw, not self::fail(): an assertion that can never execute is
                // exactly the shape the S180 escape prober hunts. The store
                // signals the anomaly and the test dies with a clear message
                // either way.
                throw new \RuntimeException('Unexpected SQL reached the simulated backups store: ' . $sql);
            }
        );

        return [$db, &$rows];
    }

    /**
     * THE acceptance test. A `label` supplied in the request body must reach
     * the PERSISTED backup — asserted by reading the row back through the
     * real `listBackups()` reader, and independently against the archive file
     * name the manager derived from the same label on disk.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_supplied_label_reaches_the_persisted_backup_row(): void
    {
        $backupDir = $this->tmpdir('phlix_s271_backups_');
        // Retention far above anything this test creates: cleanup must never
        // delete a row mid-assertion.
        $this->scratchConfigDir($backupDir, [
            'enabled' => true,
            'local_path' => $backupDir,
            'retention_count' => 100000,
            'auto_backup_interval_days' => 0,
        ]);

        [$db, $rows] = $this->statefulBackupsConnection();
        $manager = new BackupManager($db);
        $controller = new BackupController($manager);

        $request = new Request();
        $request->method = 'POST';
        $request->body = ['label' => 'nightly-pre-fix'];

        $response = $controller->create($request, []);

        self::assertSame(200, $response->statusCode, 'the create drive itself must succeed — a red here means the handler threw, not that the label was dropped');
        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($response->body, true);
        self::assertTrue($envelope['success'], 'create() answered with an error: ' . ($envelope['message'] ?? '?'));

        // --- persisted-backup assertion (NOT the request object) ---
        $persisted = $manager->listBackups();
        self::assertCount(1, $persisted, 'exactly the one backup this drive created');
        self::assertSame(
            'nightly-pre-fix',
            $persisted[0]['label'],
            'the supplied label must be the persisted row label — this is the value the old `$request->jsonBody` read could never carry (it persisted \'\')',
        );

        // --- non-vacuity controls beside the assertion ---
        // (1) Independent channel: the archive name on disk is derived from the
        //     same label. A constant-echo assertion would not prove this.
        $archiveName = basename($persisted[0]['file_path']);
        self::assertSame(
            0,
            strpos($archiveName, 'nightly-pre-fix_backup_'),
            'the label prefixes the persisted archive name',
        );
        self::assertStringEndsWith('.tar.gz', $archiveName);
        self::assertFileExists($persisted[0]['file_path']);
        // (2) The envelope's backup id IS the persisted row — the response is
        //     bound to the write, not an unrelated success.
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        self::assertSame($persisted[0]['id'], $data['backup_id'] ?? null);
        // (3) The row carries real manager-written data, not store defaults.
        self::assertSame(64, strlen($persisted[0]['checksum_sha256']), 'checksum is a sha256 hex from the real archive');
        self::assertGreaterThan(0, $persisted[0]['size_bytes']);

        // --- control drive: no label in the body ⇒ persisted label is '' ---
        // This is what stops the assertion above from being a tautology: the
        // store demonstrably tracks the input, it does not echo a constant.
        $control = new Request();
        $control->method = 'POST';
        $controller->create($control, []);
        $afterControl = $manager->listBackups();
        self::assertCount(2, $afterControl);
        self::assertSame('', $afterControl[0]['label'], 'an omitted label persists as empty — distinct from the supplied one');
        self::assertNotSame($afterControl[0]['label'], $persisted[0]['label'], 'the two drives differ ⇒ the asserted label came from the request body');

        // (4) A non-string label is refused 400 and creates NO backup — this
        // branch is only reachable when the body is actually read (the old
        // dead property read saw nothing but null).
        $garbage = new Request();
        $garbage->method = 'POST';
        $garbage->body = ['label' => ['not', 'a', 'string']];
        $refusal = $controller->create($garbage, []);
        self::assertSame(400, $refusal->statusCode, 'the type guard fired ⇒ the body demonstrably reached the handler');
        self::assertCount(2, $manager->listBackups(), 'the refused request must not have persisted a backup');
    }

    /**
     * The sibling dead read: `updateSchedule()` used the same non-existent
     * property, so neither schedule parameter ever reached the config file it
     * rewrites. Persistence here is the REWRITTEN FILE on disk — read back
     * with a fresh `include`, so the assertion is on durable state.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_schedule_request_body_reaches_the_persisted_config_file(): void
    {
        $backupDir = $this->tmpdir('phlix_s271_backups_');
        $configDir = $this->scratchConfigDir($backupDir, [
            'enabled' => true,
            'local_path' => $backupDir,
            'retention_count' => 5,
            'auto_backup_interval_days' => 7,
        ]);
        $configPath = $configDir . '/backup.php';

        // The handler persists to the FILE; it must not touch the DB at all.
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $controller = new BackupController(new BackupManager($db));

        $request = new Request();
        $request->method = 'PUT';
        $request->body = ['auto_backup_interval_days' => 9, 'retention_count' => 4];

        $response = $controller->updateSchedule($request, []);
        self::assertSame(200, $response->statusCode);

        /** @var array<string, mixed> $written */
        $written = include $configPath;

        // --- persisted assertions: values the seeded file did NOT carry ---
        // (The old jsonBody read left the seeded 7/5 untouched, so the
        // expected 9/4 cannot be a tautology.)
        self::assertSame(9, $written['auto_backup_interval_days'], 'the body value must land in the persisted config file');
        self::assertSame(4, $written['retention_count'], 'the body value must land in the persisted config file');

        // --- non-vacuity control: the write was a merge, not a clobber ---
        self::assertTrue($written['enabled'], 'keys the body did not send survive the rewrite');
        self::assertSame($backupDir, $written['local_path'], 'keys the body did not send survive the rewrite');

        // --- control through the SAME handler: an out-of-range body value is
        // refused (400). Only a handler that actually read the body can take
        // this branch — the old dead read returned 200 and wrote nothing. ---
        $invalid = new Request();
        $invalid->method = 'PUT';
        $invalid->body = ['auto_backup_interval_days' => -1];
        $refusal = $controller->updateSchedule($invalid, []);
        self::assertSame(400, $refusal->statusCode, 'validation fired ⇒ the body demonstrably reached the handler');
        /** @var array<string, mixed> $stillWritten */
        $stillWritten = include $configPath;
        self::assertSame(9, $stillWritten['auto_backup_interval_days'], 'the refused request must not alter the persisted file');
    }

    /**
     * The numeric-parse guards added when the body values became real: a
     * non-numeric interval or retention is refused 400 and the persisted
     * config file keeps the seeded values untouched. The OLD dead property
     * read could not take these branches at all — it only ever saw null —
     * so each refusal doubles as a live-read control.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_non_numeric_schedule_values_are_refused_and_nothing_is_persisted(): void
    {
        $backupDir = $this->tmpdir('phlix_s271_backups_');
        $configDir = $this->scratchConfigDir($backupDir, [
            'enabled' => true,
            'local_path' => $backupDir,
            'retention_count' => 5,
            'auto_backup_interval_days' => 7,
        ]);
        $configPath = $configDir . '/backup.php';

        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $controller = new BackupController(new BackupManager($db));

        // Non-numeric interval → 400, file untouched.
        $badInterval = new Request();
        $badInterval->method = 'PUT';
        $badInterval->body = ['auto_backup_interval_days' => 'seven'];
        $refusal = $controller->updateSchedule($badInterval, []);
        self::assertSame(400, $refusal->statusCode, 'a non-numeric interval must be refused, not cast');

        // Non-numeric retention → 400, file still untouched.
        $badRetention = new Request();
        $badRetention->method = 'PUT';
        $badRetention->body = ['retention_count' => true];
        $refusal2 = $controller->updateSchedule($badRetention, []);
        self::assertSame(400, $refusal2->statusCode, 'a boolean retention must be refused, not cast');

        /** @var array<string, mixed> $written */
        $written = include $configPath;
        self::assertSame(7, $written['auto_backup_interval_days'], 'a refused request must not alter the persisted file');
        self::assertSame(5, $written['retention_count'], 'a refused request must not alter the persisted file');

        // Succeeding control on the SAME handler: a well-formed numeric body
        // persists — so the two 400s above are the guard refusing, not the
        // handler being broken overall.
        $good = new Request();
        $good->method = 'PUT';
        $good->body = ['auto_backup_interval_days' => '12', 'retention_count' => '6'];
        self::assertSame(200, $controller->updateSchedule($good, [])->statusCode, 'numeric STRINGS are accepted (JSON clients legitimately send them)');
        /** @var array<string, mixed> $afterGood */
        $afterGood = include $configPath;
        self::assertSame(12, $afterGood['auto_backup_interval_days'], 'the accepted numeric-string landed in the persisted file');
        self::assertSame(6, $afterGood['retention_count'], 'the accepted numeric-string landed in the persisted file');
    }
}
