<?php

/**
 * Pins the README's backup-subsystem documentation to the shipped code.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
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
 *
 * M-SERVER-22 r2 forward note: the first revision of this pin asserted the
 * README's claim that there is NO backup page in the `/app` SPA — a claim
 * the r1 review refuted against this repo's own vendored bundle (the
 * `BackupPage` chunk is routed as `/app/admin/backup` and its api module
 * drives all seven endpoints). Fabricated surface-absence is exactly the
 * defect class this campaign hunts, so the pin is inverted: the truthful
 * disclosure is required, the fabricated wordings are banned. Every
 * route/default pin from M-SERVER-21 stands unmodified.
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
        // M-SERVER-22 r2: the SPA Backup page is real (vendored bundle:
        // BackupPage-*.js routed as /app/admin/backup in index-*.js) — the
        // truth is pinned and the two historical fabrications are banned.
        $this->assertStringContainsString(
            'admin-only **Backup page** in the `/app` SPA',
            $readme,
            'the truthful SPA backup-surface disclosure must stay documented (r2)'
        );
        $this->assertStringNotContainsString(
            'no backup page in the `/app` SPA',
            $readme,
            'the fabricated surface-absence claim (b865aa94) must never return'
        );
        $this->assertStringNotContainsString(
            'admin API + `bin/phlix` only (no SPA page)',
            $readme,
            'the fabricated surface-absence claim (82d9f029 Overview bullet) must never return'
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
        // R-SERVER-25: a bare substring check could not tell sibling rows
        // apart. `…/backup/{id}` was satisfied by the `…/backup/{id}/restore`
        // row (deleting the DELETE row kept the pin green), one shared
        // `/schedule` path satisfied BOTH schedule datasets, and the verb was
        // never cross-checked at all. The pin is now a full endpoint-table
        // row match — the VERB parsed from the registration marker itself, the
        // exact backticked path, line-anchored: every dataset dimension (verb,
        // path) must be present as its own row for the pin to hold.
        $this->assertSame(
            1,
            preg_match('/\$r->(post|get|put|delete)\(/', $sourceMarker, $verbMatch),
            "provider shape: the source marker must carry the verb call: {$sourceMarker}"
        );
        $verb = strtoupper($verbMatch[1]);

        $rowPattern = '/^' . '\| ' . $verb . ' \| `' . preg_quote($readmePath, '/') . '` \|/m';
        $this->assertMatchesRegularExpression(
            $rowPattern,
            self::readme(),
            "README must document {$verb} {$readmePath} as its own endpoint-table row"
                . ' (R-SERVER-25: substring presence is not documentation of THIS endpoint)'
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

    /**
     * Rider R-SERVER-26: the schedule row claimed next_scheduled_backup is
     * null "when scheduling is off", but BackupManager::getNextScheduledBackup()
     * nulls ONLY on a non-positive interval — the separate `enabled` toggle
     * gates the automatic timer at registration, not this value. The README
     * must state the reader's actual law.
     */
    public function testDocumentedScheduleNullLawMatchesTheReader(): void
    {
        $readme = self::readme();
        $this->assertStringContainsString(
            '`null` when the interval is <= 0 days',
            $readme,
            'the schedule GET row must name the interval as the null condition (rider R-SERVER-26)'
        );
        $this->assertStringContainsString(
            'the `enabled` toggle gates the automatic timer separately',
            $readme,
            'the row must keep the enabled toggle distinct from the null law'
        );
        $this->assertStringNotContainsString(
            'when scheduling is off',
            $readme,
            'the imprecise null condition must not return'
        );
        $source = (string) file_get_contents(self::repoRoot() . '/src/Admin/BackupManager.php');
        $this->assertMatchesRegularExpression(
            '/function getNextScheduledBackup\(.*?\n    \}/s',
            $source,
            'the reader the row describes must still exist (parser teeth)'
        );
    }
}
