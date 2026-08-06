<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use DI\ContainerBuilder;
use Phlix\Auth\SignedUrl;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Hub\RelayRequestDispatcher;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\FastPath\PreRouterFastPaths;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S238: a relayed browse must be able to render posters and avatars.
 *
 * ## What was broken, and how it was measured
 *
 * `GET /api/v1/artwork/{id}` and `GET /api/v1/users/{id}/avatar` were private
 * pre-router methods on {@see \Phlix\Server\Workerman\HttpHandler} and appear in
 * NO route table. {@see RelayRequestDispatcher} consults only the two route
 * tables, so through the REAL composed container both paths answered **404** and
 * a relayed inline-browse could show no images at all:
 *
 * ```
 * Application routes: 345   WebPortalRouter routes: 47
 * SUBJECT  GET /api/v1/artwork/item-1?size=w342 -> 404
 * SUBJECT  GET /api/v1/users/user-1/avatar      -> 404
 * CONTROL  GET /api/v1/media/1                  -> 401   <- a live route: the dispatcher DOES route
 * CONTROL  GET /definitely/not/a/route          -> 404   <- a 404 IS producible here
 * ```
 *
 * ⚠ **The gate is NOT the one S164 found for `/media/{id}/stream`.** Both of
 * these paths DO begin with `/api/`, so the `WebPortalRouter` second-chance
 * fallback fires for them — and 404s as well, because the route is absent from
 * both tables. Measured per path:
 *
 * ```
 * /api/v1/artwork/item-1       app-router=404  /api/ prefix=YES  webportal-2nd-chance=404
 * /api/v1/users/user-1/avatar  app-router=404  /api/ prefix=YES  webportal-2nd-chance=404
 * /media/1/stream              app-router=404  /api/ prefix=NO   webportal-2nd-chance=never consulted
 * /api/v1/media/1              app-router=404  /api/ prefix=YES  webportal-2nd-chance=401  <- alive
 * ```
 *
 * So these two have exactly ONE gate (missing registration in both tables) where
 * `/media/{id}/stream` has two. The `401` on the last row is what makes the
 * second-chance fallback a live, non-vacuous detector rather than a silent branch.
 *
 * ## What this test pins
 *
 * The route table is the REAL one: a container built from the canonical provider
 * stack with only the MySQL {@see Connection} doubled and the two image STORAGES
 * repointed at temp directories (the leaf filesystem, not the routing under test).
 * A hand-rolled container loads 53 routes and would manufacture the very 404 being
 * investigated, so {@see testTheComposedRouteTableIsTheRealOne} asserts a floor.
 *
 * @see \Phlix\Tests\Unit\Server\Http\FastPath\PreRouterFastPathsArtworkTest for the
 *      endpoint's own contract (auth, sizes, ETag/304, 404).
 */
final class RelayImageDispatchTest extends TestCase
{
    /**
     * A route-count floor for the composed {@see Application} router.
     *
     * Anti-vacuity, in the S236 style: if a future edit hollows out route
     * registration then every "the fast path answered 200" assertion below would
     * still pass while the 404 controls quietly became meaningless. 300 sits well
     * under the measured 345 and far above the 53 a hand-rolled container yields.
     */
    private const MIN_EXPECTED_APPLICATION_ROUTES = 300;

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private string $artworkDir = '';
    private string $avatarDir = '';
    private string $posterBytes = '';
    private string $avatarBytes = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        // Application registers AccessScheduleMiddleware GLOBALLY, and it answers
        // 403 whenever RequestContext carries a user id. That context is process
        // static, so a sibling test that left one set turns every dispatch below
        // into a 403 and the 404/401 controls stop meaning anything. Cleared on
        // both ends, as ApplicationPlaybackAuthGateTest does for the same reason.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_s238_' . uniqid('', true);
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

        // A real poster variant on disk: <artworkDir>/<itemId>/w342.jpg
        $this->artworkDir = $this->tempDir . '/artwork';
        mkdir($this->artworkDir . '/item-1', 0775, true);
        $this->posterBytes = self::jpegBytes(120, 180);
        file_put_contents($this->artworkDir . '/item-1/w342.jpg', $this->posterBytes);

        // A real avatar on disk: <avatarDir>/<userId>.jpg
        $this->avatarDir = $this->tempDir . '/avatars';
        mkdir($this->avatarDir, 0775, true);
        $this->avatarBytes = self::jpegBytes(64, 64);
        file_put_contents($this->avatarDir . '/user-1.jpg', $this->avatarBytes);

        putenv('JWT_SECRET=s238-relay-image-dispatch-secret-32bytes!');
        SignedUrl::resetSharedForTesting();
    }

    protected function tearDown(): void
    {
        SignedUrl::resetSharedForTesting();
        putenv('JWT_SECRET');
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        LoggerFactory::reset();

        self::removeTree($this->tempDir);

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // The acceptance: image BYTES come back over the relay dispatcher.
    // -----------------------------------------------------------------

    /**
     * A relayed poster request returns the poster's actual bytes.
     *
     * `hub-relay` is precisely what {@see \Phlix\Hub\RelayConsumer::buildRequest()}
     * stamps on `$request->userId` for a tunnelled frame (the hub-validated owner,
     * or that literal fallback), so this is the identity a real relayed browse
     * carries — not a value invented by the test.
     */
    public function testARelayedArtworkRequestReturnsThePosterBytes(): void
    {
        $response = $this->dispatch($this->relayedRequest('/api/v1/artwork/item-1', ['size' => 'w342']));

        self::assertSame(
            200,
            $response->statusCode,
            'A relayed poster request must be served, not 404d by the route table it was never in.',
        );
        self::assertSame('image/jpeg', $response->headers['Content-Type'] ?? null);

        // The bytes really are the file's, materialized exactly as
        // RelayConsumer::streamFileChunks() would read them off `filePath`.
        self::assertSame($this->artworkDir . '/item-1/w342.jpg', $response->filePath);
        self::assertSame($this->posterBytes, $response->materializeFileWindow()->body);
    }

    /** A relayed avatar request returns the avatar's actual bytes. */
    public function testARelayedAvatarRequestReturnsTheAvatarBytes(): void
    {
        $response = $this->dispatch($this->relayedRequest('/api/v1/users/user-1/avatar'));

        self::assertSame(200, $response->statusCode);
        self::assertSame('image/jpeg', $response->headers['Content-Type'] ?? null);
        self::assertSame($this->avatarDir . '/user-1.jpg', $response->filePath);
        self::assertSame($this->avatarBytes, $response->materializeFileWindow()->body);
    }

    // -----------------------------------------------------------------
    // Controls: what a 404 and a 401 mean here.
    // -----------------------------------------------------------------

    /**
     * The controls that make the two 200s above readable.
     *
     * Three different things can produce a 404 on this dispatcher (the DLNA deny,
     * the application router, the WebPortal second chance), so "it is 200 now" is
     * only evidence if a 404 is still producible AND a live route still routes.
     * The 401 next to the 404 is what separates "no such route" from "auth
     * rejected before routing" — two 404s would prove nothing.
     */
    public function testTheDispatcherStillProducesBoth404AndA401(): void
    {
        $missing = $this->dispatch($this->relayedRequest('/definitely/not/a/route'));
        self::assertSame(404, $missing->statusCode, 'A 404 must still be producible here.');

        // Anonymous — no userId, no signature — against a route that DOES exist.
        $live = $this->dispatch($this->anonymousRequest('/api/v1/media/1'));
        self::assertSame(
            401,
            $live->statusCode,
            'A live route must answer 401 to an anonymous caller: this is the control that proves the '
            . 'dispatcher routes at all, so a 404 elsewhere means "no such route" and not "auth first".',
        );
    }

    /**
     * The 200s come from the pre-router stage, NOT from a newly-registered route.
     *
     * This names which branch produced the answer. The application router — the
     * real one, all 345 routes — still 404s both paths; so does the WebPortal
     * second chance. The only thing that can have served them is
     * {@see PreRouterFastPaths}.
     */
    public function testTheImagePathsAreStillAbsentFromBothRouteTables(): void
    {
        $application = $this->application();

        foreach (['/api/v1/artwork/item-1', '/api/v1/users/user-1/avatar'] as $path) {
            self::assertSame(
                404,
                $application->dispatch($this->relayedRequest($path))->statusCode,
                "{$path} is served by the pre-router stage, not by a route — if it starts routing, this "
                . 'test should be replaced rather than deleted, because the middleware stack would then '
                . 'apply to it for the first time.',
            );
        }
    }

    /**
     * Anti-vacuity: the route table under test is the production one.
     *
     * A hand-rolled container registers 53 routes and would 404 the controls for
     * the wrong reason entirely — the failure mode S164 recorded.
     */
    public function testTheComposedRouteTableIsTheRealOne(): void
    {
        $count = 0;
        foreach ($this->application()->getRouter()->getRoutes() as $entries) {
            $count += count($entries);
        }

        self::assertGreaterThanOrEqual(
            self::MIN_EXPECTED_APPLICATION_ROUTES,
            $count,
            'The composed Application router registered ' . $count . ' routes; below the floor the 404 '
            . 'controls in this file stop meaning "no such route".',
        );
    }

    // -----------------------------------------------------------------
    // Auth: proven separately for the authenticated and anonymous cases.
    // -----------------------------------------------------------------

    /**
     * An ANONYMOUS relayed request — no user, no signature — is refused on both
     * paths. Making the images reachable must not make them public.
     *
     * @dataProvider imagePaths
     *
     * @param array<string, string> $query
     */
    public function testAnAnonymousRequestIsRefusedOnBothImagePaths(string $path, array $query): void
    {
        $response = $this->dispatch($this->anonymousRequest($path, $query));

        self::assertSame(
            401,
            $response->statusCode,
            "{$path} must refuse a caller with neither a resolved session nor a valid signed URL.",
        );
        self::assertSame('Unauthorized', $response->body);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function imagePaths(): iterable
    {
        yield 'artwork' => ['/api/v1/artwork/item-1', ['size' => 'w342']];
        yield 'avatar' => ['/api/v1/users/user-1/avatar', []];
    }

    /**
     * The signed-URL alternative still works over the relay for an anonymous
     * caller — this is the `<img src="...">` case, which carries no Authorization
     * header, so removing it would break images by a different route.
     */
    public function testAValidSignedUrlServesAnAnonymousRelayedRequest(): void
    {
        $minted = SignedUrl::fromEnv()->mint('/api/v1/artwork/item-1?size=w342');
        $query = [];
        parse_str((string) parse_url($minted, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        $response = $this->dispatch($this->anonymousRequest('/api/v1/artwork/item-1', $query));

        self::assertSame(200, $response->statusCode);
        self::assertSame($this->posterBytes, $response->materializeFileWindow()->body);
    }

    /**
     * A TAMPERED signature is refused — the positive control above would also pass
     * against a stage that never checked the signature at all.
     */
    public function testATamperedSignatureIsRefusedOverTheRelay(): void
    {
        $minted = SignedUrl::fromEnv()->mint('/api/v1/artwork/item-1?size=w342');
        $query = [];
        parse_str((string) parse_url($minted, PHP_URL_QUERY), $query);
        $sig = (string) ($query['sig'] ?? '');
        self::assertNotSame('', $sig);
        $sig[0] = $sig[0] === 'a' ? 'b' : 'a';
        $query['sig'] = $sig;

        /** @var array<string, string> $query */
        $response = $this->dispatch($this->anonymousRequest('/api/v1/artwork/item-1', $query));

        self::assertSame(401, $response->statusCode);
    }

    // -----------------------------------------------------------------
    // Detector liveness: the stage declines what is not its own.
    // -----------------------------------------------------------------

    /**
     * The stage is not a catch-all: near-miss paths still fall through to the
     * router and 404 there. Without this the 200s above could come from a matcher
     * that swallowed everything.
     *
     * @dataProvider nearMissPaths
     */
    public function testNearMissPathsFallThroughToTheRouter(string $path): void
    {
        self::assertSame(
            404,
            $this->dispatch($this->relayedRequest($path))->statusCode,
            "{$path} must not be captured by the image fast paths.",
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nearMissPaths(): iterable
    {
        yield 'artwork with no id'        => ['/api/v1/artwork/'];
        yield 'artwork with an extra seg' => ['/api/v1/artwork/item-1/large'];
        yield 'avatar with no user'       => ['/api/v1/users/avatar'];
        yield 'a longer avatar spelling'  => ['/api/v1/users/user-1/avatar/large'];
    }

    /**
     * A non-GET verb on an image path is not the stage's business and must reach
     * the router, so the stage cannot shadow a future POST/DELETE there.
     */
    public function testANonGetVerbOnAnImagePathReachesTheRouter(): void
    {
        $request = $this->relayedRequest('/api/v1/artwork/item-1');
        $request->method = 'DELETE';

        self::assertSame(404, $this->dispatch($request)->statusCode);
    }

    /**
     * The DLNA hard deny still wins: the image stage is consulted AFTER it, so no
     * ordering change reopened the authless surface that
     * {@see RelayRequestDispatcherTest} exists to keep shut.
     */
    public function testTheDlnaDenyStillPrecedesTheImageStage(): void
    {
        $response = $this->dispatch($this->relayedRequest('/dlna/stream/item-1'));

        self::assertSame(404, $response->statusCode);
        self::assertSame('Not found', $response->body);
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    private function dispatch(Request $request): Response
    {
        $container = $this->container();

        /** @var PreRouterFastPaths $fastPaths */
        $fastPaths = $container->get(PreRouterFastPaths::class);

        return (new RelayRequestDispatcher($this->application($container), $container, $fastPaths))
            ->dispatch($request);
    }

    /** What RelayConsumer::buildRequest() produces for a tunnelled frame. */
    private function relayedRequest(string $path, array $query = []): Request
    {
        $request = $this->anonymousRequest($path, $query);
        $request->userId = 'hub-relay';

        return $request;
    }

    /** @param array<string, string> $query */
    private function anonymousRequest(string $path, array $query = []): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = $path;
        $request->query = $query;
        $request->queryString = http_build_query($query);
        // Exactly what RelayConsumer stamps on a relayed frame.
        $request->remoteIp = '127.0.0.1';

        return $request;
    }

    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    private function container(): ContainerInterface
    {
        if ($this->sharedContainer !== null) {
            return $this->sharedContainer;
        }

        $connection = $this->createMock(Connection::class);
        $artworkDir = $this->artworkDir;
        $avatarDir = $this->avatarDir;

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection, $artworkDir, $avatarDir) implements ServiceProviderInterface {
            public function __construct(
                private Connection $connection,
                private string $artworkDir,
                private string $avatarDir,
            ) {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $artworkDir = $this->artworkDir;
                $avatarDir = $this->avatarDir;

                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                    // Only the leaf filesystem is repointed; the routing under
                    // test is untouched.
                    ArtworkStorage::class => factory(
                        static fn (): ArtworkStorage => new ArtworkStorage($artworkDir)
                    ),
                    AvatarStorage::class => factory(
                        static fn (): AvatarStorage => new AvatarStorage($avatarDir)
                    ),
                ]);
            }
        };

        return $this->sharedContainer = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);
    }

    private function application(?ContainerInterface $container = null): Application
    {
        if ($this->sharedApplication !== null) {
            return $this->sharedApplication;
        }

        $container ??= $this->container();

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($this->createMock(Connection::class));

        return $this->sharedApplication = new Application($container, [], $pool);
    }

    /** A tiny but genuine JPEG, so "image bytes" means image bytes. */
    private static function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private static function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
