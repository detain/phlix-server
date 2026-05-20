<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\Provider;

use Psr\Log\LoggerInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;

/**
 * MusicMetadataProviderTrait provides shared logic for music metadata providers.
 *
 * This trait handles rate-limiting enforcement (required by MusicBrainz) and
 * provides the required MusicBrainz user-agent headers.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @since 0.13.0
 */
trait MusicMetadataProviderTrait
{
    /** @var float Timestamp of last request for rate limiting */
    private float $lastRequestTime = 0.0;

    /** @var \Phlix\Common\Logger\StructuredLogger|null Structured logger instance */
    private ?\Phlix\Common\Logger\StructuredLogger $logger = null;

    /**
     * Apply rate limiting delay before making a request.
     *
     * MusicBrainz requires at least 1 request per second. This method
     * will sleep if necessary to enforce the rate limit.
     *
     * @param float $seconds Minimum time between requests in seconds
     * @return void
     */
    protected function rateLimit(float $seconds): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastRequestTime;

        if ($elapsed < $seconds) {
            $sleepTime = ($seconds - $elapsed) * 1000000;
            usleep((int) $sleepTime);
        }

        $this->lastRequestTime = microtime(true);
    }

    /**
     * Get the required headers for MusicBrainz API requests.
     *
     * MusicBrainz requires a User-Agent header with contact information
     * and enforces rate limiting. This method returns the required
     * headers for compliance.
     *
     * @param string $userAgent User-agent string (e.g., 'Phlix/1.0 (https://phlix.media)')
     * @return array<string, string> Headers array with user-agent and content-type
     */
    protected function mbHeaders(string $userAgent): array
    {
        return [
            'User-Agent' => $userAgent,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Get the logger instance.
     *
     * @return \Phlix\Common\Logger\StructuredLogger
     */
    protected function getLogger(): \Phlix\Common\Logger\StructuredLogger
    {
        if ($this->logger === null) {
            $this->logger = LoggerFactory::get(LogChannels::MEDIA);
        }
        return $this->logger;
    }

    /**
     * Set logger instance (for testing).
     *
     * @param LoggerInterface|null $logger
     * @return void
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger ? LoggerFactory::get(LogChannels::MEDIA) : null;
    }
}
