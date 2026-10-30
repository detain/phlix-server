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
 * `?` (reachable through the urldecoded music-name segments).
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

    public function testSeparatorSwitchesToAmpersandWhenTargetAlreadyCarriesQuery(): void
    {
        // /music/albums/{name} urldecodes the segment, so a %3F in the name
        // can put a literal '?' into the target — the carry must not append a
        // second '?' (which would truncate the real query at the first '?').
        $this->assertRedirectTo(
            '/app/music/album/weird?name&x=2',
            '/music/albums/weird%3Fname',
            ['x' => '2']
        );
    }
}
