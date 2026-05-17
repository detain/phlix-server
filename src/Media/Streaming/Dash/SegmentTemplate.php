<?php

declare(strict_types=1);

namespace Phlex\Media\Streaming\Dash;

use DOMElement;

/**
 * DASH SegmentTemplate - Represents a DASH SegmentTemplate element.
 *
 * SegmentTemplate provides a template for segment URLs, avoiding the need
 * to list every segment explicitly in the manifest. Used for efficient
 * live streaming where new segments appear continuously.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @since 0.11.0
 * @see https://dashif.org/specifications/DASH-MPD.pdf
 */
final class SegmentTemplate
{
    /**
     * @param int $duration Segment duration in seconds
     * @param int $startNumber Starting segment number (usually 1)
     * @param string $media Template for media segment URLs using $RepresentationID$ and $Number%05d$ placeholders
     * @param string|null $initialization Template for initialization segment URL (or null for muxed content)
     */
    public function __construct(
        public readonly int $duration,
        public readonly int $startNumber = 1,
        public readonly string $media = '$RepresentationID$_$Number%05d$.m4s',
        public readonly ?string $initialization = null,
    ) {
    }

    /**
     * Converts the SegmentTemplate to a DOMElement for MPD generation.
     *
     * @param \DOMDocument|null $ownerDoc Optional owner document for element creation
     *
     * @return DOMElement The SegmentTemplate XML element
     */
    public function toXml(?\DOMDocument $ownerDoc = null): DOMElement
    {
        $doc = $ownerDoc ?? new \DOMDocument('1.0', 'UTF-8');
        $element = $doc->createElement('SegmentTemplate');

        $element->setAttribute('duration', (string) ($this->duration * 1000));
        $element->setAttribute('startNumber', (string) $this->startNumber);
        $element->setAttribute('media', $this->media);

        if ($this->initialization !== null) {
            $element->setAttribute('initialization', $this->initialization);
        }

        return $element;
    }

    /**
     * Gets the initialization URL template.
     *
     * @return string|null The initialization URL template or null
     */
    public function getInitializationTemplate(): ?string
    {
        return $this->initialization;
    }

    /**
     * Gets the media URL template.
     *
     * @return string The media URL template
     */
    public function getMediaTemplate(): string
    {
        return $this->media;
    }

    /**
     * Gets the segment duration in seconds.
     *
     * @return int Duration in seconds
     */
    public function getDuration(): int
    {
        return $this->duration;
    }

    /**
     * Gets the starting segment number.
     *
     * @return int Starting segment number
     */
    public function getStartNumber(): int
    {
        return $this->startNumber;
    }
}
