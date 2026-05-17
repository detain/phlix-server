<?php

declare(strict_types=1);

namespace Phlex\Plugins;

use Phlex\Plugins\Exception\InvalidManifestException;
use Phlex\Plugins\Manifest\ManifestSchema;
use Phlex\Shared\Plugin\ManifestValidationError;
use RuntimeException;

/**
 * Backwards-compatibility wrapper preserving the legacy
 * `Phlex\Plugins\Manifest` FQCN for one release.
 *
 * The DTO surface (readonly properties, `fromJson`, `fromArray`,
 * `toArray`, `manifestType`) has moved to
 * {@see \Phlex\Shared\Plugin\Manifest} in `detain/phlex-shared`. The
 * `validate()` method has been extracted to
 * {@see \Phlex\Plugins\Manifest\ManifestSchema} because it depends on the
 * JSON Schema bundled with `phlex-server`.
 *
 * Existing consumers that called `$manifest->validate()` still work via
 * this wrapper; new code should prefer the explicit collaborator:
 *
 *     $errors = (new ManifestSchema())->validate($manifest);
 *
 * @deprecated since 0.11.0 — use \Phlex\Shared\Plugin\Manifest for the
 *             value object and \Phlex\Plugins\Manifest\ManifestSchema
 *             for validation. This wrapper will be removed in 0.12.0.
 * @see \Phlex\Shared\Plugin\Manifest
 * @see \Phlex\Plugins\Manifest\ManifestSchema
 *
 * @package Phlex\Plugins
 * @since 0.10.0
 */
final class Manifest extends \Phlex\Shared\Plugin\Manifest
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
