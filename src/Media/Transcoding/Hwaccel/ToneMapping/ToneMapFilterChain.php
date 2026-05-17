<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel\ToneMapping;

/**
 * Result container for a generated tone-mapping filter chain.
 *
 * Contains the FFmpeg filter graph components needed to apply
 * HDR to SDR tone-mapping for a specific vendor.
 *
 * @since 0.11.0
 */
final class ToneMapFilterChain
{
    /**
     * @param string $input_filtergraph Input filter graph (e.g., 'hwupload=extra_hw_frames=3')
     * @param string $output_filtergraph Output filter graph (e.g., 'tonemap_cuda=...')
     * @param string $metadata_filter Metadata filter (e.g., 'zscale=...')
     * @param array<string> $ffmpeg_args Extra FFmpeg arguments to prepend
     */
    public function __construct(
        public readonly string $input_filtergraph,
        public readonly string $output_filtergraph,
        public readonly string $metadata_filter,
        public readonly array $ffmpeg_args = [],
    ) {
    }

    /**
     * Checks if this filter chain is empty (no tone-mapping needed).
     *
     * @return bool True if no tone-mapping filters are required
     *
     * @since 0.11.0
     */
    public function isEmpty(): bool
    {
        return $this->input_filtergraph === ''
            && $this->output_filtergraph === ''
            && $this->metadata_filter === '';
    }

    /**
     * Gets the complete filter graph string for FFmpeg.
     *
     * Combines input, metadata, and output filters into a single filterchain string.
     *
     * @return string Complete filter graph or empty string if no filters needed
     *
     * @since 0.11.0
     */
    public function getFilterGraph(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $parts = array_filter([
            $this->input_filtergraph,
            $this->metadata_filter,
            $this->output_filtergraph,
        ]);

        return implode(',', $parts);
    }

    /**
     * Gets the video filter (-vf) argument for FFmpeg.
     *
     * @return string Complete -vf argument or empty string
     *
     * @since 0.11.0
     */
    public function getVfArgument(): string
    {
        $graph = $this->getFilterGraph();

        if ($graph === '') {
            return '';
        }

        return sprintf(' -vf "%s"', $graph);
    }
}
