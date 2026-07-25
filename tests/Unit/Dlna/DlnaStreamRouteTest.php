<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use Phlix\Config\EffectiveConfig;
use Phlix\Dlna\CdsServer;
use Phlix\Dlna\DlnaRoutes;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryRootJail;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * The wiring half of S52: `/dlna/stream/{id}` must be a ROUTER route inside
 * `loadCdsRoutes()`'s allowlist group — reachable, and gated.
 *
 * ## The defect this pins, and why a controller test cannot pin it
 *
 * DLNA has no authentication of any kind, so the ONLY thing between this route
 * and "anything that can reach the port streams the whole library" is
 * {@see DlnaAllowlistMiddleware}, which runs exclusively via
 * `Router::runMiddleware()`. The natural template to copy —
 * {@see \Phlix\Server\Workerman\HttpHandler::serveMediaStream()}, which serves
 * `/media/{id}/stream` — dispatches BEFORE `Application::dispatch()` and is
 * therefore unreachable by router middleware; it re-implements auth inline for
 * exactly that reason. A stream route built that way would have zero allowlist
 * enforcement while passing every controller-level test in the suite.
 *
 * So these tests drive the REAL {@see Application::loadCdsRoutes()} and then go
 * in through {@see Application::dispatch()} — the same call the Workerman daemon
 * makes in `HttpHandler::__invoke()`. They assert:
 *
 *   1. an off-allowlist peer gets 403 and the repository is NEVER touched;
 *   2. an allowlisted peer gets bytes;
 *   3. the registered route entry really does carry the allowlist middleware
 *      (a structural assertion, so moving the route out of the group fails even
 *      if some other 403 happened to appear);
 *   4. with `dlna.cds_enabled` off the route does not exist at all (404), so the
 *      shipped default posture is not merely "403 for strangers" but "absent";
 *   5. HEAD is routable, since renderers HEAD before they GET.
 *
 * Related precedent: the original DLNA outage was a fully-implemented service
 * that was never routed, and S44's OIDC endpoints repeated it. Reachability is
 * asserted here for that reason.
 */
final class DlnaStreamRouteTest extends TestCase
{
    private const ITEM_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    private const BODY = 'PHLIX-DLNA-BYTES';

    private string $tmp = '';
    private string $mediaPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();

        $base = realpath(sys_get_temp_dir());
        self::assertIsString($base);
        $this->tmp = $base . '/phlix_dlnaroute_' . uniqid('', true);
        mkdir($this->tmp . '/library', 0o777, true);
        $this->mediaPath = $this->tmp . '/library/Movie.mp4';
        file_put_contents($this->mediaPath, self::BODY);
    }

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        if (is_file($this->mediaPath)) {
            unlink($this->mediaPath);
        }
        if (is_dir($this->tmp . '/library')) {
            rmdir($this->tmp . '/library');
        }
        if (is_dir($this->tmp)) {
            rmdir($this->tmp);
        }
        parent::tearDown();
    }

    /**
     * Bootstrap the config overlay against a throwaway `config/dlna.php`.
     *
     * @param array<string, mixed> $dlna
     */
    private function bootstrapDlnaConfig(array $dlna): void
    {
        $dir = sys_get_temp_dir() . '/phlix_dlnaroutecfg_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/dlna.php', '<?php return ' . var_export($dlna, true) . ";\n");

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        EffectiveConfig::bootstrap($db, null, $dir);
    }

    /**
     * A PSR-11 container over a fixed map (absent keys throw, as PHP-DI does).
     *
     * @param array<string, object> $map
     */
    private function container(array $map): ContainerInterface
    {
        return new class ($map) implements ContainerInterface {
            /** @param array<string, object> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function get(string $id): mixed
            {
                if (!isset($this->map[$id])) {
                    throw new class ('Not found: ' . $id) extends \RuntimeException implements
                        NotFoundExceptionInterface {
                    };
                }

                return $this->map[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->map[$id]);
            }
        };
    }

    /**
     * Build an {@see Application} whose routes come from the REAL
     * `loadCdsRoutes()`, and hand back both it and the item repository so the
     * "handler never ran" assertion can be made on the repository itself.
     *
     * @return array{app: Application, router: Router, items: ItemRepository}
     */
    private function bootCdsRoutes(bool $withRow = true): array
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($withRow ? [
            'id'   => self::ITEM_ID,
            'type' => 'movie',
            'path' => $this->mediaPath,
        ] : null);

        $jailDb = $this->createMock(Connection::class);
        $jailDb->method('query')->willReturn([['paths' => json_encode([$this->tmp . '/library'])]]);

        $container = $this->container([
            CdsServer::class       => $this->createMock(CdsServer::class),
            ItemRepository::class  => $items,
            LibraryRootJail::class => new LibraryRootJail($jailDb),
        ]);

        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $router = new Router($container);

        (new ReflectionProperty(Application::class, 'container'))->setValue($app, $container);
        (new ReflectionProperty(Application::class, 'router'))->setValue($app, $router);

        $loadCdsRoutes = new ReflectionMethod(Application::class, 'loadCdsRoutes');
        $loadCdsRoutes->invoke($app);

        return ['app' => $app, 'router' => $router, 'items' => $items];
    }

    private function streamRequest(string $peerIp, string $method = 'GET', ?string $range = null): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = DlnaRoutes::stream(self::ITEM_ID);
        $request->remoteIp = $peerIp;
        if ($range !== null) {
            $request->headers = ['Range' => $range];
        }

        return $request;
    }

    /**
     * THE SECURITY-CRITICAL ASSERTION: an off-allowlist peer is refused, and the
     * media repository is never consulted — so the handler provably never ran.
     *
     * RED ON REVERT: registering the route outside
     * `$this->router->group('', …, [$allowlistMiddleware])` (or serving it from
     * HttpHandler, the shape `/media/{id}/stream` uses) turns this 403 into a 200
     * with the file's bytes.
     */
    public function test_an_off_allowlist_peer_is_refused_before_the_handler_runs(): void
    {
        $this->bootstrapDlnaConfig(['cds_enabled' => true, 'allowed_cidrs' => [], 'restrict_to_lan' => true]);

        $items = $this->createMock(ItemRepository::class);
        $items->expects(self::never())->method('findById');

        $jailDb = $this->createMock(Connection::class);
        $jailDb->method('query')->willReturn([]);

        $container = $this->container([
            CdsServer::class       => $this->createMock(CdsServer::class),
            ItemRepository::class  => $items,
            LibraryRootJail::class => new LibraryRootJail($jailDb),
        ]);

        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Application::class, 'container'))->setValue($app, $container);
        (new ReflectionProperty(Application::class, 'router'))->setValue($app, new Router($container));
        (new ReflectionMethod(Application::class, 'loadCdsRoutes'))->invoke($app);

        $response = $app->dispatch($this->streamRequest('203.0.113.7'));

        self::assertSame(403, $response->statusCode, 'A public peer must not be able to stream media.');
        self::assertStringNotContainsString(self::BODY, $response->body, 'No bytes may be served to a blocked peer.');
        /** @var array{code?: string} $body */
        $body = json_decode($response->body, true);
        self::assertSame('dlna.forbidden', $body['code'] ?? null);
    }

    /**
     * CONSEQUENCE (positive control): an allowlisted LAN peer really does get the
     * bytes, through the same `Application::dispatch()` call the Workerman daemon
     * makes. Without this the 403 test would also pass against a route that is
     * broken for everyone.
     */
    public function test_an_allowlisted_peer_streams_the_file(): void
    {
        $this->bootstrapDlnaConfig(['cds_enabled' => true, 'allowed_cidrs' => [], 'restrict_to_lan' => true]);

        $booted = $this->bootCdsRoutes();
        $response = $booted['app']->dispatch($this->streamRequest('192.168.1.50'))->materializeFileWindow();

        self::assertSame(200, $response->statusCode);
        self::assertSame(self::BODY, $response->body);
        self::assertSame('video/mp4', $response->headers['Content-Type'] ?? null);
        self::assertSame('bytes', $response->headers['Accept-Ranges'] ?? null);
    }

    /**
     * CONSEQUENCE: Range requests survive the full dispatch path — the renderer
     * seek that S53's `DLNA.ORG_OP=01` advertises works end to end, not just in
     * the controller unit test.
     */
    public function test_a_range_request_survives_the_dispatch_path(): void
    {
        $this->bootstrapDlnaConfig(['cds_enabled' => true, 'allowed_cidrs' => [], 'restrict_to_lan' => true]);

        $booted = $this->bootCdsRoutes();
        $response = $booted['app']
            ->dispatch($this->streamRequest('192.168.1.50', 'GET', 'bytes=6-9'))
            ->materializeFileWindow();

        self::assertSame(206, $response->statusCode);
        self::assertSame(substr(self::BODY, 6, 4), $response->body);
        self::assertSame('bytes 6-9/' . strlen(self::BODY), $response->headers['Content-Range'] ?? null);
    }

    /**
     * CONSEQUENCE: HEAD is routable and the bytes that reach the renderer declare
     * the size exactly once — renderers probe with HEAD before opening a resource,
     * so this is the first reply a device ever sees for an item.
     *
     * Asserted on the ENCODED OUTPUT of the full dispatch path, not on
     * `Response::$headers`: Workerman's encoder appends its own
     * `Content-Length: strlen($body)` after the caller's headers, so a header-array
     * assertion here stayed green while the wire carried
     * `Content-Length: 16` … `Content-Length: 0` — invalid per RFC 9110 §8.6, with
     * the useless value last. See {@see \Phlix\Server\Workerman\BodylessResponse}.
     */
    public function test_head_is_routable_and_reports_the_size_exactly_once_on_the_wire(): void
    {
        $this->bootstrapDlnaConfig(['cds_enabled' => true, 'allowed_cidrs' => [], 'restrict_to_lan' => true]);

        $booted = $this->bootCdsRoutes();
        $response = $booted['app']->dispatch($this->streamRequest('192.168.1.50', 'HEAD'));

        self::assertSame(200, $response->statusCode);

        $wire = (string) $response->toWorkermanResponse();
        self::assertSame(
            1,
            substr_count($wire, 'Content-Length:'),
            "A HEAD reply must carry exactly ONE Content-Length. Encoded bytes were:\n" . $wire,
        );
        self::assertStringContainsString('Content-Length: ' . strlen(self::BODY) . "\r\n", $wire);
        self::assertStringNotContainsString('Content-Length: 0', $wire);
        self::assertStringNotContainsString(self::BODY, $wire, 'A HEAD reply must not carry the bytes.');
    }

    /**
     * CONSEQUENCE: an off-allowlist peer cannot HEAD the resource either — the
     * probe path is gated too, not just GET.
     *
     * This matters because `Router::dispatch()` has a separate HEAD branch
     * (`dispatchAsHead()`); a route registered for GET only would take that
     * branch, and this asserts the middleware still runs there.
     */
    public function test_head_is_refused_for_an_off_allowlist_peer(): void
    {
        $this->bootstrapDlnaConfig(['cds_enabled' => true, 'allowed_cidrs' => [], 'restrict_to_lan' => true]);

        $booted = $this->bootCdsRoutes();
        $response = $booted['app']->dispatch($this->streamRequest('8.8.8.8', 'HEAD'));

        self::assertSame(403, $response->statusCode);
    }

    /**
     * CONSEQUENCE: an explicit `allowed_cidrs` entry admits an otherwise-refused
     * public peer — the operator override reaches this route like every other CDS
     * path, rather than the route having its own private policy.
     */
    public function test_an_explicit_allowlist_entry_admits_a_public_peer(): void
    {
        $this->bootstrapDlnaConfig([
            'cds_enabled'     => true,
            'allowed_cidrs'   => ['203.0.113.0/24'],
            'restrict_to_lan' => true,
        ]);

        $booted = $this->bootCdsRoutes();
        $response = $booted['app']->dispatch($this->streamRequest('203.0.113.7'))->materializeFileWindow();

        self::assertSame(200, $response->statusCode);
        self::assertSame(self::BODY, $response->body);
    }

    /**
     * STRUCTURAL PROOF that the route lives in the allowlist group.
     *
     * The behavioural 403 above could in principle be produced by some other
     * middleware; this reads the registered route entry and asserts the very
     * middleware object attached to it. Both GET and HEAD are checked, because
     * they are registered as two entries.
     */
    public function test_the_registered_route_carries_the_allowlist_middleware(): void
    {
        $this->bootstrapDlnaConfig(['cds_enabled' => true, 'allowed_cidrs' => [], 'restrict_to_lan' => true]);

        $routes = $this->bootCdsRoutes()['router']->getRoutes();

        foreach (['GET', 'HEAD'] as $method) {
            $entry = null;
            foreach ($routes[$method] ?? [] as $candidate) {
                if (($candidate['path'] ?? null) === DlnaRoutes::STREAM_PATTERN) {
                    $entry = $candidate;
                    break;
                }
            }

            self::assertIsArray($entry, $method . ' ' . DlnaRoutes::STREAM_PATTERN . ' must be registered.');

            $hasAllowlist = false;
            foreach ($entry['middleware'] as $middleware) {
                if ($middleware instanceof DlnaAllowlistMiddleware) {
                    $hasAllowlist = true;
                }
            }

            self::assertTrue(
                $hasAllowlist,
                sprintf(
                    '%s %s must be inside loadCdsRoutes()\'s allowlist group — DLNA has no auth, so this '
                    . 'middleware is the only gate the route has.',
                    $method,
                    DlnaRoutes::STREAM_PATTERN,
                ),
            );
        }
    }

    /**
     * CONSEQUENCE: with the shipped `dlna.cds_enabled = false`, the route does not
     * exist — a LAN peer gets 404, not 403 and certainly not bytes.
     *
     * This is the default-off posture: turning DLNA on is a deliberate act, and
     * until then there is no stream surface at all.
     */
    public function test_the_route_does_not_exist_while_the_cds_is_disabled(): void
    {
        $this->bootstrapDlnaConfig(['cds_enabled' => false, 'allowed_cidrs' => [], 'restrict_to_lan' => true]);

        $booted = $this->bootCdsRoutes();

        self::assertSame([], $booted['router']->getRoutes(), 'No CDS route may be registered while DLNA is off.');

        $response = $booted['app']->dispatch($this->streamRequest('192.168.1.50'));
        self::assertSame(404, $response->statusCode);
        self::assertStringNotContainsString(self::BODY, $response->body);
    }

    /**
     * LOCK-IN: `dlna.cds_enabled` still ships FALSE. S52 adds an authless byte
     * route, so a default flip here would newly expose media, not just browse
     * metadata.
     */
    public function test_the_shipped_config_still_disables_the_cds_by_default(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/config/dlna.php';

        self::assertArrayHasKey('cds_enabled', $config);
        self::assertFalse($config['cds_enabled'], 'DLNA (and therefore the authless stream route) must ship OFF.');
    }

    /**
     * CONSEQUENCE: a bogus id on the real dispatch path is a clean 404 for an
     * allowlisted peer — not a 500, and with no filesystem detail.
     */
    public function test_an_unknown_id_is_a_clean_404_on_the_dispatch_path(): void
    {
        $this->bootstrapDlnaConfig(['cds_enabled' => true, 'allowed_cidrs' => [], 'restrict_to_lan' => true]);

        $booted = $this->bootCdsRoutes(withRow: false);
        $response = $booted['app']->dispatch($this->streamRequest('192.168.1.50'));

        self::assertSame(404, $response->statusCode);
        self::assertStringNotContainsString($this->tmp, $response->body);
    }

    /**
     * LOCK-IN: the path the router registers and the path
     * {@see DlnaRoutes::stream()} builds (which S53 puts into `<res>`) agree.
     *
     * The original DLNA browse outage was exactly this kind of two-copies drift:
     * the description advertised `/ctl/ContentDirectory` while the server
     * registered `/dlna/content_directory`.
     */
    public function test_the_advertised_stream_path_matches_the_registered_pattern(): void
    {
        self::assertSame(
            str_replace('{mediaItemId}', self::ITEM_ID, DlnaRoutes::STREAM_PATTERN),
            DlnaRoutes::stream(self::ITEM_ID),
        );
    }
}
