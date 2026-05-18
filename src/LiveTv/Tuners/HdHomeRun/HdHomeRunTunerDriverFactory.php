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
     * @param LoggerInterface|null $logger Optional logger (defaults to LiveTV channel logger)
     * @return HdHomeRunTunerDriver Configured tuner driver instance
     */
    public static function build(?LoggerInterface $logger = null): HdHomeRunTunerDriver
    {
        $logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);

        $discovery = new HdHomeRunDiscovery($logger);

        return new HdHomeRunTunerDriver($discovery, $logger);
    }
}
