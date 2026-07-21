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
}
