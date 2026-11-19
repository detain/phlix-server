<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use FilesystemIterator;
use LogicException;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\UsesScratchDatabase;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Shape proofs for {@see UsesScratchDatabase} — the H-08 scratch prologue.
 *
 * The static proofs here pass WITHOUT MySQL: they pin the credential spelling
 * (the drift the extraction exists to kill), the fail-loud guards on misuse,
 * and the fix-directory naming law. The lifecycle proof needs a real server
 * and skips honestly through the composed S126 gate when none is listening —
 * so a green no-database run still sees these assertions EXECUTE, which is
 * how skip and pass stay distinguishable.
 */
final class UsesScratchDatabaseTest extends TestCase
{
    use UsesScratchDatabase;

    /** @var array<string, string|false> env values captured before the run */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['DB_USER', 'DB_PASSWORD', 'DB_DATABASE'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupScratchDatabase();

        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                continue;
            }
            putenv($key . '=' . $value);
        }

        parent::tearDown();
    }

    /**
     * The canonical quintet defaults: user 'root', password EMPTY, base
     * 'phlix_test'. The empty-password default is the whole point — the 127
     * drift case defaulted the PASSWORD to 'root' and silently targeted
     * unauthenticated servers. That spelling must never come back.
     */
    public function testCredentialsDefaultToTheCanonicalQuintet(): void
    {
        $this->clearEnv(['DB_USER', 'DB_PASSWORD', 'DB_DATABASE']);

        $credentials = self::scratchCredentials();

        $this->assertSame(IntegrationDbGuard::host(), $credentials['host']);
        $this->assertSame(IntegrationDbGuard::port(), $credentials['port']);
        $this->assertSame('root', $credentials['user']);
        $this->assertSame('', $credentials['password']);
        $this->assertSame('phlix_test', $credentials['base']);
    }

    /** An empty DB_PASSWORD env means an empty password, not 'root'. */
    public function testEmptyPasswordEnvIsHonouredNotDefaulted(): void
    {
        $this->clearEnv(['DB_USER', 'DB_PASSWORD', 'DB_DATABASE']);
        putenv('DB_USER=ci-user');
        putenv('DB_PASSWORD=');
        putenv('DB_DATABASE=ci-base');

        $credentials = self::scratchCredentials();

        $this->assertSame('ci-user', $credentials['user']);
        $this->assertSame('', $credentials['password']);
        $this->assertSame('ci-base', $credentials['base']);
    }

    /**
     * The resolver reads getenv, never $_ENV: with the two deliberately
     * disagreeing, the getenv spelling must win. This is the 127 drift
     * tripwire at the resolver boundary — $_ENV was how the divergent
     * variant smuggled its implicit 'root' password.
     */
    public function testResolverFollowsGetenvNotEnvSuperGlobal(): void
    {
        $this->clearEnv(['DB_USER', 'DB_PASSWORD', 'DB_DATABASE']);
        putenv('DB_USER=getenv-wins');
        putenv('DB_PASSWORD=getenv-too');
        $_ENV['DB_USER'] = 'sentinel-should-be-ignored';
        $_ENV['DB_PASSWORD'] = 'sentinel-should-be-ignored';

        $credentials = self::scratchCredentials();

        $this->assertSame('getenv-wins', $credentials['user']);
        $this->assertSame('getenv-too', $credentials['password']);

        unset($_ENV['DB_USER'], $_ENV['DB_PASSWORD']);
    }

    /** Opening twice without cleanup is a LogicException, BEFORE the gate. */
    public function testSecondOpenThrowsBeforeTheDatabaseGate(): void
    {
        $this->scratchDb = 'phlix_pre_open_probe';

        $this->expectException(LogicException::class);
        $this->openScratchDatabase('must never reach the gate. Runs in CI.', 's009');
    }

    /** A fresh session against nothing is programmer error, loudly. */
    public function testNewScratchConnectionBeforeOpenThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->newScratchConnection();
    }

    /**
     * Two scratch fix dirs with the same label would merge and poison each
     * other's migration ledger — the reuse throws instead.
     */
    public function testScratchFixDirLabelReuseThrows(): void
    {
        $this->scratchToken = 's009';
        $this->scratchRand = 'deadbeefcafe';

        $dir = $this->scratchFixDir('dup');
        $this->assertDirectoryExists($dir);
        $this->assertSame(
            sys_get_temp_dir() . '/phlix-s009-dup-deadbeefcafe',
            $dir,
            'fix dir name must follow the phlix-<token>-<label>-<rand> law'
        );

        $this->expectException(LogicException::class);
        $this->scratchFixDir('dup');
    }

    /** Building a fix dir before any database is open is programmer error. */
    public function testScratchFixDirBeforeOpenThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->scratchFixDir('early');
    }

    /** The migrations source resolves to the repo's real migrations dir. */
    public function testMigrationsSourcePointsAtTheRealDirectory(): void
    {
        $source = self::scratchMigrationsSource();

        $this->assertDirectoryExists($source);
        $this->assertFileExists($source . '/001_initial_schema.sql');
        $this->assertSame(
            $source . '/106_client_heartbeats.sql',
            self::scratchMigrationPath('106_client_heartbeats.sql')
        );
    }

    /**
     * The full lifecycle against a real server: gate, CREATE, live queries,
     * a fix dir with a real migration copied in, then cleanup that removes
     * BOTH the temp dir and the database. Skips honestly when no MySQL
     * listens — the composed S126 gate above, never a silent pass.
     */
    public function testScratchLifecycleCreatesUsesAndDrops(): void
    {
        $db = $this->openScratchDatabase('skipping the scratch lifecycle proof. Runs in CI.', 's009');

        $this->assertMatchesRegularExpression('/^phlix_s009_[0-9a-f]{12}$/', $this->scratchDb);

        $rows = $db->query('SELECT DATABASE()');
        $this->assertIsArray($rows);
        $this->assertSame($this->scratchDb, (string) reset($rows[0]));

        $fixDir = $this->scratchFixDir('proof', ['106_client_heartbeats.sql']);
        $this->assertFileExists($fixDir . '/106_client_heartbeats.sql');

        $db->query('CREATE TABLE scratch_canary (id INT PRIMARY KEY)');
        $db->query('INSERT INTO scratch_canary (id) VALUES (42)');
        $canary = $db->query('SELECT id FROM scratch_canary');
        $this->assertSame(42, (int) reset($canary[0]));

        $scratchName = $this->scratchDb;
        $this->cleanupScratchDatabase();

        $this->assertDirectoryDoesNotExist($fixDir);

        $credentials = self::scratchCredentials();
        $probe = new PhlixMySQLConnection(
            $credentials['host'],
            $credentials['port'],
            $credentials['user'],
            $credentials['password'],
            $credentials['base']
        );
        $survivors = $probe->query(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$scratchName]
        );
        $this->assertSame([], $survivors);
    }

    /**
     * H-08 drift tripwire: the migration-127 test once resolved credentials
     * through the $_ENV superglobal AND defaulted DB_PASSWORD to 'root' — an
     * idiom that silently targets an unauthenticated server instead of making
     * the S126 probe skip honestly. It must never resurface in a non-Support
     * test: canonical resolution is getenv-shaped, trait-side.
     */
    public function testNoNonSupportTestReadsDbCredentialsViaEnvSuperglobal(): void
    {
        $root = dirname(__DIR__, 2);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains($file->getPathname(), '/Support/')) {
                continue;
            }
            if (preg_match('/\$_ENV\s*\[\s*[\x27"]DB_/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'DB credentials must be resolved via UsesScratchDatabase::scratchCredentials(), not $_ENV (H-08)');
    }

    /**
     * @param list<string> $keys
     */
    private function clearEnv(array $keys): void
    {
        foreach ($keys as $key) {
            putenv($key);
        }
    }
}
