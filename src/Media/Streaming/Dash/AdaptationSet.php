<?php

declare(strict_types=1);

namespace Phlex\Media\Streaming\Dash;

use DOMElement;

/**
 * DASH AdaptationSet - Represents a DASH AdaptationSet element.
 *
 * An AdaptationSet contains multiple encoded representations of the same
 * content type (video, audio, or text). Clients select between representations
 * based on bandwidth, device capabilities, and network conditions.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @since 0.11.0
 * @see https://dashif.org/specifications/DASH-MPD.pdf
 */
final class AdaptationSet
{
    /**
     * @param string $id Unique identifier for this adaptation set
     * @param string $contentType Content type: 'video', 'audio', or 'text'
     * @param string $codecs Codec string (e.g., 'avc1.64001f' for H.264)
     * @param int $width Width in pixels (0 for audio/text)
     * @param int $height Height in pixels (0 for audio/text)
     * @param int $bandwidth Bandwidth in bits per second
     * @param int $sampleRate Audio sample rate in Hz (0 for video)
     * @param array<int, array{duration: float, url: string}> $segments Array of segment definitions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $contentType,
        public readonly string $codecs,
        public readonly int $width = 0,
        public readonly int $height = 0,
        public readonly int $bandwidth = 0,
        public readonly int $sampleRate = 0,
        public readonly array $segments = [],
    ) {
    }

    /**
     * Converts the AdaptationSet to a DOMElement for MPD generation.
     *
     * @param \DOMDocument|null $ownerDoc Optional owner document for element creation
     *
     * @return DOMElement The AdaptationSet XML element with Representation children
     */
    public function toXml(?\DOMDocument $ownerDoc = null): DOMElement
    {
        $doc = $ownerDoc ?? new \DOMDocument('1.0', 'UTF-8');
        $element = $doc->createElement('AdaptationSet');

        $element->setAttribute('id', $this->id);
        $element->setAttribute('contentType', $this->contentType);

        if ($this->contentType === 'video') {
            $element->setAttribute('width', (string) $this->width);
            $element->setAttribute('height', (string) $this->height);
        }

        $element->setAttribute('bandwidth', (string) $this->bandwidth);

        if ($this->contentType === 'audio' && $this->sampleRate > 0) {
            $element->setAttribute('audioSamplingRate', (string) $this->sampleRate);
        }

        // Create a Representation element as a simple container
        $representation = $doc->createElement('Representation');
        $representation->setAttribute('id', $this->id);
        $representation->setAttribute('codecs', $this->codecs);
        $representation->setAttribute('bandwidth', (string) $this->bandwidth);

        if ($this->contentType === 'video') {
            $representation->setAttribute('width', (string) $this->width);
            $representation->setAttribute('height', (string) $this->height);
        }

        if ($this->contentType === 'audio' && $this->sampleRate > 0) {
            $representation->setAttribute('audioSamplingRate', (string) $this->sampleRate);
        }

        $element->appendChild($representation);

        return $element;
    }

    /**
     * Gets the content type.
     *
     * @return string Content type ('video', 'audio', or 'text')
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Gets the adaptation set ID.
     *
     * @return string Unique identifier
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Gets the codecs string.
     *
     * @return string Codec string
     */
    public function getCodecs(): string
    {
        return $this->codecs;
    }

    /**
     * Gets the bandwidth.
     *
     * @return int Bandwidth in bits per second
     */
    public function getBandwidth(): int
    {
        return $this->bandwidth;
    }

    /**
     * Gets segment count.
     *
     * @return int Number of segments
     */
    public function getSegmentCount(): int
    {
        return count($this->segments);
    }
}
