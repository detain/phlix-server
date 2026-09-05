<?php

/**
 * S435 — encoded artist/album names resolve end-to-end through the REAL
 * composed route table (`Application::loadMusicRoutes()`), direct and over the
 * hub relay, against real MySQL.
 *
 * One test reddens PER CLASS by name if the routing-boundary decode is removed:
 * the getArtist-side tests (space/unicode/slash) for the artist handler, and
 * the getAlbum-side test for the album handler — the two handlers that passed
 * the raw segment verbatim into `MusicLibraryService::findArtistByName()`'s
 * `WHERE a.name = ?`. The `%2520` fixture test reddens on DOUBLE-decoding.
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
use Phlix\Server\Http\Router;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * Encoded-title music detail over the production composition, not call-site arguments.
 */
final class MusicEncodedRouteParamE2eTest extends TestCase
{
    use RequiresRealDatabase;

    /** S435 lane survival marker — code-resident proof this class is live. */
    public const SURVIVAL_TOKEN = 'S435ROUTEPARAMDECODEX5R8';

    private ?Connection $db = null;

    /** The router production actually dispatches music detail through. */
    private Router $router;

    /** Fixture namespace for `music_artists.name` / `music_albums.title` (both UNIQUE). */
    private string $prefix = '';

    /** @var list<int> seeded music_artists ids */
    private array $artistIds = [];

    /** @var array<string, mixed> superglobals mutated by fromGlobals() tests */
    private array $savedServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S435 music encoded-name e2e. Runs in CI.');
        $this->assertNotNull($this->db);

        $this->prefix = '!S435-' . substr(Uuid::v4(), 0, 8) . '-';
        $this->savedServer = $_SERVER;

        $this->router = $this->composedMusicRouter($this->db);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;

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

    /**
     * The headline AC: `GET /api/v1/music/artists/!S435-x-Abbey%20Road` resolves
     * the artist named `…Abbey Road`. Built with Request::fromGlobals() exactly
     * as the production entry points do — pinning that parse_url hands the RAW
     * segment to routing and the boundary is what decodes it.
     * REDS BY NAME (artist class) if the boundary decode is removed.
     */
    public function testArtistWithSpaceNameResolvesThroughTheRealComposedRouteTable(): void
    {
        $name = $this->prefix . 'Abbey Road';
        $this->seedArtist($name);

        $response = $this->dispatchFromGlobals('GET', '/api/v1/music/artists/' . rawurlencode($name));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /**
     * Unicode (percent-encoded UTF-8) artist names resolve e2e.
     * REDS BY NAME (artist class) if the boundary decode is removed.
     */
    public function testArtistWithUnicodeNameResolvesThroughTheRealComposedRouteTable(): void
    {
        $name = $this->prefix . 'Édith Piaf';
        $this->seedArtist($name);

        $response = $this->dispatchFromGlobals('GET', '/api/v1/music/artists/' . rawurlencode($name));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /**
     * Slash-name artists — the `%2F` inside ONE segment matches `[^/]+` raw and
     * decodes to the real name only after routing has matched (so encoded
     * slashes cannot forge routes or split segments).
     * REDS BY NAME (artist class) if the boundary decode is removed.
     */
    public function testArtistWithSlashNameResolvesThroughTheRealComposedRouteTable(): void
    {
        $name = $this->prefix . 'AC/DC';
        $this->seedArtist($name);

        $response = $this->dispatchFromGlobals(
            'GET',
            '/api/v1/music/artists/' . str_replace('/', '%2F', rawurlencode($name)),
        );

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /**
     * The album twin (`getAlbum()`/`findAlbumByTitle()`) — its own per-class RED
     * witness. REDS BY NAME (album class) if the boundary decode is removed.
     */
    public function testAlbumWithSpaceTitleResolvesThroughTheRealComposedRouteTable(): void
    {
        $artistId = $this->seedArtist($this->prefix . 'The Beatles');
        $title = $this->prefix . 'Abbey Road';
        $db = $this->db;
        $this->assertNotNull($db);
        $db->query(
            'INSERT INTO music_albums (artist_id, title, year, total_tracks) VALUES (?, ?, ?, ?)',
            [$artistId, $title, 1969, 0],
        );

        $response = $this->dispatchFromGlobals('GET', '/api/v1/music/albums/' . rawurlencode($title));

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($title, $body['album']['name'] ?? null);
    }

    /**
     * `%2520` planted-drift fixture (real-DB half): with BOTH artists seeded —
     * `…Abbey Road` and the literal `…Abbey%20Road` — the double-encoded request
     * must resolve ONLY the literal one. Any second decode (boundary AND handler)
     * collapses `%2520`→`%20`→space and answers with the wrong row.
     */
    public function testDoubleEncodedRequestResolvesOnlyTheLiteralPercentNameArtist(): void
    {
        $this->seedArtist($this->prefix . 'Abbey Road');
        $literal = $this->prefix . 'Abbey%20Road';
        $this->seedArtist($literal);

        $response = $this->dispatchFromGlobals(
            'GET',
            '/api/v1/music/artists/' . $this->prefix . 'Abbey%2520Road',
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
     * Over the hub relay: a real wire envelope (toJson/fromJson round trip) is
     * handed to the REAL `RelayConsumer::buildRequest()`, which copies
     * `$envelope->path` verbatim (pre-decode — pinned here), and that request
     * then resolves through the same composed route table.
     * REDS BY NAME if the boundary decode is removed.
     */
    public function testRelayedArtistEnvelopeResolvesDecodedName(): void
    {
        $name = $this->prefix . 'Abbey Road';
        $this->seedArtist($name);

        $request = $this->relayBuiltRequest('GET', '/api/v1/music/artists/' . rawurlencode($name));

        $this->assertSame(
            '/api/v1/music/artists/' . rawurlencode($name),
            $request->path,
            'the relay must carry the RAW encoded path to the router — decoding happens once, at the boundary',
        );

        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /**
     * Relay half for the slash-name artist: the envelope path `…AC%2FDC` survives
     * the real DTO safety pass (assertSafe decodes once and re-checks — no
     * traversal here) and resolves through the composed table.
     */
    public function testRelayedSlashNameEnvelopeResolvesDecodedName(): void
    {
        $name = $this->prefix . 'AC/DC';
        $this->seedArtist($name);

        $request = $this->relayBuiltRequest(
            'GET',
            '/api/v1/music/artists/' . str_replace('/', '%2F', rawurlencode($name)),
        );
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($name, $body['artist']['name'] ?? null);
    }

    /**
     * Relay half for the album handler (getAlbum class over the tunnel).
     */
    public function testRelayedAlbumEnvelopeResolvesDecodedTitle(): void
    {
        $artistId = $this->seedArtist($this->prefix . 'The Beatles');
        $title = $this->prefix . 'Abbey Road';
        $db = $this->db;
        $this->assertNotNull($db);
        $db->query(
            'INSERT INTO music_albums (artist_id, title, year, total_tracks) VALUES (?, ?, ?, ?)',
            [$artistId, $title, 1969, 0],
        );

        $request = $this->relayBuiltRequest('GET', '/api/v1/music/albums/' . rawurlencode($title));
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame($title, $body['album']['name'] ?? null);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Compose the REAL music route table: `Application::loadMusicRoutes()` with
     * the container bound to the live test Connection, so the registered
     * controllers run the same `findArtistByName()`/`findAlbumByTitle()` SQL as
     * production. Same construction pattern as `MusicTracksRouteReachabilityTest`.
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
     * Direct-path dispatch: exactly the production entry sequence —
     * REQUEST_URI → Request::fromGlobals() → (entry point stamps userId) →
     * Router::dispatch().
     */
    private function dispatchFromGlobals(string $method, string $uri): \Phlix\Server\Http\Response
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;

        $request = Request::fromGlobals();
        $request->userId = 's435-e2e-user';

        return $this->router->dispatch($request);
    }

    /**
     * Build a Request the way the relay tunnel does: a wire envelope round-trips
     * through RelayHttpRequest::toJson()/fromJson(), then the REAL private
     * RelayConsumer::buildRequest() produces the server-side Request (identity
     * from the hub-stamped relay user; path copied verbatim, NOT pre-decoded).
     */
    private function relayBuiltRequest(string $method, string $rawPath): Request
    {
        $envelope = new RelayHttpRequest(
            $method,
            $rawPath,
            '',
            ['X-Phlix-Relay-User' => 's435-relay-principal'],
            '',
        );

        // Wire fidelity: the envelope crosses the tunnel as JSON, path intact.
        $onTheWire = RelayHttpRequest::fromJson($envelope->toJson());
        $this->assertSame($rawPath, $onTheWire->path);

        $consumer = new RelayConsumer(
            new RelayConfig(
                enabled: true,
                hubRelayWsUrl: 'ws://relay.invalid:8802',
                localHttpAddress: '127.0.0.1:0',
            ),
            $this->createMock(HubClient::class),
            new StructuredLogger('s435-relay', []),
            's435-e2e-server',
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
}
