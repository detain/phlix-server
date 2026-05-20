<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Dvbt;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating DVB-T tuner driver instances.
 *
 * Builds fully-configured DvbtTunerDriver instances reading
 * configuration from config/livetv.php.
 *
 * @since 0.12.0
 */
final class DvbtTunerDriverFactory
{
    /**
     * Build a fully-configured DvbtTunerDriver instance.
     *
     * Reads dvbt configuration from config/livetv.php including
     * paths to ffmpeg and dvbv5-zap binaries.
     *
     * @param array<string, mixed> $config Livetv config array (from config/livetv.php)
     * @param LoggerInterface|null $logger Optional logger (defaults to LiveTV channel logger)
     * @return DvbtTunerDriver Configured tuner driver instance
     */
    public static function build(array $config, ?LoggerInterface $logger = null): DvbtTunerDriver
    {
        $logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);

        /** @var array<string, mixed> $dvbtConfig */
        $dvbtConfig = $config['dvbt'] ?? [];
        $enabled = (bool) ($dvbtConfig['enabled'] ?? true);

        if (!$enabled) {
            throw new \RuntimeException('DVB-T tuner driver is disabled in configuration');
        }

        $ffmpegPath = is_string($dvbtConfig['ffmpeg_path'] ?? null)
            ? $dvbtConfig['ffmpeg_path']
            : '/usr/bin/ffmpeg';
        $dvbv5ZapPath = is_string($dvbtConfig['dvbv5_zap_path'] ?? null)
            ? $dvbtConfig['dvbv5_zap_path']
            : '/usr/bin/dvbv5-zap';

        $scanner = new DvbtDeviceScanner($logger);
        $signalEngine = new DvbtSignalEngine($ffmpegPath, $dvbv5ZapPath, $logger);

        return new DvbtTunerDriver($scanner, $signalEngine, $logger);
    }

    /**
     * Build a driver instance without configuration checks.
     *
     * Use this for testing or when configuration is not available.
     *
     * @param string $ffmpegPath Path to FFmpeg binary
     * @param string $dvbv5ZapPath Path to dvbv5-zap binary
     * @param LoggerInterface|null $logger Optional logger
     * @return DvbtTunerDriver Configured tuner driver instance
     */
    public static function buildDefault(
        string $ffmpegPath = '/usr/bin/ffmpeg',
        string $dvbv5ZapPath = '/usr/bin/dvbv5-zap',
        ?LoggerInterface $logger = null
    ): DvbtTunerDriver {
        $logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);

        $scanner = new DvbtDeviceScanner($logger);
        $signalEngine = new DvbtSignalEngine($ffmpegPath, $dvbv5ZapPath, $logger);

        return new DvbtTunerDriver($scanner, $signalEngine, $logger);
    }
}
