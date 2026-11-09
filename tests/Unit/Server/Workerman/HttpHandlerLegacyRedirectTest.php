<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * M-SERVER-40 (review server__webux MAJOR-1): the browser auth flow 302s
 * failures to legacy aliases with the message in the query
 * (`/login?error=…`, `/login?notice=…`, `/auth/register?error=…`).
 * {@see HttpHandler::dispatch()} then maps the legacy path onto its `/app`
 * SPA route — and used to answer with a literal target, silently destroying
 * the message: wrong passwords, disabled signups and the pending-approval
 * notice all rendered as "nothing happened".
 *
 * Pins the law now enforced by `HttpHandler::legacyRedirect()`: every legacy
 * page redirect carries the incoming query through (re-encoded via
 * `http_build_query()`, the pre-existing photo idiom), stays query-less when
 * none was sent, and switches to `&` only when the target already carries a
 * `?` (a defensive guard since SH-012 — no shipped caller embeds a '?' any
 * more, but the M-SERVER-40 carry law pins it directly below).
 */
final class HttpHandlerLegacyRedirectTest extends TestCase
{
    private function makeHandler(): HttpHandler
    {
        return new HttpHandler(
            $this->createMock(ContainerInterface::class),
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            sys_get_temp_dir(),
            $this->createMock(Application::class),
            null,
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function dispatchPath(string $path, array $query = []): Response
    {
        $request = new Request();
        $request->path = $path;
        $request->query = $query;

        $m = new \ReflectionMethod(HttpHandler::class, 'dispatch');
        $m->setAccessible(true);
        $result = $m->invoke($this->makeHandler(), $request);
        self::assertInstanceOf(Response::class, $result);

        return $result;
    }

    private function assertRedirectTo(string $expectedLocation, string $path, array $query = []): void
    {
        $response = $this->dispatchPath($path, $query);

        self::assertSame(302, $response->statusCode);
        self::assertSame($expectedLocation, $response->headers['Location']);
    }

    public function testLoginErrorQuerySurvivesTheLegacyRedirect(): void
    {
        // The exact M1 chain: AuthController failure hop lands on
        // /login?error=… — the SPA target must arrive with the message intact.
        $this->assertRedirectTo(
            '/app/login?error=Invalid+credentials',
            '/login',
            ['error' => 'Invalid credentials']
        );
    }

    public function testAuthRegisterErrorQuerySurvivesTheLegacyRedirect(): void
    {
        $this->assertRedirectTo(
            '/app/signup?error=Signups+are+disabled',
            '/auth/register',
            ['error' => 'Signups are disabled']
        );
    }

    public function testRegisterAliasSharesTheSignupCarry(): void
    {
        $this->assertRedirectTo(
            '/app/signup?error=Username+already+taken',
            '/register',
            ['error' => 'Username already taken']
        );
    }

    public function testPendingApprovalNoticeSurvivesTheLegacyRedirect(): void
    {
        $this->assertRedirectTo(
            '/app/login?notice=Your+account+is+awaiting+administrator+approval.',
            '/login',
            ['notice' => 'Your account is awaiting administrator approval.']
        );
    }

    public function testBareLegacyPathKeepsALiteralQueryLessTarget(): void
    {
        $this->assertRedirectTo('/app/login', '/login');
        $this->assertRedirectTo('/app/signup', '/auth/register');
        $this->assertRedirectTo('/app', '/library');
    }

    public function testSearchQuerySurvivesTheLegacyRedirect(): void
    {
        // Same defect class the review did not enumerate: SearchPage.vue reads
        // route.query.q, so legacy /search?q= bookmarks died identically.
        $this->assertRedirectTo('/app/search?q=the+matrix', '/search', ['q' => 'the matrix']);
    }

    public function testLibraryFilterQuerySurvivesTheLegacyRedirect(): void
    {
        // http_build_query() re-encodes repeated keys into indexed form —
        // exactly what the pre-existing photo idiom produced; the SPA-side
        // parser reads genre[0]/genre[1].
        $this->assertRedirectTo(
            '/app?genre%5B0%5D=action&genre%5B1%5D=comedy',
            '/library',
            ['genre' => ['action', 'comedy']]
        );
    }

    public function testPhotoCarryMatchesTheFormerInlineIdiom(): void
    {
        // dispatchPhoto already carried its query via
        // '?' . http_build_query($request->query); the helper must reproduce
        // those bytes exactly.
        $this->assertRedirectTo(
            '/app/photo/albums?library_id=7&limit=2',
            '/photo/albums',
            ['library_id' => '7', 'limit' => '2']
        );
        $this->assertRedirectTo(
            '/app/photo/slideshow?interval=5',
            '/photo/slideshow',
            ['interval' => '5']
        );
    }

    public function testMusicNameSegmentStaysPercentEncodedOnTheWire(): void
    {
        // SH-012 (review server__fedev #2): the wire path is ALREADY
        // percent-encoded, so the old urldecode() here was a double-decode —
        // a decoded CR/LF made Workerman's Response::encode silently drop the
        // whole header (302 with no Location = dead redirect), and decoded
        // '+', '&' or '?' corrupted or truncated the target. The segment now
        // rides through verbatim; the SPA's route decoder performs the one
        // legitimate decode.
        $this->assertRedirectTo(
            '/app/music/album/weird%3Fname?x=2',
            '/music/albums/weird%3Fname',
            ['x' => '2']
        );
        $this->assertRedirectTo(
            '/app/music/album/A%0D%0ABcc:%20evil@x',
            '/music/albums/A%0D%0ABcc:%20evil@x'
        );
        $this->assertRedirectTo(
            '/app/music/artist/A%0D%0ABcc:%20evil@x',
            '/music/artists/A%0D%0ABcc:%20evil@x'
        );
    }

    public function testTheQueryCarrySeparatorLawStillSwitchesToAmpersand(): void
    {
        // The '&' switch survives as a guard on legacyRedirect itself: the
        // music segments no longer reach it with an embedded '?' (SH-012),
        // but the helper's contract (append with the right separator, never a
        // second '?') is the M-SERVER-40 law and stays pinned directly.
        $handler = $this->makeHandler();
        $request = new Request();
        $request->query = ['x' => '2'];

        $m = new \ReflectionMethod(HttpHandler::class, 'legacyRedirect');
        $m->setAccessible(true);

        /** @var Response $withQuery */
        $withQuery = $m->invoke($handler, $request, '/app/target?already=1');
        self::assertSame('/app/target?already=1&x=2', $withQuery->headers['Location']);

        /** @var Response $bare */
        $bare = $m->invoke($handler, $request, '/app/target');
        self::assertSame('/app/target?x=2', $bare->headers['Location']);
    }
}
