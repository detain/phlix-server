<?php

/**
 * Phlix media server component: Exception.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Exception;

use RuntimeException;

/**
 * Thrown by {@see \Phlix\Plugins\PluginLoader::enable()} when the entry
 * class cannot be loaded, does not implement
 * {@see \Phlix\Plugins\Contract\LifecycleInterface}, declares an event
 * alias unknown to {@see \Phlix\Plugins\EventNameMap}, or its
 * `onEnable()` callback throws.
 *
 * @package Phlix\Plugins\Exception
 * @since 0.10.0
 */
final class PluginEnableException extends RuntimeException
{
}
