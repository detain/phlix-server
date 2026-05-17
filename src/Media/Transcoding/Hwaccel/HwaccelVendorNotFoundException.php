<?php

declare(strict_types=1);

namespace Phlex\Media\Transcoding\Hwaccel;

/**
 * Exception thrown when a requested hardware vendor is not available or not found.
 *
 * @since 0.11.0
 */
class HwaccelVendorNotFoundException extends \RuntimeException
{
    /**
     * @param string $vendor The vendor that was not found
     * @param string[] $available_vendors List of available vendors
     */
    public function __construct(
        string $vendor,
        array $available_vendors = [],
    ) {
        $message = sprintf(
            'Hardware acceleration vendor "%s" is not available.',
            $vendor
        );

        if ($available_vendors !== []) {
            $message .= sprintf(
                ' Available vendors: %s.',
                implode(', ', $available_vendors)
            );
        }

        parent::__construct($message);
    }
}
