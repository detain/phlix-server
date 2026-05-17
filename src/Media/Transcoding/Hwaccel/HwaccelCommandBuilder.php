<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel;

use Phlex\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface;

/**
 * Fluent builder for FFmpeg hardware-accelerated transcoding commands.
 *
 * Constructs complete ffmpeg command strings with hardware-specific flags
 * in the correct order: input hwaccel flags, input file, codec, quality,
 * filters, and output.
 *
 * @since 0.11.0
 */
final class HwaccelCommandBuilder
{
    /** @var HwaccelEncoderProfileInterface Profile to use */
    private HwaccelEncoderProfileInterface $profile;

    /** @var HwaccelCapability Hardware capability */
    private HwaccelCapability $capability;

    /** @var string Quality level */
    private string $quality;

    /** @var string Path to input file */
    private string $inputPath = '';

    /** @var string Path to output file */
    private string $outputPath = '';

    /** @var string Video codec */
    private string $videoCodec = 'h264';

    /** @var string Audio codec */
    private string $audioCodec = 'aac';

    /** @var int Target bitrate in bps */
    private int $bitrate = 0;

    /** @var int Output width */
    private int $width = 0;

    /** @var int Output height */
    private int $height = 0;

    /** @var array<string> Extra filter names */
    private array $filters = [];

    /** @var array<string> Extra FFmpeg arguments */
    private array $extraArgs = [];

    /** @var string Path to FFmpeg binary */
    private string $ffmpegPath = '/usr/bin/ffmpeg';

    /**
     * Creates a new HwaccelCommandBuilder.
     *
     * @param HwaccelEncoderProfileInterface $profile Encoder profile
     * @param HwaccelCapability $capability Hardware capability
     * @param string $quality Quality level
     */
    public function __construct(
        HwaccelEncoderProfileInterface $profile,
        HwaccelCapability $capability,
        string $quality = 'medium'
    ) {
        $this->profile = $profile;
        $this->capability = $capability;
        $this->quality = $quality;
    }

    /**
     * Sets the input file path.
     *
     * @param string $path Input file path
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setInput(string $path): self
    {
        $this->inputPath = $path;

        return $this;
    }

    /**
     * Sets the output file path.
     *
     * @param string $path Output file path
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setOutput(string $path): self
    {
        $this->outputPath = $path;

        return $this;
    }

    /**
     * Sets the video codec.
     *
     * @param string $codec Codec name (e.g., 'h264', 'hevc')
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setVideoCodec(string $codec): self
    {
        $this->videoCodec = $codec;

        return $this;
    }

    /**
     * Sets the audio codec.
     *
     * @param string $codec Codec name (e.g., 'aac', 'ac3')
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setAudioCodec(string $codec): self
    {
        $this->audioCodec = $codec;

        return $this;
    }

    /**
     * Sets the target bitrate.
     *
     * @param int $bps Bitrate in bits per second
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setBitrate(int $bps): self
    {
        $this->bitrate = $bps;

        return $this;
    }

    /**
     * Sets the output resolution.
     *
     * @param int $w Width in pixels
     * @param int $h Height in pixels
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setResolution(int $w, int $h): self
    {
        $this->width = $w;
        $this->height = $h;

        return $this;
    }

    /**
     * Sets the quality level.
     *
     * @param string $level Quality level ('ultra' | 'high' | 'medium' | 'low')
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setQualityLevel(string $level): self
    {
        $this->quality = $level;

        return $this;
    }

    /**
     * Adds a filter to the filter chain.
     *
     * @param string $filter Filter name (e.g., 'deinterlace', 'denoise')
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function addFilter(string $filter): self
    {
        $this->filters[] = $filter;

        return $this;
    }

    /**
     * Adds extra FFmpeg arguments.
     *
     * @param array<string> $args Extra arguments
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function addExtraArgs(array $args): self
    {
        $this->extraArgs = array_merge($this->extraArgs, $args);

        return $this;
    }

    /**
     * Sets the FFmpeg binary path.
     *
     * @param string $path Path to FFmpeg binary
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setFfmpegPath(string $path): self
    {
        $this->ffmpegPath = $path;

        return $this;
    }

    /**
     * Builds and returns the complete ffmpeg command string.
     *
     * @return string Complete FFmpeg command
     *
     * @since 0.11.0
     */
    public function build(): string
    {
        if ($this->inputPath === '') {
            throw new \InvalidArgumentException('Input path must be set before building command');
        }

        if ($this->outputPath === '') {
            throw new \InvalidArgumentException('Output path must be set before building command');
        }

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegPath)
        );

        $inputDeviceArgs = $this->profile->getInputDeviceArgs($this->capability);
        if ($inputDeviceArgs !== '') {
            $cmd .= $inputDeviceArgs;
        }

        $cmd .= ' -i ' . escapeshellarg($this->inputPath);

        $cmd .= $this->profile->getCodecArg($this->capability, $this->videoCodec);

        $qualityArgs = $this->profile->getQualityArgs($this->quality, $this->bitrate);
        if ($qualityArgs !== '') {
            $cmd .= $qualityArgs;
        }

        $filterArgs = $this->profile->getFilterArgs($this->filters);
        if ($filterArgs !== '') {
            $cmd .= $filterArgs;
        }

        if ($this->width > 0 && $this->height > 0) {
            $scaleFilter = sprintf(
                'scale=%d:%d:force_original_aspect_ratio=decrease',
                $this->width,
                $this->height
            );
            $cmd .= sprintf(' -vf "%s"', $scaleFilter);
        }

        $cmd .= sprintf(' -c:a %s', $this->audioCodec);

        foreach ($this->extraArgs as $arg) {
            $cmd .= ' ' . $arg;
        }

        $cmd .= ' -threads 0';

        $cmd .= ' ' . escapeshellarg($this->outputPath);

        return $cmd;
    }

    /**
     * Gets the profile being used.
     *
     * @return HwaccelEncoderProfileInterface
     *
     * @since 0.11.0
     */
    public function getProfile(): HwaccelEncoderProfileInterface
    {
        return $this->profile;
    }

    /**
     * Gets the capability being used.
     *
     * @return HwaccelCapability
     *
     * @since 0.11.0
     */
    public function getCapability(): HwaccelCapability
    {
        return $this->capability;
    }

    /**
     * Gets the quality level.
     *
     * @return string
     *
     * @since 0.11.0
     */
    public function getQualityLevel(): string
    {
        return $this->quality;
    }
}
