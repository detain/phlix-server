<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel;

use Phlex\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HdrMetadata;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\HwaccelToneMapper;
use Phlex\Media\Transcoding\Hwaccel\ToneMapping\ToneMapFilterChain;
use Phlex\Media\Transcoding\Subtitles\SubtitleBurner;
use Phlex\Media\Transcoding\Subtitles\SubtitleStyleOptions;
use Phlex\Media\Transcoding\Subtitles\SubtitleTrack;

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

    /** @var HdrMetadata|null HDR metadata for tone mapping */
    private ?HdrMetadata $hdrMetadata = null;

    /** @var ToneMapFilterChain|null Cached tone map filter chain */
    private ?ToneMapFilterChain $toneMapFilterChain = null;

    /** @var HwaccelToneMapper|null Tone mapper instance */
    private ?HwaccelToneMapper $toneMapper = null;

    /** @var SubtitleTrack|null Subtitle track for burn-in */
    private ?SubtitleTrack $subtitleTrack = null;

    /** @var SubtitleStyleOptions|null Style options for subtitle burn-in */
    private ?SubtitleStyleOptions $subtitleStyle = null;

    /** @var SubtitleBurner|null Subtitle burner instance */
    private ?SubtitleBurner $subtitleBurner = null;

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
     * Sets HDR metadata for tone mapping.
     *
     * When set, the builder will inject the appropriate HDR to SDR
     * tone-mapping filter chain for the hardware vendor.
     *
     * @param HdrMetadata $hdr HDR source metadata
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setHdrMetadata(HdrMetadata $hdr): self
    {
        $this->hdrMetadata = $hdr;

        return $this;
    }

    /**
     * Sets a custom tone mapper instance.
     *
     * @param HwaccelToneMapper $toneMapper Tone mapper instance
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setToneMapper(HwaccelToneMapper $toneMapper): self
    {
        $this->toneMapper = $toneMapper;

        return $this;
    }

    /**
     * Sets the subtitle track for burn-in.
     *
     * When set, the builder will inject subtitle burn-in filter arguments
     * into the transcoding command. The SubtitleBurner must also be set
     * via setSubtitleBurner() to provide the filter chain.
     *
     * @param SubtitleTrack|null $track Subtitle track to burn in (null to disable)
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setSubtitleTrack(?SubtitleTrack $track): self
    {
        $this->subtitleTrack = $track;

        return $this;
    }

    /**
     * Sets the subtitle style options for burn-in.
     *
     * Controls the appearance of burned-in subtitles including font,
     * size, color, outline, position, and margin.
     *
     * @param SubtitleStyleOptions|null $style Style options (null for defaults)
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setSubtitleStyle(?SubtitleStyleOptions $style): self
    {
        $this->subtitleStyle = $style;

        return $this;
    }

    /**
     * Sets the subtitle burner instance.
     *
     * Required when using subtitle burn-in. The burner generates
     * the appropriate FFmpeg filter arguments based on the hardware
     * vendor's subtitle capabilities.
     *
     * @param SubtitleBurner $burner Subtitle burner instance
     *
     * @return self
     *
     * @since 0.11.0
     */
    public function setSubtitleBurner(SubtitleBurner $burner): self
    {
        $this->subtitleBurner = $burner;

        return $this;
    }

    /**
     * Gets the tone map filter chain for the current HDR metadata and vendor.
     *
     * @return ToneMapFilterChain|null Filter chain or null if no HDR metadata set
     *
     * @since 0.11.0
     */
    public function getToneMapFilterChain(): ?ToneMapFilterChain
    {
        if ($this->toneMapFilterChain === null && $this->hdrMetadata !== null) {
            $this->toneMapFilterChain = $this->buildToneMapFilterChain();
        }

        return $this->toneMapFilterChain;
    }

    /**
     * Builds the tone map filter chain based on HDR metadata and vendor.
     *
     * @return ToneMapFilterChain
     *
     * @since 0.11.0
     */
    private function buildToneMapFilterChain(): ToneMapFilterChain
    {
        if ($this->hdrMetadata === null) {
            return new ToneMapFilterChain('', '', '');
        }

        $toneMapper = $this->toneMapper ?? new HwaccelToneMapper(HwaccelRegistry::getInstance());

        return $toneMapper->getFilterChain(
            $this->capability->vendor,
            $this->hdrMetadata
        );
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

        // Inject HDR tone-mapping filter chain if HDR metadata is set
        $toneMapChain = $this->getToneMapFilterChain();
        if ($toneMapChain !== null && !$toneMapChain->isEmpty()) {
            $cmd .= $toneMapChain->getVfArgument();

            // Add extra FFmpeg args from tone mapping (e.g., -extra_hw_frames)
            foreach ($toneMapChain->ffmpeg_args as $toneArg) {
                $cmd .= ' ' . $toneArg;
            }
        }

        if ($this->width > 0 && $this->height > 0) {
            $scaleFilter = sprintf(
                'scale=%d:%d:force_original_aspect_ratio=decrease',
                $this->width,
                $this->height
            );

            // If subtitle burn-in is enabled, chain the subtitle filter before scaling
            if ($this->subtitleTrack !== null && $this->subtitleBurner !== null) {
                $styleOptions = $this->subtitleStyle ?? new SubtitleStyleOptions();
                $styleArray = [
                    'font_name' => $styleOptions->font_name,
                    'font_size' => $styleOptions->font_size,
                    'primary_color' => $styleOptions->primary_color,
                    'outline_color' => $styleOptions->outline_color,
                    'outline_thickness' => $styleOptions->outline_thickness,
                    'position' => $styleOptions->position,
                    'margin' => $styleOptions->margin,
                ];
                $subtitleArgs = $this->subtitleBurner->getBurnInArgs(
                    $this->subtitleTrack,
                    $this->capability->vendor,
                    $styleArray
                );

                // Subtitle args contain -vf and filter string; chain with scale
                if (count($subtitleArgs) >= 2 && $subtitleArgs[0] === '-vf') {
                    $subtitleFilter = $subtitleArgs[1];
                    $cmd .= sprintf(' -vf "%s,%s"', $subtitleFilter, $scaleFilter);
                    // Add any extra args (e.g., -vaapi_device for VAAPI)
                    for ($i = 2; $i < count($subtitleArgs); $i++) {
                        $cmd .= ' ' . $subtitleArgs[$i];
                    }
                } else {
                    $cmd .= sprintf(' -vf "%s"', $scaleFilter);
                }
            } else {
                $cmd .= sprintf(' -vf "%s"', $scaleFilter);
            }
        } elseif ($this->subtitleTrack !== null && $this->subtitleBurner !== null) {
            // Subtitle only, no scaling
            $styleOptions = $this->subtitleStyle ?? new SubtitleStyleOptions();
            $styleArray = [
                'font_name' => $styleOptions->font_name,
                'font_size' => $styleOptions->font_size,
                'primary_color' => $styleOptions->primary_color,
                'outline_color' => $styleOptions->outline_color,
                'outline_thickness' => $styleOptions->outline_thickness,
                'position' => $styleOptions->position,
                'margin' => $styleOptions->margin,
            ];
            $subtitleArgs = $this->subtitleBurner->getBurnInArgs(
                $this->subtitleTrack,
                $this->capability->vendor,
                $styleArray
            );

            // Subtitle args contain -vf and filter string
            if (count($subtitleArgs) >= 2 && $subtitleArgs[0] === '-vf') {
                $cmd .= sprintf(' -vf "%s"', $subtitleArgs[1]);
                // Add any extra args (e.g., -vaapi_device, -qsv_device)
                for ($i = 2; $i < count($subtitleArgs); $i++) {
                    $cmd .= ' ' . $subtitleArgs[$i];
                }
            }
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
