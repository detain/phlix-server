<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\Dto\UserRow;
use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * SRV-M12 (review server__dba): the admin user-list endpoint serialized the
 * raw users-table row — `password_hash`, `password_reset_token` and
 * `provider_data` crossed the HTTP boundary into SPA memory, proxies and any
 * response-logging layer, in one unpaginated response.
 *
 * The cure is one estate-wide definition of that secret set
 * ({@see UserRow::forWire()}, applied by the repository at the source of the
 * two admin-listing readers and by the one controller endpoint that reads a
 * raw row). These tests pin:
 *
 * 1. the hydration law: every row produced by the admin-listing readers
 *    ({@see UserRepository::findAll()}, {@see UserRepository::listByStatus()})
 *    is credential-free while keeping every operational column the documented
 *    consumers (vendored admin SPA, console client) read — RED at parent,
 *    where both methods returned the raw `SELECT *` row verbatim;
 * 2. the boundary shape: `GET /api/v1/admin/users` (plain and `?status=`) and
 *    `GET /api/v1/admin/users/{id}` carry no credential keys — RED at parent;
 * 3. the anti-over-redaction law: the authentication lookups keep the RAW row
 *    (`password_verify()` needs `password_hash`) — green at parent, a
 *    regression guard against "curing" M12 by breaking login;
 * 4. the single-source law: exactly one file in src/ declares a constant
 *    carrying the whole secret set, and the wire consumers route through
 *    {@see UserRow::forWire()} — RED at parent, where `UserListCommand` carried
 *    its own private `SENSITIVE_COLUMNS` copy (the per-caller convention the
 *    review named as the root cause).
 *
 * Rows here deliberately carry REALISTIC credential values, so a leak trips
 * the assertion instead of hiding behind an empty string.
 */
final class UserRowWireRedactionTest extends TestCase
{
    private const SRC_DIR = __DIR__ . '/../../../src';

    /** The users-table credential columns (the set {@see UserRow::forWire()} strips). */
    private const SECRET_COLUMNS = ['password_hash', 'password_reset_token', 'provider_data'];

    /** Operational columns the admin surfaces read — must SURVIVE redaction. */
    private const KEPT_COLUMNS = [
        'id',
        'username',
        'email',
        'display_name',
        'avatar_url',
        'is_admin',
        'status',
        'must_change_password',
        'password_reset_expires_at',
        'created_at',
        'updated_at',
        'last_login',
        'logout_all_devices_at',
        'tokens_not_valid_after',
    ];

    /**
     * A realistic raw `SELECT *` row, exactly as the Workerman driver hands it
     * to the repository.
     *
     * @return array<string, mixed>
     */
    private static function rawRow(string $id = 'user-1', string $username = 'alice'): array
    {
        return [
            'id' => $id,
            'username' => $username,
            'email' => $username . '@example.com',
            'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA',
            'provider' => null,
            'external_id' => null,
            'provider_data' => '{"access_token":"live-oauth-token"}',
            'display_name' => 'Alice',
            'avatar_url' => null,
            'is_admin' => 0,
            'status' => 'active',
            'must_change_password' => 0,
            'password_reset_token' => '$argon2id$v=19$m=65536,t=4,p=1$cnM$cmVzZXQtaGFzaA',
            'password_reset_expires_at' => 1780000000,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
            'last_login' => '2026-01-03 00:00:00',
            'logout_all_devices_at' => null,
            'tokens_not_valid_after' => null,
        ];
    }

    /**
     * A UserRepository whose connection answers every query with $rows — the
     * shape of a real `SELECT *` result set.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function repositoryReturning(array $rows): UserRepository
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($rows);

        return new UserRepository($db);
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. The hydration law (repository boundary)
    // ─────────────────────────────────────────────────────────────────

    public function test_find_all_never_hands_credential_columns_to_a_caller(): void
    {
        $repo = $this->repositoryReturning([self::rawRow(), self::rawRow('user-2', 'bob')]);

        $rows = $repo->findAll();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            foreach (self::SECRET_COLUMNS as $column) {
                $this->assertArrayNotHasKey(
                    $column,
                    $row,
                    "findAll() must not hand the credential column {$column} to its caller",
                );
            }
            foreach (self::KEPT_COLUMNS as $column) {
                $this->assertArrayHasKey(
                    $column,
                    $row,
                    "findAll() must keep the operational column {$column} its documented consumers read",
                );
            }
        }

        // Surviving values pass through untouched, not blanked.
        $this->assertSame('alice@example.com', $rows[0]['email']);
        $this->assertSame('2026-01-01 00:00:00', $rows[0]['created_at']);
    }

    public function test_list_by_status_never_hands_credential_columns_to_a_caller(): void
    {
        $row = self::rawRow();
        $row['status'] = 'pending';

        $repo = $this->repositoryReturning([$row]);

        $rows = $repo->listByStatus('pending');

        $this->assertCount(1, $rows);
        foreach (self::SECRET_COLUMNS as $column) {
            $this->assertArrayNotHasKey(
                $column,
                $rows[0],
                "listByStatus() must not hand the credential column {$column} to the approval queue",
            );
        }
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame('alice', $rows[0]['username']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. The boundary shape (HTTP responses)
    // ─────────────────────────────────────────────────────────────────

    public function test_admin_user_list_response_carries_no_credential_columns(): void
    {
        $controller = new AdminUserController(
            $this->repositoryReturning([self::rawRow(), self::rawRow('user-2', 'bob')]),
        );

        $response = $controller->list();

        $this->assertSame(200, $response->statusCode);
        /** @var array{users: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $body['users']);
        foreach ($body['users'] as $row) {
            foreach (self::SECRET_COLUMNS as $column) {
                $this->assertArrayNotHasKey($column, $row);
            }
        }
        // The whole serialized body must not contain a credential value.
        $this->assertStringNotContainsString('argon2id', $response->body);
        $this->assertStringNotContainsString('live-oauth-token', $response->body);
        // And must still carry what the admin UI renders.
        $this->assertStringContainsString('alice@example.com', $response->body);
    }

    public function test_admin_user_list_by_status_response_carries_no_credential_columns(): void
    {
        $row = self::rawRow();
        $row['status'] = 'pending';

        $request = new Request();
        $request->query = ['status' => 'pending'];

        $controller = new AdminUserController($this->repositoryReturning([$row]));

        $response = $controller->list($request);

        $this->assertSame(200, $response->statusCode);
        /** @var array{users: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $body['users']);
        foreach (self::SECRET_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $body['users'][0]);
        }
        $this->assertStringNotContainsString('argon2id', $response->body);
    }

    public function test_admin_user_get_response_carries_no_credential_columns(): void
    {
        // findById() is INTENTIONALLY raw (auth needs it), so the controller
        // endpoint is the place the redaction must happen for this path.
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn(self::rawRow('user-1', 'carol'));

        $controller = new AdminUserController($repo);

        $response = $controller->get(new Request(), ['id' => 'user-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{user: array<string, mixed>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        foreach (self::SECRET_COLUMNS as $column) {
            $this->assertArrayNotHasKey(
                $column,
                $body['user'],
                "get() reads a raw findById() row, so it must redact {$column} itself",
            );
        }
        $this->assertSame('carol', $body['user']['username']);
        $this->assertStringNotContainsString('argon2id', $response->body);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. Anti-over-redaction: authentication still sees the raw row
    // ─────────────────────────────────────────────────────────────────

    public function test_authentication_lookups_still_return_the_raw_credential_row(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([self::rawRow()]);

        $repo = new UserRepository($db);

        // password_verify() needs password_hash: redacting these paths would
        // "cure" SRV-M12 by breaking login. Pin the opposite, loudly.
        foreach (
            [
                'findById' => ['user-1'],
                'findByUsername' => ['alice'],
                'findByEmail' => ['alice@example.com'],
            ] as $method => $args
        ) {
            /** @var array<string, mixed>|null $row */
            $row = $repo->{$method}(...$args);
            $this->assertIsArray($row, "{$method}() must still resolve");
            $this->assertArrayHasKey(
                'password_hash',
                $row,
                "{$method}() feeds password_verify(); it must keep the RAW credential row",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. The single-source law
    // ─────────────────────────────────────────────────────────────────

    public function test_the_secret_set_is_defined_exactly_once_in_src(): void
    {
        $this->assertSame(
            ['Auth/Dto/UserRow.php'],
            $this->secretSetDefiningFiles(),
            'the users-table secret column set must be declared in exactly one '
                . 'place (UserRow::SECRET_COLUMNS behind forWire()); a second copy '
                . 'anywhere in src/ is the per-caller convention that let the admin '
                . 'user-list leak credential columns (SRV-M12)',
        );
    }

    public function test_the_helper_is_pure_and_order_preserving(): void
    {
        $raw = self::rawRow();
        $before = $raw;

        $wire = UserRow::forWire($raw);

        foreach (self::SECRET_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $wire);
        }
        foreach (self::KEPT_COLUMNS as $column) {
            $this->assertArrayHasKey($column, $wire);
        }
        // Pure: the input row is handed back untouched...
        $this->assertSame($before, $raw, 'forWire() must never mutate its argument');
        // ...and what remains keeps its relative order (key order is part of
        // the serialized shape some clients parse positionally).
        $survivors = array_keys($before);
        $survivors = array_values(array_diff($survivors, self::SECRET_COLUMNS));
        $this->assertSame($survivors, array_keys($wire));
        // Row without any secret columns is returned unchanged.
        $clean = ['id' => 'x', 'username' => 'y'];
        $this->assertSame($clean, UserRow::forWire($clean));
    }

    public function test_the_wire_consumers_route_through_the_shared_helper(): void
    {
        foreach (
            [
                'Auth/UserRepository.php',
                'Console/Commands/UserListCommand.php',
                'Server/Http/Controllers/Admin/AdminUserController.php',
            ] as $relative
        ) {
            $this->assertTrue(
                $this->hasCallTo($this->tokens(self::SRC_DIR . '/' . $relative), ['forWire']),
                "{$relative} must call the single-source redaction helper UserRow::forWire()",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Token-walk helpers (the estate's StaticAnalysisScope/MintSeam idiom)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Files under src/ declaring a constant whose value carries ALL of the
     * secret column names — i.e. a re-declaration of the same policy.
     *
     * @return list<string> paths relative to src/, sorted
     */
    private function secretSetDefiningFiles(): array
    {
        $flagged = [];
        foreach ($this->phpFiles(self::SRC_DIR) as $abs) {
            $literals = $this->constDeclarationLiterals($this->tokens($abs));
            $definesTheSet = true;
            foreach (self::SECRET_COLUMNS as $column) {
                if (!in_array($column, $literals, true)) {
                    $definesTheSet = false;
                    break;
                }
            }
            if ($definesTheSet) {
                $flagged[] = str_replace(self::SRC_DIR . '/', '', $abs);
            }
        }
        sort($flagged);

        return $flagged;
    }

    /**
     * Every quoted literal appearing inside a `const` declaration of the given
     * token stream. A const is a flat `NAME = <expr>;` assignment, so taking
     * the string literals between `=` and `;` is exact for array-shaped consts
     * — the shape a column set takes.
     *
     * @param list<array{int|string,string}|string> $tokens
     *
     * @return list<string>
     */
    private function constDeclarationLiterals(array $tokens): array
    {
        $literals = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_CONST) {
                continue;
            }
            $j = $i + 1;
            $seenEquals = false;
            while ($j < $count) {
                $next = $tokens[$j];
                if ($next === ';') {
                    break;
                }
                if ($next === '=') {
                    $seenEquals = true;
                }
                if ($seenEquals && is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literals[] = trim($next[1], "'\"");
                }
                $j++;
            }
            $i = $j;
        }

        return $literals;
    }

    /**
     * @return \Generator<string>
     */
    private function phpFiles(string $root): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    /**
     * @return list<array{int|string,string}|string>
     */
    private function tokens(string $path): array
    {
        $source = file_get_contents($path);
        $this->assertIsString($source, "unreadable source file {$path}");

        return token_get_all($source);
    }

    /**
     * True when the token stream contains NAME as a bare T_STRING preceded
     * (ignoring whitespace/comments) by `->`, `?->`, or `::` — a call or
     * class-qualified reference. Definitions (`function forWire`) sit behind
     * T_FUNCTION and comments/docblocks are separate token types, so neither
     * can satisfy the law — only a real call site does.
     *
     * @param list<array{int|string,string}|string> $tokens
     * @param list<string>                         $names
     */
    private function hasCallTo(array $tokens, array $names): bool
    {
        $operators = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR];
        $count = count($tokens);
        for ($i = 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], $names, true)) {
                continue;
            }
            $prev = $this->previousSignificant($tokens, $i);
            if (is_array($prev) && in_array($prev[0], $operators, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The previous significant (non-whitespace, non-comment) token before $i,
     * or null at stream start.
     *
     * @param list<array{int|string,string}|string> $tokens
     *
     * @return array{int|string,string}|string|null
     */
    private function previousSignificant(array $tokens, int $i): array|string|null
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
