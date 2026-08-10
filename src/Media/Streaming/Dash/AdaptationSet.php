<?php

/**
 * Phlix media server component: Dash.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming\Dash;

use DOMElement;

/**
 * DASH AdaptationSet — a set of interchangeable {@see Representation}s plus the
 * one {@see SegmentTemplate} they share.
 *
 * A client picks ONE Representation from an AdaptationSet and may switch to
 * another at any segment boundary. That is the whole reason the video rungs live
 * in a SINGLE AdaptationSet: separate AdaptationSets are alternative CONTENT
 * (different languages, different camera angles), not alternative bitrates, and
 * a player will never adapt between them. Conversely each audio LANGUAGE needs
 * its own AdaptationSet — Representations inside one set are switched
 * automatically on bandwidth, which for languages would silently change what the
 * viewer is hearing.
 *
 * S58 rewrote this class. The pre-S58 shape emitted, and the MPD schema rejects,
 * (a) a non-numeric `@id` (`AdaptationSetType/@id` is `xs:unsignedInt`) and
 * (b) `@bandwidth`, which is not an AdaptationSet attribute at all. It also
 * emitted exactly one Representation and never appended a SegmentTemplate, so
 * the manifest described no segments and offered no adaptation.
 *
 * ⚠ **Child order is load-bearing.** `AdaptationSetType`'s content model is a
 * `xs:sequence` — `Role` before `SegmentTemplate` before `Representation`. The
 * schema rejects any other order, so {@see self::toXml()} appends in exactly
 * that order.
 *
 * @author Phlix Media Server Team
 * @version 2.0.0
 * @since 0.11.0
 * @see https://dashif.org/specifications/DASH-MPD.pdf
 */
final class AdaptationSet
{
    /** `Role@schemeIdUri` for the standard DASH role vocabulary. */
    public const ROLE_SCHEME = 'urn:mpeg:dash:role:2011';

    /** The DASH peer of HLS `DEFAULT=YES` on an `#EXT-X-MEDIA` rendition. */
    public const ROLE_MAIN = 'main';

    /** The DASH peer of HLS `DEFAULT=NO`. */
    public const ROLE_ALTERNATE = 'alternate';

    /**
     * `@segmentAlignment` — every Representation's segment N covers the same
     * time range as every other's.
     *
     * TRUE is a factual claim about this server's output, not a default: each
     * rung is encoded independently with a forced IDR at the segment start and a
     * timeline computed from the same `ceil(duration / segment_seconds)`, so the
     * boundaries coincide exactly (this is the same property that lets one HLS
     * timeline serve every variant). It is ALSO why a stream-COPY rendition must
     * never be placed in an AdaptationSet: a copy gets no forced keyframe and
     * drifts to the nearest source GOP.
     */
    public const SEGMENT_ALIGNMENT = 'true';

    /**
     * `@startWithSAP` — every segment opens with a Stream Access Point of type 1
     * (a closed GOP starting with an IDR), so a client may begin decoding at any
     * segment without fetching an earlier one.
     */
    public const START_WITH_SAP = 1;

    /**
     * @param int                  $id              `xs:unsignedInt` — schema-typed, so it cannot be a name.
     * @param string               $contentType     `video` or `audio`.
     * @param string               $mimeType        e.g. `video/mp4`, `audio/mp4`.
     * @param SegmentTemplate      $segmentTemplate The ONE template shared by every representation.
     * @param list<Representation> $representations Interchangeable encodings, best-first.
     * @param string|null          $lang            BCP-47/`xs:language` tag, or null to omit.
     * @param string|null          $role            A {@see self::ROLE_MAIN}-style value, or null to omit.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $contentType,
        public readonly string $mimeType,
        public readonly SegmentTemplate $segmentTemplate,
        public readonly array $representations,
        public readonly ?string $lang = null,
        public readonly ?string $role = null,
    ) {
    }

    /**
     * Converts the AdaptationSet to a DOMElement for MPD generation.
     *
     * @param \DOMDocument|null $ownerDoc Optional owner document for element creation.
     *
     * @return DOMElement The AdaptationSet element with its Role, SegmentTemplate and Representations.
     */
    public function toXml(?\DOMDocument $ownerDoc = null): DOMElement
    {
        $doc = $ownerDoc ?? new \DOMDocument('1.0', 'UTF-8');
        $element = $doc->createElement('AdaptationSet');

        $element->setAttribute('id', (string) $this->id);
        $element->setAttribute('contentType', $this->contentType);
        $element->setAttribute('mimeType', $this->mimeType);
        if ($this->lang !== null) {
            $element->setAttribute('lang', $this->lang);
        }
        $element->setAttribute('segmentAlignment', self::SEGMENT_ALIGNMENT);
        $element->setAttribute('startWithSAP', (string) self::START_WITH_SAP);

        // Sequence order, per AdaptationSetType: Role, then SegmentTemplate,
        // then Representation. See the class docblock.
        if ($this->role !== null) {
            $roleEl = $doc->createElement('Role');
            $roleEl->setAttribute('schemeIdUri', self::ROLE_SCHEME);
            $roleEl->setAttribute('value', $this->role);
            $element->appendChild($roleEl);
        }

        $element->appendChild($this->segmentTemplate->toXml($doc));

        foreach ($this->representations as $representation) {
            $element->appendChild($representation->toXml($doc));
        }

        return $element;
    }

    /**
     * Gets the content type.
     *
     * @return string Content type (`video` or `audio`).
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Gets the adaptation set id.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Highest advertised bandwidth across this set's representations, in bits/second.
     *
     * 0 when the set holds no representations.
     */
    public function maxBandwidth(): int
    {
        $max = 0;
        foreach ($this->representations as $representation) {
            if ($representation->bandwidth > $max) {
                $max = $representation->bandwidth;
            }
        }

        return $max;
    }

    /**
     * Number of representations in this set.
     */
    public function getRepresentationCount(): int
    {
        return count($this->representations);
    }
}
