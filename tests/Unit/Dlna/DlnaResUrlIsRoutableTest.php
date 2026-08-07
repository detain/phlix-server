<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use DI\ContainerBuilder;
use DOMDocument;
use DOMElement;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Config\EffectiveConfig;
use Phlix\Dlna\ContentDirectory;
use Phlix\Dlna\DlnaRoutes;
use Phlix\Dlna\DlnaServer;
use Phlix\Dlna\SsdpAdvertiser;
use Phlix\Media\Library\ItemRepository;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionProperty;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S53's acceptance criterion: the `<res>` URL a Browse response advertises is
 * **resolvable**, and its `protocolInfo` is the one the bytes arrive under.
 *
 * ## Why "well-formed" is not the question
 *
 * The URL this replaced was perfectly well-formed —
 * `http://localhost:8096/hls/{mediaItemId}/playlist.m3u8` — and completely
 * dead: wrong id kind (a media-item id where a transcode JOB id belongs), a
 * file that does not exist, behind `SignedUrlMiddleware`, on a host a TV
 * resolves to itself. Any test that checked the shape of a URL string would
 * have passed on every one of those defects. So this file never asserts on the
 * string alone. It:
 *
 *  1. resolves the advertised URL's path against the **route table the
 *     production `Application` actually composed**, by reflection, and requires
 *     the matching entry's registered pattern to be EXACTLY
 *     {@see DlnaRoutes::STREAM_PATTERN} — never a substring test, because a
 *     sibling wildcard can absorb a wrong path and still answer;
 *  2. then feeds that same URL back through `Application::dispatch()` from an
 *     allowlisted peer and requires the media file's BYTES, with the
 *     `Content-Type` the `protocolInfo` promised. That is the simulated DLNA
 *     client the AC asks for: browse, read `<res>`, fetch it, play it.
 *
 * ## And the security half
 *
 * The deleted no-bridge fallback published `$item['path']` — the absolute
 * filesystem path — as the resource value. A CDS Browse is unauthenticated on
 * the allowlisted path, so that disclosed the server's directory layout to
 * anything on the LAN. {@see self::test_no_resource_value_is_ever_a_filesystem_path()}
 * reddens if any resource value is path-shaped, over a deliberately varied set
 * of rows rather than one specimen.
 *
 * @see \Phlix\Tests\Unit\Server\Core\ApplicationDlnaAdminWiringGuardTest The
 *      harness shape this follows: assertions against the PRODUCTION container
 *      and the PRODUCTION router, never a hand-wired object.
 */
final class DlnaResUrlIsRoutableTest extends TestCase
{
    /**
     * Lower bound on a sane composed route table, per the S219/S239 precedent.
     * A hand-rolled container yields ~53 routes where the real one yields ~345.
     */
    private const MIN_EXPECTED_ROUTES = 300;

    /** A host that is NOT this machine's auto-detected address, so the setting is provably honoured. */
    private const ADVERTISE_HOST = '192.0.2.55';

    private const PORT = 8096;

    private const ITEM_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const BODY = 'PHLIX-DLNA-RES-BYTES';

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private string $mediaPath = '';

    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $base = realpath(sys_get_temp_dir());
        self::assertIsString($base);
        $this->tempDir = $base . '/phlix_s53_' . uniqid('', true);
        mkdir($this->tempDir . '/library', 0o775, true);

        $this->mediaPath = $this->tempDir . '/library/Movie.mp4';
        file_put_contents($this->mediaPath, self::BODY);

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

        // The REAL config/ directory, with two persisted `server_settings`
        // overrides layered on exactly as an admin would set them. Nothing about
        // the shipped defaults is faked: `dlna.cds_enabled` really does ship
        // false, and `dlna.advertise_host` really does ship ''.
        EffectiveConfig::bootstrap($this->settingsConnection(), null, null);
    }

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/library/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir . '/library')) {
            rmdir($this->tempDir . '/library');
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    /**
     * A `Connection` double that answers the two reads the production wiring
     * makes: the `server_settings` overlay and `LibraryRootJail`'s roots.
     */
    private function settingsConnection(): Connection
    {
        $roots = json_encode([$this->tempDir . '/library']);
        $connection = $this->createMock(Connection::class);
        $connection->method('query')->willReturnCallback(
            static function (mixed $sql = null) use ($roots): array {
                $text = is_string($sql) ? $sql : '';

                if (str_contains($text, 'server_settings')) {
                    return [
                        [
                            'setting_key'   => 'dlna.cds_enabled',
                            'setting_value' => '1',
                            'value_type'    => 'bool',
                        ],
                        [
                            'setting_key'   => 'dlna.advertise_host',
                            'setting_value' => self::ADVERTISE_HOST,
                            'value_type'    => 'string',
                        ],
                    ];
                }

                if (str_contains($text, 'FROM libraries')) {
                    return [['paths' => $roots]];
                }

                return [];
            }
        );

        return $connection;
    }

    /**
     * The PRODUCTION container: `ContainerFactory::defaultProviders()` with the
     * MySQL {@see Connection} and {@see ItemRepository} doubled and NOTHING
     * else. In particular {@see DlnaServer}, {@see ContentDirectory} and the
     * {@see \Phlix\Dlna\LibraryBridge} inside it are built by the real
     * `DlnaServicesProvider`, which is the wiring under test: PHP-DI's
     * `autowire()` skips optional constructor parameters, so a bridge that lost
     * its base-URL argument would silently resolve the origin somewhere else,
     * and only a container-built instance can show that.
     */
    private function container(): ContainerInterface
    {
        if ($this->sharedContainer !== null) {
            return $this->sharedContainer;
        }

        $connection = $this->settingsConnection();

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturnCallback(
            fn (string $id): ?array => $id === self::ITEM_ID ? $this->row() : null
        );

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection, $items) implements ServiceProviderInterface {
            public function __construct(
                private Connection $connection,
                private ItemRepository $items,
            ) {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $items = $this->items;

                $builder->addDefinitions([
                    Connection::class      => factory(static fn (): Connection => $connection),
                    ItemRepository::class  => factory(static fn (): ItemRepository => $items),
                ]);
            }
        };

        return $this->sharedContainer = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path'     => null,
            'server'             => ['port' => self::PORT],
        ], $providers);
    }

    private function application(): Application
    {
        if ($this->sharedApplication !== null) {
            return $this->sharedApplication;
        }

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($this->createMock(Connection::class));

        return $this->sharedApplication = new Application($this->container(), [], $pool);
    }

    /**
     * The `media_items` row the whole test hangs off — a real file on disk,
     * inside the configured library root, with a container the stream route
     * direct-plays.
     *
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'id'        => self::ITEM_ID,
            'parent_id' => 'library-video',
            'name'      => 'Some Movie',
            'type'      => 'movie',
            'path'      => $this->mediaPath,
        ];
    }

    /**
     * The route table of the `Router` the production `Application` composed.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function routeTable(): array
    {
        $property = new ReflectionProperty(Application::class, 'router');
        $router = $property->getValue($this->application());

        if (!$router instanceof Router) {
            self::fail('ANTI-VACUITY: Application::$router did not hold a Router.');
        }

        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();

        $count = 0;
        foreach ($routes as $entries) {
            $count += count($entries);
        }

        if ($count < self::MIN_EXPECTED_ROUTES) {
            self::fail(sprintf(
                'ANTI-VACUITY: the composed route table holds %d route(s), below the %d floor. '
                . 'This harness is not reading the production container, so nothing below is a '
                . 'real constraint.',
                $count,
                self::MIN_EXPECTED_ROUTES
            ));
        }

        return $routes;
    }

    /**
     * Every REGISTERED route pattern that would match `$path` for `$method`.
     *
     * The Router keys static routes by their literal path and parametric ones
     * by the compiled regex; both entries carry the registered `path`, which is
     * what is returned. Returning the whole list — rather than the first hit —
     * is deliberate: if a sibling wildcard also matches, that shows up as two
     * entries instead of being hidden behind a passing dispatch.
     *
     * @return list<string>
     */
    private function registeredPatternsMatching(string $method, string $path): array
    {
        $hits = [];

        foreach ($this->routeTable()[$method] ?? [] as $key => $entry) {
            $registered = $entry['path'] ?? null;
            if (!is_string($registered)) {
                continue;
            }

            $isRegex = str_starts_with($key, '#^');
            $matches = $isRegex
                ? preg_match($key, $path) === 1
                : $key === $path;

            if ($matches) {
                $hits[] = $registered;
            }
        }

        return $hits;
    }

    /**
     * The `<res>` element the production ContentDirectory emits for `$item`.
     *
     * @param array<string, mixed>|null $item Defaults to {@see self::row()}.
     */
    private function resElement(?array $item = null): DOMElement
    {
        $contentDirectory = $this->container()->get(ContentDirectory::class);
        self::assertInstanceOf(ContentDirectory::class, $contentDirectory);
        self::assertTrue(
            $contentDirectory->hasLibraryBridge(),
            'ANTI-VACUITY: the container-built ContentDirectory has no LibraryBridge, so it is '
            . 'serving STUB data and emits no <res> at all.'
        );

        $xml = $contentDirectory->generateDidl([$this->cdsObject($item ?? $this->row())], true);

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'DIDL-Lite is not well-formed: ' . $xml);

        $nodes = $dom->getElementsByTagNameNS('urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/', 'res');
        self::assertSame(1, $nodes->length, 'Expected exactly one <res>. Got: ' . $xml);

        $res = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $res);

        return $res;
    }

    /**
     * Put a raw row through the SAME transform the browse path uses.
     *
     * `ContentDirectory` never sees raw `media_items` rows in production — it
     * sees `LibraryBridge::itemToCdsObject()` output, which is where `mime_type`
     * is resolved. Hand-building the DIDL input would test a shape production
     * never produces.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function cdsObject(array $row): array
    {
        $server = $this->container()->get(DlnaServer::class);
        self::assertInstanceOf(DlnaServer::class, $server);

        $bridgeProperty = new ReflectionProperty(ContentDirectory::class, 'libraryBridge');
        $bridge = $bridgeProperty->getValue($server->getContentDirectory());
        self::assertInstanceOf(\Phlix\Dlna\LibraryBridge::class, $bridge);

        return $bridge->itemToCdsObject($row);
    }

    // -----------------------------------------------------------------
    // 1. The advertised URL names a REGISTERED route
    // -----------------------------------------------------------------

    /**
     * The `<res>` path resolves to exactly ONE registered route, and that route
     * is {@see DlnaRoutes::STREAM_PATTERN}.
     *
     * RED ON REVERT: restoring `return $this->hlsStreamer->getStreamUrl($item)`
     * in `LibraryBridge::getStreamUrl()` advertises `/hls/{id}/playlist.m3u8`,
     * which matches NO registered route inside the CDS group — the assertion
     * below reports zero matches.
     *
     * The comparison is `assertSame` against the pattern constant, never
     * `str_contains`: a sibling wildcard such as `/dlna/{anything}` would absorb
     * a wrong path and still answer, and a substring check could not tell the
     * two apart.
     */
    public function test_the_advertised_res_path_is_the_registered_dlna_stream_route(): void
    {
        $url = $this->resElement()->textContent;

        $path = parse_url($url, PHP_URL_PATH);
        self::assertIsString($path, "The advertised <res> value is not a parseable URL: {$url}");

        $hits = $this->registeredPatternsMatching('GET', $path);

        self::assertSame(
            [DlnaRoutes::STREAM_PATTERN],
            $hits,
            sprintf(
                'The advertised <res> path %s must resolve to exactly one registered route, and it '
                . 'must be %s. Matched: %s',
                $path,
                DlnaRoutes::STREAM_PATTERN,
                $hits === [] ? '(nothing — the URL is unrouted)' : implode(', ', $hits)
            )
        );
    }

    /**
     * THE CONTROL for the assertion above, and a CORRECTION to the step brief.
     *
     * The brief describes the pre-S53 `<res>` URL as "a dead URL … a file that
     * does not exist". Measured against the composed route table, it is worse
     * than that in one respect and better in another: the path
     * `/hls/{mediaItemId}/playlist.m3u8` **does** match a registered route —
     * `/hls/{job_id}/{file}` — so it is not unrouted. It is *mis*routed: the
     * `{job_id}` placeholder is fed a media-item id, and the group is wrapped in
     * `SignedUrlMiddleware`, which a DLNA renderer can never satisfy.
     *
     * That makes this control MORE important, not less: because the old URL did
     * match something, a test that only asked "does the advertised path match
     * some route?" would have passed on the defect. The exact-equality
     * assertion above is what distinguishes the two, and this test is what shows
     * the two are genuinely distinguishable.
     */
    public function test_the_previously_advertised_hls_path_resolves_to_a_different_route_that_refuses(): void
    {
        $hlsPath = '/hls/' . self::ITEM_ID . '/playlist.m3u8';

        $hits = $this->registeredPatternsMatching('GET', $hlsPath);
        self::assertNotEmpty($hits, 'ANTI-VACUITY: the pre-S53 path matched nothing at all.');
        self::assertNotContains(
            DlnaRoutes::STREAM_PATTERN,
            $hits,
            'The pre-S53 HLS path must NOT resolve to the DLNA stream route — if it did, the '
            . 'exact-equality assertion above could not tell the fixed URL from the broken one.'
        );

        // …and following it gets a renderer nothing. Asserted from the SAME
        // allowlisted peer that successfully streams the new URL, so the refusal
        // is a property of this URL and not of the peer.
        $request = new Request();
        $request->method   = 'GET';
        $request->path     = $hlsPath;
        $request->remoteIp = '192.168.1.50';

        $response = $this->application()->dispatch($request);

        self::assertNotSame(
            200,
            $response->statusCode,
            'The pre-S53 <res> URL must not serve anything; if it did, S53 would be pointless.'
        );
        self::assertStringNotContainsString(self::BODY, $response->body);
    }

    /**
     * A near-miss path must not be absorbed by a sibling wildcard.
     *
     * Separate from the control above because it asks a different question: not
     * "is the old URL distinguishable" but "could ANY `/dlna/…` path answer",
     * which would make the exact-pattern match meaningless.
     */
    public function test_a_near_miss_dlna_path_matches_no_registered_route(): void
    {
        self::assertSame([], $this->registeredPatternsMatching('GET', '/dlna/strea/' . self::ITEM_ID));
        self::assertSame([], $this->registeredPatternsMatching('GET', '/dlna/stream/' . self::ITEM_ID . '/x'));
    }

    /**
     * Renderers HEAD a resource before opening it, so the advertised path must
     * be registered for HEAD as well as GET.
     */
    public function test_the_advertised_res_path_is_also_registered_for_head(): void
    {
        $path = parse_url($this->resElement()->textContent, PHP_URL_PATH);
        self::assertIsString($path);

        self::assertSame([DlnaRoutes::STREAM_PATTERN], $this->registeredPatternsMatching('HEAD', $path));
    }

    // -----------------------------------------------------------------
    // 2. The simulated renderer: fetch the advertised URL, get the bytes
    // -----------------------------------------------------------------

    /**
     * THE ACCEPTANCE CRITERION. Browse → read `<res>` → GET it → bytes.
     *
     * This is the whole point of the step, done the way a renderer does it:
     * nothing here knows the path in advance. The URL is taken from the DIDL the
     * production ContentDirectory emitted and pushed back through
     * `Application::dispatch()` — the same call `HttpHandler::__invoke()` makes.
     *
     * The `Content-Type` is compared against the MIME field of the very
     * `protocolInfo` the same `<res>` advertised, so "the bytes arrive under the
     * type we promised" is asserted as a relation between the two, not as two
     * independent literals that could drift together.
     */
    public function test_a_renderer_that_follows_the_advertised_res_url_receives_the_bytes(): void
    {
        $res = $this->resElement();
        $url = $res->textContent;

        $path = parse_url($url, PHP_URL_PATH);
        self::assertIsString($path);

        $request = new Request();
        $request->method   = 'GET';
        $request->path     = $path;
        $request->remoteIp = '192.168.1.50';

        $response = $this->application()->dispatch($request)->materializeFileWindow();

        self::assertSame(200, $response->statusCode, 'Advertised URL did not serve: ' . $response->body);
        self::assertSame(self::BODY, $response->body);
        self::assertSame('bytes', $response->headers['Accept-Ranges'] ?? null);

        $advertisedMime = explode(':', $res->getAttribute('protocolInfo'))[2] ?? '';
        self::assertSame(
            $advertisedMime,
            $response->headers['Content-Type'] ?? null,
            'The served Content-Type must be the MIME the <res> protocolInfo advertised; a renderer '
            . 'that sees the two disagree rejects the object.'
        );
    }

    /**
     * `DLNA.ORG_OP=01` is a PROMISE that byte-range seek works. Kept honest by
     * exercising it on the advertised URL rather than on a hand-built one.
     */
    public function test_the_advertised_url_honours_the_byte_seek_it_promises(): void
    {
        $res = $this->resElement();
        self::assertStringContainsString('DLNA.ORG_OP=01', $res->getAttribute('protocolInfo'));

        $path = parse_url($res->textContent, PHP_URL_PATH);
        self::assertIsString($path);

        $request = new Request();
        $request->method   = 'GET';
        $request->path     = $path;
        $request->remoteIp = '192.168.1.50';
        $request->headers  = ['Range' => 'bytes=6-9'];

        $response = $this->application()->dispatch($request)->materializeFileWindow();

        self::assertSame(206, $response->statusCode);
        self::assertSame(substr(self::BODY, 6, 4), $response->body);
    }

    // -----------------------------------------------------------------
    // 3. The advertised HOST is the configured one, shared by all three sites
    // -----------------------------------------------------------------

    /**
     * `<res>`'s origin is the `dlna.advertise_host` an operator set — the same
     * one the device description and the SSDP `LOCATION` use.
     *
     * The three had to be asserted TOGETHER, because before S53 they were not
     * the same: `DlnaServicesProvider` honoured the setting while
     * `SsdpAdvertiser` (constructed as `new SsdpAdvertiser(null, …)` in
     * `start.php`) ignored it and always auto-detected. They agreed only under
     * the shipped default of `''`. {@see self::ADVERTISE_HOST} is deliberately a
     * TEST-NET address this machine cannot possibly auto-detect, so a resolver
     * that fell back to detection fails here rather than coincidentally passing.
     */
    public function test_res_the_description_and_the_ssdp_location_all_name_the_configured_host(): void
    {
        $expectedOrigin = 'http://' . self::ADVERTISE_HOST . ':' . self::PORT;

        $url = $this->resElement()->textContent;
        self::assertSame($expectedOrigin, $this->originOf($url), '<res> origin');

        $server = $this->container()->get(DlnaServer::class);
        self::assertInstanceOf(DlnaServer::class, $server);
        self::assertSame($expectedOrigin, $server->getBaseUrl(), 'device description origin');

        self::assertSame(
            $expectedOrigin,
            $this->originOf($this->ssdpLocationUrl()),
            'SSDP LOCATION origin — before S53 this ignored dlna.advertise_host entirely.'
        );
    }

    /**
     * The PRODUCTION container passes the origin to the bridge EXPLICITLY.
     *
     * ## Why this needs its own test, measured
     *
     * Deleting the fifth argument from `new LibraryBridge(…)` in
     * `DlnaServicesProvider` left the whole rest of this file GREEN: the bridge
     * has a lazy fallback that resolves the origin from the same
     * `EffectiveConfig`, so under this harness it produces an identical string.
     * The wiring is nonetheless load-bearing for two reasons a URL comparison
     * cannot see:
     *
     *  - **The port.** `DlnaServer` gets its port from the boot `$appConfig`
     *    the provider was handed; the lazy fallback reads `config/server.php`.
     *    Those are the same number today and need not be — a deployment that
     *    overrides the listen port in `$appConfig` would advertise a `<res>`
     *    on one port and a description on another.
     *  - **The event loop.** The fallback ends in
     *    `SsdpAdvertiser::detectLocalIp()`, a BLOCKING `fsockopen` with a
     *    one-second timeout. In the container factory that runs at wiring time;
     *    reached lazily it would run inside whichever request first browsed,
     *    stalling the Workerman worker for up to a second.
     *
     * So this reads the private field off the container-built bridge, which is
     * the only way to distinguish "was told" from "worked it out". It is the
     * same class of assertion `ApplicationDlnaAdminWiringGuardTest` makes, and
     * for the same reason: PHP-DI's `autowire()` silently skips optional
     * constructor parameters.
     */
    public function test_the_container_wires_the_base_url_into_the_bridge_explicitly(): void
    {
        $server = $this->container()->get(DlnaServer::class);
        self::assertInstanceOf(DlnaServer::class, $server);

        $bridgeProperty = new ReflectionProperty(ContentDirectory::class, 'libraryBridge');
        $bridge = $bridgeProperty->getValue($server->getContentDirectory());
        self::assertInstanceOf(\Phlix\Dlna\LibraryBridge::class, $bridge);

        $wired = (new ReflectionProperty(\Phlix\Dlna\LibraryBridge::class, 'dlnaBaseUrl'))
            ->getValue($bridge);

        self::assertNotNull(
            $wired,
            'DlnaServicesProvider must pass DlnaServer::getBaseUrl() to LibraryBridge. A null here '
            . 'means the bridge is left to resolve the origin itself — which happens to agree today '
            . 'but reads a different port source and performs a blocking network probe inside '
            . 'whichever request browses first.'
        );
        self::assertSame($server->getBaseUrl(), $wired);
    }

    /**
     * THE CONTROL for the assertion above: the field genuinely CAN be null, so
     * asserting it is not is a real constraint rather than a tautology.
     */
    public function test_a_bridge_built_without_an_origin_really_does_hold_null(): void
    {
        $bridge = new \Phlix\Dlna\LibraryBridge(
            $this->createMock(ItemRepository::class),
            $this->createMock(\Phlix\Media\Streaming\HlsStreamer::class)
        );

        self::assertNull(
            (new ReflectionProperty(\Phlix\Dlna\LibraryBridge::class, 'dlnaBaseUrl'))->getValue($bridge)
        );
    }

    /**
     * The `LOCATION` URL a production-shaped advertiser (no explicit IP, exactly
     * as `start.php` builds it) would publish.
     *
     * Built via `newInstanceWithoutConstructor()` on purpose: `SsdpAdvertiser`
     * extends `Workerman\Worker`, whose constructor registers the instance in
     * the process-static `Worker::$workers`, so a plain `new` here would inject
     * a phantom worker into every later test in the run.
     */
    private function ssdpLocationUrl(): string
    {
        $advertiser = (new ReflectionClass(SsdpAdvertiser::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty(SsdpAdvertiser::class, 'ipAddress'))->setValue($advertiser, null);
        (new ReflectionProperty(SsdpAdvertiser::class, 'port'))->setValue($advertiser, self::PORT);

        return $advertiser->getLocationUrl();
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        self::assertIsArray($parts, "Not a parseable URL: {$url}");

        return sprintf(
            '%s://%s:%s',
            $parts['scheme'] ?? '',
            $parts['host'] ?? '',
            $parts['port'] ?? ''
        );
    }

    // -----------------------------------------------------------------
    // 4. The deleted path leak, with a detector that would see it return
    // -----------------------------------------------------------------

    /**
     * NO resource value may be a filesystem path — over a VARIED set of rows,
     * not one specimen.
     *
     * RED ON MASTER: this is the test the deleted `elseif` at
     * `ContentDirectory::addItemMetadata()` fails. That arm emitted
     * `$item['path']` as the `<res>` value whenever no LibraryBridge was set,
     * publishing the server's directory layout in an UNAUTHENTICATED Browse
     * response.
     *
     * The shapes vary on the two axes that decide which arm ran: whether a
     * bridge is present, and whether the row has an id. A single row could only
     * ever exercise one of them.
     */
    public function test_no_resource_value_is_ever_a_filesystem_path(): void
    {
        $withBridge = $this->container()->get(ContentDirectory::class);
        self::assertInstanceOf(ContentDirectory::class, $withBridge);

        $bridgeless = new ContentDirectory($this->createMock(ItemRepository::class));

        /** @var list<array{0: ContentDirectory, 1: array<string, mixed>}> $cases */
        $cases = [
            [$withBridge, $this->cdsObject($this->row())],
            [$withBridge, $this->cdsObject(['id' => '', 'name' => 'No id', 'type' => 'movie',
                'path' => '/srv/media/secret/Leak.mp4'])],
            [$bridgeless, $this->cdsObject($this->row())],
            [$bridgeless, ['id' => 'x', 'name' => 'Raw row', 'type' => 'photo',
                'path' => '/srv/media/secret/Leak.jpg']],
            [$bridgeless, ['name' => 'No id at all', 'type' => 'movie',
                'path' => '/srv/media/secret/Leak2.mkv']],
        ];

        $inspected = 0;
        foreach ($cases as [$directory, $item]) {
            $xml = $directory->generateDidl([$item], true);

            $dom = new DOMDocument();
            self::assertTrue($dom->loadXML($xml), 'DIDL-Lite is not well-formed: ' . $xml);

            foreach ($dom->getElementsByTagNameNS('urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/', 'res') as $res) {
                $inspected++;
                $value = $res->textContent;

                self::assertStringStartsWith(
                    'http://',
                    $value,
                    "A <res> value must be an absolute HTTP URL. Got: {$value}"
                );
                self::assertStringNotContainsString(
                    '/srv/media/',
                    $value,
                    "A <res> value leaked a filesystem path: {$value}"
                );
                self::assertStringNotContainsString(
                    $this->tempDir,
                    $value,
                    "A <res> value leaked the media file's real location: {$value}"
                );
            }

            // Belt and braces at the raw-string level: nothing anywhere in the
            // document may name the on-disk file, not just the <res> value.
            self::assertStringNotContainsString($this->mediaPath, $xml);
        }

        // ANTI-VACUITY: at least one case must actually have produced a <res>,
        // or "no leak" would be true of an empty document.
        self::assertGreaterThan(
            0,
            $inspected,
            'ANTI-VACUITY: no <res> element was inspected at all, so this detector proved nothing.'
        );
    }
}
