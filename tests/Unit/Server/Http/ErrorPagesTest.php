<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Server\Http\ErrorPages;
use PHPUnit\Framework\TestCase;

/**
 * M-SERVER-87 (server minors batch W-min103): the server-rendered fallback
 * pages are complete, styled documents — not bare `<h1>` fragments, and not
 * operator command lines on a visitor's screen.
 *
 * The laws pinned here mirror what the review asked for, element by element:
 * doctype + `lang` + viewport, a home affordance, visitor-first copy on the
 * missing-bundle 503 (the `npm` recipe lives below it, inside a collapsed
 * `<details>`), and a regression guard that the bare-fragment bodies never
 * creep back into the call-site files.
 *
 * The S113 head-only contract for these exact bodies is pinned (on encoded
 * bytes) by {@see \Phlix\Tests\Unit\Server\Workerman\HttpHandlerHeadNoBodyTest}.
 */
final class ErrorPagesTest extends TestCase
{
    public function testThe404IsACompleteDocument(): void
    {
        self::assertDocumentLaws(ErrorPages::notFoundHtml());
        self::assertStringContainsString('<h1>Page not found</h1>', ErrorPages::notFoundHtml());
        self::assertStringContainsString('href="/app"', ErrorPages::notFoundHtml());
    }

    public function testTheLastResort500IsACompleteDocumentWithoutExceptionText(): void
    {
        self::assertDocumentLaws(ErrorPages::serverErrorHtml());
        self::assertStringContainsString('<h1>Something went wrong</h1>', ErrorPages::serverErrorHtml());
        self::assertStringContainsString('href="/app"', ErrorPages::serverErrorHtml());
        // The pre-existing law, restated at the copy level: detail goes to the
        // log; the wire stays generic — no echo surface, no status-phrase body.
        self::assertStringNotContainsString('Internal Server Error', ErrorPages::serverErrorHtml());
        self::assertSame(
            1,
            substr_count(ErrorPages::serverErrorHtml(), '<h1>'),
            'one heading, static copy — not a templated error dump'
        );
    }

    public function testTheMissingBundle503LeadsWithVisitorCopyAndFoldsTheRecipe(): void
    {
        $html = ErrorPages::uiNotBuilt()->body;

        self::assertDocumentLaws($html);
        $visitorAt = strpos($html, 'web portal is not ready yet');
        $recipeAt = strpos($html, 'npm ci');
        self::assertIsInt($visitorAt, 'the visitor-facing headline must exist');
        self::assertIsInt($recipeAt, 'the operator recipe must still be present');
        self::assertLessThan($recipeAt, $visitorAt, 'a visitor must meet copy before any command line');
        self::assertStringContainsString('<details>', $html);
        self::assertStringContainsString('Server administrator', $html);
    }

    public function testTheUnreadableBundle503IsADocumentWithARetryAffordance(): void
    {
        $html = ErrorPages::uiUnreadable()->body;

        self::assertDocumentLaws($html);
        self::assertStringContainsString('Try again', $html);
    }

    public function testStatusCodesAreUnchangedFromTheFragmentEra(): void
    {
        self::assertSame(404, ErrorPages::notFound()->statusCode);
        self::assertSame(503, ErrorPages::uiNotBuilt()->statusCode);
        self::assertSame(503, ErrorPages::uiUnreadable()->statusCode);
        self::assertSame('text/html; charset=utf-8', ErrorPages::notFound()->headers['Content-Type']);
    }

    /**
     * Regression guard for the defect itself: the bare-fragment bodies must
     * not reappear in the files that used to build them inline, and every
     * named call site must go through the SSOT.
     */
    public function testTheCallSitesCarryNoBareFragmentsAndAllRideTheSsot(): void
    {
        $handlerSrc = file_get_contents(dirname(__DIR__, 4) . '/src/Server/Workerman/HttpHandler.php');
        $shellSrc = file_get_contents(
            dirname(__DIR__, 4) . '/src/Server/WebPortal/Controllers/SharedUiController.php'
        );
        self::assertIsString($handlerSrc);
        self::assertIsString($shellSrc);

        foreach (
            [
                '<h1>404 - Page not found</h1>',
                '<h1>500 Internal Server Error</h1>',
            ] as $fragment
        ) {
            self::assertStringNotContainsString($fragment, $handlerSrc);
        }
        self::assertStringNotContainsString('Shared UI not built', $shellSrc);
        self::assertStringNotContainsString('npm run build', $shellSrc);

        self::assertSame(5, substr_count($handlerSrc, 'ErrorPages::notFound()'));
        self::assertSame(1, substr_count($handlerSrc, 'ErrorPages::serverErrorHtml()'));
        self::assertSame(2, substr_count($shellSrc, 'ErrorPages::uiNotBuilt()'));
        self::assertSame(1, substr_count($shellSrc, 'ErrorPages::uiUnreadable()'));
    }

    /**
     * The chrome every fallback page must carry (the ask's checklist,
     * element by element).
     */
    private static function assertDocumentLaws(string $html): void
    {
        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<meta charset="utf-8">', $html);
        self::assertStringContainsString(
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            $html
        );
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringContainsString('prefers-color-scheme: dark', $html);
        self::assertStringEndsWith("</body>\n</html>\n", $html);
    }
}
