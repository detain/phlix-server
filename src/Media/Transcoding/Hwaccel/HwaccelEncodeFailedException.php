<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel;

/**
 * Exception thrown when hardware encoding fails.
 *
 * @since 0.11.0
 */
class HwaccelEncodeFailedException extends \RuntimeException
{
    /**
     * @param string $vendor The vendor that failed
     * @param string $encoder The encoder that was being used
     * @param string $reason The reason for failure
     */
    public function __construct(
        string $vendor,
        string $encoder,
        string $reason = '',
    ) {
        $message = sprintf(
            'Hardware encoding failed for vendor "%s" using encoder "%s".',
            $vendor,
            $encoder
        );

        if ($reason !== '') {
            $message .= sprintf(' Reason: %s', $reason);
        }

        parent::__construct($message);
    }
}
