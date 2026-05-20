<?php

declare(strict_types=1);

namespace Phlix\Plugins\Exception;

use Phlix\Shared\Plugin\ManifestValidationError;
use RuntimeException;
use Throwable;

/**
 * Thrown by {@see \Phlix\Plugins\PluginLoader::install()} and
 * {@see \Phlix\Plugins\PluginLoader::installFromDirectory()} when the
 * plugin source cannot be installed for any reason — invalid manifest,
 * unsatisfied `phlix_min_server_version`, signature mismatch, vendor
 * `composer install` failure, IO errors while extracting the archive.
 *
 * When the failure originates in schema validation, the list of
 * {@see ManifestValidationError} instances is attached via
 * {@see self::validationErrors()} so callers can surface every problem
 * at once rather than the single first failure.
 *
 * @package Phlix\Plugins\Exception
 * @since 0.10.0
 */
final class PluginInstallException extends RuntimeException
{
    /**
     * @param string                          $message           Human-readable summary.
     * @param list<ManifestValidationError>   $validationErrors  Optional list of
     *        manifest validation failures that caused this exception.
     * @param int                             $code              Inherited exception code.
     * @param Throwable|null                  $previous          Inherited cause.
     */
    public function __construct(
        string $message,
        private readonly array $validationErrors = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Manifest validation errors that triggered the install failure.
     * Empty when the failure was unrelated to schema validation.
     *
     * @return list<ManifestValidationError>
     *
     * @since 0.10.0
     */
    public function validationErrors(): array
    {
        return $this->validationErrors;
    }
}
