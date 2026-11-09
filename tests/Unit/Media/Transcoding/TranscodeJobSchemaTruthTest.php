<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;

/**
 * SH-022 dead-schema truth pins.
 *
 * These tests freeze what the code ACTUALLY does with three pieces of shipped
 * schema that carry no live feature (full narrative in
 * `docs/data-model.md` → "Dead schema facts"):
 *
 *  1. the `transcode_jobs` status enum — `cancelled` has no writer, `running`
 *     is written nowhere in src/ today, and there is no `completeJob()`
 *     method despite a docblock that once implied one;
 *  2. the `api_keys` table (001) — zero references anywhere in src/;
 *  3. `sessions.profile_id` (002:56) — SessionManager never lists the column.
 *
 * Each pin is a deliberate tripwire: if a future feature revives one of these
 * dead paths (a cancel endpoint, API-key auth, a session-scoped profile), the
 * matching test reddens ON PURPOSE and the author must rotate the pin and the
 * docs section in the same commit. That is the whole point — silent schema
 * rot stays visible.
 */
final class TranscodeJobSchemaTruthTest extends TestCase
{
    /** Root of the repository (…/phlix-server). */
    private const REPO_ROOT = __DIR__ . '/../../../../';

    /**
     * All src/ PHP files — the population every scan below quantifies over.
     *
     * @return list<string> absolute file paths
     */
    private function srcFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::REPO_ROOT . 'src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertNotEmpty($files, 'src/ scan population must be non-empty');
        return $files;
    }

    /**
     * Every SQL string that WRITES transcode_jobs rows in src/, as a flat
     * filename => concatenated-literals list map. Matching is on the table
     * name inside INSERT/UPDATE/DELETE statement prefixes only.
     *
     * @return array<string, list<string>>
     */
    private function transcodeJobWriteSql(): array
    {
        $writes = [];
        foreach ($this->srcFiles() as $file) {
            $code = (string) file_get_contents($file);
            $isWriter = preg_match_all(
                '/(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+transcode_jobs\b/i',
                $code
            ) > 0;
            if ($isWriter) {
                // Collect every literal line that participates in such a statement:
                // simple, sufficient tokenisation — statement literals in this repo
                // are single- or double-quoted one-liners or multi-line strings
                // whose STATUS VALUES appear on quoted lines.
                $lines = [];
                foreach (preg_split('/\R/', $code) ?: [] as $line) {
                    if (preg_match('/transcode_jobs|SET status|VALUES|status = \'/i', $line) === 1) {
                        $lines[] = $line;
                    }
                }
                if ($lines !== []) {
                    $writes[$file] = $lines;
                }
            }
        }
        self::assertArrayHasKey(
            self::REPO_ROOT . 'src/Media/Transcoding/TranscodeManager.php',
            $writes,
            'baseline sanity: TranscodeManager must appear in the write population'
        );
        return $writes;
    }

    /**
     * The enum definition shipped by 001 is frozen: adding an enum member or
     * writing a status outside this set would redden the first pin spuriously,
     * so the member list itself is pinned here against the migration file.
     */
    public function testTranscodeJobsStatusEnumMembersAreFrozenAtFive(): void
    {
        $sql = (string) file_get_contents(self::REPO_ROOT . 'migrations/001_initial_schema.sql');
        self::assertSame(
            1,
            preg_match(
                "/status ENUM\('queued', 'running', 'completed', 'failed', 'cancelled'\)/",
                $sql,
                $m
            ),
            '001 transcode_jobs enum shape changed — rotate this pin AND the Dead schema doc section together.'
        );
    }

    /**
     * No src/ writer ever produces a 'cancelled' (or 'queued', or 'pending')
     * transcode_jobs status. 'pending' additionally is NOT an enum member at
     * all — STATUS_PENDING survives as an unrepresentable external-API fossil.
     */
    public function testNoTranscodeJobsWriterProducesCancelledQueuedOrPendingStatus(): void
    {
        $forbidden = [
            "status = 'cancelled'",
            "status='cancelled'",
            "status = 'queued'",
            "status='queued'",
            "status = 'pending'",
            "status='pending'",
            "'cancelled', 100", // VALUES-style cancelled
            "'queued', 100",
            "'pending', 100",
        ];
        $hits = [];
        foreach ($this->transcodeJobWriteSql() as $file => $lines) {
            foreach ($lines as $line) {
                foreach ($forbidden as $needle) {
                    if (stripos($line, $needle) !== false) {
                        $hits[] = basename($file) . ': ' . trim($needle . ' <> ' . $line);
                    }
                }
            }
        }
        self::assertSame(
            [],
            $hits,
            'A transcode_jobs writer now produces a status the SH-022 finding says has none. '
            . 'Revive intentional: rotate this pin, the constant block comment, and docs/data-model.md.'
        );
    }

    /**
     * 'running' is likewise never WRITTEN (INSERT/UPDATE SET) — it exists only
     * as read-side normalisation default and as the reaper's WHERE guard for
     * legacy pre-cutover corpses. A new live 'running' writer would make the
     * reaper a real feature again and belongs with a docs pass.
     */
    public function testRunningStatusHasNoWriterOnlyReadDefaultAndReaperGuard(): void
    {
        $offenders = [];
        foreach ($this->srcFiles() as $file) {
            $code = (string) file_get_contents($file);
            // UPDATE ... SET <assign-list> WHERE: the assignment list stops at the
            // first WHERE, so the reaper's `AND status = 'running'` GUARD lives in
            // the residue and cannot convict this pin.
            if (preg_match_all('/UPDATE\s+transcode_jobs\s+SET\s+(.*?)(?:\bWHERE\b)/is', $code, $sets) > 0) {
                foreach ($sets[1] as $setList) {
                    if (stripos($setList, "'running'") !== false) {
                        $offenders[] = basename($file) . ': SET ' . trim($setList);
                    }
                }
            }
            // INSERT ... VALUES (…): the seeded value list is the writer shape
            // for INSERTs (today's only transcode_jobs INSERT seeds 'completed').
            $hasInserts = preg_match_all(
                '/INSERT\s+INTO\s+transcode_jobs\b[^)]{0,800}\)\s*VALUES\s*\((.*?)\)/is',
                $code,
                $ins
            ) > 0;
            if ($hasInserts) {
                foreach ($ins[1] as $valueList) {
                    if (stripos($valueList, "'running'") !== false) {
                        $offenders[] = basename($file) . ': VALUES (' . trim($valueList);
                    }
                }
            }
        }
        self::assertSame([], $offenders, "Found a transcode_jobs 'running' writer: " . implode(' | ', $offenders));
    }

    /**
     * TranscodeManager has no completeJob() method. The docblock citation that
     * once claimed otherwise was corrected in SH-022 to name the two REAL
     * writers (getJobReadiness() marker sync + reapStaleRunningJobs()); if a
     * completeJob() ever lands, the epoch-guard prose AND this pin must move
     * together. (LibraryScanCommand keeps its own unrelated private
     * completeJob() for scan jobs — different class, different table.)
     */
    public function testTranscodeManagerCarriesNoCompleteJobMethod(): void
    {
        $code = (string) file_get_contents(self::REPO_ROOT . 'src/Media/Transcoding/TranscodeManager.php');
        self::assertSame(
            0,
            preg_match('/function\s+completeJob\s*\(/', $code),
            'TranscodeManager::completeJob() now exists — rotate the SH-022 cure prose and this pin together.'
        );
        self::assertMatchesRegularExpression(
            '/function\s+reapStaleRunningJobs\s*\(/',
            $code,
            'The reaper named by the corrected docblock must exist.'
        );
    }

    /**
     * api_keys: the 001 table is referenced by ZERO characters in src/. (Not
     * even in comments — the SH-022 truth pass moved its narrative to
     * docs/data-model.md where it belongs.)
     */
    public function testApiKeysTableHasZeroReferencesInSrc(): void
    {
        $hits = [];
        foreach ($this->srcFiles() as $file) {
            if (preg_match('/\bapi_keys\b/i', (string) file_get_contents($file)) === 1) {
                $hits[] = $file;
            }
        }
        self::assertSame(
            [],
            $hits,
            'Something in src/ touches api_keys again — either the dead-table finding is cured '
            . '(rotate this pin + docs) or a half-finished feature leaked in.'
        );
    }

    /**
     * sessions.profile_id: SessionManager's INSERT/UPDATE statements never
     * list the column, so every row keeps NULL default — profile identity
     * travels in the JWT claim (JwtHandler docblock). DELETEs are exempt (a
     * WHERE on profile_id would still be a read; none exists today either).
     */
    public function testSessionManagerNeverWritesSessionsProfileId(): void
    {
        $code = (string) file_get_contents(self::REPO_ROOT . 'src/Session/SessionManager.php');
        $statements = [];
        self::assertGreaterThanOrEqual(
            1,
            preg_match_all(
                '/(INSERT\s+INTO\s+sessions|UPDATE\s+sessions)[^"]*?"([^"]*)"/is',
                $code,
                $statements,
                PREG_SET_ORDER
            ),
            'Expected to find SessionManager statement literals to inspect.'
        );
        foreach ($statements as $set) {
            self::assertStringNotContainsString(
                'profile_id',
                $set[2],
                'SessionManager now lists profile_id in a sessions write: ' . $set[2]
            );
        }
    }
}
