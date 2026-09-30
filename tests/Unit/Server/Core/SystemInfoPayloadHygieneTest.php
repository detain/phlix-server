<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Version;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * L-3 (security scan @9e765895 era) — the anonymous `GET /system/info` payload
 * carries ONLY what a client update-check needs, and nothing that fingerprints
 * the runtime stack.
 *
 * ## The defect this pins shut
 *
 * The route is registered with NO middleware (`x-phlix-middleware: []`), so
 * every field it returns is public. Pre-fix it answered
 * `{server, version, php_version, workerman_version}` — the two stack-version
 * extras tell an unauthenticated scanner the exact PHP and Workerman builds,
 * i.e. which published CVEs apply to this host. The estate consumer sweep
 * (ui / hub / roku / tizen / windows / mobile / console / web-ui / docs) found
 * exactly ONE reader of this endpoint — `phlix-windows-client`
 * `src/main/versionCheck.ts` — and it reads only the top-level `version`
 * (falling back open on 404). `php_version` and `workerman_version` have ZERO
 * consumers anywhere, so the fix drops them outright rather than admin-gating
 * keys nobody fetches. `server` + `version` stay: they are the update-check
 * contract itself.
 *
 * ## Why the assertions are shaped this way
 *
 * The route table is the PRODUCTION one: an `Application` built from
 * {@see ContainerFactory::defaultProviders()} with only MySQL doubled — the
 * same harness S437/the L-1/L-2 lane proved can full-chain dispatch anonymous
 * routes without a database (the ServersAndJobsAuthGateTest pattern).
 */
final class SystemInfoPayloadHygieneTest extends TestCase
{
    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // Global AccessScheduleMiddleware reads process-static RequestContext; a
        // leaked user id would flip dispatch. Cleared both ends.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_l3_sysinfo_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents(
            $loggerConfigPath,
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n"
        );
        $this->loggerConfigPath = $loggerConfigPath;
    }

    protected function tearDown(): void
    {
        // Container graph resolution constructs MediaAssetJobStore /
        // SimilarityJobStore through MediaServicesProvider factories, which mint
        // the shared /tmp queue dirs. Sweep so the suite leaves zero residue.
        foreach (['phlix_media_asset_jobs', 'phlix_similarity_jobs'] as $sharedQueue) {
            $sharedDir = sys_get_temp_dir() . '/' . $sharedQueue;
            if (is_dir($sharedDir)) {
                foreach (glob($sharedDir . '/*') ?: [] as $queued) {
                    @unlink($queued);
                }
                @rmdir($sharedDir);
            }
        }
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function container(): ContainerInterface
    {
        if ($this->sharedContainer !== null) {
            return $this->sharedContainer;
        }

        $connection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
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

        return $this->sharedContainer = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);
    }

    private function application(): Application
    {
        if ($this->sharedApplication !== null) {
            return $this->sharedApplication;
        }

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($this->createMock(Connection::class));

        $config = [
            'hub' => ['config_dir' => $this->tempDir],
            'server' => ['name' => 'Phlix Test Server'],
        ];

        return $this->sharedApplication = new Application($this->container(), $config, $pool);
    }

    private function request(string $path): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = $path;
        $request->remoteIp = '127.0.0.1';

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testAnonymousSystemInfoSucceedsAndKeepsTheUpdateCheckContract(): void
    {
        $served = $this->application()->dispatch($this->request('/system/info'));

        $this->assertSame(200, $served->statusCode);

        $body = $this->body($served);
        // The windows update-check contract (versionCheck.ts reads top-level
        // `version`; `server` is the display name beside it).
        $this->assertArrayHasKey('version', $body);
        $this->assertSame(Version::STRING, $body['version']);
        $this->assertArrayHasKey('server', $body);
        $this->assertSame('Phlix Test Server', $body['server']);
    }

    public function testAnonymousSystemInfoDoesNotFingerprintTheRuntimeStack(): void
    {
        $body = $this->body($this->application()->dispatch($this->request('/system/info')));

        $this->assertArrayNotHasKey(
            'php_version',
            $body,
            'L-3: PHP_VERSION is stack fingerprinting; no estate consumer reads it.'
        );
        $this->assertArrayNotHasKey(
            'workerman_version',
            $body,
            'L-3: Workerman\Worker::VERSION is stack fingerprinting; no consumer reads it.'
        );
    }
}
