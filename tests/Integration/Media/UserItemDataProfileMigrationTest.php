<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Common\Uuid;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-MySQL proof for S79 — `migrations/100_user_item_data_profile_id.sql`.
 *
 * ## Why this cannot be a mocked test
 *
 * The whole claim of S79 is a DATA claim: *"the migration preserves every
 * existing favorite/like/watched record under some profile"*. A mocked
 * `Connection` cannot see any of the four things that decide whether that is
 * true — the backfill's `ORDER BY is_active DESC, created_at ASC, id ASC`
 * tiebreak, whether `profile_id` really ends up `NOT NULL`, whether widening the
 * primary key over live rows rewrites or drops any of them, and whether the
 * `MigrationRunner`'s quote-aware splitter keeps group (E)'s `;`-bearing column
 * COMMENT in one piece. So this suite builds the PRE-migration schema, seeds it,
 * runs the REAL migration file through the REAL {@see MigrationRunner}, and reads
 * everything back.
 *
 * ## Why a scratch database rather than `phlix_test`
 *
 * Proving the backfill needs rows that predate it — i.e. `user_item_data` rows
 * with no `profile_id` at all. On a shared, already-migrated `phlix_test` that
 * state is unreachable without dropping the primary key and relaxing the column,
 * which is a destructive DDL edit to a table other suites use. So the suite gates
 * on the standard S126 health check and then opens its OWN connection to a
 * throwaway `phlix_s79_*` database that it creates in `setUp` and drops in
 * `tearDown`. Nothing outside that database is touched.
 *
 * ## The fixture, and why each account is in it
 *
 *   - `many`  — three profiles, the middle one active. Proves "active wins" over
 *               "earliest created".
 *   - `noact` — two profiles, NONE active. Proves the earliest-created fallback,
 *               and that the tiebreak is by `created_at` and not by insert order
 *               (the later-created profile is inserted FIRST on purpose).
 *   - `one`   — the ordinary single-profile account.
 *   - `none`  — ZERO profiles. This is the case that would silently lose data, and
 *               it is live in production: `AuthManager::register()` does not create
 *               a profile, so every account created after migration 080 ran has
 *               none.
 *   - `nofav` — a profile but no `user_item_data` rows, so the backfill has to
 *               leave a profile unused rather than inventing a row for it.
 */
final class UserItemDataProfileMigrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** Number of `user_item_data` rows the fixture seeds. */
    private const SEEDED_ROWS = 8;

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    private string $migrationsDir = '';

    /** @var array<string, string> username => user UUID */
    private array $users = [];

    /** @var array<string, string> profile label => profile UUID */
    private array $profiles = [];

    /** @var array<string, string> item label => media item UUID */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();

        // S126 gate: skip when MySQL is genuinely absent, fail when it is present
        // but unusable. Deliberately NOT wrapped in try/catch.
        $this->requireHealthyDatabase('skipping S79 user_item_data profile migration test. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $this->scratchDb = 'phlix_s79_' . str_replace('-', '', substr(Uuid::v4(), 0, 13));

        // ⚠ Deliberately NOT wrapped in try/catch. A MySQL that is reachable but
        // refuses `CREATE DATABASE` is exactly the "reachable but UNUSABLE"
        // condition S126 exists to make LOUD — turning it into a skip here would
        // reintroduce that defect in a new spelling, and
        // `tests/Unit/Support/IntegrationDbGuardAdoptionTest` flags the shape.
        // The genuine-absence case is already handled by the gate above.
        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');

        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->migrationsDir = sys_get_temp_dir() . '/' . $this->scratchDb . '-migrations';
        mkdir($this->migrationsDir, 0o755, true);
        copy($this->migrationFilePath(), $this->migrationsDir . '/100_user_item_data_profile_id.sql');

        $this->buildPreMigrationSchema();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->migrationsDir !== '' && is_dir($this->migrationsDir)) {
            foreach ((array) glob($this->migrationsDir . '/*') as $file) {
                if (is_string($file)) {
                    unlink($file);
                }
            }
            rmdir($this->migrationsDir);
        }

        $admin = $this->admin;
        if ($admin !== null && $this->scratchDb !== '') {
            $admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';

        parent::tearDown();
    }

    /**
     * THE acceptance criterion: the migration preserves EVERY pre-existing row.
     *
     * The assertion is `before === after`, not `after > 0`. A migration that
     * dropped six of eight rows and kept two would satisfy "non-zero"; only the
     * equality catches it. The per-account breakdown is asserted too, so a
     * migration that lost one account's rows and duplicated another's — which
     * keeps the total equal — is also caught.
     */
    public function testTheMigrationPreservesEveryRowAndAssignsEachOneAProfile(): void
    {
        $before = $this->countItemDataRows();
        $beforeByUser = $this->countItemDataRowsByUser();

        $this->assertSame(
            self::SEEDED_ROWS,
            $before,
            'the fixture must actually be in the table before the migration runs — '
            . 'a vacuous zero here would make every assertion below trivially true'
        );

        $result = $this->runMigration100();

        $this->assertSame(
            [],
            $result['errors'],
            'migration 100 must apply without a genuine error'
        );
        $this->assertSame(
            MigrationRunner::EXIT_SUCCESS,
            MigrationRunner::exitCodeFor($result),
            'the runner must report success'
        );

        $after = $this->countItemDataRows();

        $this->assertSame(
            $before,
            $after,
            'migration 100 must neither drop nor duplicate a single user_item_data row'
        );
        $this->assertSame(
            $beforeByUser,
            $this->countItemDataRowsByUser(),
            'every account must keep exactly the rows it had'
        );
        $this->assertSame(
            0,
            $this->countRowsWithoutAProfile(),
            'no row may survive the migration without a profile'
        );
    }

    /**
     * Each row lands on the profile the documented rule picks, and the column
     * values ride along untouched.
     */
    public function testEachRowLandsOnTheDocumentedProfileWithItsValuesIntact(): void
    {
        $this->runMigration100();

        // `many` has three profiles; the ACTIVE one wins even though it is not the
        // earliest created.
        $this->assertProfileFor('many', 'one', $this->profiles['many-active']);
        $this->assertProfileFor('many', 'two', $this->profiles['many-active']);
        $this->assertProfileFor('many', 'three', $this->profiles['many-active']);

        // `noact` has no active profile; the EARLIEST-CREATED one wins, even though
        // it was INSERTed second.
        $this->assertProfileFor('noact', 'two', $this->profiles['noact-earliest']);
        $this->assertProfileFor('noact', 'three', $this->profiles['noact-earliest']);

        // The ordinary single-profile account.
        $this->assertProfileFor('one', 'one', $this->profiles['one-solo']);

        // Values are carried, not reset. `many`/`one` had a rating of 8 and 7.
        $row = $this->itemDataRow('many', 'one');
        $this->assertNotNull($row);
        $this->assertSame('8', (string) ($row['rating'] ?? ''), 'rating must survive the PK widening');
        $this->assertSame('1', (string) ($row['favorite'] ?? ''), 'favorite must survive the PK widening');
        $this->assertSame('1', (string) ($row['like_level'] ?? ''), 'like_level must survive the PK widening');
        $this->assertSame('1', (string) ($row['watched'] ?? ''), 'watched must survive the PK widening');
    }

    /**
     * The zero-profile account — the one that would silently lose favourites —
     * gets a profile created for it, named after its username, and keeps BOTH of
     * its rows under it.
     */
    public function testAnAccountWithNoProfileGetsOneAndKeepsItsRows(): void
    {
        $this->assertSame(
            0,
            $this->countProfilesFor('none'),
            'the fixture must start with a genuinely profile-less account'
        );

        $this->runMigration100();

        $this->assertSame(1, $this->countProfilesFor('none'), 'exactly one profile must be created');

        $rows = $this->itemDataRowsFor('none');
        $this->assertCount(2, $rows, "the profile-less account's rows must all survive");

        $assigned = [];
        foreach ($rows as $row) {
            $assigned[] = (string) ($row['profile_id'] ?? '');
        }
        $this->assertCount(1, array_unique($assigned), 'both rows must land on the SAME new profile');
        $this->assertNotContains('', $assigned, 'neither row may be left without a profile');

        $profile = $this->soleProfileFor('none');
        $this->assertNotNull($profile);
        $this->assertSame(
            $assigned[0],
            (string) ($profile['id'] ?? ''),
            'the rows must point at the profile that was created for this account'
        );
        $this->assertSame(
            'none_' . $this->token(),
            (string) ($profile['name'] ?? ''),
            'the created profile is named after the username, as migration 080 does'
        );
        $this->assertSame('1', (string) ($profile['is_active'] ?? ''), 'the created profile is active');
    }

    /**
     * The resulting schema is the one the step asked for: `profile_id` NOT NULL,
     * a three-column primary key including it, a profile-leading index and a
     * cascading foreign key.
     */
    public function testTheResultingSchemaIsProfileKeyed(): void
    {
        $this->runMigration100();

        $column = $this->row(
            "SELECT IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLUMN_COMMENT
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'user_item_data' AND COLUMN_NAME = 'profile_id'",
            [$this->scratchDb]
        );
        $this->assertNotNull($column, 'profile_id must exist');
        $this->assertSame('NO', (string) ($column['IS_NULLABLE'] ?? ''), 'profile_id must be NOT NULL');
        $this->assertSame('char', (string) ($column['DATA_TYPE'] ?? ''));
        $this->assertSame(36, (int) ($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0));
        $this->assertStringContainsString(
            'scopes favorite/rating/like_level/watched per profile',
            (string) ($column['COLUMN_COMMENT'] ?? ''),
            "the column COMMENT contains a ';' — if the runner's splitter had cut the "
            . 'statement there, the comment would be truncated or the DDL would not have parsed'
        );

        $this->assertSame(
            ['user_id', 'profile_id', 'item_id'],
            $this->indexColumns('PRIMARY'),
            'the primary key must be widened to include profile_id, user_id still leftmost'
        );
        $this->assertSame(
            ['profile_id', 'item_id'],
            $this->indexColumns('idx_profile_item'),
            'a profile-leading index must exist for the profile-scoped reads'
        );

        $fk = $this->row(
            "SELECT DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'user_item_data'
               AND CONSTRAINT_NAME = 'fk_user_item_data_profile'",
            [$this->scratchDb]
        );
        $this->assertNotNull($fk, 'the profile foreign key must exist');
        $this->assertSame('CASCADE', (string) ($fk['DELETE_RULE'] ?? ''));
    }

    /**
     * Two profiles of ONE account can now hold independent data for the SAME item
     * — the forward-looking half of the acceptance criteria. Before the PK
     * widening this second INSERT would have collided on `(user_id, item_id)`.
     */
    public function testTwoProfilesOfOneAccountHoldIndependentDataForTheSameItem(): void
    {
        $this->runMigration100();

        $this->conn()->query(
            'INSERT INTO user_item_data (user_id, profile_id, item_id, favorite, rating)'
            . ' VALUES (?, ?, ?, ?, ?)',
            [$this->users['many'], $this->profiles['many-earliest'], $this->items['one'], 0, 2]
        );

        $active = $this->itemDataRowForProfile($this->profiles['many-active'], $this->items['one']);
        $other = $this->itemDataRowForProfile($this->profiles['many-earliest'], $this->items['one']);

        $this->assertNotNull($active, "the active profile's row must be untouched");
        $this->assertNotNull($other, "the second profile's row must have been accepted");
        $this->assertSame('8', (string) ($active['rating'] ?? ''));
        $this->assertSame('2', (string) ($other['rating'] ?? ''));
        $this->assertSame('1', (string) ($active['favorite'] ?? ''));
        $this->assertSame('0', (string) ($other['favorite'] ?? ''));
    }

    /**
     * Deleting a profile removes that profile's rows and leaves its siblings'
     * alone — the `ON DELETE CASCADE` half of the foreign key, with a control
     * beside it so "everything vanished" cannot pass as "the cascade worked".
     */
    public function testDeletingAProfileCascadesOnlyItsOwnRows(): void
    {
        $this->runMigration100();

        $survivorsBefore = count($this->itemDataRowsFor('one'));
        $this->assertSame(1, $survivorsBefore, 'the control account must have a row to keep');

        $this->conn()->query('DELETE FROM user_profiles WHERE id = ?', [$this->profiles['many-active']]);

        $this->assertCount(
            0,
            $this->itemDataRowsFor('many'),
            "deleting a profile must cascade that profile's per-item data"
        );
        $this->assertCount(
            $survivorsBefore,
            $this->itemDataRowsFor('one'),
            'a different account must be entirely unaffected — the control'
        );
    }

    /**
     * Replaying the file on an already-migrated database is a SUCCESS and changes
     * no data. The runner's ledger normally prevents a replay; this clears it on
     * purpose, because a half-applied file (the runner is continue-and-report, not
     * stop-on-first-error) is retried on the next run and must be safe.
     */
    public function testReplayingTheMigrationIsASuccessAndChangesNothing(): void
    {
        $this->runMigration100();

        $rowsAfterFirst = $this->countItemDataRows();
        $profilesAfterFirst = $this->countAllProfiles();

        $this->conn()->query('DELETE FROM schema_migrations');
        $replay = $this->runMigration100();

        $this->assertSame([], $replay['errors'], 'a replay must raise no genuine error');
        $this->assertSame(MigrationRunner::EXIT_SUCCESS, MigrationRunner::exitCodeFor($replay));
        $this->assertSame($rowsAfterFirst, $this->countItemDataRows(), 'a replay must not change row count');
        $this->assertSame(
            $profilesAfterFirst,
            $this->countAllProfiles(),
            'a replay must not create a second round of default profiles'
        );
    }

    /**
     * The no-orphan guard genuinely reddens the run when a row is left without a
     * profile — group (D) of the migration.
     *
     * Paired with a SUCCEEDING CONTROL in the same test: the identical statements,
     * against the identical table with the NULL filled in, exit 0. Without the
     * control, an exit code of 1 could equally mean "the guard SQL is simply
     * broken", which would be indistinguishable from "the guard fired correctly".
     */
    public function testTheNoOrphanGuardRedensTheRunAndPassesWhenThereAreNoOrphans(): void
    {
        $guardDir = $this->migrationsDir . '-guard';
        mkdir($guardDir, 0o755, true);
        file_put_contents($guardDir . '/900_orphan_guard.sql', $this->orphanGuardStatements());

        // A table shaped like the mid-migration state: profile_id present, still
        // nullable, one row with no profile.
        $this->conn()->query('DROP TABLE IF EXISTS user_item_data');
        $this->conn()->query(
            'CREATE TABLE user_item_data (
                user_id CHAR(36) NOT NULL,
                profile_id CHAR(36) NULL,
                item_id CHAR(36) NOT NULL,
                PRIMARY KEY (user_id, item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->conn()->query(
            'INSERT INTO user_item_data (user_id, profile_id, item_id) VALUES (?, NULL, ?)',
            ['orphan-user', 'orphan-item']
        );

        $orphaned = $this->runMigrationDir($guardDir);

        $this->assertSame(
            MigrationRunner::EXIT_FAILURE,
            MigrationRunner::exitCodeFor($orphaned),
            'a row without a profile must make the migration FAIL, not silently continue'
        );
        $this->assertNotSame([], $orphaned['errors'], 'the failure must be recorded as a genuine error');

        // ---- the succeeding control: same SQL, same table, no orphan ----
        $this->conn()->query('UPDATE user_item_data SET profile_id = ?', ['some-profile']);
        $this->conn()->query('DELETE FROM schema_migrations');

        $clean = $this->runMigrationDir($guardDir);

        $this->assertSame(
            [],
            $clean['errors'],
            'the SAME guard statements must pass cleanly once every row has a profile'
        );
        $this->assertSame(MigrationRunner::EXIT_SUCCESS, MigrationRunner::exitCodeFor($clean));

        foreach ((array) glob($guardDir . '/*') as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }
        rmdir($guardDir);
    }

    // ---- helpers -------------------------------------------------------------

    private function conn(): Connection
    {
        $db = $this->db;
        $this->assertNotNull($db, 'the scratch connection must be open');

        return $db;
    }

    private function migrationFilePath(): string
    {
        return dirname(__DIR__, 3) . '/migrations/100_user_item_data_profile_id.sql';
    }

    /**
     * Group (D) of the real migration file, extracted verbatim by its own comment
     * markers, so this test cannot drift from the shipped SQL.
     */
    private function orphanGuardStatements(): string
    {
        $sql = (string) file_get_contents($this->migrationFilePath());

        $start = strpos($sql, '-- (D) Refuse');
        $end = strpos($sql, '-- (E) Now that');

        if ($start === false || $end === false || $end <= $start) {
            self::fail('migration 100 must still carry its group (D) and group (E) markers');
        }

        $slice = substr($sql, $start, $end - $start);
        $this->assertStringContainsString('fail_migration_100', $slice, 'group (D) must contain the guard');

        return $slice;
    }

    /**
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int}
     */
    private function runMigration100(): array
    {
        return $this->runMigrationDir($this->migrationsDir);
    }

    /**
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int}
     */
    private function runMigrationDir(string $dir): array
    {
        $db = $this->conn();
        $runner = new MigrationRunner(static fn(): Connection => $db, $dir);

        /** @var array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int} $result */
        $result = $runner->run();

        return $result;
    }

    private function countItemDataRows(): int
    {
        return $this->scalarCount('SELECT COUNT(*) AS c FROM user_item_data', []);
    }

    private function countRowsWithoutAProfile(): int
    {
        return $this->scalarCount(
            "SELECT COUNT(*) AS c FROM user_item_data WHERE profile_id IS NULL OR profile_id = ''",
            []
        );
    }

    private function countAllProfiles(): int
    {
        return $this->scalarCount('SELECT COUNT(*) AS c FROM user_profiles', []);
    }

    private function countProfilesFor(string $username): int
    {
        return $this->scalarCount(
            'SELECT COUNT(*) AS c FROM user_profiles WHERE user_id = ?',
            [$this->users[$username]]
        );
    }

    /**
     * @return array<string, int> user UUID => row count
     */
    private function countItemDataRowsByUser(): array
    {
        $rows = $this->rows('SELECT user_id, COUNT(*) AS c FROM user_item_data GROUP BY user_id', []);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) ($row['user_id'] ?? '')] = (int) ($row['c'] ?? 0);
        }
        ksort($out);

        return $out;
    }

    private function assertProfileFor(string $username, string $item, string $expectedProfileId): void
    {
        $row = $this->itemDataRow($username, $item);

        $this->assertNotNull($row, "row {$username}/{$item} must still exist");
        $this->assertSame(
            $expectedProfileId,
            (string) ($row['profile_id'] ?? ''),
            "row {$username}/{$item} must be assigned to the documented profile"
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itemDataRow(string $username, string $item): ?array
    {
        return $this->row(
            'SELECT * FROM user_item_data WHERE user_id = ? AND item_id = ?',
            [$this->users[$username], $this->items[$item]]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itemDataRowForProfile(string $profileId, string $itemId): ?array
    {
        return $this->row(
            'SELECT * FROM user_item_data WHERE profile_id = ? AND item_id = ?',
            [$profileId, $itemId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemDataRowsFor(string $username): array
    {
        return $this->rows(
            'SELECT * FROM user_item_data WHERE user_id = ?',
            [$this->users[$username]]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function soleProfileFor(string $username): ?array
    {
        return $this->row(
            'SELECT id, name, is_active FROM user_profiles WHERE user_id = ?',
            [$this->users[$username]]
        );
    }

    /**
     * The ordered column list of an index on `user_item_data`.
     *
     * @return list<string>
     */
    private function indexColumns(string $indexName): array
    {
        $rows = $this->rows(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
             ORDER BY SEQ_IN_INDEX',
            [$this->scratchDb, 'user_item_data', $indexName]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = (string) ($row['COLUMN_NAME'] ?? '');
        }

        return $columns;
    }

    /**
     * @param list<mixed> $params
     */
    private function scalarCount(string $sql, array $params): int
    {
        $row = $this->row($sql, $params);

        return $row === null ? 0 : (int) ($row['c'] ?? 0);
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $params): ?array
    {
        $rows = $this->rows($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $result = $this->conn()->query($sql, $params);
        if (!is_array($result)) {
            return [];
        }

        $out = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    private function token(): string
    {
        return substr($this->scratchDb, -8);
    }

    /**
     * The `user_item_data` shape as of migrations 039 + 044 + 045 — i.e. BEFORE
     * migration 100 — plus the tables its foreign keys need.
     */
    private function buildPreMigrationSchema(): void
    {
        $db = $this->conn();

        $db->query(
            'CREATE TABLE users (
                id CHAR(36) PRIMARY KEY,
                username VARCHAR(255) NOT NULL UNIQUE,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->query(
            'CREATE TABLE media_items (
                id CHAR(36) PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Verbatim from migration 002.
        $db->query(
            'CREATE TABLE user_profiles (
                id CHAR(36) PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                name VARCHAR(100) NOT NULL,
                avatar_url VARCHAR(500),
                is_active BOOLEAN DEFAULT FALSE,
                is_admin BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->query(
            "CREATE TABLE profile_settings (
                id CHAR(36) PRIMARY KEY,
                profile_id CHAR(36) NOT NULL UNIQUE,
                content_rating ENUM('G','PG','PG-13','R','NC-17','X','UNRATED') DEFAULT 'R',
                pin_hash VARCHAR(255),
                pin_required_for_admin BOOLEAN DEFAULT FALSE,
                max_daily_watch_time INT DEFAULT 0,
                allowed_genres JSON,
                blocked_genres JSON,
                allow_unrated BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (profile_id) REFERENCES user_profiles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->query(
            'CREATE TABLE user_item_data (
                user_id     CHAR(36)  NOT NULL,
                item_id     CHAR(36)  NOT NULL,
                favorite    BOOLEAN   NOT NULL DEFAULT FALSE,
                rating      INT       NULL,
                like_level  TINYINT   NULL,
                watched     BOOLEAN   NULL DEFAULT NULL,
                updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, item_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (item_id) REFERENCES media_items(id) ON DELETE CASCADE,
                INDEX idx_user_updated   (user_id, updated_at),
                INDEX idx_item_favorite  (item_id, favorite)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function seedFixture(): void
    {
        $db = $this->conn();
        $token = $this->token();

        foreach (['many', 'noact', 'one', 'none', 'nofav'] as $name) {
            $id = Uuid::v4();
            $this->users[$name] = $id;
            $db->query(
                'INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)',
                [$id, $name . '_' . $token, $name . '_' . $token . '@example.test', 'x']
            );
        }

        foreach (['one', 'two', 'three'] as $label) {
            $id = Uuid::v4();
            $this->items[$label] = $id;
            $db->query('INSERT INTO media_items (id, name) VALUES (?, ?)', [$id, 'Item ' . $label]);
        }

        // `many`: three profiles, the MIDDLE one active.
        $this->seedProfile('many-earliest', 'many', 'First', false, '2024-01-01 00:00:00');
        $this->seedProfile('many-active', 'many', 'Kid', true, '2024-02-01 00:00:00');
        $this->seedProfile('many-latest', 'many', 'Guest', false, '2024-03-01 00:00:00');

        // `noact`: none active. The LATER-created one is inserted FIRST on purpose,
        // so an implementation that fell back to insert order would pick the wrong one.
        $this->seedProfile('noact-latest', 'noact', 'Second', false, '2024-05-01 00:00:00');
        $this->seedProfile('noact-earliest', 'noact', 'Earliest', false, '2024-04-01 00:00:00');

        $this->seedProfile('one-solo', 'one', 'Solo', true, '2024-01-01 00:00:00');
        $this->seedProfile('nofav-solo', 'nofav', 'Nobody', true, '2024-01-01 00:00:00');

        // `none` deliberately gets NO profile.

        $this->seedItemData('none', 'one', 1, 9, 2, 1);
        $this->seedItemData('none', 'two', 1, null, 0, null);
        $this->seedItemData('one', 'one', 1, 7, 1, null);
        $this->seedItemData('many', 'one', 1, 8, 1, 1);
        $this->seedItemData('many', 'two', 0, 5, -1, null);
        $this->seedItemData('many', 'three', 1, null, 2, 1);
        $this->seedItemData('noact', 'two', 1, 3, 0, null);
        $this->seedItemData('noact', 'three', 0, null, -2, 1);
    }

    private function seedProfile(
        string $label,
        string $username,
        string $name,
        bool $isActive,
        string $createdAt
    ): void {
        $id = Uuid::v4();
        $this->profiles[$label] = $id;

        $this->conn()->query(
            'INSERT INTO user_profiles (id, user_id, name, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $this->users[$username], $name, $isActive ? 1 : 0, $createdAt]
        );
    }

    private function seedItemData(
        string $username,
        string $item,
        int $favorite,
        ?int $rating,
        ?int $likeLevel,
        ?int $watched
    ): void {
        $this->conn()->query(
            'INSERT INTO user_item_data (user_id, item_id, favorite, rating, like_level, watched)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [$this->users[$username], $this->items[$item], $favorite, $rating, $likeLevel, $watched]
        );
    }
}
