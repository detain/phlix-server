<?php

/**
 * Phlix media server component: Catalog.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Plugins\Catalog;

use RuntimeException;

/**
 * Thrown when a catalog source cannot be fetched or parsed.
 *
 * {@see PluginCatalogService::fetchCatalog()} raises this for network
 * failures, non-200 responses, malformed JSON, or a JSON document that does
 * not match the catalog shape. The {@see \Phlix\Server\Http\Controllers\PluginCatalogController}
 * catches it per-source and reports it in the `errors` array rather than
 * failing the whole aggregate — one unreachable catalog must not blank the
 * admin Plugins page.
 *
 * @package Phlix\Plugins\Catalog
 * @since 0.33.0
 */
final class CatalogFetchException extends RuntimeException
{
    /**
     * @param string $source The catalog source URL that failed (as supplied
     *                       by the operator, before resolution).
     * @param string $reason Human-readable failure reason.
     */
    public function __construct(
        public readonly string $source,
        string $reason,
    ) {
        parent::__construct($reason);
    }
}
