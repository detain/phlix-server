<?php

declare(strict_types=1);

namespace Phlex\Plugins\Scrobbler\Trakt;

/**
 * Marker interface for Trakt scrobbler plugin entry classes.
 *
 * This interface identifies plugins that provide Trakt.tv integration.
 * Any class implementing this interface is recognized as a Trakt plugin
 * by the plugin loader.
 *
 * @package Phlex\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
interface TraktPluginInterface
{
}
