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
 * DASH Representation — one encoding of one AdaptationSet's content.
 *
 * A Representation is the DASH peer of an HLS `#EXT-X-STREAM-INF` level: the
 * things a player may switch BETWEEN live in the same AdaptationSet, one
 * Representation each. The pre-S58 `AdaptationSet` emitted exactly one
 * Representation per AdaptationSet, which is a manifest with no adaptation in
 * it at all — hence this class.
 *
 * `@id` is what `$RepresentationID$` in a
 * {@see SegmentTemplate} expands to, so it is not decorative: for this server it
 * IS the rendition id (`720p`, `original`, `a0`) that names the files on disk.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @since S58
 * @see https://dashif.org/specifications/DASH-MPD.pdf
 */
final class Representation
{
    /**
     * @param string   $id        Representation id — substituted for `$RepresentationID$`.
     * @param string   $codecs    RFC 6381 codecs string for THIS representation only.
     * @param int      $bandwidth Peak bandwidth in bits/second (required by the MPD schema).
     * @param int      $width     Pixel width, or 0 to omit (audio).
     * @param int      $height    Pixel height, or 0 to omit (audio).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $codecs,
        public readonly int $bandwidth,
        public readonly int $width = 0,
        public readonly int $height = 0,
    ) {
    }

    /**
     * Converts the Representation to a DOMElement for MPD generation.
     *
     * @param \DOMDocument|null $ownerDoc Optional owner document for element creation.
     *
     * @return DOMElement The Representation XML element.
     */
    public function toXml(?\DOMDocument $ownerDoc = null): DOMElement
    {
        $doc = $ownerDoc ?? new \DOMDocument('1.0', 'UTF-8');
        $element = $doc->createElement('Representation');

        $element->setAttribute('id', $this->id);
        $element->setAttribute('codecs', $this->codecs);
        // Required by the schema (RepresentationType/@bandwidth, use="required").
        $element->setAttribute('bandwidth', (string) $this->bandwidth);

        // width/height are xs:unsignedInt and meaningless for audio; emitting
        // `width="0"` on an audio representation is schema-valid but a lie, so
        // they are omitted rather than zeroed.
        if ($this->width > 0 && $this->height > 0) {
            $element->setAttribute('width', (string) $this->width);
            $element->setAttribute('height', (string) $this->height);
        }

        return $element;
    }
}
