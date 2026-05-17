<?php

declare(strict_types=1);

namespace Phlex\Plugins\Exception;

use RuntimeException;

/**
 * Thrown by {@see \Phlex\Plugins\PluginLoader::enable()} when the entry
 * class cannot be loaded, does not implement
 * {@see \Phlex\Plugins\Contract\LifecycleInterface}, declares an event
 * alias unknown to {@see \Phlex\Plugins\EventNameMap}, or its
 * `onEnable()` callback throws.
 *
 * @package Phlex\Plugins\Exception
 * @since 0.10.0
 */
final class PluginEnableException extends RuntimeException
{
}
