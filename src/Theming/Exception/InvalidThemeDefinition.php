<?php

/**
 * Phlix media server component: Theming.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Theming\Exception;

use InvalidArgumentException;

/**
 * Raised when a plugin-supplied theme payload fails validation.
 *
 * Every rejection is LOUD and TOTAL: {@see \Phlix\Theming\ThemeSourceRegistry}
 * validates a source's entire theme list before it commits any of it, so a
 * single bad token cannot leave a half-registered source behind. The message
 * always names the offending source, theme id and (where applicable) the
 * exact token key, so an operator can fix the plugin without guessing.
 *
 * This is the security boundary's failure signal — a plugin token value lands
 * in CSS, so "reject and say why" is preferred over "sanitize and continue".
 *
 * @package Phlix\Theming\Exception
 * @since 0.44.0
 */
final class InvalidThemeDefinition extends InvalidArgumentException
{
}
