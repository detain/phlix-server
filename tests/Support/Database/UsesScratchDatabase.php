<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Support\Database;

use Phlix\Common\Database\PhlixMySQLConnection;
use LogicException;
use Workerman\MySQL\Connection;

/**
 * H-08 — the scratch-database prologue, said once.
 *
 * Before this trait, nineteen integration tests each hand-copied the same six
 * movements: the S126 gate call, the credential quintet (`IntegrationDbGuard`
 * host/port + `getenv` user/password/base), a `phlix_<token>_<rand>` database
 * name, a CREATE DATABASE through an admin connection, a second connection
 * opened against the scratch database, and a tearDown that sweeps temp fix
 * directories before dropping the scratch database. Copies drifted:
 * `TokenSessionFamilyMigration127RealDbTest` resolved credentials through
 * `$_ENV` with an implicit `root` password (a third spelling of "connect"),
 * others minted fresh randoms per fix directory, one skipped the
 * `IF EXISTS` guard on its DROP. This trait is the one true spelling; the
 * drift case is the regression the guard tests here pin shut.
 *
 * ## What adopting looks like
 *
 * ```php
 * final class SomeMigrationRealDbTest extends TestCase
 * {
 *     use UsesScratchDatabase;          // replaces `use RequiresRealDatabase;`
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $db = $this->openScratchDatabase('skipping X. Runs in CI.', 's042');
 *         // ... file-specific DDL against $db ...
 *     }
 *
 *     protected function tearDown(): void
 *     {
 *         $this->cleanupScratchDatabase();
 *         parent::tearDown();
 *     }
 * }
 * ```
 *
 * The composed {@see RequiresRealDatabase} keeps the gating law intact:
 * absence skips, reachable-but-unusable reddens, and neither this trait nor
 * its consumers may wrap that in a `try` — the docblock there is the law,
 * {@see IntegrationDbGuardAdoptionTest} the check.
 *
 * @see tests/Unit/Support/UsesScratchDatabaseTest.php for the shape proofs
 *      (credential defaults, name shape, label-reuse fail-loud).
 */
trait UsesScratchDatabase
{
    use RequiresRealDatabase;

    /** Connection to the scratch database, set by openScratchDatabase(). */
    private ?Connection $db = null;

    /** Admin connection to the base database — the one allowed to CREATE/DROP. */
    private ?Connection $admin = null;

    /** Name of the live scratch database, '' when none is open. */
    private string $scratchDb = '';

    /** Per-instance `bin2hex(random_bytes(6))` salt shared by DB and fix dirs. */
    private string $scratchRand = '';

    /** Name token passed to openScratchDatabase(), reused by scratchFixDir(). */
    private string $scratchToken = '';

    /** Temp directories registered for the tearDown sweep. */
    /** @var list<string> */
    private array $fixDirs = [];

    /**
     * The canonical credential quintet: guarded host/port from the resolved
     * `config/database.php`, then the raw-`getenv` test defaults — user `root`,
     * password EMPTY (never an implicit 'root' password: an unauthenticated
     * server must fail the SELECT-1 probe and skip honestly, not be silently
     * targeted), base database `phlix_test`.
     *
     * Static so the one structural outlier (the class-scoped scratch schema of
     * `DbRemediationRealDbTest`) and multi-connection proofs can reach the same
     * spelling without re-inlining `getenv`.
     *
     * @return array{host: string, port: int, user: string, password: string, base: string}
     */
    protected static function scratchCredentials(): array
    {
        return [
            'host' => IntegrationDbGuard::host(),
            'port' => IntegrationDbGuard::port(),
            'user' => (string) (getenv('DB_USER') ?: 'root'),
            'password' => (string) (getenv('DB_PASSWORD') ?: ''),
            'base' => (string) (getenv('DB_DATABASE') ?: 'phlix_test'),
        ];
    }

    /**
     * Gate, CREATE a throwaway `phlix_<token>_<rand>` database, and hand back
     * a live connection to it.
     *
     * @param string $skipReason Trailing part of the honest skip message (S126 law).
     * @param string $token      Name token, e.g. 's518' → database `phlix_s518_<rand>`.
     *
     * @return Connection The scratch-database connection (also reachable as $this->db).
     */
    protected function openScratchDatabase(string $skipReason, string $token): Connection
    {
        if ($this->scratchDb !== '') {
            throw new LogicException(
                'scratch database is already open — cleanupScratchDatabase() first'
            );
        }

        $this->requireHealthyDatabase($skipReason);

        $credentials = self::scratchCredentials();
        $this->scratchRand = bin2hex(random_bytes(6));
        $this->scratchToken = $token;
        $this->scratchDb = 'phlix_' . $token . '_' . $this->scratchRand;

        $this->admin = new PhlixMySQLConnection(
            $credentials['host'],
            $credentials['port'],
            $credentials['user'],
            $credentials['password'],
            $credentials['base']
        );
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');

        $this->db = new PhlixMySQLConnection(
            $credentials['host'],
            $credentials['port'],
            $credentials['user'],
            $credentials['password'],
            $this->scratchDb
        );

        return $this->db;
    }

    /**
     * A FRESH session against the live scratch database — for proofs that need
     * two simultaneous connections (lock races, blocking probes). The scratch
     * database stays managed by this trait; the returned handle is the
     * caller's to close/let go.
     */
    protected function newScratchConnection(): PhlixMySQLConnection
    {
        if ($this->scratchDb === '') {
            throw new LogicException(
                'scratch database is not open — call openScratchDatabase() first'
            );
        }

        $credentials = self::scratchCredentials();

        return new PhlixMySQLConnection(
            $credentials['host'],
            $credentials['port'],
            $credentials['user'],
            $credentials['password'],
            $this->scratchDb
        );
    }

    /**
     * A temp directory named `phlix-<token>-<label>-<rand>` under the system
     * temp dir, populated with copies of the named files from the REAL
     * migrations directory (the isolated-copy idiom MigrationRunner proofs
     * use so a replay test never mutates the source tree).
     *
     * Label reuse within one instance is a LogicException: two same-label
     * applications would silently merge into one directory and poison the
     * ledger each of them proves.
     *
     * @param string $label  Directory label, e.g. 'fix' or 'fix-012_thing.sql'.
     * @param list<string> $files Migration basenames to copy in.
     */
    protected function scratchFixDir(string $label, array $files = []): string
    {
        if ($this->scratchRand === '') {
            throw new LogicException(
                'scratch database is not open — call openScratchDatabase() first'
            );
        }

        $dir = sys_get_temp_dir() . '/phlix-' . $this->scratchToken . '-' . $label . '-' . $this->scratchRand;

        foreach ($this->fixDirs as $registered) {
            if ($registered === $dir) {
                throw new LogicException(
                    "scratch fix dir label '$label' is already registered for this instance"
                );
            }
        }

        mkdir($dir, 0o755, true);
        $this->fixDirs[] = $dir;

        foreach ($files as $file) {
            copy(self::scratchMigrationsSource() . '/' . $file, $dir . '/' . $file);
        }

        return $dir;
    }

    /**
     * Adopt an already-created directory into the tearDown sweep — for the
     * few tests whose dirs are named off something other than the shared salt
     * (a database name, a uniqid). The sweep order is registration order.
     */
    protected function registerScratchDir(string $dir): void
    {
        $this->fixDirs[] = $dir;
    }

    /** The repo's real migrations directory (source of scratchFixDir copies). */
    protected static function scratchMigrationsSource(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    /** Path of one migration file in the REAL migrations directory. */
    protected static function scratchMigrationPath(string $basename): string
    {
        return self::scratchMigrationsSource() . '/' . $basename;
    }

    /**
     * Sweep every registered temp directory (unlink contents, rmdir), then
     * DROP the live scratch database through the admin connection — the
     * canonical tearDown order. Safe to call when nothing was opened.
     */
    protected function cleanupScratchDatabase(): void
    {
        $this->cleanScratchFixDirs();
        $this->dropScratchDatabase();
    }

    /** Sweep registered temp dirs only (for interleaved custom sweeps). */
    protected function cleanScratchFixDirs(): void
    {
        foreach ($this->fixDirs as $fixDir) {
            if (is_dir($fixDir)) {
                foreach ((array) glob($fixDir . '/*') as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($fixDir);
            }
        }

        $this->fixDirs = [];
    }

    /** Drop the scratch database only (canonical guard: never with nothing open). */
    protected function dropScratchDatabase(): void
    {
        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';
        $this->scratchRand = '';
        $this->scratchToken = '';
    }
}
