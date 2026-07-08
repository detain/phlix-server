<?php

/**
 * Phlix media server component: Catalog.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Plugins\Catalog;

/**
 * Thrown when a catalog entry's plugin name does not match the required
 * `phlix-plugin-*` pattern.
 *
 * @package Phlix\Plugins\Catalog
 * @since 0.41.0
 */
final class CatalogEntryValidationException extends \RuntimeException
{
    /**
     * @param string $name The invalid plugin name that failed validation.
     */
    public function __construct(
        public readonly string $name,
    ) {
        parent::__construct(
            sprintf(
                'Plugin name "%s" does not match the required pattern "phlix-plugin-…"',
                $name,
            ),
        );
    }
}
