<?php

declare(strict_types=1);

namespace Phlex\Media\Streaming\Trickplay;

use Phlex\Media\Transcoding\FfmpegRunner;

/**
 * Trickplay Generator — generates BIF thumbnail grid images for thumbnail seek.
 *
 * This class extracts individual frames from a video at fixed intervals,
 * assembles them into grid images using FFmpeg's tile filter, and generates
 * a BIF (Bitmap Image Format) index XML that maps byte offsets to grid
 * positions for byte-range serving.
 *
 * @since 0.11.0
 */
class TrickplayGenerator
{
    /** @var FfmpegRunner FFmpeg runner for frame extraction */
    private FfmpegRunner $ffmpeg;

    /** @var string Output directory for trickplay files */
    private string $outputDir;

    /**
     * Creates a new TrickplayGenerator instance.
     *
     * @param FfmpegRunner $ffmpeg FFmpeg runner for frame extraction
     * @param string $outputDir Base output directory for trickplay files
     */
    public function __construct(FfmpegRunner $ffmpeg, string $outputDir)
    {
        $this->ffmpeg = $ffmpeg;
        $this->outputDir = rtrim($outputDir, '/');
    }

    /**
     * Generates all trickplay thumbnail images for a given job.
     *
     * Computes how many thumbnails are needed based on video duration,
     * extracts frames at each interval, assembles them into grid images,
     * and generates the BIF index XML.
     *
     * @param string $jobId Transcode job identifier
     * @param string $inputPath Path to the source video file
     * @param TrickplayConfig|null $config Optional configuration (uses defaults if null)
     *
     * @return TrickplayResult Result containing image files and index path
     *
     * @throws \RuntimeException If video duration cannot be determined
     */
    public function generate(string $jobId, string $inputPath, ?TrickplayConfig $config = null): TrickplayResult
    {
        $config = $config ?? new TrickplayConfig();

        $probeResult = $this->ffmpeg->probe($inputPath);
        if ($probeResult === null) {
            throw new \RuntimeException("Failed to probe video: {$inputPath}");
        }

        $duration = $this->extractDuration($probeResult);
        if ($duration <= 0) {
            throw new \RuntimeException("Invalid video duration: {$duration}");
        }

        $thumbnailCount = (int) ceil($duration / $config->interval_seconds);
        $thumbnailsPerGrid = $config->getThumbnailsPerGrid();
        $gridCount = (int) ceil($thumbnailCount / $thumbnailsPerGrid);

        $jobDir = $this->outputDir . '/trickplay/' . $jobId;
        if (!is_dir($jobDir)) {
            mkdir($jobDir, 0755, true);
        }

        $imageFiles = [];
        $timestamp = 0;
        $thumbIndex = 0;
        $gridIndex = 0;
        $offset = 0;

        while ($timestamp < $duration && $thumbIndex < $thumbnailCount) {
            $gridFile = 'bif_' . str_pad((string) $gridIndex, 2, '0', STR_PAD_LEFT) . $config->getFileExtension();
            $gridPath = $jobDir . '/' . $gridFile;

            $timestampsInThisGrid = min($thumbnailsPerGrid, $thumbnailCount - $thumbIndex);
            $gridTimestamps = [];
            for ($i = 0; $i < $timestampsInThisGrid; $i++) {
                $gridTimestamps[] = $timestamp;
                $timestamp += $config->interval_seconds;
            }

            $this->extractFrameBatch($inputPath, $jobDir, $thumbIndex, $gridTimestamps, $config);

            $this->assembleGrid($jobDir, $thumbIndex, $timestampsInThisGrid, $gridIndex, $config);

            $fileSize = file_exists($gridPath) ? (int) filesize($gridPath) : 0;
            $imageFiles[$gridFile] = [
                'offset' => $offset,
                'size' => $fileSize,
                'start_index' => $thumbIndex,
                'count' => $timestampsInThisGrid,
            ];

            $offset += $fileSize;
            $thumbIndex += $timestampsInThisGrid;
            $gridIndex++;
        }

        $indexXml = $this->generateIndex($jobId, $this->createResult($jobId, $config, $imageFiles));

        return $this->createResult($jobId, $config, $imageFiles, $indexXml);
    }

    /**
     * Extracts a single frame at a given timestamp.
     *
     * @param string $inputPath Source video path
     * @param int $timestampSeconds Timestamp to capture frame
     * @param string $outputPath Destination image path
     *
     * @return bool True if extraction succeeded
     */
    public function extractFrame(string $inputPath, int $timestampSeconds, string $outputPath): bool
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -ss %d -vframes 1 -q:v 2 -f image2 %s',
            escapeshellarg($this->ffmpeg->getFfmpegPath()),
            escapeshellarg($inputPath),
            $timestampSeconds,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Generates the BIF index XML file that maps byte offsets to grid positions.
     *
     * The BIF index format is:
     * ```xml
     * <ThumbList>
     *   <Thumbs>
     *     <Thumb index="0" time="0" offset="0" length="4096"/>
     *     ...
     *   </Thumbs>
     * </ThumbList>
     * ```
     *
     * @param string $jobId Transcode job identifier
     * @param TrickplayResult $result Trickplay result containing image file metadata
     *
     * @return string Path to the generated index XML file
     */
    public function generateIndex(string $jobId, TrickplayResult $result): string
    {
        $jobDir = $this->outputDir . '/trickplay/' . $jobId;
        $indexPath = $jobDir . '/index.xml';

        $xml = new \XMLWriter();
        $xml->openUri($indexPath);
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('ThumbList');

        $thumbIndex = 0;
        foreach ($result->getSortedImageFiles() as $filename => $metadata) {
            $offset = $metadata['offset'];
            $size = $metadata['size'];
            $startIndex = $metadata['start_index'] ?? 0;
            $count = $metadata['count'] ?? 1;

            for ($i = 0; $i < $count; $i++) {
                $time = ($startIndex + $i) * $result->interval_seconds;
                $xml->startElement('Thumb');
                $xml->writeAttribute('index', (string) $thumbIndex);
                $xml->writeAttribute('time', (string) $time);
                $xml->writeAttribute('offset', (string) $offset);
                $xml->writeAttribute('length', (string) $size);
                $xml->endElement();
                $thumbIndex++;
            }
        }

        $xml->endElement(); // ThumbList
        $xml->endDocument();

        return $indexPath;
    }

    /**
     * Cleans up trickplay files for a job.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return void
     */
    public function cleanup(string $jobId): void
    {
        $jobDir = $this->outputDir . '/trickplay/' . $jobId;

        if (!is_dir($jobDir)) {
            return;
        }

        $files = glob($jobDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($jobDir);
    }

    /**
     * Calculates how many grid images are needed for a given duration.
     *
     * @param int $durationSeconds Video duration in seconds
     * @param TrickplayConfig $config Trickplay configuration
     *
     * @return int Number of grid images required
     */
    public function calculateGridCount(int $durationSeconds, TrickplayConfig $config): int
    {
        $thumbnailCount = (int) ceil($durationSeconds / $config->interval_seconds);
        return (int) ceil($thumbnailCount / $config->getThumbnailsPerGrid());
    }

    /**
     * Extracts a batch of frames at multiple timestamps using a single FFmpeg command.
     *
     * @param string $inputPath Source video path
     * @param string $outputDir Output directory for frames
     * @param int $startIndex Starting thumbnail index
     * @param array<int> $timestamps Array of timestamps to extract
     * @param TrickplayConfig $config Trickplay configuration
     *
     * @return bool True if extraction succeeded
     */
    private function extractFrameBatch(
        string $inputPath,
        string $outputDir,
        int $startIndex,
        array $timestamps,
        TrickplayConfig $config
    ): bool {
        if (empty($timestamps)) {
            return true;
        }

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s',
            escapeshellarg($this->ffmpeg->getFfmpegPath()),
            escapeshellarg($inputPath)
        );

        foreach ($timestamps as $index => $timestamp) {
            $frameIndex = $startIndex + $index;
            $framePath = $outputDir . '/frame_' . str_pad((string) $frameIndex, 5, '0', STR_PAD_LEFT) . $config->getFileExtension();
            $cmd .= sprintf(
                ' -ss %d -vframes 1 %s',
                escapeshellarg((string) $timestamp),
                escapeshellarg($framePath)
            );
        }

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Assembles individual frames into a grid image using FFmpeg tile filter.
     *
     * @param string $outputDir Output directory
     * @param int $startIndex Starting frame index
     * @param int $count Number of frames to tile
     * @param int $gridIndex Grid image index
     * @param TrickplayConfig $config Trickplay configuration
     *
     * @return bool True if assembly succeeded
     */
    private function assembleGrid(
        string $outputDir,
        int $startIndex,
        int $count,
        int $gridIndex,
        TrickplayConfig $config
    ): bool {
        $gridFile = 'bif_' . str_pad((string) $gridIndex, 2, '0', STR_PAD_LEFT) . $config->getFileExtension();
        $outputPath = $outputDir . '/' . $gridFile;

        $inputs = '';
        for ($i = 0; $i < $count; $i++) {
            $frameIndex = $startIndex + $i;
            $framePath = $outputDir . '/frame_' . str_pad((string) $frameIndex, 5, '0', STR_PAD_LEFT) . $config->getFileExtension();
            if (file_exists($framePath)) {
                $inputs .= ' -i ' . escapeshellarg($framePath);
            }
        }

        if (empty($inputs)) {
            return false;
        }

        $tileLayout = $config->grid_columns . 'x' . $config->grid_rows;
        $qscale = $config->image_format === 'jpeg'
            ? ' -q:v 2'
            : '';

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error%s -filter_complex "tile=%s:margin=2:padding=3"%s %s',
            escapeshellarg($this->ffmpeg->getFfmpegPath()),
            $inputs,
            $tileLayout,
            $qscale,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);

        for ($i = 0; $i < $count; $i++) {
            $frameIndex = $startIndex + $i;
            $framePath = $outputDir . '/frame_' . str_pad((string) $frameIndex, 5, '0', STR_PAD_LEFT) . $config->getFileExtension();
            if (file_exists($framePath)) {
                unlink($framePath);
            }
        }

        return $exitCode === 0;
    }

    /**
     * Creates a TrickplayResult from generation parameters.
     *
     * @param string $jobId Transcode job identifier
     * @param TrickplayConfig $config Trickplay configuration
     * @param array<string, array{offset: int, size: int}> $imageFiles Image file metadata
     * @param string|null $indexXml Path to index XML (optional)
     *
     * @return TrickplayResult
     */
    private function createResult(
        string $jobId,
        TrickplayConfig $config,
        array $imageFiles,
        ?string $indexXml = null
    ): TrickplayResult {
        return new TrickplayResult(
            $jobId,
            $config->interval_seconds,
            $config->grid_columns,
            $config->grid_rows,
            $imageFiles,
            $indexXml ?? '',
        );
    }

    /**
     * Extracts duration in seconds from ffprobe output.
     *
     * @param array<string, mixed> $probeResult Result from FFprobe
     *
     * @return float Duration in seconds
     */
    private function extractDuration(array $probeResult): float
    {
        $format = $probeResult['format'] ?? null;
        if (!is_array($format)) {
            return 0.0;
        }

        $duration = $format['duration'] ?? null;
        if (is_numeric($duration)) {
            return (float) $duration;
        }

        return 0.0;
    }
}
