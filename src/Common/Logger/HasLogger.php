<?php

declare(strict_types=1);

namespace Phlex\Common\Logger;

/**
 * Trait for classes that need logging capability.
 *
 * Provides a lazy-initialised {@see StructuredLogger} keyed to the
 * {@see LogChannels::APPLICATION} channel by default. Callers that want
 * a different channel should inject a configured logger via
 * {@see setLogger()} instead of relying on the fallback.
 *
 * @package Phlex\Common\Logger
 * @since 0.1.0
 */
trait HasLogger
{
    private ?StructuredLogger $logger = null;

    protected function setLogger(StructuredLogger $logger): void
    {
        $this->logger = $logger;
    }

    protected function getLogger(): StructuredLogger
    {
        if ($this->logger === null) {
            $this->logger = LoggerFactory::get(LogChannels::APPLICATION);
        }
        return $this->logger;
    }
}
