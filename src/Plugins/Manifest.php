<?php

declare(strict_types=1);

namespace Phlix\Plugins;

use Phlix\Plugins\Exception\InvalidManifestException;
use Phlix\Plugins\Manifest\ManifestSchema;
use Phlix\Shared\Plugin\ManifestValidationError;
use RuntimeException;

/**
 * Backwards-compatibility wrapper preserving the legacy
 * `Phlix\Plugins\Manifest` FQCN for one release.
 *
 * The DTO surface (readonly properties, `fromJson`, `fromArray`,
 * `toArray`, `manifestType`) has moved to
 * {@see \Phlix\Shared\Plugin\Manifest} in `detain/phlix-shared`. The
 * `validate()` method has been extracted to
 * {@see \Phlix\Plugins\Manifest\ManifestSchema} because it depends on the
 * JSON Schema bundled with `phlix-server`.
 *
 * Existing consumers that called `$manifest->validate()` still work via
 * this wrapper; new code should prefer the explicit collaborator:
 *
 *     $errors = (new ManifestSchema())->validate($manifest);
 *
 * @deprecated since 0.11.0 — use \Phlix\Shared\Plugin\Manifest for the
 *             value object and \Phlix\Plugins\Manifest\ManifestSchema
 *             for validation. This wrapper will be removed in 0.12.0.
 * @see \Phlix\Shared\Plugin\Manifest
 * @see \Phlix\Plugins\Manifest\ManifestSchema
 *
 * @package Phlix\Plugins
 * @since 0.10.0
 */
final class Manifest extends \Phlix\Shared\Plugin\Manifest
{
    /**
     * Parse a JSON manifest, translating the shared package's
     * {@see RuntimeException} into the legacy
     * {@see InvalidManifestException} so existing catchers keep
     * working.
     *
     * @throws InvalidManifestException When the JSON is malformed or the root is not an object.
     */
    public static function fromJson(string $json): static
    {
        try {
            return parent::fromJson($json);
        } catch (RuntimeException $e) {
            if ($e instanceof InvalidManifestException) {
                throw $e;
            }
            throw new InvalidManifestException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate the manifest using the bundled `manifest.schema.json` and
     * unknown-field soft rules.
     *
     * @return list<ManifestValidationError>
     */
    public function validate(): array
    {
        return (new ManifestSchema())->validate($this);
    }
}
