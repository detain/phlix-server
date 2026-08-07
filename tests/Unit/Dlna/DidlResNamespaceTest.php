<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Phlix\Dlna\ContentDirectory;
use Phlix\Dlna\LibraryBridge;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\HlsStreamer;

/**
 * Consequence tests for the DIDL-Lite `<res>` element's XML NAMESPACE.
 *
 * ## What was wrong before
 *
 * `ContentDirectory::addItemMetadata()` emitted the resource element as
 * `<upnp:res protocolInfo="…">…</upnp:res>`. In DIDL-Lite, `res` belongs to the
 * DEFAULT namespace `urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/` -- the one
 * declared as `xmlns=` on the `<DIDL-Lite>` root. Only upnp properties
 * (`upnp:class`, `upnp:album`, `upnp:artist`, `upnp:albumArtURI`, …) take the
 * `upnp:` prefix, exactly as `dc:title` takes `dc:`.
 *
 * Under the `upnp:` prefix the element resolved to
 * `urn:schemas-upnp-org:metadata-1-0/upnp/`, a DIFFERENT namespace, so a
 * control point matching on the DIDL-Lite `res` element found none and treated
 * every object as having no playable resource.
 *
 * ## What these tests do NOT claim
 *
 * This is a wire-format correctness fix ONLY — it says nothing about whether
 * the advertised URL resolves. That question is S53's, and it is asserted
 * against the PRODUCTION route table in
 * {@see \Phlix\Tests\Unit\Dlna\DlnaResUrlIsRoutableTest}. DLNA also ships
 * disabled by default.
 *
 * ## S53 changed the fixture, not the claims
 *
 * These tests used to run against a ContentDirectory with NO LibraryBridge,
 * which reached `addItemMetadata()`'s no-bridge fallback — the arm that emitted
 * the item's ABSOLUTE FILESYSTEM PATH as the resource value. S53 deleted that
 * arm (it leaked the server's directory layout to any LAN peer and was not
 * playable by anything), so a bridge-less ContentDirectory now correctly emits
 * no `<res>` at all and there would be nothing here to make namespace
 * assertions about. The bridge is wired below for that reason; the namespace
 * questions are unchanged.
 *
 * ## Why the assertions are namespace-resolved
 *
 * A test that merely looked for the substring "res" -- or even for a `res`
 * element by local name -- would pass just as happily on `<upnp:res>`. These
 * tests resolve the element through `getElementsByTagNameNS()`, which is the
 * same question a control point asks, so a regression to the `upnp:` prefix
 * cannot slip through.
 */
final class DidlResNamespaceTest extends TestCase
{
    /** The DIDL-Lite default namespace `res` must live in. */
    private const NS_DIDL = 'urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/';

    /** The upnp property namespace `res` must NOT live in. */
    private const NS_UPNP = 'urn:schemas-upnp-org:metadata-1-0/upnp/';

    /** The origin the wired bridge advertises, fixed so assertions are exact. */
    private const BASE_URL = 'http://192.168.1.10:8096';

    private ContentDirectory $contentDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contentDirectory = new ContentDirectory(
            $this->createMock(ItemRepository::class)
        );
        $this->contentDirectory->setLibraryBridge(new LibraryBridge(
            $this->createMock(ItemRepository::class),
            $this->createMock(HlsStreamer::class),
            null,
            null,
            self::BASE_URL
        ));
    }

    /**
     * Parse rendered DIDL and fail loudly on malformed XML -- a namespace bug
     * that also broke well-formedness must not read as a namespace pass.
     */
    private function parse(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml);
        self::assertTrue($loaded, 'Rendered DIDL-Lite is not well-formed XML: ' . $xml);

        return $dom;
    }

    /**
     * @return array<string, mixed>
     */
    private function videoItem(string $name = 'Some Movie'): array
    {
        return [
            'id' => 'item-1',
            'parent_id' => 'library-video',
            'name' => $name,
            'type' => 'item',
            'class' => 'object.item.videoItem.movie',
            'path' => '/media/movies/some-movie.mp4',
            'mime_type' => 'video/mp4',
        ];
    }

    /**
     * The resource element resolves into the DIDL-Lite DEFAULT namespace.
     *
     * Mutation-verified: restoring `<upnp:res …></upnp:res>` in
     * `ContentDirectory::addItemMetadata()` moves the element into
     * {@see NS_UPNP}, the DIDL-namespace lookup returns 0 nodes, and this test
     * fails.
     */
    public function test_res_element_is_in_the_didl_lite_default_namespace(): void
    {
        $xml = $this->contentDirectory->generateDidl([$this->videoItem()], true);

        $dom = $this->parse($xml);

        $inDidl = $dom->getElementsByTagNameNS(self::NS_DIDL, 'res');
        self::assertSame(
            1,
            $inDidl->length,
            'Expected exactly one <res> in the DIDL-Lite default namespace.'
        );

        $res = $inDidl->item(0);
        self::assertNotNull($res);
        self::assertSame(self::NS_DIDL, $res->namespaceURI);
    }

    /**
     * ...and it is NOT in the upnp property namespace.
     *
     * Asserted separately from the positive case so a future change that
     * emitted the element TWICE (once per namespace) would still be caught.
     */
    public function test_res_element_is_not_in_the_upnp_namespace(): void
    {
        $xml = $this->contentDirectory->generateDidl([$this->videoItem()], true);

        $dom = $this->parse($xml);

        self::assertSame(
            0,
            $dom->getElementsByTagNameNS(self::NS_UPNP, 'res')->length,
            '<res> must not be emitted under the upnp: prefix.'
        );

        // Belt and braces at the raw-string level: the serialized form must not
        // carry the prefix at all.
        self::assertStringNotContainsString('upnp:res', $xml);
    }

    /**
     * `upnp:class` still takes the upnp prefix.
     *
     * Guards against an over-correction that strips the prefix from the
     * properties that genuinely need it.
     */
    public function test_upnp_class_remains_in_the_upnp_namespace(): void
    {
        $xml = $this->contentDirectory->generateDidl([$this->videoItem()], true);

        $dom = $this->parse($xml);

        $class = $dom->getElementsByTagNameNS(self::NS_UPNP, 'class');
        self::assertSame(1, $class->length);
        self::assertSame('object.item.videoItem.movie', $class->item(0)?->textContent);
    }

    /**
     * `protocolInfo` and the resource value survive the namespace change
     * unchanged.
     *
     * The expected values are the CURRENT output, pinned verbatim. Both changed
     * in S53 and the change is the point:
     *
     * - the value was `/media/movies/some-movie.mp4` — the ABSOLUTE FILESYSTEM
     *   PATH, from the deleted no-bridge fallback — and is now the routable
     *   `/dlna/stream/{id}` URL;
     * - `protocolInfo`'s fourth field was a bare `*` (the old `match` fell to
     *   its empty default for the DIDL node type `item`) and now carries the
     *   profile implied by the `video/mp4` MIME plus the byte-seek/no-convert
     *   flags that describe what the stream route actually does.
     */
    public function test_res_keeps_its_protocol_info_and_value(): void
    {
        $xml = $this->contentDirectory->generateDidl([$this->videoItem()], true);

        $dom = $this->parse($xml);

        $res = $dom->getElementsByTagNameNS(self::NS_DIDL, 'res')->item(0);
        self::assertInstanceOf(\DOMElement::class, $res);

        self::assertSame(
            'http-get:*:video/mp4:DLNA.ORG_PN=AVC_MP4_MP_HD;DLNA.ORG_OP=01;DLNA.ORG_CI=0',
            $res->getAttribute('protocolInfo')
        );
        self::assertSame(self::BASE_URL . '/dlna/stream/item-1', $res->textContent);
    }

    /**
     * A ContentDirectory with NO LibraryBridge emits NO `<res>`.
     *
     * The deleted fallback used to fill that gap with `$item['path']`. Pinned
     * here, next to the fixture that used to exercise it, so a future author
     * who "restores the missing resource element" has to confront the reason it
     * is missing.
     */
    public function test_a_bridgeless_content_directory_emits_no_resource_element(): void
    {
        $bridgeless = new ContentDirectory($this->createMock(ItemRepository::class));

        $xml = $bridgeless->generateDidl([$this->videoItem()], true);
        $dom = $this->parse($xml);

        self::assertSame(0, $dom->getElementsByTagNameNS(self::NS_DIDL, 'res')->length);
        self::assertStringNotContainsString('/media/movies/some-movie.mp4', $xml);
    }

    /**
     * Titles are escaped exactly ONCE.
     *
     * `htmlspecialchars()` is applied to the title in `itemToDidl()` and to each
     * metadata value in `addItemMetadata()`; a second pass anywhere would
     * surface as the literal text `Tom &amp; Jerry <Special>` rather than
     * `Tom & Jerry <Special>`. Reading `textContent` back through the parser is
     * what distinguishes single from double escaping -- a raw substring check
     * could not.
     */
    public function test_title_is_single_escaped_not_double_escaped(): void
    {
        $title = 'Tom & Jerry <Special> "Quoted"';

        $xml = $this->contentDirectory->generateDidl(
            [$this->videoItem($title)],
            true
        );

        $dom = $this->parse($xml);

        $titleNode = $dom->getElementsByTagNameNS(
            'http://purl.org/dc/elements/1.1/',
            'title'
        )->item(0);

        self::assertNotNull($titleNode);
        self::assertSame($title, $titleNode->textContent);
    }
}
