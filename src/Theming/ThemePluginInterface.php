<?php

declare(strict_types=1);

namespace Phlex\Theming;

/**
 * Marker interface for ui-theme plugin entry classes.
 *
 * ui-theme plugins are declarative plugins that provide CSS and optionally
 * JS to customize the WebPortal appearance. They do NOT subscribe to
 * runtime events; they are purely asset bundles with a manifest.
 *
 * Plugins that provide a ui-theme should have an entry class implementing
 * this interface, though the ThemeRegistry::registerFromPlugin() method
 * reads the theme data directly from the manifest without instantiation.
 *
 * @package Phlex\Theming
 * @since 0.14.0
 */
interface ThemePluginInterface
{
}
