<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support\Dash;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Phlix\Tests\Support\Dash\MpdSchema;

/**
 * The net under the S58 MPD validator itself.
 *
 * `TranscodeManagerVodMpdTest` proves the validator can REJECT; this file
 * proves it cannot quietly stop being a validator — by depending on the network
 * (measured: w3.org answered HTTP 429 during the S58 mutation run and every
 * document then read as invalid), or by losing one of its three schema files.
 */
final class MpdSchemaTest extends TestCase
{
    private const FILES = ['DASH-MPD.xsd', 'xlink.xsd', 'xml.xsd'];

    public function testEverySchemaFileTheValidatorNeedsIsVendored(): void
    {
        $dir = dirname(MpdSchema::path());

        foreach (self::FILES as $file) {
            $this->assertFileExists("{$dir}/{$file}");
            $this->assertGreaterThan(1000, (int) filesize("{$dir}/{$file}"), "{$file} looks truncated");
        }
    }

    /**
     * No `schemaLocation` may be an absolute URL.
     *
     * This is the one that actually bites: `xlink.xsd` as published imports
     * `http://www.w3.org/2001/xml.xsd`, and libxml fetches it on EVERY
     * validation. Re-fetching the file verbatim in some future refresh would
     * silently reintroduce a gate whose verdict depends on a third party's rate
     * limiter, and nothing else in the suite would notice.
     */
    public function testNoVendoredSchemaReachesOutToTheNetwork(): void
    {
        $dir = dirname(MpdSchema::path());
        $locations = [];

        foreach (self::FILES as $file) {
            $doc = new DOMDocument();
            $this->assertTrue($doc->load("{$dir}/{$file}"), "{$file} is not parseable");

            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
            // Real <xs:import>/<xs:include> ELEMENTS only. `xml.xsd` quotes an
            // absolute schemaLocation inside its own <xs:documentation>, so a
            // textual grep over these files reports two false positives.
            foreach ($xpath->query('//xs:import[@schemaLocation]|//xs:include[@schemaLocation]') ?: [] as $node) {
                $this->assertInstanceOf(DOMElement::class, $node);
                $locations["{$file}"][] = $node->getAttribute('schemaLocation');
            }
        }

        // Denominator + shape: a query that matched nothing would pass the
        // assertion below without proving anything.
        $this->assertSame(
            ['DASH-MPD.xsd' => ['xlink.xsd'], 'xlink.xsd' => ['xml.xsd']],
            $locations,
            'the vendored import graph is DASH-MPD -> xlink -> xml, all relative'
        );
    }

    /**
     * Positive control for the two negative ones: the smallest legal MPD
     * validates, so a later "everything is rejected" regression (which is what
     * an unresolvable import produces) cannot hide behind the reject tests.
     */
    public function testTheSmallestLegalManifestValidates(): void
    {
        $this->assertSame([], MpdSchema::errors(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="p" minBufferTime="PT2S">'
            . '<Period id="1"/></MPD>'
        ));
    }

    public function testAnEmptySourceIsAnErrorNotAnException(): void
    {
        $this->assertSame(['the manifest is empty'], MpdSchema::errors(''));
    }
}
