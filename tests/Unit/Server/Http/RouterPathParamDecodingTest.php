<?php

/**
 * S435 — route parameters must be percent-decoded ONCE at the routing boundary.
 *
 * Before this step the Router extracted the RAW encoded path segment and handed
 * it to handlers verbatim, so `GET /api/v1/music/albums/Abbey%20Road` looked up
 * an album literally named `Abbey%20Road` — dead for every artist/album whose
 * name contains a space, accent or unicode character.
 *
 * RED-ON-DRIFT CONTRACT (S435 planted-drift proofs):
 *  - deleting `$params = $this->decodePathParams($params);` from
 *    `Router::dispatch()` reddens the space/unicode/plus/slash tests here BY
 *    NAME (and the companion e2e class per handler);
 *  - decoding twice (boundary AND handler) reddens
 *    testPercentDoubleEncodingDecodesExactlyOnceThe2520Fixture.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Boundary-level pins for {@see Router} path-parameter percent-decoding.
 */
final class RouterPathParamDecodingTest extends TestCase
{
    /** S435 lane survival marker — code-resident proof this class is live. */
    public const SURVIVAL_TOKEN = 'S435ROUTEPARAMDECODEX5R8';

    /**
     * The lane's survival token lives in code, never in markdown (P-1 law).
     */
    public function testSurvivalTokenIsCodeResident(): void
    {
        $this->assertSame('S435ROUTEPARAMDECODEX5R8', self::SURVIVAL_TOKEN);
    }

    /**
     * The headline defect: `%20` in a path segment must reach the handler as a
     * space. REDS BY NAME if the boundary decode is removed.
     */
    public function testSpaceEncodedArtistParamIsDecodedAtTheBoundary(): void
    {
        $captured = $this->dispatchArtistSegment('Abbey%20Road');

        $this->assertSame(
            'Abbey Road',
            $captured['mbid'],
            'a %20-encoded path segment must be handed to the handler decoded',
        );
    }

    /**
     * Unicode names ride percent-encoded UTF-8 bytes. REDS BY NAME if the
     * boundary decode is removed.
     */
    public function testUnicodeEncodedArtistParamIsDecodedAtTheBoundary(): void
    {
        $captured = $this->dispatchArtistSegment('%C3%89dith%20Piaf');

        $this->assertSame(
            'Édith Piaf',
            $captured['mbid'],
            'percent-encoded UTF-8 (Édith Piaf) must decode to the real name',
        );
    }

    /**
     * %-POLICY (documented in Router::decodePathParams): a `+` in a PATH segment
     * is a LITERAL plus (RFC 3986 sub-delim); `+`-means-space belongs to
     * application/x-www-form-urlencoded QUERY strings only. So the boundary
     * decodes with rawurldecode(), and a name carrying a real plus —
     * `Black+Sabbath` — must survive verbatim. urldecode() would corrupt it.
     */
    public function testPlusSignInPathSegmentStaysALiteralPlus(): void
    {
        $captured = $this->dispatchArtistSegment('Black+Sabbath');
        $this->assertSame('Black+Sabbath', $captured['mbid']);

        // And a percent-encoded plus decodes to a literal plus — same name.
        $encoded = $this->dispatchArtistSegment('Black%2BSabbath');
        $this->assertSame('Black+Sabbath', $encoded['mbid']);
    }

    /**
     * The `%2520` fixture (planted-drift proof for DOUBLE-decoding): an artist
     * whose real name contains the three characters `%20` arrives on the wire
     * as `%2520`. ONE boundary decode yields the literal `Abbey%20Road`; a
     * second decode anywhere downstream would collapse it to `Abbey Road` and
     * resolve the WRONG row. REDS if the fix ever decodes twice.
     */
    public function testPercentDoubleEncodingDecodesExactlyOnceThe2520Fixture(): void
    {
        $captured = $this->dispatchArtistSegment('Abbey%2520Road');

        $this->assertSame(
            'Abbey%20Road',
            $captured['mbid'],
            'decoding must happen EXACTLY once — %2520 yields a literal %20, never a space',
        );
    }

    /**
     * Malformed percent sequences never throw at the boundary: rawurldecode
     * leaves `%ZZ` verbatim, so the handler sees the faithful raw reading and
     * answers 404 for a name that could not have been registered this way.
     */
    public function testMalformedPercentSequencesPassThroughWithoutThrowing(): void
    {
        $garbage = $this->dispatchArtistSegment('%ZZ%YY');
        $this->assertSame('%ZZ%YY', $garbage['mbid']);

        $trailing = $this->dispatchArtistSegment('Tupac%');
        $this->assertSame('Tupac%', $trailing['mbid']);

        $lonePercent = $this->dispatchArtistSegment('%');
        $this->assertSame('%', $lonePercent['mbid']);
    }

    /**
     * Routing must keep matching the RAW path: `%2F` is not a segment
     * separator until AFTER the match, so an encoded slash stays inside the
     * one `{mbid}` segment and decodes to the real slash-name (AC/DC). Without
     * the ordering guarantee, `%2F` before matching would forge routes and
     * defeat upstream traversal guards.
     */
    public function testEncodedSlashDecodesToASlashNameAfterSingleSegmentMatching(): void
    {
        $captured = $this->dispatchArtistSegment('AC%2FDC');

        $this->assertSame(
            'AC/DC',
            $captured['mbid'],
            'a %2F-encoded slash must arrive as ONE decoded segment value',
        );
    }

    /**
     * …and a LITERAL slash still cannot split the parameter — the compiled
     * `[^/]+` class is untouched by the fix, so `%2F` decoding cannot smuggle
     * an extra segment through.
     */
    public function testLiteralSlashStillDoesNotMatchTheSingleSegmentPlaceholder(): void
    {
        $router = new Router();
        $reached = false;
        $router->get('/api/v1/music/artists/{mbid}', function () use (&$reached): Response {
            $reached = true;
            return (new Response())->json(['ok' => true]);
        });

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/music/artists/AC/DC';

        $response = $router->dispatch($request);

        $this->assertFalse($reached, 'a literal / must never cross a [^/]+ boundary');
        $this->assertSame(404, $response->statusCode);
    }

    /**
     * The HEAD→GET fallback (Router::dispatchAsHead) is the second extraction
     * site; it must publish the same decoded params as dispatch() or HEAD would
     * probe a different library row than GET resolves. REDS if the decode is
     * missing from the HEAD arm.
     */
    public function testHeadFallbackDecodesPathParamsLikeGet(): void
    {
        $router = new Router();
        /** @var array<string, string>|null $captured */
        $captured = null;
        $router->get('/api/v1/music/artists/{mbid}', function (Request $req, array $params) use (&$captured): Response {
            $captured = $params;
            return (new Response())->json(['ok' => true]);
        });

        $request = new Request();
        $request->method = 'HEAD';
        $request->path = '/api/v1/music/artists/Abbey%20Road';

        $router->dispatch($request);

        $this->assertIsArray($captured, 'HEAD must reach the GET handler');
        $this->assertSame(
            'Abbey Road',
            $captured['mbid'] ?? null,
            'the HEAD arm must decode at the same boundary as dispatch()',
        );
    }

    /**
     * `Request::$pathParams` (the published map, read via pathParam()) must
     * carry the decoded values too — it is written from the same extraction.
     */
    public function testPublishedPathParamsAreDecoded(): void
    {
        $router = new Router();
        $requestSeen = null;
        $router->get('/api/v1/music/albums/{mbid}', function (Request $req) use (&$requestSeen): Response {
            $requestSeen = $req;
            return (new Response())->json(['ok' => true]);
        });

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/music/albums/Abbey%20Road';
        $router->dispatch($request);

        $this->assertInstanceOf(Request::class, $requestSeen);
        $this->assertNotNull($requestSeen);
        $this->assertSame(['mbid' => 'Abbey Road'], $requestSeen->pathParams);
        $this->assertSame('Abbey Road', $requestSeen->pathParam('mbid'));
    }

    /**
     * Dispatch one raw (still-encoded) segment through the real music route
     * pattern and hand back what the controller would receive.
     *
     * @return array<string, string> The params passed to the handler
     */
    private function dispatchArtistSegment(string $rawSegment): array
    {
        $router = new Router();
        /** @var array<string, string>|null $captured */
        $captured = null;
        $router->get('/api/v1/music/artists/{mbid}', function (Request $req, array $params) use (&$captured): Response {
            $captured = $params;
            return (new Response())->json(['ok' => true]);
        });

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/music/artists/' . $rawSegment;

        $router->dispatch($request);

        $this->assertIsArray($captured, 'the route must have matched and reached the handler');
        return $captured;
    }
}
