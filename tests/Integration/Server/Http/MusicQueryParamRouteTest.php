<?php

/**
 * S240 — music entity NAME on a query parameter resolves end-to-end through the
 * REAL composed route table (`Application::loadMusicRoutes()`), direct over a
 * real Workerman wire buffer and over the hub relay envelope, against real MySQL.
 *
 * The relay hub's path-traversal guard refuses percent-encoded slashes inside
 * PATHS (by design — pinned in the hub repo, untouched here), so slash-bearing
 * names like "AC/DC" could never reach `/api/v1/music/artists/{mbid}` through
 * the relay. The additive `GET /api/v1/music/artist?name=` /
 * `GET /api/v1/music/album?name=[&artist=]` spelling carries the name on the
 * QUERY STRING, which crosses the bridge byte-for-byte and is percent-decoded
 * EXACTLY ONCE by the request layer — never again in the handler.
 *
 * One test reddens PER CLASS by name if the feature regresses:
 * - space class:  testArtistNameWithSpaceResolves… / testAlbumTitleWithSpaceResolves…
 * - slash class:  testArtistNameWithSlashResolves… / testAlbumTitleWithSlashResolves…
 * and the `%2520` planted-drift fixture test reddens on any DOUBLE-decode.
 * The relay tests redden if the query stops crossing the tunnel envelope.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Server\Http;

use Phlix\Auth\UserIdentityRepository;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayConfig;
use Phlix\Hub\RelayConsumer;
use Phlix\Hub\RelayIdentityResolver;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanHttpRequest;

/**
 * Query-param music detail over the production composition, not call-site arguments.
 */
final class MusicQueryParamRouteTest extends TestCase
{
    use RequiresRealDatabase;

    /** S240 lane survival marker — code-resident proof this class is live. */
    public const ROUTE_SHAPE_TOKEN = 'S240QRYPARAMX9Q5';

    private ?Connection $db = null;

    /** The router production actually dispatches music detail through. */
    private Router $router;

    /** Fixture namespace for `music_artists.name` / `music_albums.title` (both UNIQUE). */
    private string $prefix = '';

    /** @var list<int> seeded music_artists ids */
    private array $artistIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S240 music query-param route e2e. Runs in CI.');
        $this->assertNotNull($this->db);

        $this->prefix = '!S240-' . substr(Uuid::v4(), 0, 8) . '-';

        $this->router = $this->composedMusicRouter($this->db);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            $ids = $this->artistIds;
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->query("DELETE FROM music_albums WHERE artist_id IN ($placeholders)", $ids);
                $db->query("DELETE FROM music_artists WHERE id IN ($placeholders)", $ids);
            }
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Token home — the decision marker is executed, not decorative.       */
    /* ------------------------------------------------------------------ */

    public function testQueryShapeDecisionTokenIsMirroredByTheRegistrar(): void
    {
        $this->assertSame(
            Application::MUSIC_QUERY_PARAM_ROUTE_TOKEN,
            self::ROUTE_SHAPE_TOKEN,
            'the route-shape decision site and its proof must pin the same token',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Artist class — space / slash / query-transport specials.            */
    /* ------------------------------------------------------------------ */

    /**
     * Headline AC (space class): `GET /api/v1/music/artist?name=…Abbey%20Road`
     * resolves the artist named `…Abbey Road`. Built from REAL wire bytes through
     * the production transport (`Workerman\Protocols\Http\Request` →
     * `Request::fromWorkerman()`), so `parse_str` percent-decodes the query
     * exactly like a live HTTP worker does.
     * REDS BY NAME (space class) if the route or the decoded hand-off is removed.
     */
    public function testArtistNameWithSpaceResolvesThroughTheRealComposedRouteTable(): void
    {
        $name = $this->prefix . 'Abbey Road';
        $this->seedArtist($name);

        $response = $this->dispatchWire('/api/v1/music/artist?name=' . rawurlencode($name));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /**
     * Headline AC (slash class): the name rides as `%2F` on the wire and arrives
     * at `findArtistByName()` as the literal `AC/DC` — the byte the relay path
     * guard will never let through a path segment.
     * REDS BY NAME (slash class) if the route or the decoded hand-off is removed.
     */
    public function testArtistNameWithSlashResolvesThroughTheRealComposedRouteTable(): void
    {
        $name = $this->prefix . 'AC/DC';
        $this->seedArtist($name);

        $response = $this->dispatchWire('/api/v1/music/artist?name=' . rawurlencode($name));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /**
     * Query-transport specials: `&` and `+` are structural in a query string, so
     * the client MUST percent-encode them (`%26`, `%2B`); `parse_str` then hands
     * the handler the literal name. Pins the exact encoding contract for the
     * client migration lanes (ui/mobile/tizen/console).
     */
    public function testArtistNameWithPlusAndAmpersandResolvesWhenProperlyEncoded(): void
    {
        $name = $this->prefix . 'Chicago & +Friends';
        $this->seedArtist($name);

        $response = $this->dispatchWire('/api/v1/music/artist?name=' . rawurlencode($name));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /**
     * `%2520` planted-drift fixture: with BOTH artists seeded — `…Abbey Road` and
     * the literal `…Abbey%20Road` — the double-encoded request must resolve ONLY
     * the literal one. Any second decode in the handler collapses
     * `%2520`→`%20`→space and answers with the wrong row.
     */
    public function testDoubleEncodedNameResolvesOnlyTheLiteralPercentNameArtist(): void
    {
        $this->seedArtist($this->prefix . 'Abbey Road');
        $literal = $this->prefix . 'Abbey%20Road';
        $this->seedArtist($literal);

        $response = $this->dispatchWire(
            '/api/v1/music/artist?name=' . rawurlencode($literal),
        );

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame(
            $literal,
            $body['artist']['name'] ?? null,
            'one decode — the literal %20 artist, never the space-named one',
        );
    }

    /**
     * Contract: missing or empty `name` is a 400 on the query twin, mirroring the
     * legacy path handler's empty-segment answer byte-for-byte.
     */
    public function testArtistWithoutNameParamIsRejectedWithBadRequest(): void
    {
        $missing = $this->dispatchWire('/api/v1/music/artist');
        $this->assertSame(400, $missing->statusCode);
        $this->assertSame(
            ['error' => 'Artist name is required'],
            json_decode($missing->body, true),
        );

        $empty = $this->dispatchWire('/api/v1/music/artist?name=');
        $this->assertSame(400, $empty->statusCode);
        $this->assertSame(
            ['error' => 'Artist name is required'],
            json_decode($empty->body, true),
        );
    }

    /** Unknown name answers 404 through the query twin — same contract as the path twin. */
    public function testArtistWithUnknownNameAnswersNotFound(): void
    {
        $response = $this->dispatchWire('/api/v1/music/artist?name=' . rawurlencode($this->prefix . 'nobody'));

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(
            ['error' => 'Artist not found'],
            json_decode($response->body, true),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Album class — space / slash + the artist disambiguator.             */
    /* ------------------------------------------------------------------ */

    /**
     * Album twin (space class). REDS BY NAME if the route or the decoded
     * hand-off is removed.
     */
    public function testAlbumTitleWithSpaceResolvesThroughTheRealComposedRouteTable(): void
    {
        $artistId = $this->seedArtist($this->prefix . 'The Beatles');
        $title = $this->prefix . 'Abbey Road';
        $this->seedAlbum($artistId, $title);

        $response = $this->dispatchWire('/api/v1/music/album?name=' . rawurlencode($title));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($title, $body['album']['name'] ?? null);
    }

    /**
     * Album twin (slash class): a slash-bearing album title (real-world:
     * "Ride/Drive") resolves through the query spelling. REDS BY NAME if the
     * route or the decoded hand-off is removed.
     */
    public function testAlbumTitleWithSlashResolvesThroughTheRealComposedRouteTable(): void
    {
        $artistId = $this->seedArtist($this->prefix . 'Metallica');
        $title = $this->prefix . 'Ride/Drive';
        $this->seedAlbum($artistId, $title);

        $response = $this->dispatchWire('/api/v1/music/album?name=' . rawurlencode($title));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($title, $body['album']['name'] ?? null);
    }

    /**
     * The optional `artist` disambiguator keeps its existing semantics on the
     * query twin: two artists share one slash-bearing title, and only the pair
     * request answers with the requested artist's album.
     */
    public function testAlbumNameWithArtistDisambiguatorResolvesTheRequestedPair(): void
    {
        $title = $this->prefix . 'Greatest%2FHits';
        $firstId = $this->seedArtist($this->prefix . 'Arist One');
        $secondName = $this->prefix . 'Arist Two';
        $secondId = $this->seedArtist($secondName);
        $this->seedAlbum($firstId, $title);
        $this->seedAlbum($secondId, $title);

        $response = $this->dispatchWire(
            '/api/v1/music/album?name=' . rawurlencode($title)
            . '&artist=' . rawurlencode($secondName),
        );

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($title, $body['album']['name'] ?? null);
        $this->assertSame($secondName, $body['album']['artist'] ?? null);
    }

    /** Contract: missing/empty `name` answers the legacy 400 byte-for-byte. */
    public function testAlbumWithoutNameParamIsRejectedWithBadRequest(): void
    {
        $response = $this->dispatchWire('/api/v1/music/album?name=');

        $this->assertSame(400, $response->statusCode);
        $this->assertSame(
            ['error' => 'Album name is required'],
            json_decode($response->body, true),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Relay class — the query crosses the tunnel byte-for-byte.           */
    /* ------------------------------------------------------------------ */

    /**
     * Over the hub relay: a real wire envelope (toJson/fromJson round trip) whose
     * QUERY carries `name=…AC%2FDC` is handed to the REAL
     * `RelayConsumer::buildRequest()`, which parse_str-decodes it into
     * `$request->query`, and that request then resolves through the same composed
     * route table. This is the class the hub's path guard makes unreachable as a
     * path segment — as a query parameter it crosses untouched (guard is
     * path-only, pinned in the hub repo, which this diff does not touch).
     */
    public function testRelayedSlashNameEnvelopeResolvesThroughTheComposedTable(): void
    {
        $name = $this->prefix . 'AC/DC';
        $this->seedArtist($name);

        $request = $this->relayBuiltRequest('/api/v1/music/artist', 'name=' . rawurlencode($name));

        $this->assertSame(
            'name=' . rawurlencode($name),
            $request->queryString,
            'the relay must carry the RAW encoded query to the request layer verbatim',
        );
        $this->assertSame($name, $request->queryString('name'));

        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /** Relay half for the space-class artist. */
    public function testRelayedSpaceNameEnvelopeResolvesThroughTheComposedTable(): void
    {
        $name = $this->prefix . 'Abbey Road';
        $this->seedArtist($name);

        $request = $this->relayBuiltRequest('/api/v1/music/artist', 'name=' . rawurlencode($name));
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /** Relay half for the album twin (slash class, with the artist pair). */
    public function testRelayedAlbumEnvelopeResolvesThroughTheComposedTable(): void
    {
        $artistName = $this->prefix . 'Metallica';
        $artistId = $this->seedArtist($artistName);
        $title = $this->prefix . 'Ride/Drive';
        $this->seedAlbum($artistId, $title);

        $request = $this->relayBuiltRequest(
            '/api/v1/music/album',
            'name=' . rawurlencode($title) . '&artist=' . rawurlencode($artistName),
        );
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($title, $body['album']['name'] ?? null);
        $this->assertSame($artistName, $body['album']['artist'] ?? null);
    }

    /* ------------------------------------------------------------------ */
    /* Controls — additive change moved nothing.                           */
    /* ------------------------------------------------------------------ */

    /** Auth posture: the query twins sit in the SAME AuthMiddleware group — an
     * unauthenticated wire request is answered 401 before the handler runs. */
    public function testUnauthenticatedQueryRouteRequestIsRejectedWithUnauthorized(): void
    {
        $response = $this->dispatchWire(
            '/api/v1/music/artist?name=' . rawurlencode($this->prefix . 'Anyone'),
            authenticated: false,
        );

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * No shadow: the plural LIST routes never consume `name` — the list route
     * still answers 200 with its own payload even when `name` is present and
     * would resolve nothing. The singular/plural segments are distinct statics.
     */
    public function testListRouteDoesNotConsumeTheNameParam(): void
    {
        $response = $this->dispatchWire(
            '/api/v1/music/artists?name=' . rawurlencode($this->prefix . 'Definitely Not An Artist'),
        );

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('artists', $body);
        $this->assertIsArray($body['artists']);
    }

    /**
     * Additive guarantee: the legacy `{mbid}` path detail route still resolves
     * (space name, percent-encoded segment) exactly as S435 left it.
     */
    public function testLegacyArtistPathRouteStillResolvesUnaffected(): void
    {
        $name = $this->prefix . 'Abbey Road';
        $this->seedArtist($name);

        $response = $this->dispatchWire('/api/v1/music/artists/' . rawurlencode($name));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /* ------------------------------------------------------------------ */
    /* Scaffolding (mirrors MusicEncodedRouteParamE2eTest).                */
    /* ------------------------------------------------------------------ */

    /**
     * Compose the REAL music route table: `Application::loadMusicRoutes()` with
     * the container bound to the live test Connection, so the registered
     * controllers run the same `findArtistByName()`/`findAlbumByTitle()` SQL as
     * production. Same construction pattern as `MusicEncodedRouteParamE2eTest`.
     */
    private function composedMusicRouter(Connection $db): Router
    {
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id): mixed => $id === Connection::class
                ? $db
                : throw new \RuntimeException('unbound: ' . $id),
        );

        $ref = new ReflectionClass(Application::class);

        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        $router = new Router();

        foreach (
            [
            'container' => $container,
            'connectionPool' => $this->createMock(ConnectionPool::class),
            'config' => [],
            'router' => $router,
            ] as $property => $value
        ) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($app, $value);
        }

        $loader = $ref->getMethod('loadMusicRoutes');
        $loader->setAccessible(true);
        $loader->invoke($app);

        return $router;
    }

    /**
     * Wire dispatch: REAL HTTP bytes through the production transport —
     * `Workerman\Protocols\Http\Request` (parse_str-decodes the query) →
     * `Request::fromWorkerman()` → (entry point stamps userId) → dispatch.
     */
    private function dispatchWire(string $target, bool $authenticated = true): Response
    {
        $request = Request::fromWorkerman(
            new WorkermanHttpRequest("GET $target HTTP/1.1\r\nHost: phlix.test\r\n\r\n"),
        );

        if ($authenticated) {
            $request->userId = 's240-e2e-user';
        }

        return $this->router->dispatch($request);
    }

    /**
     * Build a Request the way the relay tunnel does: a wire envelope round-trips
     * through RelayHttpRequest::toJson()/fromJson() — the `query` field verbatim —
     * then the REAL private `RelayConsumer::buildRequest()` produces the
     * server-side Request (identity from the hub-stamped relay user; the query is
     * parse_str-decoded into `$request->query` exactly once there).
     */
    private function relayBuiltRequest(string $rawPath, string $rawQuery): Request
    {
        $envelope = new RelayHttpRequest(
            'GET',
            $rawPath,
            $rawQuery,
            ['X-Phlix-Relay-User' => 's240-relay-principal'],
            '',
        );

        // Wire fidelity: the envelope crosses the tunnel as JSON, query intact.
        $onTheWire = RelayHttpRequest::fromJson($envelope->toJson());
        $this->assertSame($rawQuery, $onTheWire->query);

        $consumer = new RelayConsumer(
            new RelayConfig(
                enabled: true,
                hubRelayWsUrl: 'ws://relay.invalid:8802',
                localHttpAddress: '127.0.0.1:0',
            ),
            $this->createMock(HubClient::class),
            new StructuredLogger('s240-relay', []),
            's240-e2e-server',
            identityResolver: new RelayIdentityResolver(
                $this->createMock(UserIdentityRepository::class),
            ),
        );

        $build = new ReflectionMethod(RelayConsumer::class, 'buildRequest');
        $build->setAccessible(true);

        /** @var Request $request */
        $request = $build->invoke($consumer, $onTheWire);

        return $request;
    }

    /**
     * Seed one artist under the run-unique prefix; returns its id.
     */
    private function seedArtist(string $name): int
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query('INSERT INTO music_artists (name) VALUES (?)', [$name]);

        $rows = $db->query(
            'SELECT id FROM music_artists WHERE name = ? ORDER BY id DESC LIMIT 1',
            [$name],
        );
        $this->assertIsArray($rows);
        $this->assertArrayHasKey(0, $rows);
        $id = (int) $rows[0]['id'];
        $this->artistIds[] = $id;

        return $id;
    }

    /**
     * Seed one album (year/total_tracks mirror the S435 fixture insert).
     */
    private function seedAlbum(int $artistId, string $title): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            'INSERT INTO music_albums (artist_id, title, year, total_tracks) VALUES (?, ?, ?, ?)',
            [$artistId, $title, 1969, 0],
        );
    }
}
