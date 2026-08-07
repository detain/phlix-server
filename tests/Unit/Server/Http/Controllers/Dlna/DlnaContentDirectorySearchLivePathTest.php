<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Dlna;

use Phlix\Dlna\ContentDirectory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Server\Http\Controllers\Dlna\DlnaContentDirectoryController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * S218: `u:Search` on the LIVE ContentDirectory SOAP path.
 *
 * ## Why this file exists — and why the obvious file is the wrong one
 *
 * Before this test, `grep -F 'u:Search' tests/` hit exactly two places: a
 * docblock, and `tests/Unit/Dlna/DlnaServerTest.php`, which drives
 * `DlnaServer::processSoapRequest()`. That method is **runtime-dead**: a
 * repo-wide search for `processSoapRequest` (src/ + tests/ + vendor/, and the
 * sibling repos under `/home/sites/phlix`) finds its one definition at
 * `DlnaServer.php:320`, one docblock mention in `SoapArgumentExtractor`, and
 * **seven callers — all of them inside `DlnaServerTest.php`.** Nothing in
 * production calls it, and `DlnaServer` performs no dynamic dispatch (no
 * `$this->$method(...)`), so those tests cannot reach the shipped handler. The
 * container does build `DlnaServer`, but only for `getContentDirectory()`,
 * `getScpdXml()` and the device description.
 *
 * The path a real control point actually takes is:
 *
 *   `POST /dlna/content_directory`  (`DlnaRoutes::CONTENT_DIRECTORY_CONTROL`,
 *   registered at `Application.php:3142-3145`)
 *     → `DlnaContentDirectoryController::handle()`
 *       → `DlnaContentDirectoryController::parseSoapBody()`
 *         → `Phlix\Dlna\SoapArgumentExtractor::firstBodyChild()`
 *            + `::directChildArguments()`
 *       → `dispatchAction('Search')` → `handleSearch()`
 *         → `ContentDirectory::search()`
 *
 * `DlnaContentDirectorySoapTest` covers **Browse** through that path well.
 * Search had no coverage on it at all. So the DLNA Search action could have
 * been broken in production while the suite stayed green — the exact
 * "a green test pins dead code" shape.
 *
 * Every test here drives `handle()` with a real namespaced envelope and a real
 * `SOAPACTION` header, so the assertions run against the shipped code path.
 * Mutating `SoapArgumentExtractor` reddens them; mutating `DlnaServer` does not
 * (which is the point).
 */
final class DlnaContentDirectorySearchLivePathTest extends TestCase
{
    /** Container the search runs against. `library-*` resolves bridge-lessly. */
    private const CONTAINER_ID = 'library-movies';

    /** A criteria string `ContentDirectory::parseSearchCriteria()` accepts. */
    private const CRITERIA = 'dc:title contains "Blade"';

    /**
     * A genuine UPnP ContentDirectory **Search** envelope, as a control point
     * sends it: namespaced `u:` action named after the operation, arguments as
     * direct children.
     */
    private function searchEnvelope(
        string $containerId = self::CONTAINER_ID,
        string $criteria = self::CRITERIA,
    ): string {
        return '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . '<s:Body>'
            . '<u:Search xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<ContainerID>' . $containerId . '</ContainerID>'
            . '<SearchCriteria>' . htmlspecialchars($criteria, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</SearchCriteria>'
            . '<Filter>dc:title,upnp:class</Filter>'
            . '<StartingIndex>0</StartingIndex>'
            . '<RequestedCount>25</RequestedCount>'
            . '<SortCriteria>+dc:title</SortCriteria>'
            . '</u:Search>'
            . '</s:Body></s:Envelope>';
    }

    /**
     * The Browse CONTROL. A Search assertion that fires on "the controller
     * returned 200" would also fire for a Browse, so the discriminating claim
     * needs a succeeding sibling that must take the OTHER branch.
     */
    private function browseEnvelope(): string
    {
        return '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . '<s:Body>'
            . '<u:Browse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<ObjectID>' . self::CONTAINER_ID . '</ObjectID>'
            . '<BrowseFlag>BrowseDirectChildren</BrowseFlag>'
            . '<Filter>*</Filter>'
            . '<StartingIndex>0</StartingIndex>'
            . '<RequestedCount>25</RequestedCount>'
            . '<SortCriteria></SortCriteria>'
            . '</u:Browse>'
            . '</s:Body></s:Envelope>';
    }

    /**
     * Drive the PRODUCTION entry point: `handle()` with a raw body and a real
     * `SOAPACTION` header, exactly as the registered route invokes it.
     */
    private function post(
        DlnaContentDirectoryController $controller,
        string $body,
        string $soapAction,
    ): Response {
        $request = new Request();
        $request->method  = 'POST';
        $request->path    = \Phlix\Dlna\DlnaRoutes::CONTENT_DIRECTORY_CONTROL;
        $request->rawBody = $body;
        $request->headers = [
            'Content-Type' => 'text/xml; charset="utf-8"',
            'SOAPACTION'   => '"urn:schemas-upnp-org:service:ContentDirectory:1#' . $soapAction . '"',
        ];

        return $controller->handle($request, []);
    }

    /**
     * ANTI-VACUITY FLOOR for every test below.
     *
     * The whole claim is "a REAL-WORLD NAMESPACED `u:Search` envelope". If the
     * fixture ever degraded to a bare `<Search>` — or lost the UPnP namespace,
     * or stopped being the first child of `<Body>` — the tests would still pass
     * while no longer testing what they say they test. This pins the fixture's
     * shape, so the coverage cannot silently become weaker than its docblock.
     */
    public function test_the_fixture_is_a_real_namespaced_upnp_search_envelope(): void
    {
        $envelope = $this->searchEnvelope();

        self::assertStringContainsString(
            '<u:Search xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">',
            $envelope,
            'The action element must be namespace-prefixed and named after the operation.',
        );
        self::assertStringContainsString('<SearchCriteria>', $envelope);
        self::assertStringNotContainsString('<action>', $envelope);

        // And it must be the FIRST child of <Body> — that is what the live
        // parser keys on.
        self::assertStringContainsString('<s:Body><u:Search', $envelope);
    }

    /**
     * CONSEQUENCE: a real Search envelope reaches `ContentDirectory::search()`
     * with the arguments the envelope carried — not defaults, and not Browse.
     *
     * This is the load-bearing assertion. The collaborator is a mock so the
     * hand-off is observable, but everything upstream of it — `handle()`,
     * `parseSoapBody()`, `SoapArgumentExtractor`, `dispatchAction()`,
     * `handleSearch()` — is the shipped production code.
     *
     * Mutation-verified: making `SoapArgumentExtractor::directChildArguments()`
     * return `[]` reddens this (every argument collapses to its `handleSearch()`
     * default: `'0'`, `'*'`, `'*'`, `0`, `0`, `''`).
     */
    public function test_a_real_search_envelope_reaches_content_directory_search_with_its_arguments(): void
    {
        $cds = $this->createMock(ContentDirectory::class);
        $cds->expects(self::never())->method('browse');
        $cds->expects(self::once())
            ->method('search')
            ->with(
                self::CONTAINER_ID,
                self::CRITERIA,
                'dc:title,upnp:class',
                0,
                25,
                '+dc:title',
            )
            ->willReturn([
                'Result'         => '<DIDL-Lite/>',
                'NumberReturned' => 0,
                'TotalMatches'   => 0,
                'UpdateID'       => 1,
            ]);

        $response = $this->post(
            new DlnaContentDirectoryController($cds),
            $this->searchEnvelope(),
            'Search',
        );

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('SearchResponse', $response->body);
    }

    /**
     * THE CONTROL beside the assertion above.
     *
     * Same controller class, same driver, same headers shape — a Browse
     * envelope must take the OTHER arm of `dispatchAction()`. Without this, a
     * `dispatchAction()` that answered every action with `handleSearch()` would
     * pass the Search test.
     */
    public function test_a_browse_envelope_on_the_same_path_still_routes_to_browse(): void
    {
        $cds = $this->createMock(ContentDirectory::class);
        $cds->expects(self::never())->method('search');
        $cds->expects(self::once())
            ->method('browse')
            ->with(self::CONTAINER_ID, 'BrowseDirectChildren', '*', 0, 25, '')
            ->willReturn([
                'Result'         => '<DIDL-Lite/>',
                'NumberReturned' => 0,
                'TotalMatches'   => 0,
                'UpdateID'       => 1,
            ]);

        $response = $this->post(
            new DlnaContentDirectoryController($cds),
            $this->browseEnvelope(),
            'Browse',
        );

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('BrowseResponse', $response->body);
        self::assertStringNotContainsString('SearchResponse', $response->body);
    }

    /**
     * CONSEQUENCE: end-to-end, with a REAL `ContentDirectory`, a Search
     * envelope produces a `SearchResponse` whose DIDL contains only the
     * matching item.
     *
     * The mocked test above proves the arguments arrive; this one proves they
     * are *acted on*, with no test double between the parser and the search
     * implementation. Two candidate rows, one criteria — a Search that silently
     * degraded into a Browse would return BOTH.
     */
    public function test_search_filters_the_container_end_to_end_through_a_real_content_directory(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findByParent')->willReturn([
            ['id' => 'm1', 'parent_id' => self::CONTAINER_ID, 'name' => 'Blade Runner 2049', 'type' => 'movie'],
            ['id' => 'm2', 'parent_id' => self::CONTAINER_ID, 'name' => 'Casablanca', 'type' => 'movie'],
        ]);

        $response = $this->post(
            new DlnaContentDirectoryController(new ContentDirectory($items)),
            $this->searchEnvelope(),
            'Search',
        );

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertStringContainsString('<SearchResponse>', $response->body);
        self::assertStringContainsString('<NumberReturned>1</NumberReturned>', $response->body);
        self::assertStringContainsString('<TotalMatches>1</TotalMatches>', $response->body);
        self::assertStringContainsString('Blade Runner 2049', $response->body);
        self::assertStringNotContainsString('Casablanca', $response->body);
    }

    /**
     * SECURITY, Search-shaped: embedded metadata must not bleed into a
     * top-level Search argument.
     *
     * `DlnaContentDirectorySoapTest` pins this for Browse's `<ObjectID>`. The
     * same hardening has to hold for the action the live path had no test for,
     * and the shape is deliberately different: here the nested element is a
     * `<SearchCriteria>` buried inside `<Filter>`, with NO top-level
     * `<SearchCriteria>` at all — so a descendant walk would hand the search
     * implementation an attacker-chosen criteria string.
     *
     * Mutation-verified: replacing `directChildArguments()`'s direct-child walk
     * with an any-descendant one reddens this.
     */
    public function test_a_nested_search_criteria_does_not_bleed_into_the_live_search_arguments(): void
    {
        $envelope = '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<s:Body>'
            . '<u:Search xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<ContainerID>' . self::CONTAINER_ID . '</ContainerID>'
            . '<Filter><SearchCriteria>dc:title contains "injected"</SearchCriteria></Filter>'
            . '<StartingIndex>0</StartingIndex>'
            . '<RequestedCount>25</RequestedCount>'
            . '</u:Search>'
            . '</s:Body></s:Envelope>';

        $cds = $this->createMock(ContentDirectory::class);
        $cds->expects(self::once())
            ->method('search')
            ->with(
                self::CONTAINER_ID,
                // The handler default, because there is no DIRECT-child
                // <SearchCriteria> — never the injected one.
                '*',
                self::anything(),
                0,
                25,
                self::anything(),
            )
            ->willReturn([
                'Result'         => '<DIDL-Lite/>',
                'NumberReturned' => 0,
                'TotalMatches'   => 0,
                'UpdateID'       => 1,
            ]);

        $response = $this->post(new DlnaContentDirectoryController($cds), $envelope, 'Search');

        self::assertSame(200, $response->statusCode);
        self::assertStringNotContainsString('injected', $response->body);
    }

    /**
     * CONSEQUENCE: a UPnP error from the search implementation becomes a SOAP
     * fault, not a 200 with an empty result.
     *
     * `ContentDirectory::search()` answers unsupported criteria with UPnP error
     * 800. The live path must surface that; returning 200 would tell the
     * control point the search succeeded and matched nothing.
     */
    public function test_unsupported_search_criteria_produce_a_upnp_fault_on_the_live_path(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findByParent')->willReturn([]);

        $response = $this->post(
            new DlnaContentDirectoryController(new ContentDirectory($items)),
            $this->searchEnvelope(self::CONTAINER_ID, 'this is not a upnp search expression'),
            'Search',
        );

        self::assertSame(500, $response->statusCode);
        self::assertStringContainsString('<errorCode>800</errorCode>', $response->body);
        self::assertStringNotContainsString('<SearchResponse>', $response->body);
    }
}
