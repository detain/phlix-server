<?php

/**
 * 2026-11-10 off-kit owner ruling (estate audit MINOR-2/MAJOR-2): the
 * shared-schema admin key `auth.ws_query_token` is WIRED as the tier-2 read
 * of the CP-007/CP-024 `:8097` query-carrier gate — the SSOT resolver is
 * {@see QueryTokenGatePolicy} and the precedence is FIXED:
 *
 *   EXPLICIT ENV PHLIX_WS_QUERY_TOKEN  >  ADMIN ROW auth.ws_query_token  >
 *   PHASE B DEFAULT DENY
 *
 * Every leg here injects a fake settings store (the de573d6e rework law:
 * per-decision freshness is only provable through an injected repository
 * whose rows can change under a LIVE server). Defect-inheritance law: each
 * limb carries a uniquely convicting arm-pure check — tier-1 legs assert the
 * row store is NEVER consulted (read counter 0), row legs assert it IS
 * consulted (counter >= 1), and the non-query carrier legs pin the whole
 * admin tier is invisible to bearer/cookie handshakes (CP-006 precedence law
 * UNMOVED). The shared-schema key is the string enum 'allow'/'deny'
 * (phlix-shared schemas/server-settings.schema.json) — the brief's "bool"
 * premise is falsified by the schema on disk and pinned so by the
 * non-string leg (a bool row is an unusable shape: deny).
 *
 * Unmoved laws re-pinned per leg: carrier precedence, the pre-101 rejection
 * path (zero new wire codes), the redacted warn-log (no token bytes ever,
 * and NEVER the raw stored-row bytes either), and the env escape hatch
 * restoring PHASE A byte-ident acceptance.
 *
 * Harness idiom: WsQueryTokenGateTest (temp stream-handler logger.php via
 * LoggerFactory + real parsed upgrade Requests over mocked TcpConnections).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Auth\JwtHandler;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\QueryTokenGatePolicy;
use Phlix\Server\WebSocket\SyncPlayAuthMiddleware;
use Phlix\Server\WebSocket\WebSocketServer;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

final class WsQueryTokenAdminControlTest extends TestCase
{
    /** Shared-schema admin key under test (resolver constant = SSOT). */
    private const ROW_KEY = QueryTokenGatePolicy::ADMIN_SETTING_KEY;

    private const ALLOW_LITERALS = ['legacy ?token= query carrier', 'PHLIX_WS_QUERY_TOKEN=allow'];
    private const DENY_LITERALS = ['legacy ?token= query carrier', 'PHLIX_WS_QUERY_TOKEN=deny'];
    private const REDACTED_FORM = 'token=[redacted]';
    private const UNUSABLE_MARK = 'holds an unusable value';
    private const UNREADABLE_MARK = 'settings store unreadable';

    private JwtHandler $jwtHandler;
    private string $jwtSecret = 'ws-query-token-admin-row-secret-256-';
    private string $tempDir = '';
    private string $logPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('PHLIX_WS_QUERY_TOKEN');
        ConnectionPool::getInstance()->clear();
        $this->jwtHandler = new JwtHandler($this->jwtSecret, 'HS256', 3600, 604800);
        $this->bindFileLogger();
    }

    protected function tearDown(): void
    {
        putenv('PHLIX_WS_QUERY_TOKEN');
        ConnectionPool::getInstance()->clear();
        LoggerFactory::reset();
        foreach (['/logger.php', '/app.log'] as $residue) {
            if (is_file($this->tempDir . $residue)) {
                unlink($this->tempDir . $residue);
            }
        }
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function bindFileLogger(): void
    {
        $base = realpath(sys_get_temp_dir());
        $this->assertIsString($base);
        $this->tempDir = $base . '/phlix_wqta_' . uniqid('', true);
        mkdir($this->tempDir, 0o775, true);
        $this->logPath = $this->tempDir . '/app.log';
        $configPath = $this->tempDir . '/logger.php';
        file_put_contents(
            $configPath,
            "<?php\nreturn [\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->logPath, true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n"
        );
        LoggerFactory::reset();
        LoggerFactory::init($configPath);
    }

    private function logOffset(): int
    {
        clearstatcache(true, $this->logPath);
        return is_file($this->logPath) ? (int)filesize($this->logPath) : 0;
    }

    private function logSegment(int $from): string
    {
        // The quiet-path legs (well-formed rows warn nothing) can finish a
        // decision before Monolog's StreamHandler ever opens the file — an
        // unguarded read warns. Absence IS the empty segment.
        return is_file($this->logPath) ? substr((string)file_get_contents($this->logPath), $from) : '';
    }

    private function normalizeLog(string $segment): string
    {
        $normalized = preg_replace('/^\[[^\]]+\]/m', '[TS]', $segment);
        $this->assertIsString($normalized);
        $normalized = preg_replace('/"connection_id":"[^"]*"/', '"connection_id":"CID"', $normalized);
        $this->assertIsString($normalized);
        return $normalized;
    }

    /**
     * @param array<string, mixed> $rows
     */
    private function fakeRepo(array $rows = []): WsQueryTokenAdminRowFakeSettings
    {
        return new WsQueryTokenAdminRowFakeSettings($rows);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $extraHeaders
     * @return array{tracker: array<string, mixed>, headers: array<int, string>, log: string}
     */
    private function runHandshake(
        array $config,
        ?SettingsRepository $settings,
        ?string $queryToken = null,
        ?string $subprotocol = null,
        ?string $sessionCookie = null,
        array $extraHeaders = []
    ): array {
        return $this->runHandshakeOn(
            new WebSocketServer($config, null, null, null, $settings),
            $queryToken,
            $subprotocol,
            $sessionCookie,
            $extraHeaders
        );
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{tracker: array<string, mixed>, headers: array<int, string>, log: string}
     */
    private function runHandshakeOn(
        WebSocketServer $server,
        ?string $queryToken = null,
        ?string $subprotocol = null,
        ?string $sessionCookie = null,
        array $extraHeaders = []
    ): array {
        $tracker = [];
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('close')->willReturnCallback(function () use (&$tracker): void {
            $tracker['close'] = true;
        });

        $offset = $this->logOffset();
        $server->onConnect($connection);
        $server->onWebSocketConnect($connection, $this->makeHandshakeRequest(
            $queryToken,
            $subprotocol,
            $sessionCookie,
            $extraHeaders
        ));

        return ['tracker' => $tracker, 'headers' => $connection->headers, 'log' => $this->logSegment($offset)];
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function makeHandshakeRequest(
        ?string $queryToken = null,
        ?string $subprotocol = null,
        ?string $sessionCookie = null,
        array $extraHeaders = []
    ): Request {
        $query = $queryToken === null ? '' : '?token=' . $queryToken;
        $raw = "GET /syncplay{$query} HTTP/1.1\r\nHost: localhost\r\n";
        if ($subprotocol !== null) {
            $raw .= "Sec-WebSocket-Protocol: {$subprotocol}\r\n";
        }
        if ($sessionCookie !== null) {
            $raw .= 'Cookie: ' . AuthController::SESSION_COOKIE . '=' . $sessionCookie . "\r\n";
        }
        foreach ($extraHeaders as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }

        return new Request($raw . "\r\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function authConfig(?string $queryTokenPolicy = null): array
    {
        $config = ['host' => '0.0.0.0', 'port' => 8097, 'jwt_secret' => $this->jwtSecret];
        if ($queryTokenPolicy !== null) {
            $config['query_token'] = $queryTokenPolicy;
        }
        return $config;
    }

    // --- tier 2 convictions: the admin row speaks when env is silent ---------

    public function test_adminRowAllowAdmitsQueryCarrierWhenEnvIsSilent(): void
    {
        // Conviction (a): row 'allow' + env unset -> the QUERY carrier is
        // accepted (telemetry literal + redaction intact — the row-driven
        // allow rides the PHASE A log site byte-ident) and the store was
        // actually consulted (counter >= 1 proves tier 2 was reached, not a
        // silently-skipped arm). Strip the resolver's row read -> red.
        $repo = $this->fakeRepo([self::ROW_KEY => 'allow']);
        $rawToken = $this->jwtHandler->createAccessToken('user-row-allow');

        $result = $this->runHandshake($this->authConfig(), $repo, $rawToken);

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertTrue($connections[0]->isAuthenticated());
        $this->assertArrayNotHasKey('close', $result['tracker']);
        $this->assertGreaterThanOrEqual(1, $repo->reads, 'The admin row must be consulted when env is silent.');
        foreach (self::ALLOW_LITERALS as $literal) {
            $this->assertStringContainsString($literal, $result['log']);
        }
        $this->assertStringContainsString(self::REDACTED_FORM, $result['log']);
        $this->assertStringNotContainsString($rawToken, $result['log']);
    }

    public function test_rowAbsentWithEnvSilentDeniesByteIdentToExplicitDenyAndStillConsultsTheRow(): void
    {
        // Conviction (e): row absent + env absent == today's deny,
        // byte-ident — same tracker, same (empty) headers, same normalized
        // warn-log as the explicit-deny arm — AND the row tier ran (counter
        // == 1: silence must be produced by an honest row lookup, never by
        // skipping tier 2). Kill the default tier -> red on the identity
        // compare; skip the read -> red on the counter.
        $repo = $this->fakeRepo();
        $token = $this->jwtHandler->createAccessToken('user-row-absent');

        $rowRun = $this->runHandshake($this->authConfig(), $repo, $token);
        $rowLog = $this->normalizeLog($rowRun['log']);
        $this->assertSame(1, $repo->reads, 'Absence must be an honest row read, not a skipped tier.');

        ConnectionPool::getInstance()->clear();
        $denyRun = $this->runHandshake($this->authConfig('deny'), null, $token);

        $this->assertTrue($rowRun['tracker']['close'] ?? false);
        $this->assertCount(0, ConnectionPool::getInstance()->all());
        $this->assertSame($rowRun['tracker'], $denyRun['tracker']);
        $this->assertSame($rowRun['headers'], $denyRun['headers']);
        $this->assertSame($rowLog, $this->normalizeLog($denyRun['log']));
        foreach (self::DENY_LITERALS as $literal) {
            $this->assertStringContainsString($literal, $rowLog);
        }
        $this->assertStringNotContainsString($token, $rowLog);
    }

    public function test_adminRowFlipIsHonoredOnTheNextHandshakeWithoutRebuild(): void
    {
        // The de573d6e stale-cache killer, per-decision arm: ONE live
        // WebSocketServer (one middleware, one resolver instance) — absent
        // row denies, the flip to 'allow' admits on the VERY NEXT handshake
        // with no restart/recycle, the flip back to 'deny' refuses again.
        // Any memoization of the row inside the resolver -> red on the flip
        // (the class the F7 rework MAJOR'd before).
        $repo = $this->fakeRepo();
        $server = new WebSocketServer($this->authConfig(), null, null, null, $repo);
        $token = $this->jwtHandler->createAccessToken('user-row-flip');

        $first = $this->runHandshakeOn($server, $token);
        $this->assertTrue($first['tracker']['close'] ?? false, 'Absent row must deny.');

        ConnectionPool::getInstance()->clear();
        $repo->rows[self::ROW_KEY] = 'allow';
        $second = $this->runHandshakeOn($server, $token);
        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertTrue($connections[0]->isAuthenticated(), 'The row flip must land without a rebuild.');

        ConnectionPool::getInstance()->clear();
        $repo->rows[self::ROW_KEY] = 'deny';
        $third = $this->runHandshakeOn($server, $token);
        $this->assertTrue($third['tracker']['close'] ?? false, 'The row re-flip must deny again.');
    }

    // --- tier 1 outranks tier 2 (both directions, arm-pure) ------------------

    public function test_envDenyOutranksAdminRowAllow(): void
    {
        // Conviction (b): env 'deny' + row 'allow' -> deny wins; the row is
        // NEVER read (counter 0 — an env-set handshake must not spend a
        // SELECT, and a resolver that consulted the row anyway would be
        // reachable only by inverting the precedence).
        $repo = $this->fakeRepo([self::ROW_KEY => 'allow']);
        $token = $this->jwtHandler->createAccessToken('user-env-deny-row-allow');

        $result = $this->runHandshake($this->authConfig('deny'), $repo, $token);

        $this->assertTrue($result['tracker']['close'] ?? false);
        $this->assertSame(0, $repo->reads, 'Tier 1 silence must short-circuit before the row read.');
        foreach (self::DENY_LITERALS as $literal) {
            $this->assertStringContainsString($literal, $result['log']);
        }
        $this->assertStringNotContainsString($token, $result['log']);
    }

    public function test_envAllowOutranksAdminRowDeny(): void
    {
        // Conviction (c): the straggler escape hatch still restores PHASE A
        // acceptance over an admin-row deny — byte-ident telemetry (allow
        // literal + redaction), socket live, row never consulted.
        $repo = $this->fakeRepo([self::ROW_KEY => 'deny']);
        $token = $this->jwtHandler->createAccessToken('user-env-allow-row-deny');

        $result = $this->runHandshake($this->authConfig('allow'), $repo, $token);

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertTrue($connections[0]->isAuthenticated());
        $this->assertArrayNotHasKey('close', $result['tracker']);
        $this->assertSame(0, $repo->reads, 'The escape hatch must outrank the row without reading it.');
        foreach (self::ALLOW_LITERALS as $literal) {
            $this->assertStringContainsString($literal, $result['log']);
        }
    }

    // --- unusable / unreadable rows normalize to deny (CP-024 empty-knob family) --

    public function test_nonEnumRowStringNormalizesToDenyWithBoundedRedactedLog(): void
    {
        // Conviction (d): an admin row outside the enum ('ALLOWED' — a
        // near-miss string) denies + logs a BOUNDED (once per resolver
        // instance) warning naming the key — and NEVER the stored bytes
        // (the estate redaction law extended to config data: only gettype).
        // Mirrors the CP-024 empty-knob normalization family; the disclosed
        // divergence from the env tier: garbage env fails OPEN (typo
        // never-lock-out), garbage row fails CLOSED (an explicit-but-broken
        // admin statement can only restore the shipped deny posture).
        $repo = $this->fakeRepo([self::ROW_KEY => 'ALLOWED']);
        $offset = $this->logOffset();
        $middleware = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, null, $repo);

        $this->assertFalse($middleware->queryTokenCarrierAllowed());
        $this->assertFalse($middleware->queryTokenCarrierAllowed());

        $log = $this->logSegment($offset);
        $this->assertSame(
            1,
            substr_count($log, self::UNUSABLE_MARK),
            'The unusable-row alarm is bounded per instance.'
        );
        $this->assertStringContainsString(self::ROW_KEY, $log);
        $this->assertStringNotContainsString('ALLOWED', $log, 'Stored row bytes must never reach the log.');
    }

    public function test_nonStringRowValueNormalizesToDenyAndLogsItsTypeOnly(): void
    {
        // Premise-falsification pin (PHP 8.3.6 executed law, schema is the
        // authority): the brief's "bool" shape is NOT what the shared schema
        // declares — the key is the string enum 'allow'/'deny'
        // (phlix-shared schemas/server-settings.schema.json). A raw-SQL or
        // restored write can still land a bool; the resolver treats ANY
        // non-string as unusable -> deny, disclosing only gettype
        // ('boolean'), never the value.
        $repo = $this->fakeRepo([self::ROW_KEY => true]);
        $offset = $this->logOffset();
        $middleware = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, null, $repo);

        $this->assertFalse($middleware->queryTokenCarrierAllowed());

        $log = $this->logSegment($offset);
        $this->assertStringContainsString(self::UNUSABLE_MARK, $log);
        $this->assertStringContainsString('boolean', $log);
    }

    public function test_exactRowDenyAndExactRowAllowNeedNoLogAndAnswerTheirDisposition(): void
    {
        // Vocabulary pin at the row tier: the resolver matches the enum
        // members EXACTLY (no trim/case tolerance — the API/validator owns
        // normalization; a raw restorative write of ' allow' is an unusable
        // row by this law, disclosed). Well-formed rows log NOTHING extra:
        // the once-per-worker health path stays quiet.
        $allow = $this->fakeRepo([self::ROW_KEY => 'allow']);
        $deny = $this->fakeRepo([self::ROW_KEY => 'deny']);
        $offset = $this->logOffset();
        $allowMiddleware = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, null, $allow);
        $denyMiddleware = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, null, $deny);

        $this->assertTrue($allowMiddleware->queryTokenCarrierAllowed());
        $this->assertFalse($denyMiddleware->queryTokenCarrierAllowed());
        $this->assertSame('', trim($this->logSegment($offset)), 'Well-formed rows must not warn.');

        $nearMiss = $this->fakeRepo([self::ROW_KEY => ' allow']);
        $nearMissMiddleware = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, null, $nearMiss);
        $this->assertFalse(
            $nearMissMiddleware->queryTokenCarrierAllowed(),
            'Row vocabulary is exact-member, not env-style tolerant.'
        );
    }

    public function test_settingsStoreFailureFailsClosedToDenyWithBoundedLogAndNoThrowEscape(): void
    {
        // The disclosed deviation (register + class docblock): an unreadable
        // store -> DENY + one bounded warning, never a throw — a throw out
        // of onWebSocketConnect triggers Workerman's Worker::stopAll() and
        // kills the :8097 worker (the shipped rate-limit-block law: the hook
        // "must NOT throw"). Server-level arm proves the reject rides the
        // ESTABLISHED deny path (literal + pool reap); middleware-level arms
        // prove the bounded alarm and the escape containment.
        $repo = $this->fakeRepo([self::ROW_KEY => 'allow']);
        $repo->throwOnRead = true;
        $token = $this->jwtHandler->createAccessToken('user-store-down');
        $offset = $this->logOffset();

        $result = $this->runHandshake($this->authConfig(), $repo, $token);

        $log = $this->logSegment($offset);
        $this->assertTrue($result['tracker']['close'] ?? false);
        $this->assertCount(0, ConnectionPool::getInstance()->all());
        $this->assertSame(1, substr_count($log, self::UNREADABLE_MARK));
        foreach (self::DENY_LITERALS as $literal) {
            $this->assertStringContainsString($literal, $log);
        }

        $second = $this->runHandshake($this->authConfig(), $repo, $token);
        $this->assertTrue($second['tracker']['close'] ?? false);
    }

    public function test_nonStringConfigValueCountsAsEnvSilentNotAsAFalsePolicy(): void
    {
        // Belt-leg at the server boundary (the CP-024-era ternary-else, now
        // the silence-fold): a hand-edited config carrying a non-string
        // query_token is NOT an operator statement — the fold to null keeps
        // the ?string parameter contract honest (the middleware file is
        // strict_types: a raw 42 would TypeError at construction) and the
        // gate lands on the default deny, not on any parsed reading of 42.
        // Strip the fold in WebSocketServer's construction -> red (TypeError).
        $config = ['host' => '0.0.0.0', 'port' => 8097, 'jwt_secret' => $this->jwtSecret, 'query_token' => 42];
        $result = $this->runHandshake($config, null, $this->jwtHandler->createAccessToken('user-int-config'));
        $this->assertTrue($result['tracker']['close'] ?? false);
        foreach (self::DENY_LITERALS as $literal) {
            $this->assertStringContainsString($literal, $result['log']);
        }
    }

    // --- the gate never touches the modern carriers --------------------------

    public function test_bearerAndAdmittedCookieLegsNeverConsultTheAdminRowAndStayByteIdent(): void
    {
        // CP-006 precedence law UNMOVED + arm purity at the resolver: with
        // the row tier armed to 'allow', a bearer handshake and an admitted
        // cookie handshake must (i) never read the store — counter pinned at
        // exactly 0, so the row SELECT rides query handshakes ONLY — and
        // (ii) land byte-ident (outcome, 101 echo, normalized log) against
        // the same handshakes run with NO store at all. Any read on the
        // modern rails -> red on the counter; a widened gate condition ->
        // red on the identity compare.
        $repo = $this->fakeRepo([self::ROW_KEY => 'allow']);
        $bearer = $this->jwtHandler->createAccessToken('user-row-carrier');

        $bearerRun = $this->runHandshake($this->authConfig(), $repo, null, 'bearer, ' . $bearer);
        $bearerConnections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $bearerConnections);
        $this->assertTrue($bearerConnections[0]->isAuthenticated());
        $this->assertSame(['Sec-WebSocket-Protocol: bearer'], $bearerRun['headers']);
        $bearerLog = $this->normalizeLog($bearerRun['log']);
        ConnectionPool::getInstance()->clear();

        $jar = $this->jwtHandler->createAccessToken('user-row-cookie');
        $cookieRun = $this->runHandshake(
            $this->authConfig(),
            $repo,
            null,
            null,
            $jar,
            ['Origin' => 'http://localhost']
        );
        $cookieConnections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $cookieConnections);
        $this->assertTrue($cookieConnections[0]->isAuthenticated());
        ConnectionPool::getInstance()->clear();

        $plainBearerRun = $this->runHandshake($this->authConfig(), null, null, 'bearer, ' . $bearer);
        $plainBearerConnections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $plainBearerConnections);
        $this->assertSame($bearerRun['headers'], $plainBearerRun['headers']);
        $this->assertSame($bearerLog, $this->normalizeLog($plainBearerRun['log']));
        ConnectionPool::getInstance()->clear();

        $this->assertSame(0, $repo->reads, 'The admin row must be invisible to non-query carriers.');
        $this->assertStringNotContainsString('query carrier', $bearerLog);
        $this->assertStringNotContainsString('query carrier', $this->normalizeLog($cookieRun['log']));
    }

    // --- disclosed constructor-shape boundary ---------------------------------

    public function test_directCtorEmptyStringPolicyCountsAsEnvSilentAndGarbageEnvStillFailsOpen(): void
    {
        // Disclosed boundary (register): an EMPTY 4th-arg now means "operator
        // silent" (falls to the row/default tiers — deny with no store), where
        // pre-wiring it parsed to an ALLOW via the fail-open env vocabulary.
        // Unreachable from config/server.php in BOTH eras (EnvConfig folds
        // empty into unset for text AND nullableText), so no shipped surface
        // moves; pinned because the resolver owns the boundary now. Adjacent
        // arm: a SET-but-unrecognized env value keeps failing OPEN — the
        // PHASE A vocabulary is UNMOVED behind the new precedence.
        $silent = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, '');
        $this->assertFalse(
            $silent->queryTokenCarrierAllowed(),
            'Empty direct-ctor policy counts as silence -> default deny.'
        );

        $garbage = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, 'bananas');
        $this->assertTrue(
            $garbage->queryTokenCarrierAllowed(),
            'Set-but-garbage env still fails open (never-lock-out law).'
        );

        $silentWithRow = $this->fakeRepo([self::ROW_KEY => 'allow']);
        $rescued = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, '', $silentWithRow);
        $this->assertTrue(
            $rescued->queryTokenCarrierAllowed(),
            'Silence must fall THROUGH to the row, not to the default.'
        );
    }
}

/**
 * In-file test double (the sanctioned phpcs-tests.xml MultipleClasses
 * idiom): a settings store whose rows can be re-written UNDER a live
 * server — the only way to prove the resolver reads per decision and
 * caches nothing. The parent constructor is deliberately unchained: the
 * fake answers exactly the method the resolver calls and never touches
 * the real Connection-backed machinery.
 */
final class WsQueryTokenAdminRowFakeSettings extends SettingsRepository
{
    /** @var array<string, mixed> */
    public array $rows;

    /** Row reads performed — arm-purity convictions pin this counter. */
    public int $reads = 0;

    public bool $throwOnRead = false;

    /**
     * @param array<string, mixed> $rows
     */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    public function getEffective(string $key): mixed
    {
        $this->reads++;
        if ($this->throwOnRead) {
            throw new \RuntimeException('venue: settings store unreachable');
        }
        return $this->rows[$key] ?? null;
    }
}
