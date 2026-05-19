<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Tuners\HdHomeRun;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating HDHomeRun tuner driver instances.
 *
 * @since 0.12.0
 */
final class HdHomeRunTunerDriverFactory
{
    /**
     * Build a fully-configured HDHomeRunTunerDriver instance.
     *
     * The factory wires a default {@see HdHomeRunApiClient} pointed at
     * loopback; per-device API clients are an exercise for callers that
     * need to address multiple physical tuners.
     *
     * @param LoggerInterface|null $logger Optional logger (defaults to LiveTV channel logger)
     * @return HdHomeRunTunerDriver Configured tuner driver instance
     */
    public static function build(?LoggerInterface $logger = null): HdHomeRunTunerDriver
    {
        $logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);

        $discovery = new HdHomeRunDiscovery($logger);
        $apiClient = new HdHomeRunApiClient('http://127.0.0.1', $logger);

        return new HdHomeRunTunerDriver($discovery, $apiClient, $logger);
    }
}
