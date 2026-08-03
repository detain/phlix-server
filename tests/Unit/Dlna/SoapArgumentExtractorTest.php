<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use Phlix\Dlna\SoapArgumentExtractor;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Direct unit coverage for the shared, namespace-aware SOAP argument extractor
 * (S28 sub-task (b)).
 *
 * The controller-level test ({@see \Phlix\Tests\Unit\Server\Http\Controllers\Dlna\DlnaContentDirectorySoapTest})
 * exercises this helper end-to-end through the live parser; these tests pin the
 * helper's own contract — namespace-agnostic action matching, direct-child-only
 * argument reads (the DIDL-Lite bleed guard), XXE safety, and the defensive
 * XPath literal quoting — so the single-sourced logic cannot silently regress.
 *
 */
final class SoapArgumentExtractorTest extends TestCase
{
    private function browse(string $bodyTag = 's:Body', string $action = 'u:Browse'): string
    {
        return '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<' . $bodyTag . '>'
            . '<' . $action . '>'
            . '<ObjectID>42</ObjectID>'
            . '<BrowseFlag>BrowseDirectChildren</BrowseFlag>'
            . '<SortCriteria></SortCriteria>'
            . '</' . $action . '>'
            . '</' . $bodyTag . '>'
            . '</s:Envelope>';
    }

    // ------------------------------------------------------------------
    // loadBody — well-formed vs malformed, XXE safety.
    // ------------------------------------------------------------------

    public function testLoadBodyReturnsElementForWellFormedXml(): void
    {
        $doc = SoapArgumentExtractor::loadBody('<r><x>1</x></r>');

        self::assertInstanceOf(SimpleXMLElement::class, $doc);
    }

    public function testLoadBodyReturnsNullForMalformedXml(): void
    {
        self::assertNull(SoapArgumentExtractor::loadBody('<r><x></r>'));
        self::assertNull(SoapArgumentExtractor::loadBody(''));
    }

    /**
     * XXE guard: external entities are never substituted (no `LIBXML_NOENT`),
     * so a declared entity does not expand into a value the parser will hand
     * back as an argument.
     */
    public function testLoadBodyDoesNotSubstituteExternalEntities(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<r><x>&xxe;</x></r>';

        $doc = SoapArgumentExtractor::loadBody($xml);

        // Either parsing is refused, or the entity is left unexpanded — never
        // the contents of /etc/passwd.
        if ($doc !== null) {
            self::assertStringNotContainsString('root:', (string) $doc->x);
        } else {
            self::assertNull($doc);
        }
    }

    // ------------------------------------------------------------------
    // firstBodyChild — namespace-agnostic Body match.
    // ------------------------------------------------------------------

    public function testFirstBodyChildReturnsActionRegardlessOfBodyPrefix(): void
    {
        foreach (['s:Body', 'SOAP-ENV:Body', 'Body'] as $bodyTag) {
            $envelope = $bodyTag === 'SOAP-ENV:Body'
                // SOAP-ENV needs its own namespace declaration to be well-formed.
                ? str_replace(
                    'xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"',
                    'xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"'
                    . ' xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"',
                    $this->browse($bodyTag)
                )
                : $this->browse($bodyTag);

            $action = SoapArgumentExtractor::firstBodyChild($envelope);

            self::assertInstanceOf(SimpleXMLElement::class, $action, "Body tag {$bodyTag}");
            self::assertSame('Browse', $action->getName(), "Body tag {$bodyTag}");
        }
    }

    public function testFirstBodyChildReturnsNullWhenNoBodyChild(): void
    {
        $envelope = '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<s:Body></s:Body></s:Envelope>';

        self::assertNull(SoapArgumentExtractor::firstBodyChild($envelope));
        self::assertNull(SoapArgumentExtractor::firstBodyChild('<not-xml'));
    }

    // ------------------------------------------------------------------
    // findActionElement — by local name, envelope + bare fallbacks.
    // ------------------------------------------------------------------

    public function testFindActionElementMatchesByLocalNameUnderBody(): void
    {
        $action = SoapArgumentExtractor::findActionElement($this->browse(), 'Browse');

        self::assertInstanceOf(SimpleXMLElement::class, $action);
        self::assertSame('Browse', $action->getName());
    }

    public function testFindActionElementFallsBackToBareRootElement(): void
    {
        $bare = '<u:Browse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<ObjectID>7</ObjectID></u:Browse>';

        $action = SoapArgumentExtractor::findActionElement($bare, 'Browse');

        self::assertInstanceOf(SimpleXMLElement::class, $action);
        self::assertSame('7', SoapArgumentExtractor::extractArgument($action, 'ObjectID'));
    }

    public function testFindActionElementFallsBackToABareNestedActionElement(): void
    {
        // Action element is neither under a SOAP <Body> nor the document root —
        // some minimalist control points wrap it in an arbitrary element.
        $xml = '<wrapper xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<u:Browse><ObjectID>9</ObjectID></u:Browse></wrapper>';

        $action = SoapArgumentExtractor::findActionElement($xml, 'Browse');

        self::assertInstanceOf(SimpleXMLElement::class, $action);
        self::assertSame('9', SoapArgumentExtractor::extractArgument($action, 'ObjectID'));
    }

    public function testFindActionElementReturnsNullWhenActionAbsent(): void
    {
        self::assertNull(SoapArgumentExtractor::findActionElement($this->browse(), 'Search'));
        self::assertNull(SoapArgumentExtractor::findActionElement('<garbage', 'Browse'));
    }

    // ------------------------------------------------------------------
    // extractArgument — direct child by local name; present-but-empty vs absent.
    // ------------------------------------------------------------------

    public function testExtractArgumentReadsDirectChildByLocalName(): void
    {
        $action = SoapArgumentExtractor::firstBodyChild($this->browse());
        self::assertNotNull($action);

        self::assertSame('42', SoapArgumentExtractor::extractArgument($action, 'ObjectID'));
        self::assertSame('BrowseDirectChildren', SoapArgumentExtractor::extractArgument($action, 'BrowseFlag'));
    }

    public function testExtractArgumentDistinguishesEmptyFromAbsent(): void
    {
        $action = SoapArgumentExtractor::firstBodyChild($this->browse());
        self::assertNotNull($action);

        // Present but empty <SortCriteria/> → '' (not null).
        self::assertSame('', SoapArgumentExtractor::extractArgument($action, 'SortCriteria'));
        // Genuinely absent → null.
        self::assertNull(SoapArgumentExtractor::extractArgument($action, 'RequestedCount'));
    }

    // ------------------------------------------------------------------
    // directChildArguments — the DIDL-Lite bleed guard.
    // ------------------------------------------------------------------

    public function testDirectChildArgumentsIgnoreNestedSameNamedElement(): void
    {
        // A nested <ObjectID> buried inside <Filter> must NOT surface as a
        // top-level argument — this is the metadata-bleed shape.
        $envelope = '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<s:Body>'
            . '<u:Browse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<BrowseFlag>BrowseDirectChildren</BrowseFlag>'
            . '<Filter><ObjectID>injected-999</ObjectID></Filter>'
            . '</u:Browse></s:Body></s:Envelope>';

        $action = SoapArgumentExtractor::firstBodyChild($envelope);
        self::assertNotNull($action);

        $args = SoapArgumentExtractor::directChildArguments($action);

        self::assertArrayNotHasKey('ObjectID', $args, 'Nested <ObjectID> must not bleed in.');
        // <Filter> wraps only elements, so its own text is empty and it is dropped.
        self::assertArrayNotHasKey('Filter', $args);
        self::assertSame('BrowseDirectChildren', $args['BrowseFlag'] ?? null);
    }

    public function testDirectChildArgumentsDropEmptyAndKeepFirstOfRepeats(): void
    {
        $envelope = '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<s:Body>'
            . '<u:Browse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            . '<BrowseFlag>BrowseDirectChildren</BrowseFlag>'
            . '<SortCriteria></SortCriteria>'
            . '<Dup>first</Dup><Dup>second</Dup>'
            . '</u:Browse></s:Body></s:Envelope>';

        $action = SoapArgumentExtractor::firstBodyChild($envelope);
        self::assertNotNull($action);

        $args = SoapArgumentExtractor::directChildArguments($action);

        // Empty <SortCriteria/> dropped so a handler default can apply.
        self::assertArrayNotHasKey('SortCriteria', $args);
        // First occurrence of a repeated local name wins.
        self::assertSame('first', $args['Dup'] ?? null);
        self::assertSame('BrowseDirectChildren', $args['BrowseFlag'] ?? null);
    }

    // ------------------------------------------------------------------
    // xpathLiteral — defensive quoting for all three quote shapes.
    // ------------------------------------------------------------------

    public function testXpathLiteralQuotesEveryQuoteShape(): void
    {
        // No quotes → single-quoted.
        self::assertSame("'Browse'", SoapArgumentExtractor::xpathLiteral('Browse'));
        // Contains a single quote → double-quoted.
        self::assertSame('"a\'b"', SoapArgumentExtractor::xpathLiteral("a'b"));
        // Contains a double quote → single-quoted.
        self::assertSame("'a\"b'", SoapArgumentExtractor::xpathLiteral('a"b'));
        // Contains BOTH → concat().
        $both = SoapArgumentExtractor::xpathLiteral('a\'b"c');
        self::assertStringStartsWith('concat(', $both);
        self::assertStringContainsString('"\'"', $both, 'concat() must splice a literal apostrophe.');
    }
}
