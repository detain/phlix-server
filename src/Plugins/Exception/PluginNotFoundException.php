<?php

declare(strict_types=1);

namespace Phlex\Plugins\Exception;

use RuntimeException;

/**
 * Thrown by {@see \Phlex\Plugins\PluginLoader} (enable/disable/uninstall)
 * and {@see \Phlex\Plugins\Repository\PluginRepository::findByName()}
 * when the caller references a plugin that is not present in the
 * `plugins` table.
 *
 * @package Phlex\Plugins\Exception
 * @since 0.10.0
 */
final class PluginNotFoundException extends RuntimeException
{
}
