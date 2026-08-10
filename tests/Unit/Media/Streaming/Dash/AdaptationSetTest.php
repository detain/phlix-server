<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Dash;

use DOMElement;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Dash\AdaptationSet;
use Phlix\Media\Streaming\Dash\Representation;
use Phlix\Media\Streaming\Dash\SegmentTemplate;

/**
 * S58 rewrote this class. Three of the four bugs it shipped are pinned here;
 * the fourth (a missing `SegmentTemplate`) is the first test below.
 */
class AdaptationSetTest extends TestCase
{
    public function testToXmlAppendsTheSharedSegmentTemplateAndEveryRepresentation(): void
    {
        $element = $this->videoSet()->toXml();

        $this->assertEquals('AdaptationSet', $element->nodeName);
        $this->assertSame(['SegmentTemplate', 'Representation', 'Representation'], $this->childNames($element));
    }

    /**
     * The content model of `AdaptationSetType` is an `xs:sequence`: Role, then
     * SegmentTemplate, then Representation. Any other order makes the whole
     * manifest schema-invalid, so the order is asserted, not assumed.
     */
    public function testTheChildOrderIsRoleThenSegmentTemplateThenRepresentations(): void
    {
        $element = $this->videoSet(AdaptationSet::ROLE_MAIN)->toXml();

        $this->assertSame(
            ['Role', 'SegmentTemplate', 'Representation', 'Representation'],
            $this->childNames($element)
        );
    }

    /**
     * `AdaptationSetType/@id` is `xs:unsignedInt`. The pre-S58 class typed it
     * `string` and emitted names like `video-1080`, which the schema rejects —
     * the constructor's `int` is what makes that unrepresentable now.
     */
    public function testTheIdIsAnUnsignedIntegerNotAName(): void
    {
        $this->assertSame('0', $this->videoSet()->toXml()->getAttribute('id'));
        $this->assertSame(0, $this->videoSet()->getId());
    }

    /**
     * `@bandwidth` is a Representation attribute, not an AdaptationSet one. The
     * pre-S58 class set it on both and the schema rejects it on the set.
     */
    public function testNoBandwidthAttributeIsEmittedOnTheSetItself(): void
    {
        $element = $this->videoSet()->toXml();

        $this->assertFalse($element->hasAttribute('bandwidth'));
        $this->assertSame('5128000', $this->children($element, 'Representation')[0]->getAttribute('bandwidth'));
    }

    public function testAVideoSetDeclaresItsMimeTypeAlignmentAndSap(): void
    {
        $element = $this->videoSet()->toXml();

        $this->assertSame('video', $element->getAttribute('contentType'));
        $this->assertSame('video/mp4', $element->getAttribute('mimeType'));
        $this->assertSame('true', $element->getAttribute('segmentAlignment'));
        $this->assertSame('1', $element->getAttribute('startWithSAP'));
    }

    public function testAnAudioSetCarriesItsLanguageAndRole(): void
    {
        $set = new AdaptationSet(
            1,
            'audio',
            'audio/mp4',
            SegmentTemplate::fromSeconds(
                6,
                0,
                'seg-$RepresentationID$-$Number%05d$.m4s',
                'init-$RepresentationID$.m4s'
            ),
            [new Representation('a1', 'mp4a.40.2', 128000)],
            'fra',
            AdaptationSet::ROLE_ALTERNATE
        );

        $element = $set->toXml();
        $role = $this->children($element, 'Role')[0];

        $this->assertSame('fra', $element->getAttribute('lang'));
        $this->assertSame(AdaptationSet::ROLE_SCHEME, $role->getAttribute('schemeIdUri'));
        $this->assertSame('alternate', $role->getAttribute('value'));
        $this->assertSame('audio', $set->getContentType());
    }

    /**
     * `@lang` must be absent, not empty: `xs:language` does not admit an empty
     * string and one would invalidate the whole document.
     */
    public function testALanguagelessSetOmitsTheAttributeEntirely(): void
    {
        $this->assertFalse($this->videoSet()->toXml()->hasAttribute('lang'));
    }

    public function testARolelessSetAppendsNoRoleElement(): void
    {
        $this->assertSame(
            ['SegmentTemplate', 'Representation', 'Representation'],
            $this->childNames($this->videoSet()->toXml())
        );
    }

    public function testMaxBandwidthIsTheHighestRepresentation(): void
    {
        $this->assertSame(5128000, $this->videoSet()->maxBandwidth());
        $this->assertSame(2, $this->videoSet()->getRepresentationCount());
    }

    public function testMaxBandwidthOfAnEmptySetIsZero(): void
    {
        $set = new AdaptationSet(0, 'video', 'video/mp4', SegmentTemplate::fromSeconds(6, 0, 'x'), []);

        $this->assertSame(0, $set->maxBandwidth());
        $this->assertSame(0, $set->getRepresentationCount());
    }

    private function videoSet(?string $role = null): AdaptationSet
    {
        return new AdaptationSet(
            0,
            'video',
            'video/mp4',
            SegmentTemplate::fromSeconds(
                6,
                0,
                'seg-v$RepresentationID$-$Number%05d$.m4s',
                'init-v$RepresentationID$.m4s'
            ),
            [
                new Representation('1080p', 'avc1.640029', 5128000, 1920, 1080),
                new Representation('720p', 'avc1.640029', 3128000, 1280, 720),
            ],
            null,
            $role
        );
    }

    /** @return list<string> */
    private function childNames(DOMElement $element): array
    {
        $out = [];
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node->nodeName;
            }
        }

        return $out;
    }

    /** @return list<DOMElement> */
    private function children(DOMElement $element, string $name): array
    {
        $out = [];
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement && $node->nodeName === $name) {
                $out[] = $node;
            }
        }
        $this->assertNotSame([], $out, "expected a <{$name}> child");

        return $out;
    }
}
