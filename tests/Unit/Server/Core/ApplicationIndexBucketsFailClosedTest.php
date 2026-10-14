<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserProfileManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * R18-01 — the A-Z bucket route (`GET /api/v1/media/index`) must fail CLOSED
 * when the parental RatingGate cannot be resolved.
 *
 * ## The defect this pins shut
 *
 * The hunk above the catch promises "an unidentified request gets a deny-all
 * cap (S235)" — yet the catch arm left `$paramsArr` UNcapped whenever the gate
 * itself exploded (container/DB failure inside RatingGate resolution). A
 * transient settings/DB fault therefore widened the visible catalogue to every
 * rating for capped accounts: the exact rows the SQL predicate exists to hide.
 * The fix degrades the catch to `RatingGate::denyAll()` — the same array
 * `resolveFilterForUser('')` answers with — so a broken gate is indistinguishable
 * from the strictest cap, never from no cap.
 *
 * ## Harness posture
 *
 * Production route table (mirrors ServersAndJobsAuthGateTest): a real
 * `Application` over `ContainerFactory::defaultProviders()` with MySQL doubled;
 * the LAST provider overrides `ItemRepository` (capture fake) and `RatingGate`
 * (per-test definition). The handler closure is invoked directly — the same
 * unit of behaviour the router runs post-middleware — because the global
 * AccessScheduleMiddleware cannot authenticate a doubled DB in this harness
 * (same documented limit S437/ServersAndJobs record).
 *
 * Three arms pinned: resolved cap threads through unchanged; a null (permissive
 * / admin) resolution stays uncapped; a THROWING gate now carries the deny-all
 * pair into valueBuckets().
 */
final class ApplicationIndexBucketsFailClosedTest extends TestCase
{
    private const INDEX_PATH = '/api/v1/media/index';

    private string $tempDir = '';
    private string $loggerConfigPath = '';

    /** @var array<string, mixed>|null params captured from the last valueBuckets() call */
    private ?array $capturedParams = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        $this->capturedParams = null;

        $this->tempDir = sys_get_temp_dir() . '/phlix_r1801_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);
        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents(
            $this->loggerConfigPath,
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
    }

    protected function tearDown(): void
    {
        // Container graph resolution constructs MediaAssetJobStore /
        // SimilarityJobStore through MediaServicesProvider factories, which mint
        // the shared /tmp queue dirs. Sweep so the suite leaves zero residue
        // (same duty ServersAndJobsAuthGateTest records).
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

    // -----------------------------------------------------------------
    // Arms
    // -----------------------------------------------------------------

    public function testResolvedCapThreadsIntoTheBucketQueryUnchanged(): void
    {
        $filter = ['allowedRatings' => ['G', 'PG'], 'allowUnrated' => false];
        $response = $this->dispatchIndex($this->gateReturning($filter));

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($this->capturedParams);
        $this->assertSame($filter['allowedRatings'], $this->capturedParams['allowedRatings']);
        $this->assertFalse($this->capturedParams['allowUnrated']);
    }

    public function testPermissiveResolutionLeavesParamsUncapped(): void
    {
        $response = $this->dispatchIndex($this->gateReturning(null));

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($this->capturedParams);
        $this->assertArrayNotHasKey('allowedRatings', $this->capturedParams);
        $this->assertArrayNotHasKey('allowUnrated', $this->capturedParams);
    }

    public function testBrokenGateDegradesToTheDenyAllCapNotToUncapped(): void
    {
        $response = $this->dispatchIndex($this->gateThatThrows());

        $this->assertSame(200, $response->statusCode, 'the route still answers; only its cap changes');
        $this->assertIsArray($this->capturedParams);
        $denyAll = RatingGate::denyAll();
        $this->assertSame($denyAll['allowedRatings'], $this->capturedParams['allowedRatings']);
        $this->assertSame($denyAll['allowUnrated'], $this->capturedParams['allowUnrated']);
        $this->assertSame(
            [RatingGate::NO_USER_RATING],
            $this->capturedParams['allowedRatings'],
            'R18-01: a resolution failure must equal the strictest cap — the sentinel that '
            . 'matches no real row — never the pre-fix uncapped read.'
        );
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    /**
     * @param callable(): RatingGate $gateFactory container definition for the gate
     */
    private function dispatchIndex(callable $gateFactory): Response
    {
        $app = $this->application($gateFactory);
        $handler = $this->indexHandler($app);

        $request = new Request();
        $request->method = 'GET';
        $request->path = self::INDEX_PATH;
        $request->remoteIp = '127.0.0.1';
        $request->userId = 'user-1';

        /** @var Response $response */
        $response = $handler($request, []);

        return $response;
    }

    /**
     * Real final RatingGate over a profile-manager double (the class is final,
     * so it is composed, not mocked).
     */
    private function gateReturning(?array $filter): callable
    {
        return function () use ($filter): RatingGate {
            $profiles = $this->createMock(UserProfileManager::class);
            $profiles->method('getActiveRatingFilter')->willReturn($filter);

            return new RatingGate($this->createMock(ItemRepository::class), $profiles);
        };
    }

    private function gateThatThrows(): callable
    {
        return static function (): RatingGate {
            throw new \RuntimeException('R18-01 planted gate resolution failure');
        };
    }

    /** @param callable(): RatingGate $gateFactory */
    private function application(callable $gateFactory): Application
    {
        $connection = $this->createMock(Connection::class);
        $itemsMock = $this->captureItemRepository();

        $providers = ContainerFactory::defaultProviders();
        // LAST provider wins: replace the media doubles for this route only.
        $providers[] = new class ($connection, $itemsMock, $gateFactory) implements ServiceProviderInterface {
            /** @param \PHPUnit\Framework\MockObject\MockObject&ItemRepository $items */
            public function __construct(
                private Connection $connection,
                private ItemRepository $items,
                private $gateFactory,
            ) {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $items = $this->items;
                $gateFactory = $this->gateFactory;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                    ItemRepository::class => factory(static fn (): ItemRepository => $items),
                    RatingGate::class => factory(static fn (): RatingGate => $gateFactory()),
                ]);
            }
        };

        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($connection);

        return new Application($container, ['hub' => ['config_dir' => $this->tempDir]], $pool);
    }

    /**
     * ItemRepository double whose valueBuckets() records `$params` and answers
     * an empty bucket set (IndexBuckets builds a valid empty response from it).
     */
    private function captureItemRepository(): ItemRepository
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('valueBuckets')
            ->willReturnCallback(
                function (string $field, array $params, ?string $libraryId = null): array {
                    $this->capturedParams = $params;
                    return [];
                }
            );

        return $items;
    }

    /** @return callable(Request, array<string,string>): Response */
    private function indexHandler(Application $app): callable
    {
        $property = (new ReflectionClass(Application::class))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($app);
        $this->assertInstanceOf(Router::class, $router, 'ANTI-VACUITY: production router missing');

        foreach ($router->getRoutes()['GET'] ?? [] as $entry) {
            if (($entry['path'] ?? null) === self::INDEX_PATH) {
                $this->assertIsCallable($entry['handler']);

                return $entry['handler'];
            }
        }

        $this->fail(self::INDEX_PATH . ' is not registered on the production router.');
    }
}
