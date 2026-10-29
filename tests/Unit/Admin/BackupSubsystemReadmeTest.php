<?php

/**
 * Pins the README's backup-subsystem documentation to the shipped code.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Review server__enduser M-SERVER-21: "The whole backup subsystem is
 * undocumented in the README." The documentation now exists; this test is
 * what keeps it honest, in the house's README-claim-detector style (the
 * same shape as `MigrationRunnerTest::testReadmeSupportClaimIsAnnotated…`):
 * it reads the README as text and cross-checks every load-bearing claim
 * against the source that must back it.
 *
 * The law it enforces: a documented admin route path must appear BOTH in
 * the README (table or section) and as a real registration in
 * `AdminRoutes.php`, and the documented schedule defaults must equal the
 * shipped `config/backup.php` values. Docs drift, code drifts, and a
 * silent divergence between the two is exactly how the item was born.
 */
final class BackupSubsystemReadmeTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function readme(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/README.md');
    }

    private static function adminRoutesSource(): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/src/Server/Http/Routes/AdminRoutes.php'
        );
    }

    /**
     * The seven documented backup routes: README spelling => AdminRoutes.php
     * registration fragment (verb call + group-relative path).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function backupRouteProvider(): array
    {
        return [
            'create'     => ['/api/v1/admin/backup/create', "\$r->post('/backup/create'"],
            'list'       => ['/api/v1/admin/backup/list', "\$r->get('/backup/list'"],
            'delete'     => ['/api/v1/admin/backup/{id}', "\$r->delete('/backup/{id}'"],
            'restore'    => ['/api/v1/admin/backup/{id}/restore', "\$r->post('/backup/{id}/restore'"],
            'upload-s3'  => ['/api/v1/admin/backup/{id}/upload-s3', "\$r->post('/backup/{id}/upload-s3'"],
            'get-sched'  => ['/api/v1/admin/backup/schedule', "\$r->get('/backup/schedule'"],
            'put-sched'  => ['/api/v1/admin/backup/schedule', "\$r->put('/backup/schedule'"],
        ];
    }

    public function testReadmeCarriesTheBackupSubsystemSection(): void
    {
        $readme = self::readme();
        $this->assertStringContainsString(
            '### Backups (admin-managed)',
            $readme,
            'the README must keep a dedicated backup section (M-SERVER-21)'
        );
        // The section's honest-surface claims must survive edits too.
        $this->assertStringContainsString(
            'no backup page in the `/app` SPA',
            $readme,
            'the no-SPA-page disclosure must stay documented'
        );
        $this->assertStringContainsString(
            'no restore path',
            $readme,
            'the data/SSL restore gap must stay disclosed'
        );
    }

    /**
     * @param string $readmePath   the path as the README documents it
     * @param string $sourceMarker the verb call + group-relative path in AdminRoutes.php
     * @dataProvider backupRouteProvider
     */
    public function testEveryDocumentedBackupRouteIsReallyRegistered(string $readmePath, string $sourceMarker): void
    {
        $this->assertStringContainsString(
            $readmePath,
            self::readme(),
            "README must document {$readmePath}"
        );
        $this->assertStringContainsString(
            $sourceMarker,
            self::adminRoutesSource(),
            "{$readmePath} is documented but {$sourceMarker} is no longer registered"
        );
        // The README spells full paths; the registrar spells them under the
        // '/api/v1/admin' group — assert the group actually applies here.
        $this->assertStringContainsString(
            "'/api/v1/admin'",
            self::adminRoutesSource(),
            'the admin group prefix the README paths rely on must stay'
        );
    }

    public function testDocumentedScheduleDefaultsMatchTheShippedConfig(): void
    {
        /** @var mixed $raw */
        $raw = require self::repoRoot() . '/config/backup.php';
        $this->assertIsArray($raw);
        /** @var array<string, mixed> $config */
        $config = $raw;

        $readme = self::readme();
        $this->assertStringContainsString(
            '`local_path` | `' . (string) $config['local_path'] . '`',
            $readme,
            'the documented default local_path must equal the shipped config/backup.php value'
        );
        $this->assertStringContainsString(
            '`retention_count` | `' . (string) $config['retention_count'] . '`',
            $readme,
            'the documented default retention_count must equal the shipped config/backup.php value'
        );
        $this->assertStringContainsString(
            '`auto_backup_interval_days` | `' . (string) $config['auto_backup_interval_days'] . '`',
            $readme,
            'the documented default interval must equal the shipped config/backup.php value'
        );
    }
}
