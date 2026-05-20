<?php

declare(strict_types=1);

namespace Phlix\Plugins\Exception;

use RuntimeException;

/**
 * Thrown by {@see \Phlix\Plugins\PluginLoader} (enable/disable/uninstall)
 * and {@see \Phlix\Plugins\Repository\PluginRepository::findByName()}
 * when the caller references a plugin that is not present in the
 * `plugins` table.
 *
 * @package Phlix\Plugins\Exception
 * @since 0.10.0
 */
final class PluginNotFoundException extends RuntimeException
{
}
