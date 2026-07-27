<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use PHPUnit\Framework\TestCase;

/**
 * S156 — static guard over the migration chain's ownership of
 * `UNIQUE KEY uq_playback_state_session_media (session_id, media_item_id)`.
 *
 * THE DEFECT THIS LOCKS SHUT. Migration
 * `090_playback_state_session_media_unique.sql` is NAMED for this key but
 * carries no executable statement at all — it is a pure comment block that
 * reserves the number and points operators at `migrations/cleanup_090.php`.
 * That script is MANUAL: nothing in `scripts/run-migrations.php`,
 * `bin/phlix migrate`, `scripts/install.sh` or `docker/docker-entrypoint.sh`
 * calls it. So a database built by the migration chain ALONE had NO unique key,
 * and therefore the `ON DUPLICATE KEY UPDATE` in
 * `PlaybackController::reportProgress()` / `StreamManager::persistStreamState()`
 * could never fire: every ~15 s progress tick INSERTed a brand-new row.
 * Finished episodes never left Continue Watching, Next Up and Most Watched were
 * unbuildable, and `playback_state` grew without bound.
 *
 * Production only had the key because somebody ran the finalizer by hand once —
 * which is precisely why every measurement taken against production looked
 * correct and proved nothing about a fresh install.
 *
 * These assertions are deliberately static (no DB): they run in every CI job,
 * not only the ones with MySQL. The real-schema counterpart is
 * {@see \Phlix\Tests\Integration\Session\PlaybackStateUniqueKeyPresentTest}.
 */
final class PlaybackStateUniqueKeyMigrationTest extends TestCase
{
    private const KEY_NAME = 'uq_playback_state_session_media';

    /** The migration that actually adds the key. */
    private const ADDER = '097_playback_state_unique_key.sql';

    /** The migration that only ever DOCUMENTED it. */
    private const DOCUMENTER = '090_playback_state_session_media_unique.sql';

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

    /** Strip full-line `--`/`#` comments, leaving only executable SQL. */
    private static function executable(string $sql): string
    {
        return preg_replace('/^\s*(--|#).*$/m', '', $sql) ?? $sql;
    }

    /**
     * The adder exists and sorts after the migration that creates the table.
     *
     * `playback_state` is created in `001_initial_schema.sql`; an adder sorting
     * before that would fail outright on a fresh install.
     */
    public function testTheAdderExistsAndSortsAfterTheTableIsCreated(): void
    {
        $files = self::migrationFiles();

        $this->assertContains(
            self::ADDER,
            $files,
            'the migration that adds the playback_state unique key must exist — without it a fresh '
            . 'install has no (session_id, media_item_id) constraint and progress reporting cannot upsert',
        );

        $creator = '001_initial_schema.sql';
        $this->assertContains($creator, $files);

        $this->assertGreaterThan(
            array_search($creator, $files, true),
            array_search(self::ADDER, $files, true),
            self::ADDER . ' must sort AFTER ' . $creator . ', which creates playback_state',
        );
    }

    /**
     * The heart of S156: migration 090 has NO executable statement, so it cannot
     * be the thing that creates the key.
     *
     * This is asserted rather than merely narrated because 090's own header
     * argues at length that deferring to the finalizer was correct. If somebody
     * later "fixes" 090 by adding the ALTER inline, its ledger checksum flips on
     * every already-migrated install AND the bare ALTER fails with 1062 on any
     * dirty table — the two failure modes 097 exists to avoid.
     */
    public function testTheDocumenterStillCarriesNoExecutableStatement(): void
    {
        $executable = trim(self::executable(self::read(self::DOCUMENTER)));

        $this->assertSame(
            '',
            $executable,
            self::DOCUMENTER . ' must remain comment-only. Adding SQL to it flips its checksum in the '
            . 'schema_migrations ledger on every already-migrated install. The key belongs in ' . self::ADDER,
        );
    }

    /**
     * The adder really adds a UNIQUE key, on exactly `(session_id,
     * media_item_id)` in that order, under the name
     * {@see \Phlix\Session\PlaybackStateDeduper::UNIQUE_KEY_NAME}.
     *
     * The column order is load-bearing beyond the constraint itself: the upsert
     * conflict target is the whole pair, and `session_id` first also gives the
     * per-session lookups a usable left prefix.
     */
    public function testTheAdderAddsTheUniqueKeyOnSessionIdThenMediaItemId(): void
    {
        $sql = self::read(self::ADDER);

        $this->assertMatchesRegularExpression(
            '/ALTER TABLE playback_state ADD UNIQUE KEY ' . self::KEY_NAME
            . ' \(session_id, media_item_id\)/',
            $sql,
            self::ADDER . ' must add the UNIQUE key on (session_id, media_item_id)',
        );
    }

    /**
     * The migration's key name must stay identical to the one
     * `PlaybackStateDeduper` uses, or the two ways of arriving at this schema
     * produce two DIFFERENT keys — and `hasUniqueKey()` would report `false` on
     * a correctly migrated database, sending operators to run a finalizer that
     * then fails with a duplicate-index error.
     */
    public function testTheMigrationAndTheDeduperAgreeOnTheKeyName(): void
    {
        $this->assertSame(
            self::KEY_NAME,
            \Phlix\Session\PlaybackStateDeduper::UNIQUE_KEY_NAME,
            'the migration and PlaybackStateDeduper must create/detect the SAME key name',
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
            'cleanup_090.php',
            $message,
            'the error text must name the finalizer that de-duplicates',
        );
    }

    /**
     * Nothing after the adder may drop the key again. This is the exact
     * regression S152 found in the path_hash chain (087 dropped what 072
     * deferred), and the only reason that went unnoticed for nine migrations is
     * that no test looked.
     */
    public function testNoLaterMigrationDropsTheKeyAgain(): void
    {
        $files = self::migrationFiles();
        $adderPos = array_search(self::ADDER, $files, true);
        $this->assertIsInt($adderPos);

        foreach (array_slice($files, $adderPos + 1) as $later) {
            $executable = self::executable(self::read($later));

            $this->assertDoesNotMatchRegularExpression(
                '/DROP\s+(INDEX|KEY)\s+' . self::KEY_NAME . '/i',
                $executable,
                $later . ' drops ' . self::KEY_NAME . ' after ' . self::ADDER
                . ' added it — a fresh install would lose the upsert constraint again, and finished '
                . 'episodes would stop leaving Continue Watching. If the drop is genuinely required, '
                . 're-add the key in the SAME file or in a later one.',
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
        $this->assertStringContainsString("INDEX_NAME = '" . self::KEY_NAME . "'", $sql);
        $this->assertStringContainsString('PREPARE stmt FROM @sql', $sql);
    }

    /**
     * The adder must NOT try to merge duplicates itself.
     *
     * Choosing which duplicate survives decides where a user resumes playback.
     * That rule lives once, in `PlaybackStateDeduper::findKeeperId()`, and a
     * second implementation in SQL would be a competing source of truth that
     * nothing tests. 097's dirty-table branch is a guard, not a fixer.
     */
    public function testTheAdderDoesNotDeleteRowsItself(): void
    {
        $executable = self::executable(self::read(self::ADDER));

        $this->assertDoesNotMatchRegularExpression(
            '/\bDELETE\s+FROM\b/i',
            $executable,
            self::ADDER . ' must not delete playback_state rows. Merging duplicates decides where a '
            . 'user resumes and belongs to PlaybackStateDeduper (via migrations/cleanup_090.php) alone.',
        );
    }
}
