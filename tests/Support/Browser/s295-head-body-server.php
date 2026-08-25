<?php

/**
 * S295 — a REAL HTTP server whose global chain short-circuits every request.
 *
 * ## Why this file exists
 *
 * S295's acceptance criterion is a wire-byte proof: a `HEAD` refused by the ONLY
 * global middleware ({@see \Phlix\Server\Http\Middleware\AccessScheduleMiddleware})
 * must carry NO body and the REAL `Content-Length`, beside a desyncing control
 * (a `GET` that carries a body). The S113 model tests execute the real encoders
 * but not a real socket; this script is the real socket.
 *
 * ## What is real here, and what is not
 *
 * REAL: {@see Worker} (the same HTTP driver `start.php` runs),
 * {@see Request::fromWorkerman()} (the daemon's request constructor, not
 * `fromGlobals()`), the REAL {@see Application} constructor — including the
 * S295 chain-return seam `Application::flagHeadShortCircuitReply()` and the
 * AccessScheduleMiddleware wrapper it registers — the real
 * {@see \Phlix\Server\Http\Middleware\AccessScheduleMiddleware} over the real
 * {@see \Phlix\Access\AccessScheduleService}, and
 * {@see Response::toWorkermanResponse()} (the same encoder HttpHandler's
 * matched-route branch sends at `HttpHandler.php:267`).
 *
 * NOT REAL, deliberately: the database (one JSON row replayed by
 * {@see \Phlix\Tests\Support\Browser\StubScheduleConnection} — a PHPUnit mock
 * cannot cross `proc_open()`), and the authentication context. The middleware's
 * only gate is `RequestContext::hasUserId()`; production publishes that value
 * via `RequestAuthenticator`/`AuthMiddleware`. This harness sets the same
 * per-request context keys directly, because the S295 seam is the middleware's
 * REFUSAL, not the auth that precedes it (auth behaviour has its own tests).
 *
 * ## Usage
 *
 *   php tests/Support/Browser/s295-head-body-server.php \
 *       --row=<schedule-row.json> --profile=<profile-uuid> --port=<n> \
 *       --log=<requests.jsonl> --pid=<pidfile> [--workers=1] start
 *
 * The trailing `start` is Workerman's own command word ({@see Worker::runAll()}
 * exits with a usage banner without it).
 *
 * @package Phlix
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\Browser\StubScheduleConnection;
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;
use Workerman\Worker;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use function DI\factory;

/**
 * Reads `--name=value` out of `$argv`. Absent and empty are the same thing: a
 * required option that arrived empty must abort, never default to something that
 * quietly serves the wrong fixture.
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
        fwrite(STDERR, "S295 head-body-server: --{$name}= is required\n");
        exit(2);
    }

    return $default;
};

$rowFile = $option('row');
$profileId = $option('profile');
$port = (int) $option('port');
$logFile = $option('log');
$pidFile = $option('pid');
$workers = max(1, (int) $option('workers', '1'));

if ($port <= 0) {
    fwrite(STDERR, "S295 head-body-server: --port must be a positive integer\n");
    exit(2);
}

// A fresh log per run, created in the MASTER so a reader cannot mistake "the
// file is not there yet" for "no request was served".
file_put_contents($logFile, '');
file_put_contents($logFile . '.workers', '');

Worker::$pidFile = $pidFile;
// Workerman writes its own log beside the start file by default, which would
// drop a stray `workerman.log` into tests/Support/Browser/ on every run.
Worker::$logFile = $logFile . '.workerman';

$worker = new Worker("http://127.0.0.1:{$port}");
$worker->count = $workers;
$worker->name = "s295-head-body:{$port}";

// The stub connection, built in the MASTER so the forked children inherit it
// (like every other static/object the workers share).
$stub = StubScheduleConnection::fromJsonFile($rowFile);

// A temp logger config so the Application constructor's eager controller
// factories can initialise LoggerFactory without touching the repo's real logs.
$loggerConfigPath = $logFile . '.logger.php';
file_put_contents(
    $loggerConfigPath,
    "<?php\nreturn [\n"
    . "    'default' => 'file',\n"
    . "    'handlers' => [\n"
    . "        'file' => [\n"
    . "            'type' => 'stream',\n"
    . "            'path' => " . var_export($logFile . '.app.log', true) . ",\n"
    . "            'level' => 'debug',\n"
    . "        ],\n"
    . "    ],\n"
    . "];\n",
);

$config = [
    'server' => ['name' => 'S295 head-body probe'],
    'logger_config_path' => $loggerConfigPath,
    'db_config_path' => dirname(__DIR__, 3) . '/config/database.php',
    'web_portal' => ['template_dir' => dirname(__DIR__, 3) . '/public/templates'],
];

// Bind the container's Connection to the stub so the autowired
// AccessScheduleService/UserProfileManager answer the schedule check from the
// fixture row. Every other binding is the real provider stack.
$providers = ContainerFactory::defaultProviders();
$providers[] = new class ($stub) implements ServiceProviderInterface {
    public function __construct(private readonly Connection $connection)
    {
    }

    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $connection = $this->connection;
        $builder->addDefinitions([
            Connection::class => factory(static fn (): Connection => $connection),
        ]);
    }
};

/**
 * Per-worker state, populated AFTER the fork. Captured by reference: each child
 * gets its own copy, and nothing request-scoped is ever written to it.
 *
 * @var array{application: ?Application, profileId: string, log: string} $state
 */
$state = ['application' => null, 'profileId' => $profileId, 'log' => $logFile];

$worker->onWorkerStart = static function () use (
    &$state,
    $config,
    $providers
): void {
    // S315 — every child announces itself. `/__ready` proves ONE worker is
    // accepting.
    file_put_contents($state['log'] . '.workers', getmypid() . "\n", FILE_APPEND | LOCK_EX);

    // Same order as start.php: the container is built per worker, AFTER the
    // fork, so each child owns its long-lived state.
    ConnectionPool::init($config['db_config_path']);
    LoggerFactory::init($config['logger_config_path']);

    $container = ContainerFactory::create($config, $providers);
    /** @var ConnectionPool $connectionPool */
    $connectionPool = $container->get(ConnectionPool::class);
    $application = new Application($container, $config, $connectionPool);

    $state['application'] = $application;
};

$worker->onMessage = static function (
    TcpConnection $connection,
    WorkermanRequest $wr
) use (&$state): void {
    // The ONLY path that does not reach the chain. It exists so the test can
    // wait for the forked worker to be accepting. Not logged.
    if ($wr->path() === '/__ready') {
        $connection->send(new WorkermanResponse(200, ['Content-Type' => 'text/plain'], 'ready'));
        return;
    }

    $application = $state['application'];
    if (!$application instanceof Application) {
        // Unreachable in practice (onWorkerStart runs before the first accept),
        // and a 500 rather than a silent empty 200.
        $connection->send(new WorkermanResponse(500, ['Content-Type' => 'text/plain'], 'application not built'));
        return;
    }

    $request = Request::fromWorkerman($wr, $connection);

    // Simulate the authenticated-request precondition the schedule gate tests:
    // production publishes these via RequestAuthenticator/AuthMiddleware.
    RequestContext::setUserId('user-1');
    RequestContext::setProfileId($state['profileId']);

    $response = $application->dispatch($request);

    $line = json_encode([
        'pid' => getmypid(),
        'method' => $request->method,
        'path' => $request->path,
        'status' => $response->statusCode,
        'headOnly' => $response->headOnly,
        'contentType' => $response->headers['Content-Type'] ?? null,
        'contentLength' => $response->headers['Content-Length'] ?? null,
        // The RESPONSE OBJECT's entity — what the equivalent GET would ship. On a
        // flagged HEAD the encoder ({@see BodylessResponse}) suppresses it on the
        // wire; this field measures the object, not the socket, by design.
        'entityBytes' => strlen($response->body),
    ], JSON_UNESCAPED_SLASHES);

    file_put_contents($state['log'], $line . "\n", FILE_APPEND | LOCK_EX);

    // Production's wire encoder — the same one HttpHandler's matched-route
    // branch sends for a global short-circuit (HttpHandler.php:267).
    $connection->send($response->toWorkermanResponse());
};

Worker::runAll();
