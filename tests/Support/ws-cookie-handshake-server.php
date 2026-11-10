<?php

/**
 * CP-008 — a REAL Workerman WebSocket listener running the production
 * {@see \Phlix\Server\WebSocket\WebSocketServer} handshake gate.
 *
 * ## Why this file exists
 *
 * COOKIE-DESIGN.md §7 requires the cookie-carrier handshake law to be proved
 * "two-real-socket" — the mock-connection cohorts (CookieWsHandshakeTest,
 * WsQueryTokenGateTest) pin the hook's decisions; this harness pins what
 * actually lands on the wire: the composed 101 bytes (marker echo present or
 * absent), the absence of any reply on refused handshakes, and the redaction
 * of credentials in the log file the server itself writes.
 *
 * The venue idiom is copied from tests/Support/Browser/s295-head-body-server.php
 * (proc_open'd Workerman master, `exec` load-bearing so proc_terminate signals
 * the master, pidfile + logFile redirected into the scratch dir, a roll-call
 * file each forked worker appends to at start, and the trailing `start`
 * command word runAll() requires).
 *
 * ## What is real here, and what is not
 *
 * REAL: {@see Worker} + the Websocket protocol (the actual 101 composition and
 * Sec-WebSocket-Accept math), WebSocketServer's standalone construction
 * (it creates its own worker on `websocket://127.0.0.1:<port>`), its
 * onConnect/onWebSocketConnect/onClose callbacks, the SyncPlayAuthMiddleware
 * built from the shipped config['jwt_secret'] + config['query_token'] pair,
 * and the LoggerFactory the rejection/telemetry records flow through.
 *
 * NOT REAL, deliberately: the AuthManager liveness seam. The standalone
 * constructor keeps it null — the crypto + access-type law is exercised; the
 * HTTP-parity liveness matrix is pinned by WsHandshakeLivenessParityTest
 * (PHPUnit mocks cannot cross proc_open(), same disclosure as S295).
 *
 * ## Usage
 *
 *   php tests/Support/ws-cookie-handshake-server.php \
 *       --port=<n> --secret=<s> --gate=<allow|deny|absent> \
 *       --roll=<pidfile-lines> --log=<app.log> --pid=<w.pid> start
 *
 * `--gate=absent` omits the config['query_token'] key entirely (the shipped
 * default posture). The app log path doubles as the readiness signal: the
 * forked worker re-publishes its pid into --roll AFTER onStart() ran, so a
 * non-empty roll file proves the handshake gate is armed.
 *
 * @package Phlix
 */

declare(strict_types=1);

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\WebSocket\WebSocketServer;
use Workerman\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Reads `--name=value` out of `$argv`. A required option that arrived empty
 * aborts loudly — a gate that silently defaults serves the wrong law.
 */
$option = static function (string $name, ?string $default = null) use ($argv): string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            $value = substr($arg, strlen($name) + 3);
            if ($value !== '') {
                return $value;
            }
        }
    }
    if ($default === null) {
        fwrite(STDERR, "ws-cookie-handshake-server: --{$name}= is required\n");
        exit(2);
    }

    return $default;
};

$port = (int) $option('port');
$secret = $option('secret');
$gate = $option('gate', 'absent');
$rollFile = $option('roll');
$logFile = $option('log');
$pidFile = $option('pid');

if ($port <= 0 || $secret === '') {
    fwrite(STDERR, "ws-cookie-handshake-server: --port must be positive and --secret non-empty\n");
    exit(2);
}

// Fresh roll + log per run, created in the MASTER so a reader cannot mistake
// "not there yet" for "no record was written".
file_put_contents($rollFile, '');
file_put_contents($logFile, '');

// A temp logger config so the gate's records land in this scratch file only —
// an ungated stream handler attaches to every channel (same shape the S295
// harness used for the Application's eager LoggerFactory consumers).
$loggerConfigPath = $logFile . '.logger.php';
file_put_contents(
    $loggerConfigPath,
    "<?php\nreturn [\n"
    . "    'default' => 'ws',\n"
    . "    'handlers' => [\n"
    . "        'ws' => [\n"
    . "            'type' => 'stream',\n"
    . "            'path' => " . var_export($logFile, true) . ",\n"
    . "            'level' => 'debug',\n"
    . "        ],\n"
    . "    ],\n"
    . "];\n",
);
LoggerFactory::init($loggerConfigPath);

Worker::$pidFile = $pidFile;
Worker::$logFile = $logFile . '.workerman';

$config = [
    'host' => '127.0.0.1',
    'port' => $port,
    'jwt_secret' => $secret,
];
if ($gate !== 'absent') {
    $config['query_token'] = $gate;
}

$server = new WebSocketServer($config);

// Announce AFTER the server's own onStart() (logger init, timers) has run, so
// roll presence proves the FULL gate — including the middleware built in the
// constructor — is live in a worker, not merely the master's listen backlog.
$worker = $server->getWorker();
$worker->count = 1;
$worker->name = "cp008-ws-cookie-handshake:{$port}";
$innerStart = $worker->onWorkerStart;
$worker->onWorkerStart = static function (Worker $w) use ($innerStart, $rollFile): void {
    /** @var callable(Worker): void $innerStart */
    call_user_func($innerStart, $w);
    file_put_contents($rollFile, getmypid() . "\n", FILE_APPEND | LOCK_EX);
};

Worker::runAll();
