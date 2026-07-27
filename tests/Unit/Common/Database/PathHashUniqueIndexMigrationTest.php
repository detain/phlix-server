<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use PHPUnit\Framework\TestCase;

/**
 * S152 — static guard over the migration chain's ownership of
 * `UNIQUE KEY idx_media_items_library_path_hash (library_id, path_hash)`.
 *
 * THE DEFECT THIS LOCKS SHUT. Migration 072 added the `path_hash` generated
 * column but deferred the UNIQUE index to `migrations/cleanup_072.php`, a
 * MANUAL post-deploy script. Migration 087 then DROPped the index outright
 * (087:47-48). Nothing in `scripts/run-migrations.php` / `bin/phlix migrate`
 * ever re-created it, so a database built by the migration chain ALONE carried
 * NO path-dedupe constraint at all: duplicate media rows were possible, and the
 * S151 `const`/`rows=1` track lookup silently degraded to `ref`/`key_len=144`.
 * Production only had the index because somebody ran the script by hand once —
 * which is precisely why every measurement taken against production looked
 * correct and proved nothing about a fresh install.
 *
 * These assertions are deliberately static (no DB): they run in every CI job,
 * not only the ones with MySQL. The real-schema counterpart is
 * {@see \Phlix\Tests\Integration\Media\PathHashUniqueIndexPresentTest}.
 */
final class PathHashUniqueIndexMigrationTest extends TestCase
{
    private const INDEX_NAME = 'idx_media_items_library_path_hash';

    /** The migration that (re-)adds the index. */
    private const ADDER = '096_path_hash_unique_index.sql';

    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 4) . '/migrations';
    }

    /**
     * Every `*.sql` migration basename, in the runner's own order
     * (`glob()` + `sort()`, see MigrationRunner::discoverMigrationFiles()).
     *
     * @return list<string>
     */
    private static function migrationFiles(): array
    {
        $files = glob(self::migrationsDir() . '/*.sql');
        self::assertIsArray($files, 'migrations/*.sql must be readable');
        sort($files);

        return array_values(array_map('basename', $files));
    }

    private static function read(string $basename): string
    {
        $sql = file_get_contents(self::migrationsDir() . '/' . $basename);
        self::assertIsString($sql, $basename . ' must be readable');

        return $sql;
    }

    /**
     * The adder exists and the runner will reach it AFTER 087's
     * `DROP INDEX idx_media_items_library_path_hash`. Sort order is the whole
     * game here: an adder that sorted before the drop would be undone by it on
     * every fresh install and on the ledger-empty transition path.
     */
    public function testTheAdderSortsAfterTheMigrationThatDropsTheIndex(): void
    {
        $files = self::migrationFiles();

        $this->assertContains(self::ADDER, $files, 'the migration that re-adds the unique index must exist');

        $dropper = '087_path_hash_include_track_audiobook.sql';
        $this->assertContains($dropper, $files);

        $this->assertGreaterThan(
            array_search($dropper, $files, true),
            array_search(self::ADDER, $files, true),
            self::ADDER . ' must sort AFTER ' . $dropper . ', which DROPs the index',
        );
    }

    /**
     * The adder really adds the UNIQUE index, on exactly `(library_id,
     * path_hash)` in that order — a non-unique index, or a different column
     * order, would satisfy neither the dedupe constraint nor the `const` plan.
     */
    public function testTheAdderAddsTheUniqueIndexOnLibraryIdThenPathHash(): void
    {
        $sql = self::read(self::ADDER);

        $this->assertMatchesRegularExpression(
            '/ALTER TABLE media_items ADD UNIQUE INDEX ' . self::INDEX_NAME . ' \(library_id, path_hash\)/',
            $sql,
            self::ADDER . ' must add the UNIQUE index on (library_id, path_hash)',
        );
    }

    /**
     * The dirty-table branch's remediation text has to survive MySQL's
     * identifier limit. An identifier longer than 64 characters raises error
     * 1059 ("Identifier name '...' is too long") instead of 1054, and the
     * operator loses the one thing that made the failure actionable.
     */
    public function testTheDirtyTableRemedyFitsInAMysqlIdentifierAndNamesTheFinalizer(): void
    {
        $sql = self::read(self::ADDER);

        $matched = preg_match('/\'SELECT `([^`]+)`\'/', $sql, $m);
        $this->assertSame(1, $matched, self::ADDER . ' must carry the remediation as a quoted identifier');

        $message = $m[1];
        $this->assertLessThanOrEqual(
            64,
            strlen($message),
            'a >64-char identifier raises MySQL 1059 and the remediation text is lost',
        );
        $this->assertStringContainsString(
            'cleanup_072.php',
            $message,
            'the error text must name the finalizer that de-duplicates',
        );
    }

    /**
     * Nothing after the adder may drop the index again. This is the exact
     * regression 087 introduced, and the only reason it went unnoticed for
     * nine migrations is that no test looked.
     */
    public function testNoLaterMigrationDropsTheIndexAgain(): void
    {
        $files = self::migrationFiles();
        $adderPos = array_search(self::ADDER, $files, true);
        $this->assertIsInt($adderPos);

        foreach (array_slice($files, $adderPos + 1) as $later) {
            $sql = self::read($later);
            // Strip full-line comments so a header merely *discussing* the drop
            // (as 087's does at length) is not mistaken for one.
            $executable = preg_replace('/^\s*(--|#).*$/m', '', $sql) ?? $sql;

            $this->assertDoesNotMatchRegularExpression(
                '/DROP\s+INDEX\s+' . self::INDEX_NAME . '/i',
                $executable,
                $later . ' drops ' . self::INDEX_NAME . ' after ' . self::ADDER
                . ' re-added it — a fresh install would lose the path-dedupe constraint again. '
                . 'If the drop is genuinely required (a column rewrite), re-add the index in the SAME file '
                . 'or in a later one.',
            );
        }
    }

    /**
     * The adder must stay idempotent by construction: it decides what to run
     * from `information_schema`, so a re-apply (checksum divergence, an empty
     * ledger, a manual re-run) is a no-op rather than a 1061 note or a table
     * rebuild.
     */
    public function testTheAdderChecksInformationSchemaBeforeAltering(): void
    {
        $sql = self::read(self::ADDER);

        $this->assertStringContainsString('information_schema.STATISTICS', $sql);
        $this->assertStringContainsString("INDEX_NAME = '" . self::INDEX_NAME . "'", $sql);
        $this->assertStringContainsString('PREPARE stmt FROM @sql', $sql);
    }
}
