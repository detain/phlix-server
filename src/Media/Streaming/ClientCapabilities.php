<?php

/**
 * Phlix media server component: Streaming.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming;

/**
 * Represents client decoder capabilities for play decisioning.
 *
 * Sent by the client via the X-Phlix-Client-Capabilities header as a
 * JSON-encoded object. Used server-side to decide direct-play vs transcode
 * when the client lacks support for certain codecs (e.g., E-AC-3).
 *
 * @since 0.74.0
 */
final class ClientCapabilities
{
    /** @var array<string, bool> Map of codec names to support flag (true = supported) */
    private array $supportedCodecs;

    /**
     * @param array<string, bool> $supportedCodecs Map of codec name => supported
     */
    private function __construct(array $supportedCodecs)
    {
        $this->supportedCodecs = $supportedCodecs;
    }

    /**
     * Creates a ClientCapabilities instance from a JSON string.
     *
     * @param string|null $json Raw JSON string from X-Phlix-Client-Capabilities header
     *
     * @return self Instance with empty/default capabilities when input is empty/invalid
     */
    public static function fromJson(?string $json): self
    {
        if (!is_string($json) || $json === '') {
            return new self([]);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return new self([]);
        }

        // Normalize: ensure all codec values are bool
        $supportedCodecs = [];
        foreach ($decoded as $codec => $supported) {
            if (is_string($codec)) {
                $supportedCodecs[strtolower($codec)] = (bool) $supported;
            }
        }

        return new self($supportedCodecs);
    }

    /**
     * Creates an empty ClientCapabilities instance (all codecs unsupported/not declared).
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Returns whether the client supports a specific codec.
     *
     * When a codec is not declared in the capabilities payload, the server
     * should err on the side of caution and assume it may not be supported.
     * However, for well-known codecs that are widely supported (aac, mp3),
     * we default to true when not explicitly declared.
     *
     * @param string $codec Codec name (e.g., 'eac3', 'aac', 'opus')
     *
     * @return bool True when the client supports this codec
     */
    public function supportsCodec(string $codec): bool
    {
        $codecLower = strtolower($codec);

        // Explicit declaration takes precedence
        if (array_key_exists($codecLower, $this->supportedCodecs)) {
            return $this->supportedCodecs[$codecLower];
        }

        // Default to true for widely-supported codecs not explicitly declared
        // This avoids unnecessary transcoding when the client is new/doesn't declare them
        $widelySupported = ['aac', 'mp3', 'opus', 'flac', 'ac3', 'vorbis', 'pcm'];
        if (in_array($codecLower, $widelySupported, true)) {
            return true;
        }

        // For other codecs (like eac3), assume not supported when not declared
        // to ensure the client gets playable audio
        return false;
    }

    /**
     * Returns the raw supported codecs map.
     *
     * @return array<string, bool>
     */
    public function getSupportedCodecs(): array
    {
        return $this->supportedCodecs;
    }

    /**
     * Returns whether the client has explicitly declared any codec capabilities.
     */
    public function hasExplicitCapabilities(): bool
    {
        return $this->supportedCodecs !== [];
    }
}
