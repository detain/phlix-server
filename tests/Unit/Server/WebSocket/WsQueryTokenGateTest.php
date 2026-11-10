<?php

/**
 * CP-007 + CP-024 PHASE B (COOKIE-DESIGN.md §4.1): the PHLIX_WS_QUERY_TOKEN
 * gate on `:8097` + the redacted query-carrier warn-log.
 *
 * PHASE B posture (this file's law since the CP-024 flip): the SHIPPED
 * DEFAULT IS DENY. An unset/empty knob (gate key absent from the config,
 * or every pre-CP-007 config shape) refuses the legacy `?token=` carrier
 * pre-101 — the estate producer census proved zero live query producers
 * before the flip (mobile, the last one, moved to bearer-subprotocol at
 * phlix-mobile-client bdbe1e1). The env knob remains the escape hatch:
 * explicit allow restores the PHASE A behavior byte-ident (accept +
 * one redacted telemetry line); explicit deny is the same rejection path
 * the new default takes. The parser vocabulary is UNCHANGED from PHASE A:
 * only the exact word deny (case/trim tolerant) denies, so unrecognized
 * garbage still fails open to allow — a typo cannot silently tighten the
 * surface beyond the shipped posture. The gate governs the QUERY carrier
 * ONLY: bearer and admitted cookie legs are byte-ident under EVERY
 * setting including gate-absent (CP-006 precedence law, §2.3) and are
 * pinned against exactly that by the identity legs below.
 *
 * 2026-11-10 admin-control wiring: the gate answer moved behind the live
 * three-tier resolver (env > admin row auth.ws_query_token > deny —
 * QueryTokenGatePolicy); every leg here constructs WITHOUT a settings
 * store, so the row tier is unavailable and each disposition resolves to
 * exactly the answer pinned here — the whole file is the movement-
 * invisibility proof, green with zero leg edited except the config-surface
 * arm (rotated: unset now passes through as null, the PHASE B deny default
 * it still answers moved into the resolver). The admin-tier convictions
 * live in WsQueryTokenAdminControlTest.php.
 *
 * Harness idiom: CookieWsHandshakeTest (real parsed upgrade Requests over
 * mocked TcpConnections) + DlnaResUrlIsRoutableTest (temp stream-handler
 * logger.php bound via LoggerFactory so log lines are capturable bytes).
 * Defect-inheritance law: every limb carries a uniquely convicting leg;
 * belt legs green at the parent are disclosed, never counted as
 * convictions.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\JwtHandler;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\SyncPlayAuthMiddleware;
use Phlix\Server\WebSocket\WebSocketServer;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

final class WsQueryTokenGateTest extends TestCase
{
    /** Stable substrings of the two gate dispositions (log-only, never wire). */
    private const ALLOW_LITERALS = ['legacy ?token= query carrier', 'PHLIX_WS_QUERY_TOKEN=allow'];
    private const DENY_LITERALS = ['legacy ?token= query carrier', 'PHLIX_WS_QUERY_TOKEN=deny'];
    private const REDACTED_FORM = 'token=[redacted]';

    private JwtHandler $jwtHandler;
    private string $jwtSecret = 'ws-query-token-gate-secret-256bit-x';
    private string $tempDir = '';
    private string $logPath = '';

    protected function setUp(): void
    {
        parent::setUp();
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
        self::assertIsString($base);
        $this->tempDir = $base . '/phlix_wqtg_' . uniqid('', true);
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
        // Reset BEFORE init so no memoized logger from an earlier fixture in
        // this process keeps a stale (or default) handler set.
        LoggerFactory::reset();
        LoggerFactory::init($configPath);
    }

    private function logOffset(): int
    {
        // Monolog's StreamHandler appends through an open handle — without a
        // fresh stat, filesize() would serve the cached size and a later run's
        // segment would swallow the earlier run's bytes.
        clearstatcache(true, $this->logPath);
        return is_file($this->logPath) ? (int)filesize($this->logPath) : 0;
    }

    private function logSegment(int $from): string
    {
        $contents = (string)file_get_contents($this->logPath);
        return substr($contents, $from);
    }

    /**
     * Neutralize the two per-run-volatile log fields (Monolog timestamp and
     * the pool wrapper's spl-object-derived connection_id) so two identical
     * handshakes compare byte-for-byte.
     */
    private function normalizeLog(string $segment): string
    {
        $normalized = preg_replace('/^\[[^\]]+\]/m', '[TS]', $segment);
        self::assertIsString($normalized);
        $normalized = preg_replace('/"connection_id":"[^"]*"/', '"connection_id":"CID"', $normalized);
        self::assertIsString($normalized);
        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $extraHeaders
     * @return array{tracker: array<string, mixed>, headers: array<int, string>, log: string}
     */
    private function runHandshake(
        array $config,
        ?string $queryToken = null,
        ?string $subprotocol = null,
        ?string $sessionCookie = null,
        array $extraHeaders = []
    ): array {
        $server = new WebSocketServer($config);
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

    // --- wired handshake arms ------------------------------------------------

    public function test_gateAbsentQueryHandshakeIsRefusedByThePhaseBDenyDefault(): void
    {
        // PHASE B conviction (limb a): with NO query_token key — the shipped
        // default surface and every pre-CP-007 config shape — a VALID query
        // credential is now refused pre-101 exactly like the explicit-deny
        // arm: socket closed, pool wrapper reaped, never authenticated, no
        // echo. REDDENED at the parent (measured: this leg asserted today's
        // accept shape and the flip turned it red before rotation).
        $rawToken = $this->jwtHandler->createAccessToken('user-shape');
        $result = $this->runHandshake($this->authConfig(), $rawToken);

        $this->assertTrue($result['tracker']['close'] ?? false, 'The deny default must close the socket pre-101.');
        $this->assertCount(0, ConnectionPool::getInstance()->all(), 'The pool wrapper must be reaped.');
        $this->assertSame([], $result['headers']);

        // Limb (d): the SAME rejection path also carries the SAME redacted
        // warn-log — no new log shape may appear on the new default path.
        foreach (self::DENY_LITERALS as $literal) {
            $this->assertStringContainsString($literal, $result['log']);
        }
        $this->assertStringContainsString(self::REDACTED_FORM, $result['log']);
        $this->assertStringNotContainsString($rawToken, $result['log']);
    }

    public function test_allowPolicyAcceptsQueryCarrierAndLogsRedactedTelemetry(): void
    {
        // PHASE B escape hatch: explicit allow restores the PHASE A
        // byte-ident behavior — handshake live AND exactly one telemetry
        // line, path kept, credential masked. Strip the log site -> red;
        // flip the escape hatch off -> red.
        $rawToken = $this->jwtHandler->createAccessToken('user-allow');
        $result = $this->runHandshake($this->authConfig('allow'), $rawToken);

        $connections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $connections);
        $this->assertTrue($connections[0]->isAuthenticated());
        $this->assertArrayNotHasKey('close', $result['tracker']);

        foreach (self::ALLOW_LITERALS as $literal) {
            $this->assertStringContainsString($literal, $result['log']);
        }
        $this->assertStringContainsString(self::REDACTED_FORM, $result['log']);
        $this->assertStringNotContainsString($rawToken, $result['log']);
    }

    public function test_denyPolicyRejectsQueryCarrierPreUpgrade(): void
    {
        // The gate arm: deny + valid query credential must refuse the socket
        // exactly like the other pre-101 rejects — pool wrapper removed, TCP
        // close, never authenticated. No HTTP envelope exists pre-101, so this
        // is the zero-new-wire-codes shape. Strip the reject arm -> red.
        $result = $this->runHandshake($this->authConfig('deny'), $this->jwtHandler->createAccessToken('user-deny'));

        $this->assertTrue($result['tracker']['close'] ?? false, 'deny mode must close the socket.');
        $this->assertCount(0, ConnectionPool::getInstance()->all(), 'The pool wrapper must be reaped.');
        $this->assertSame([], $result['headers']);
    }

    public function test_denyRejectionLogIsRedacted(): void
    {
        // Estate redaction law on the NEW log line: the raw credential bytes
        // must never reach the log; the masked form proves the URI rode along.
        // Swap redactTokenQuery() for raw $request->uri() -> red.
        $rawToken = $this->jwtHandler->createAccessToken('user-deny-log');
        $result = $this->runHandshake($this->authConfig('deny'), $rawToken);

        foreach (self::DENY_LITERALS as $literal) {
            $this->assertStringContainsString($literal, $result['log']);
        }
        $this->assertStringContainsString(self::REDACTED_FORM, $result['log']);
        $this->assertStringNotContainsString($rawToken, $result['log']);
    }

    public function test_allowTelemetryLogCarriesNoRawCredentialBytes(): void
    {
        // Redaction is proven on BOTH dispositions' log sites — a mutation
        // that keeps the deny line masked but leaks the allow line reddens
        // exactly here.
        $rawToken = $this->jwtHandler->createAccessToken('user-allow-bytes');
        $result = $this->runHandshake($this->authConfig('allow'), $rawToken);

        $this->assertStringNotContainsString($rawToken, $result['log']);
        $this->assertStringContainsString(self::REDACTED_FORM, $result['log']);
    }

    public function test_bearerLegIsByteIdentUnderEveryGateSetting(): void
    {
        // Precedence law (§2.3, CP-006 UNMOVED): the gate governs the query
        // carrier ONLY. A bearer handshake must produce identical outcome,
        // identical 101 echo, and an identical log excerpt under gate-absent
        // (the new deny default), under allow and under deny. Widen the gate
        // condition past carrier==='query' -> red. Limb (b) of the flip.
        $bearer = $this->jwtHandler->createAccessToken('user-bearer-x');

        $absentRun = $this->runHandshake($this->authConfig(), null, 'bearer, ' . $bearer);
        $absentConnections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $absentConnections);
        $this->assertTrue($absentConnections[0]->isAuthenticated());
        $this->assertSame(['Sec-WebSocket-Protocol: bearer'], $absentRun['headers']);
        $absentLog = $this->normalizeLog($absentRun['log']);
        ConnectionPool::getInstance()->clear();

        $allowRun = $this->runHandshake($this->authConfig('allow'), null, 'bearer, ' . $bearer);
        $allowConnections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $allowConnections);
        $this->assertTrue($allowConnections[0]->isAuthenticated());
        $this->assertSame($absentRun['headers'], $allowRun['headers']);
        $this->assertSame($absentLog, $this->normalizeLog($allowRun['log']));
        ConnectionPool::getInstance()->clear();

        $denyRun = $this->runHandshake($this->authConfig('deny'), null, 'bearer, ' . $bearer);
        $denyConnections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $denyConnections);
        $this->assertTrue($denyConnections[0]->isAuthenticated());
        $this->assertSame($absentRun['headers'], $denyRun['headers']);
        $this->assertSame($absentLog, $this->normalizeLog($denyRun['log']));

        $this->assertStringNotContainsString('query carrier', $absentLog);
        $this->assertStringNotContainsString('query carrier', $allowRun['log']);
        $this->assertStringNotContainsString('query carrier', $denyRun['log']);
    }

    public function test_admittedCookieLegIsByteIdentUnderEveryGateSetting(): void
    {
        // Same identity law for the ambient carrier CP-006 admitted: jar +
        // same-site Origin authenticates identically under EVERY setting —
        // gate-absent (the new deny default) included — still answers no
        // bearer marker, and logs nothing about the gate. Limb (b).
        $jar = $this->jwtHandler->createAccessToken('user-jar-x');

        $absentRun = $this->runHandshake($this->authConfig(), null, null, $jar, ['Origin' => 'http://localhost']);
        $absentConnections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $absentConnections);
        $this->assertTrue($absentConnections[0]->isAuthenticated());
        $this->assertSame([], $absentRun['headers']);
        $absentLog = $this->normalizeLog($absentRun['log']);
        ConnectionPool::getInstance()->clear();

        $allowRun = $this->runHandshake($this->authConfig('allow'), null, null, $jar, ['Origin' => 'http://localhost']);
        $allowConnections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $allowConnections);
        $this->assertTrue($allowConnections[0]->isAuthenticated());
        $this->assertSame($absentRun['headers'], $allowRun['headers']);
        $this->assertSame($absentLog, $this->normalizeLog($allowRun['log']));
        ConnectionPool::getInstance()->clear();

        $denyRun = $this->runHandshake($this->authConfig('deny'), null, null, $jar, ['Origin' => 'http://localhost']);
        $denyConnections = ConnectionPool::getInstance()->all();
        $this->assertCount(1, $denyConnections);
        $this->assertTrue($denyConnections[0]->isAuthenticated());
        $this->assertSame($absentRun['headers'], $denyRun['headers']);
        $this->assertSame($absentLog, $this->normalizeLog($denyRun['log']));

        $this->assertStringNotContainsString('query carrier', $absentLog);
        $this->assertStringNotContainsString('query carrier', $allowRun['log']);
        $this->assertStringNotContainsString('query carrier', $denyRun['log']);
    }

    public function test_denyDoesNotHijackTheMismatchOrMissingCredentialLaws(): void
    {
        // The mismatch reject runs BEFORE the gate and owns half-migrated
        // clients; under deny those handshakes must still report the mismatch
        // story (and a credential-less handshake the missing-token story) —
        // never the gate line. Reorder the gate above the mismatch check -> red.
        $result = $this->runHandshake(
            $this->authConfig('deny'),
            'query-token-value',
            'bearer, bearer-token-value'
        );
        $this->assertTrue($result['tracker']['close'] ?? false);
        $this->assertStringContainsString('bearer subprotocol token disagrees', $result['log']);
        $this->assertStringNotContainsString('query carrier', $result['log']);

        ConnectionPool::getInstance()->clear();
        $bare = $this->runHandshake($this->authConfig('deny'));
        $this->assertTrue($bare['tracker']['close'] ?? false);
        $this->assertStringContainsString('missing token', $bare['log']);
        $this->assertStringNotContainsString('query carrier', $bare['log']);
    }

    // --- policy parse + config-surface arms ------------------------------------

    public function test_parseQueryTokenPolicyTreatsOnlyExplicitDenyAsDeny(): void
    {
        $this->assertFalse(SyncPlayAuthMiddleware::parseQueryTokenPolicy('deny'));
        $this->assertFalse(SyncPlayAuthMiddleware::parseQueryTokenPolicy('  DENY  '));
        $this->assertFalse(SyncPlayAuthMiddleware::parseQueryTokenPolicy('Deny'));
    }

    public function test_parseQueryTokenPolicyFailsOpenOnEveryOtherValue(): void
    {
        // Opt-in tightening: garbage/empty/near-miss values never silently
        // tighten the surface (a typo in production must not lock legacy
        // clients out). Flip the comparison to a strict allow-list -> red.
        foreach (['allow', '', '   ', 'false', '0', 'denyish', 'no'] as $value) {
            $this->assertTrue(
                SyncPlayAuthMiddleware::parseQueryTokenPolicy($value),
                "Policy value '{$value}' must fail open to allow."
            );
        }
    }

    public function test_configSurfacePassesTheEnvTierThroughAndDefersWhenSilent(): void
    {
        // PHASE B (CP-024) shipped this surface as a synthesized 'deny'
        // string for unset/empty. The 2026-11-10 admin-control wiring
        // rotated this leg (measured red at the flip: the unset assert saw
        // null where it demanded 'deny'): the config surface now passes the
        // ENV tier through verbatim and speaks SILENCE as null — the
        // nullableText unset/empty law, matching every other "not configured"
        // knob — so the resolver can hear the admin row underneath. The
        // shipped default-answer (deny) moved with it into
        // QueryTokenGatePolicy, where the gate-absent legs above still pin it.
        $config = require dirname(__DIR__, 4) . '/config/server.php';
        $this->assertArrayHasKey('query_token', $config['websocket']);
        $this->assertNull(
            $config['websocket']['query_token'],
            'unset env must pass through as silence (null), not a synthesized deny'
        );

        putenv('PHLIX_WS_QUERY_TOKEN=');
        try {
            $emptied = require dirname(__DIR__, 4) . '/config/server.php';
            $this->assertNull($emptied['websocket']['query_token'], 'empty env is the same silence');
        } finally {
            putenv('PHLIX_WS_QUERY_TOKEN');
        }

        putenv('PHLIX_WS_QUERY_TOKEN=allow');
        try {
            $escaped = require dirname(__DIR__, 4) . '/config/server.php';
            $this->assertSame('allow', $escaped['websocket']['query_token']);
        } finally {
            putenv('PHLIX_WS_QUERY_TOKEN');
        }

        putenv('PHLIX_WS_QUERY_TOKEN=deny');
        try {
            $tightened = require dirname(__DIR__, 4) . '/config/server.php';
            $this->assertSame('deny', $tightened['websocket']['query_token']);
        } finally {
            putenv('PHLIX_WS_QUERY_TOKEN');
        }
    }

    public function test_middlewareConstructionDefaultsTheGateToDeny(): void
    {
        // PHASE B conviction at the constructor seam (limb a): CP-006-era
        // argument shapes that never name a policy now resolve to deny —
        // the default lives on the constructor parameter. Explicit allow
        // still resolves to allow (escape hatch), explicit deny to deny.
        // REDDENED at the parent (legacyShape asserted allowed() true).
        $legacyShape = new SyncPlayAuthMiddleware($this->jwtSecret);
        $this->assertFalse($legacyShape->queryTokenCarrierAllowed());

        $escapeHatch = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, 'allow');
        $this->assertTrue($escapeHatch->queryTokenCarrierAllowed());

        $explicitDeny = new SyncPlayAuthMiddleware($this->jwtSecret, true, null, 'deny');
        $this->assertFalse($explicitDeny->queryTokenCarrierAllowed());
    }

    public function test_gateAbsentRejectionIsByteIdentToExplicitDenyRejection(): void
    {
        // "Same rejection path, same shape" pin: the new default must not
        // invent a third disposition — headers, close behavior and the
        // normalized warn-log of a gate-absent query handshake equal the
        // explicit-deny arm's byte-for-byte. Mint a bespoke absent-mode log
        // line -> red. Belt leg by design (green both sides); disclosed.
        $token = $this->jwtHandler->createAccessToken('user-samepath');

        $absentRun = $this->runHandshake($this->authConfig(), $token);
        $absentLog = $this->normalizeLog($absentRun['log']);
        ConnectionPool::getInstance()->clear();

        $denyRun = $this->runHandshake($this->authConfig('deny'), $token);
        $this->assertSame($absentRun['tracker'], $denyRun['tracker']);
        $this->assertSame($absentRun['headers'], $denyRun['headers']);
        $this->assertSame($absentLog, $this->normalizeLog($denyRun['log']));
    }
}
