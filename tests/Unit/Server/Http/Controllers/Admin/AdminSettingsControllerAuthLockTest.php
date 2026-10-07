<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Recording fake of the `server_settings` table on a Connection mock:
 * labels every statement in order (BEGIN / LOCK / SELECT_ALL / UPSERT /
 * COMMIT / ROLLBACK), captures the FOR UPDATE bind params, and runs an
 * optional injection closure the moment the lock statement arrives — the
 * deterministic stand-in for "the other writer committed while we were
 * blocking on the lock".
 *
 * Co-located with its only consumer per the tests/ allow-list for
 * in-file fixtures (PSR1.Classes.ClassDeclaration.MultipleTests is
 * excluded by phpcs-tests.xml for exactly this pattern).
 */
final class AuthLockRecorder
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
 * B2: the F7 auth-method lock-out guard re-validates INSIDE a transaction,
 * under `SELECT ... FOR UPDATE` row locks, on the auth-toggle write path.
 *
 * The original guard was a read-probe-write: it validated against a live
 * snapshot, then persisted without any lock, so two concurrent admin PUTs
 * could each see the other's method still ON and land the server all-off.
 *
 * Unlike AdminSettingsControllerAuthGuardTest (mocked store, envelope law),
 * this file drives a REAL SettingsRepository on a recording fake Connection,
 * because the laws here are about STATEMENT SHAPE and ORDER:
 *   - BEGIN → FOR UPDATE → state read → writes → COMMIT on auth PUTs;
 *   - ROLLBACK + zero UPSERT on guard rejection;
 *   - ZERO transaction/lock statements on non-auth PUTs;
 *   - and the race itself, deterministically: a concurrent commit that lands
 *     exactly while the lock is taken must be VISIBLE to the guard re-read —
 *     the pre-B2 ordering (guard before the lock) reddens that test by
 *     persisting the doomed toggle.
 */
final class AdminSettingsControllerAuthLockTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../../../../../config';

    /** Wire a Connection mock that routes every statement through the recorder. */
    private function buildConnection(AuthLockRecorder $recorder): Connection
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

                $this->fail('unexpected statement: ' . $sql);
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
    private function store(AuthLockRecorder $recorder, array $seed): SettingsRepository
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

    private function controller(SettingsRepository $store, bool $withPolicy = true): AdminSettingsController
    {
        if (!$withPolicy) {
            return new AdminSettingsController($store);
        }

        $users = $this->createMock(UserRepository::class);
        $users->method('findActiveAdminsForLockoutProbe')->willReturn([]);

        $policy = new AuthMethodPolicy(
            $store,
            $users,
            new WebAuthnCredentialRepository($this->createMock(Connection::class)),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );

        return new AdminSettingsController($store, $policy);
    }

    private function put(array $settings, AdminSettingsController $controller): Response
    {
        $request       = new Request();
        $request->body = ['settings' => $settings];

        return $controller->update($request, []);
    }

    /**
     * Law 1 — happy auth PUT: BEGIN, FOR UPDATE over all five toggle keys,
     * THEN the guard's state read, the write, the response read, COMMIT.
     * No ROLLBACK on the accepted path.
     */
    public function test_auth_put_statement_order_is_begin_lock_read_write_read_commit(): void
    {
        $recorder   = new AuthLockRecorder();
        $store      = $this->store($recorder, ['auth.password.enabled' => '1', 'auth.webauthn.enabled' => '1']);
        $controller = $this->controller($store);

        $response = $this->put(['auth.webauthn.enabled' => false], $controller);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(
            ['BEGIN', 'LOCK', 'SELECT_ALL', 'UPSERT', 'SELECT_ALL', 'COMMIT'],
            $recorder->statements,
            'auth PUT must serialize as BEGIN → FOR UPDATE → locked state read → write → response read → COMMIT',
        );
        $this->assertSame(
            [
                'auth.password.enabled',
                'auth.webauthn.enabled',
                'auth.oidc.enabled',
                'auth.ldap.enabled',
                'auth.github.enabled',
            ],
            $recorder->lockParams,
            'the lock must cover all five toggle key slots, not just the touched one',
        );
        $this->assertNotContains('ROLLBACK', $recorder->statements);
    }

    /**
     * Law 2 (the race itself) — a concurrent commit that lands WHILE the lock
     * is being taken must be seen by the guard: password goes OFF under us,
     * so our webauthn-off PUT must then refuse (R1) and persist NOTHING.
     *
     * Pre-B2 the guard read happened before any lock existed; this exact
     * interleaving persisted both toggles OFF, and this test reddens on the
     * 'UPSERT' + 200 the unserialized flow produces.
     */
    public function test_concurrent_disable_committed_under_the_lock_is_visible_to_the_guard(): void
    {
        $recorder = new AuthLockRecorder();
        $recorder->onLock = static function (array &$rows): void {
            $rows['auth.password.enabled']['setting_value'] = '0';
        };
        $store      = $this->store($recorder, ['auth.password.enabled' => '1', 'auth.webauthn.enabled' => '1']);
        $controller = $this->controller($store);

        $response = $this->put(['auth.webauthn.enabled' => false], $controller);

        $this->assertSame(422, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertFalse($body['success']);
        $this->assertSame('Validation failed', $body['error']);
        $this->assertSame('all_methods_disabled', $body['reason']);
        $this->assertSame(['auth.webauthn.enabled'], array_keys($body['errors']));
        $this->assertNotContains('UPSERT', $recorder->statements);
        $this->assertSame(
            ['BEGIN', 'LOCK', 'SELECT_ALL', 'ROLLBACK'],
            $recorder->statements,
            'rejection under lock: rolled back, wrote nothing, read exactly once after the lock',
        );
    }

    /**
     * Law 3 — plain guard rejection (no concurrency): the 422 envelope is the
     * same byte-identical contract surface, now wrapped in BEGIN…ROLLBACK.
     */
    public function test_guard_rejection_rolls_back_and_persists_nothing(): void
    {
        $recorder   = new AuthLockRecorder();
        $store      = $this->store($recorder, ['auth.password.enabled' => '0', 'auth.webauthn.enabled' => '1']);
        $controller = $this->controller($store);

        $response = $this->put(['auth.webauthn.enabled' => false], $controller);

        $this->assertSame(422, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertSame('all_methods_disabled', $body['reason']);
        $this->assertArrayHasKey('message', $body);
        $this->assertNotContains('UPSERT', $recorder->statements);
        $this->assertSame(['BEGIN', 'LOCK', 'SELECT_ALL', 'ROLLBACK'], $recorder->statements);
    }

    /**
     * Law 4 — a write-set WITHOUT toggles changes nothing: no BEGIN, no FOR
     * UPDATE, no COMMIT, no ROLLBACK ever touches the connection. Zero new
     * locks for every ordinary settings save.
     */
    public function test_non_auth_put_takes_no_transaction_and_no_lock(): void
    {
        $recorder   = new AuthLockRecorder();
        $store      = $this->store($recorder, []);
        $controller = $this->controller($store);

        $response = $this->put(['metadata.min_match_confidence' => 0.5], $controller);

        $this->assertSame(200, $response->statusCode);
        $this->assertContains('UPSERT', $recorder->statements);
        $this->assertNotContains('BEGIN', $recorder->statements);
        $this->assertNotContains('LOCK', $recorder->statements);
        $this->assertNotContains('COMMIT', $recorder->statements);
        $this->assertNotContains('ROLLBACK', $recorder->statements);
    }

    /**
     * Law 5 — the unwired-guard 500 fail-closed path also rolls its (empty)
     * transaction back: no dangling open transaction on the pooled connection.
     */
    public function test_unwired_guard_rejection_still_rolls_back(): void
    {
        $recorder   = new AuthLockRecorder();
        $store      = $this->store($recorder, ['auth.password.enabled' => '1', 'auth.webauthn.enabled' => '1']);
        $controller = $this->controller($store, withPolicy: false);

        $response = $this->put(['auth.webauthn.enabled' => false], $controller);

        $this->assertSame(500, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertSame('Auth-method lock-out guard unavailable', $body['error']);
        $this->assertNotContains('UPSERT', $recorder->statements);
        $this->assertSame(['BEGIN', 'LOCK', 'ROLLBACK'], $recorder->statements);
    }
}
