<?php

declare(strict_types=1);

namespace Phlix\Plugins\Manifest;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Phlix\Shared\Plugin\Manifest;
use Phlix\Shared\Plugin\ManifestValidationError;
use RuntimeException;

/**
 * JSON-Schema validator for a {@see Manifest}.
 *
 * Extracted from the original `Phlix\Plugins\Manifest::validate()`
 * during Step B.3 of `PHLIX_EXPANSION_PLAN.md` so the framework-neutral
 * DTO portion can move to `detain/phlix-shared` while the validator
 * (which depends on `justinrainbow/json-schema` and the bundled schema
 * file at `docs/plugins/manifest.schema.json`) stays here.
 *
 * Run validation by constructing a `ManifestSchema` and calling
 * {@see self::validate()}; soft rules (`unknown_field`) and JSON Schema
 * results are merged into a single error list.
 *
 * @package Phlix\Plugins\Manifest
 * @since 0.11.0
 */
final class ManifestSchema
{
    /**
     * Absolute filesystem path to the JSON Schema shipped with the
     * project. Resolved at call time rather than baked at class-load
     * time so the project root can move under tests.
     */
    private const SCHEMA_RELATIVE_PATH = '/docs/plugins/manifest.schema.json';

    /**
     * Validate the manifest against the bundled JSON Schema and a small
     * set of soft rules (unknown top-level fields). Returns an empty
     * array when the manifest is valid.
     *
     * @return list<ManifestValidationError>
     *
     * @throws RuntimeException When the bundled schema file is missing or unreadable.
     */
    public function validate(Manifest $manifest): array
    {
        $errors = [];

        $schema = self::loadSchema();

        // The validator wants the *decoded* payload as a stdClass-like
        // tree, not an associative array, so round-trip the raw data.
        /** @var mixed $payload */
        $payload = json_decode((string) json_encode($manifest->getRawData()), false);

        $validator = new Validator();
        $validator->validate($payload, $schema, Constraint::CHECK_MODE_NORMAL);

        /**
         * @var list<array{
         *     property: string,
         *     pointer: string,
         *     message: string,
         *     constraint: string|array<string, mixed>
         * }> $rawErrors
         */
        $rawErrors = $validator->getErrors();
        foreach ($rawErrors as $rawError) {
            $errors[] = self::mapSchemaError($rawError);
        }

        foreach ($manifest->getUnknownFields() as $unknown) {
            $errors[] = new ManifestValidationError(
                field: $unknown,
                code: 'unknown_field',
                message: sprintf('Unknown top-level field "%s" — not part of the manifest schema.', $unknown),
            );
        }

        return $errors;
    }

    /**
     * Load and decode the bundled JSON Schema. The schema ships with the
     * source tree, so a missing or malformed file indicates a broken
     * install — fail loudly rather than turning it into a manifest
     * validation error.
     *
     * @throws RuntimeException When the schema file is missing or unreadable.
     */
    private static function loadSchema(): mixed
    {
        $schemaPath = self::resolveSchemaPath();
        $schemaSource = @file_get_contents($schemaPath);
        if ($schemaSource === false) {
            throw new RuntimeException(
                sprintf('Manifest schema not found at %s — Phlix install is broken.', $schemaPath),
            );
        }

        return json_decode($schemaSource, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return string Absolute path to the bundled schema file.
     */
    private static function resolveSchemaPath(): string
    {
        // src/Plugins/Manifest/ManifestSchema.php -> project root is three dirs up.
        $root = dirname(__DIR__, 3);

        return $root . self::SCHEMA_RELATIVE_PATH;
    }

    /**
     * Translate a single error entry produced by
     * {@see Validator::getErrors()} into our DTO shape.
     *
     * @param array{
     *     property: string,
     *     pointer: string,
     *     message: string,
     *     constraint: string|array<string, mixed>
     * } $rawError
     */
    private static function mapSchemaError(array $rawError): ManifestValidationError
    {
        $constraint = $rawError['constraint'];
        if (is_array($constraint)) {
            /** @var mixed $name */
            $name = $constraint['name'] ?? '';
            $code = is_string($name) ? $name : '';
        } else {
            $code = $constraint;
        }

        if ($code === '') {
            $code = 'invalid';
        }

        $field = (string) $rawError['property'];
        if ($field === '' && $rawError['pointer'] !== '') {
            $field = trim(str_replace('/', '.', $rawError['pointer']), '.');
        }

        return new ManifestValidationError(
            field: $field,
            code: $code,
            message: (string) $rawError['message'],
        );
    }
}
