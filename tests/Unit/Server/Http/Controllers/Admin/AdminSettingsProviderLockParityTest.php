<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Plugins\Github\Plugin as GithubPlugin;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Plugins\Repository\PluginSettingsStore;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Recording fake of the `server_settings` table for the provider-parity proof.
 *
 * Deliberate clone of AuthLockRecorder (AdminSettingsControllerAuthLockTest)
 * — same statement classifier, same default-arm tripwire — under a distinct
 * name because the two fixture classes share this namespace (a co-located
 * `AuthLockRecorder` here would collide, and the allow-list pattern keeps
 * each fake beside its consumer). Cloning the CLASSIFIER is the point: both
 * surfaces must survive the identical statement-shape lens, and the expected
 * sequences are read side-by-side against the admin file.
 */
final class ProviderLockRecorder
{
    /** @var list<string> */
    public array $statements = [];

    /** @var array<string, array{setting_key: string, setting_value: string, value_type: string}> */
    public array $rows = [];

    /** @var list<string>|null */
    public ?array $lockParams = null;

    /** @var (callable(array<string, array{setting_key: string, setting_value: string, value_type: string}>): void)|null */
    public $onLock = null;
}

/**
 * B2 provider parity: the provider-integrations DISABLE surface runs the
 * SAME serialized protocol as the admin settings PUT —
 * BEGIN → `SELECT ... FOR UPDATE` over the five canonical toggle keys in the
 * canonical order → LOCKED guard re-read → flag write → COMMIT; rejection
 * ROLLBACKs with zero persistence and the byte-identical 422 envelope.
 *
 * Pre-parity the disable guard was a plain pre-write check
 * (assertSafeTransition on an unlocked snapshot, then an unserialized
 * bootstrapper->disable()), so a disable∥admin-PUT interleave could land the
 * server all-off. These laws are about STATEMENT SHAPE and ORDER against the
 * real AuthProviderController → real AuthMethodPolicy → real
 * SettingsRepository → ONE recording Connection — the same single-store
 * topology production has via the PHP-DI singleton (identity pinned by
 * AuthMethodPolicyWiringGuardTest). The real AuthProviderBootstrapper writes
 * through that same store, so the flag write is observed as an UPSERT inside
 * the transaction rather than asserted via a mock.
 *
 * Mirrors AdminSettingsControllerAuthLockTest test-for-test:
 *   - parity Law 1  ↔ admin Law 1 (accepted order + five-key lock);
 *   - parity Law 2  ↔ admin Law 2 (concurrent commit visible under the lock);
 *   - parity Law 3  ↔ admin Law 3 (rejection = ROLLBACK + zero writes);
 *   - zero-txn enable ↔ admin Law 4's sibling (the ON direction needs none);
 *   - recorder tripwire law carried over verbatim.
 */
final class AdminSettingsProviderLockParityTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../../../../../config';

    /** The canonical lock set AdminSettingsControllerAuthLockTest pins — byte-identical order. */
    private const CANONICAL_LOCK_KEYS = [
        'auth.password.enabled',
        'auth.webauthn.enabled',
        'auth.oidc.enabled',
        'auth.ldap.enabled',
        'auth.github.enabled',
    ];

    /** Wire a Connection mock that routes every statement through the recorder. */
    private function buildConnection(ProviderLockRecorder $recorder): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use ($recorder): array {
                if (str_contains($sql, 'FOR UPDATE')) {
                    $recorder->statements[] = 'LOCK';
                    $recorder->lockParams   = is_array($params) ? array_values($params) : [];
                    if ($recorder->onLock !== null) {
                        ($recorder->onLock)($recorder->rows);
                    }

                    return [];
                }

                if ($sql === 'SELECT setting_key, setting_value, value_type FROM server_settings') {
                    $recorder->statements[] = 'SELECT_ALL';

                    return array_values($recorder->rows);
                }

                if (str_contains($sql, 'INSERT INTO server_settings')) {
                    $recorder->statements[] = 'UPSERT';
                    /** @var array{0: string, 1: string, 2: string, 3: string} $p */
                    $p                     = is_array($params) ? $params : ['', '', '', ''];
                    $recorder->rows[$p[1]] = ['setting_key' => $p[1], 'setting_value' => $p[2], 'value_type' => $p[3]];

                    return [];
                }

                throw new RuntimeException('unexpected statement: ' . $sql);
            },
        );
        $db->method('beginTrans')->willReturnCallback(static function () use ($recorder): bool {
            $recorder->statements[] = 'BEGIN';

            return true;
        });
        $db->method('commitTrans')->willReturnCallback(static function () use ($recorder): bool {
            $recorder->statements[] = 'COMMIT';

            return true;
        });
        $db->method('rollBackTrans')->willReturnCallback(static function () use ($recorder): bool {
            $recorder->statements[] = 'ROLLBACK';

            return true;
        });

        return $db;
    }

    /** @param array<string, string> $seed setting_key => stored text value */
    private function store(ProviderLockRecorder $recorder, array $seed): SettingsRepository
    {
        foreach ($seed as $key => $encoded) {
            $recorder->rows[$key] = [
                'setting_key'   => $key,
                'setting_value' => $encoded,
                'value_type'    => 'bool',
            ];
        }

        return new SettingsRepository($this->buildConnection($recorder), self::CONFIG_DIR);
    }

    /**
     * Policy dependencies other than the shared store: one admin by default,
     * no WebAuthn credentials, identities/has_password per test seed.
     *
     * @param list<array{id: string, has_password: bool}> $admins
     * @param array<string, list<string>>                  $identities
     */
    private function policy(
        SettingsRepository $store,
        AuthProviderBootstrapper $bootstrapper,
        array $admins = [['id' => 'admin-1', 'has_password' => true]],
        array $identities = [],
    ): AuthMethodPolicy {
        $users = $this->createMock(UserRepository::class);
        $users->method('findActiveAdminsForLockoutProbe')->willReturn($admins);

        $waDb = $this->createMock(Connection::class);
        $waDb->method('query')->willReturn([]);

        $identitiesRepo = $this->createMock(UserIdentityRepository::class);
        $identitiesRepo->method('findByUserId')->willReturnCallback(
            static fn (string $userId): array => array_map(
                static fn (string $provider): array => ['provider' => $provider],
                $identities[$userId] ?? [],
            ),
        );

        return new AuthMethodPolicy(
            $store,
            $users,
            new WebAuthnCredentialRepository($waDb),
            $identitiesRepo,
            $bootstrapper,
        );
    }

    /**
     * The full provider stack on ONE store: real bootstrapper (github fully
     * configured via plugin-mock settings), real registry, real policy —
     * mirroring the production PHP-DI singleton wiring where all three share
     * the one SettingsRepository/connection the protocol locks on.
     */
    private function providerController(
        ProviderLockRecorder $recorder,
        SettingsRepository $store,
        ?AuthMethodPolicy $policy = null,
        array $admins = [['id' => 'admin-1', 'has_password' => true]],
        array $identities = [],
    ): AuthProviderController {
        $registry = new AuthProviderRegistry();

        // The plugin classes are final — they are REAL, fed by a mocked
        // PluginSettingsStore interface (the DB-backed settings source).
        // GitHub answers fully configured; oidc/ldap stores are never read
        // on these paths (only the toggled provider is built/fingerprinted).
        $githubStore = $this->createMock(PluginSettingsStore::class);
        $githubStore->method('get')->with('github')->willReturn([
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ]);

        $bootstrapper = new AuthProviderBootstrapper(
            $store,
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
            new GithubPlugin($githubStore),
        );

        $policy ??= $this->policy($store, $bootstrapper, $admins, $identities);

        return new AuthProviderController($registry, $bootstrapper, $policy);
    }

    private function disable(AuthProviderController $controller, string $name): Response
    {
        return $controller->disableProvider($this->createMock(Request::class), ['name' => $name]);
    }

    /**
     * Parity Law 1 — accepted provider disable: BEGIN, FOR UPDATE over all
     * five toggle keys, the guard's locked state read, the flag UPSERT,
     * COMMIT. No ROLLBACK on the accepted path. Same statement lens, same
     * canonical lock set as the admin surface's Law 1.
     *
     * (The provider path has one fewer SELECT_ALL than the admin path only
     * because admin additionally reads getEffectiveMany to build its response
     * body — the guard-window prefix BEGIN → LOCK → SELECT_ALL and the
     * terminal COMMIT are the shared protocol shape.)
     */
    public function test_disable_statement_order_is_begin_lock_read_write_commit(): void
    {
        $recorder = new ProviderLockRecorder();
        $store    = $this->store($recorder, [
            'auth.password.enabled'   => '1',
            'auth.webauthn.enabled'   => '1',
            'auth.github.enabled'     => '1',
        ]);
        $response = $this->disable($this->providerController($recorder, $store), 'github');

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(
            ['BEGIN', 'LOCK', 'SELECT_ALL', 'UPSERT', 'COMMIT'],
            $recorder->statements,
            'provider disable must serialize as BEGIN → FOR UPDATE → locked state read → write → COMMIT',
        );
        $this->assertNotContains('ROLLBACK', $recorder->statements);
        $this->assertSame('0', $recorder->rows['auth.github.enabled']['setting_value']);
        $this->assertSame('bool', $recorder->rows['auth.github.enabled']['value_type']);
    }

    /**
     * Parity Law 1-bis (universal overlap) — the lock set is the SAME five
     * canonical keys in the SAME canonical order the admin surface locks,
     * not the touched key alone. Two writers with identical lock sets can
     * only ever fully serialize; a partial-overlap deadlock pair cannot form.
     */
    public function test_disable_locks_the_identical_canonical_five_key_set_as_the_admin_surface(): void
    {
        $recorder = new ProviderLockRecorder();
        $store    = $this->store($recorder, [
            'auth.password.enabled' => '1',
            'auth.webauthn.enabled' => '1',
            'auth.github.enabled'   => '1',
        ]);

        $this->disable($this->providerController($recorder, $store), 'github');

        $this->assertSame(
            self::CANONICAL_LOCK_KEYS,
            $recorder->lockParams,
            'provider disable must lock the identical five-key set in the identical canonical order as the admin PUT',
        );
    }

    /**
     * Parity Law 2 (the cross-surface race itself) — an admin commit that
     * lands WHILE the provider path holds/waits on the lock must be visible
     * to the guard re-read: password goes OFF under us, webauthn was already
     * OFF, so the github-off disable must refuse (R1) and persist NOTHING.
     *
     * Pre-parity the guard read happened before any lock existed; this exact
     * interleaving persisted the doomed flag row, and this test reddens on
     * the ['UPSERT','COMMIT'] + 200 the unserialized flow produces.
     */
    public function test_concurrent_admin_commit_under_the_lock_is_visible_to_the_provider_guard(): void
    {
        $recorder = new ProviderLockRecorder();
        $recorder->onLock = static function (array &$rows): void {
            $rows['auth.password.enabled']['setting_value'] = '0';
        };
        $store = $this->store($recorder, [
            'auth.password.enabled' => '1',
            'auth.webauthn.enabled' => '0',
            'auth.oidc.enabled'     => '0',
            'auth.ldap.enabled'     => '0',
            'auth.github.enabled'   => '1',
        ]);

        $response = $this->disable($this->providerController($recorder, $store), 'github');

        $this->assertSame(422, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertFalse($body['success']);
        $this->assertSame('Validation failed', $body['error']);
        $this->assertSame('all_methods_disabled', $body['reason']);
        $this->assertSame(['auth.github.enabled'], array_keys($body['errors']));
        $this->assertNotContains('UPSERT', $recorder->statements);
        $this->assertSame(
            ['BEGIN', 'LOCK', 'SELECT_ALL', 'ROLLBACK'],
            $recorder->statements,
            'rejection under lock: rolled back, wrote nothing, read exactly once after the lock',
        );
        $this->assertSame('1', $recorder->rows['auth.github.enabled']['setting_value']);
    }

    /**
     * Parity Law 3 — plain R2 rejection (no concurrency): the last usable
     * admin factor is the provider being disabled. Byte-identical 422
     * envelope, now wrapped in BEGIN…ROLLBACK with zero writes.
     */
    public function test_guard_rejection_rolls_back_and_persists_nothing(): void
    {
        $recorder = new ProviderLockRecorder();
        $store    = $this->store($recorder, [
            'auth.password.enabled' => '1',
            'auth.webauthn.enabled' => '1',
            'auth.github.enabled'   => '1',
        ]);
        $controller = $this->providerController(
            $recorder,
            $store,
            admins: [['id' => 'admin-1', 'has_password' => false]],
            identities: ['admin-1' => ['github']],
        );

        $response = $this->disable($controller, 'github');

        $this->assertSame(422, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertSame('Validation failed', $body['error']);
        $this->assertSame('admin_lockout', $body['reason']);
        $this->assertArrayHasKey('auth.github.enabled', $body['errors']);
        $this->assertArrayHasKey('message', $body);
        $this->assertNotContains('UPSERT', $recorder->statements);
        $this->assertSame(['BEGIN', 'LOCK', 'SELECT_ALL', 'ROLLBACK'], $recorder->statements);
        $this->assertSame('1', $recorder->rows['auth.github.enabled']['setting_value']);
    }

    /**
     * Parity Law 4 — the 404 unknown-provider gate still PRECEDES the
     * protocol: a non-toggleable name never touches BEGIN/LOCK at all.
     */
    public function test_unknown_provider_never_enters_the_transaction(): void
    {
        $recorder = new ProviderLockRecorder();
        $store    = $this->store($recorder, ['auth.password.enabled' => '1']);

        $response = $this->disable($this->providerController($recorder, $store), 'password');

        $this->assertSame(404, $response->statusCode);
        $this->assertSame([], $recorder->statements);
    }

    /**
     * Zero-transaction enable law — the ON direction stays OUTSIDE the
     * protocol (monotone-safe: enabling only ADDS a factor, it can never
     * create an all-off landing). Exactly one UPSERT for the flag row; BEGIN,
     * LOCK, COMMIT and ROLLBACK must never appear. The counterpart of admin
     * Law 4's non-auth zero-txn pin, and the executable half of the
     * enableProvider docblock rationale.
     */
    public function test_enable_provider_persists_without_any_transaction(): void
    {
        $recorder = new ProviderLockRecorder();
        $store    = $this->store($recorder, [
            'auth.password.enabled' => '1',
            'auth.webauthn.enabled' => '1',
        ]);
        $controller = $this->providerController($recorder, $store);

        $response = $controller->enableProvider($this->createMock(Request::class), ['name' => 'github']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertTrue($body['enabled']);
        $this->assertTrue($body['live'], 'github is fully configured in this harness, so enable must report live');
        $this->assertSame(['UPSERT'], $recorder->statements);
        $this->assertNotContains('BEGIN', $recorder->statements);
        $this->assertNotContains('LOCK', $recorder->statements);
        $this->assertNotContains('COMMIT', $recorder->statements);
        $this->assertNotContains('ROLLBACK', $recorder->statements);
        $this->assertNull($recorder->lockParams);
        $this->assertSame('1', $recorder->rows['auth.github.enabled']['setting_value']);
    }

    /**
     * Recorder tripwire law (carried verbatim from the admin suite): the
     * default arm is fail-loud, not decoration — any unclassified statement
     * aborts with the offending SQL instead of silently returning [].
     */
    public function test_recorder_fails_loud_on_any_unclassified_statement(): void
    {
        $recorder = new ProviderLockRecorder();
        $db       = $this->buildConnection($recorder);

        try {
            $db->query('DELETE FROM server_settings WHERE 1 = 1');
            $this->fail('the default arm should have tripped');
        } catch (RuntimeException $tripwire) {
            $this->assertStringContainsString(
                'unexpected statement: DELETE FROM server_settings',
                $tripwire->getMessage()
            );
        }

        $this->assertSame([], $recorder->statements);
    }
}
