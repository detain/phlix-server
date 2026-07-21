<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Phlix\Dlna\ContentDirectory;
use Phlix\Media\Library\ItemRepository;

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
 * This is a wire-format correctness fix ONLY. It does not make DLNA playback
 * work end to end: the advertised stream URL must additionally be reachable
 * WITHOUT authentication, which it is not today. DLNA also ships disabled by
 * default.
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

    private ContentDirectory $contentDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contentDirectory = new ContentDirectory(
            $this->createMock(ItemRepository::class)
        );
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
     * The expected value is the CURRENT output, pinned verbatim: the DLNA
     * profile suffix is `*` because `getProtocolInfo()` switches on the item's
     * `type` (here the DIDL node type `item`, not a media type like `video`).
     * Whether that profile selection is right is a separate question -- this
     * test exists to prove the namespace change did not perturb the attribute.
     */
    public function test_res_keeps_its_protocol_info_and_value(): void
    {
        $xml = $this->contentDirectory->generateDidl([$this->videoItem()], true);

        $dom = $this->parse($xml);

        $res = $dom->getElementsByTagNameNS(self::NS_DIDL, 'res')->item(0);
        self::assertInstanceOf(\DOMElement::class, $res);

        self::assertSame(
            'http-get:*:video/mp4:*',
            $res->getAttribute('protocolInfo')
        );
        self::assertSame('/media/movies/some-movie.mp4', $res->textContent);
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
