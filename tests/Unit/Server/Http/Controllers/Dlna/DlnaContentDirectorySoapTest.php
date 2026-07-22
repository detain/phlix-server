<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Dlna;

use Phlix\Dlna\ContentDirectory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Server\Http\Controllers\Dlna\DlnaContentDirectoryController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The ContentDirectory SOAP endpoint must accept what real control points send.
 *
 * ## The bug this pins
 *
 * `parseSoapBody()` looked for an element whose local name was literally
 * `action`. In SOAP the action element is named after the OPERATION —
 * `<u:Browse>`, `<u:Search>` — so no control point on earth sends `<action>`.
 * `$action` therefore stayed null and every well-formed UPnP Browse was
 * rejected with `Invalid SOAP body` and HTTP 400.
 *
 * Confirmed against the live server: the very first real Browse issued after
 * the CDS was wired up came back 400. The wiring was necessary but not
 * sufficient, which is why this is asserted with a REAL envelope rather than a
 * convenient one.
 */
final class DlnaContentDirectorySoapTest extends TestCase
{
    /**
     * A genuine UPnP ContentDirectory Browse envelope, as a TV sends it —
     * namespaced, with the action named after the operation.
     */
    private function browseEnvelope(string $objectId = '0'): string
    {
        return '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . '<s:Body>'
            . '<u:Browse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<ObjectID>' . $objectId . '</ObjectID>'
            . '<BrowseFlag>BrowseDirectChildren</BrowseFlag>'
            . '<Filter>*</Filter>'
            . '<StartingIndex>0</StartingIndex>'
            . '<RequestedCount>10</RequestedCount>'
            . '<SortCriteria></SortCriteria>'
            . '</u:Browse>'
            . '</s:Body></s:Envelope>';
    }

    private function controller(): DlnaContentDirectoryController
    {
        $items = $this->createMock(ItemRepository::class);

        return new DlnaContentDirectoryController(new ContentDirectory($items));
    }

    private function post(string $body, string $soapAction = 'Browse'): \Phlix\Server\Http\Response
    {
        $request = new Request();
        $request->rawBody = $body;
        $request->headers = [
            'SOAPACTION' => '"urn:schemas-upnp-org:service:ContentDirectory:1#' . $soapAction . '"',
        ];

        return $this->controller()->handle($request, []);
    }

    /**
     * CONSEQUENCE: a real Browse envelope is ACCEPTED.
     *
     * Mutation-verified: restoring the `localName === 'action'` test makes this
     * fail with 400 / "Invalid SOAP body".
     */
    public function test_a_real_browse_envelope_is_accepted(): void
    {
        $response = $this->post($this->browseEnvelope());

        self::assertSame(
            200,
            $response->statusCode,
            'A standard UPnP Browse envelope must not be rejected as an invalid SOAP body.'
        );
        self::assertStringNotContainsString('Invalid SOAP body', $response->body);
    }

    /**
     * CONSEQUENCE: the response is a Browse response, not some other action.
     *
     * A 200 alone would not prove the action name was parsed — only that
     * nothing threw. This asserts the parser identified `Browse` specifically.
     */
    public function test_the_browse_action_is_identified_from_the_envelope(): void
    {
        $body = $this->post($this->browseEnvelope())->body;

        self::assertStringContainsString('BrowseResponse', $body);
    }

    /**
     * CONSEQUENCE: action arguments are extracted, not silently dropped.
     *
     * `ObjectID` drives which container is listed. If arguments were lost,
     * every browse would return the root regardless of what was asked for —
     * a failure that still looks like a working server from a distance.
     */
    public function test_action_arguments_are_extracted(): void
    {
        $controller = $this->controller();
        $method = new \ReflectionMethod($controller, 'parseSoapBody');
        $method->setAccessible(true);

        /** @var array{action: string, arguments: array<string, mixed>}|null $parsed */
        $parsed = $method->invoke($controller, $this->browseEnvelope('42'));

        self::assertNotNull($parsed, 'A real Browse envelope must parse.');
        self::assertSame('Browse', $parsed['action']);
        self::assertSame('42', $parsed['arguments']['ObjectID'] ?? null);
        self::assertSame('BrowseDirectChildren', $parsed['arguments']['BrowseFlag'] ?? null);
    }

    /**
     * CONSEQUENCE: an unknown action gets a UPnP fault, not a parse error.
     *
     * The parser no longer validates the action NAME (it takes whatever child
     * of Body it finds), so the "is this a real action" decision belongs to
     * dispatchAction(). This asserts that boundary holds — an unknown action
     * must still be reported, not silently treated as a Browse.
     */
    public function test_an_unknown_action_is_reported_rather_than_silently_accepted(): void
    {
        $envelope = str_replace('Browse', 'NotARealAction', $this->browseEnvelope());

        $body = $this->post($envelope, 'NotARealAction')->body;

        self::assertStringContainsString('NotARealAction', $body);
        self::assertStringNotContainsString('BrowseResponse', $body);
    }

    /**
     * CONSEQUENCE: an empty body is still rejected.
     *
     * Loosening the parser must not turn it into "accept anything".
     */
    public function test_an_empty_body_is_still_rejected(): void
    {
        self::assertSame(400, $this->post('')->statusCode);
    }

    /**
     * CONSEQUENCE: a well-formed envelope with NO action element under <Body>
     * is rejected, not silently treated as some default action.
     *
     * `parseSoapBody()` returns null when `firstBodyChild()` finds nothing under
     * the SOAP Body, and `handle()` maps that to 400 "Invalid SOAP body".
     */
    public function test_a_body_without_an_action_element_is_rejected(): void
    {
        $envelope = '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<s:Body></s:Body></s:Envelope>';

        $response = $this->post($envelope);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('Invalid SOAP body', $response->body);
    }

    /**
     * SECURITY: embedded metadata must not bleed into a top-level argument.
     *
     * The old walk read "the first text node for each local-name ANYWHERE in
     * the document", so a nested `<ObjectID>` buried inside another argument
     * became THE ObjectID whenever the real top-level one was absent — embedded
     * DIDL-Lite metadata could hijack the request. Arguments are now read only
     * from the action element's DIRECT children, so a nested same-named element
     * is ignored and the handler default applies instead.
     *
     * Mutation-verified: restoring the any-descendant walk makes this fail with
     * `arguments['ObjectID'] === 'injected-999'`.
     */
    public function test_nested_same_named_element_does_not_bleed_into_arguments(): void
    {
        // NOTE: NO top-level <ObjectID> — the only <ObjectID> is nested inside
        // <Filter>, exactly the DIDL-Lite bleed shape the hardening prevents.
        $envelope = '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<s:Body>'
            . '<u:Browse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<BrowseFlag>BrowseDirectChildren</BrowseFlag>'
            . '<Filter><ObjectID>injected-999</ObjectID></Filter>'
            . '<StartingIndex>0</StartingIndex>'
            . '<RequestedCount>10</RequestedCount>'
            . '</u:Browse>'
            . '</s:Body></s:Envelope>';

        $controller = $this->controller();
        $method = new \ReflectionMethod($controller, 'parseSoapBody');
        $method->setAccessible(true);

        /** @var array{action: string, arguments: array<string, mixed>}|null $parsed */
        $parsed = $method->invoke($controller, $envelope);

        self::assertNotNull($parsed, 'A real Browse envelope must parse.');
        self::assertSame('Browse', $parsed['action']);
        self::assertArrayNotHasKey(
            'ObjectID',
            $parsed['arguments'],
            'A nested <ObjectID> inside <Filter> must NOT bleed into the top-level arguments.'
        );
        // The genuine direct-child arguments are still extracted correctly.
        self::assertSame('BrowseDirectChildren', $parsed['arguments']['BrowseFlag'] ?? null);
    }
}
