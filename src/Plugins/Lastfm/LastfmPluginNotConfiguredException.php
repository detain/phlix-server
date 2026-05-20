<?php

declare(strict_types=1);

namespace Phlix\Plugins\Lastfm;

/**
 * Thrown when the Last.fm plugin is not fully configured (e.g. api_key,
 * api_secret, or session_key is empty).
 *
 * @package Phlix\Plugins\Lastfm
 * @since 0.15.0
 */
final class LastfmPluginNotConfiguredException extends \RuntimeException
{
    public function __construct(
        string $message = 'Last.fm plugin is not configured: '
        . 'api_key, api_secret, and session_key are required.'
    ) {
        parent::__construct($message);
    }
}
