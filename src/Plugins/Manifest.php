<?php

declare(strict_types=1);

namespace Phlex\Plugins;

use JsonException;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Phlex\Plugins\Exception\InvalidManifestException;

/**
 * Immutable value object representing a parsed `plugin.json`.
 *
 * Construction is split into two phases:
 *
 *  1. {@see self::fromJson()} / {@see self::fromArray()} — cheap parsing
 *     that throws {@see InvalidManifestException} only on hard
 *     structural problems (malformed JSON, non-object root). All other
 *     issues, including unknown {@see ManifestType} values and missing
 *     required fields, are deferred so the caller sees every problem
 *     at once via {@see self::validate()}.
 *  2. {@see self::validate()} — runs the JSON Schema at
 *     `docs/plugins/manifest.schema.json` against the original input
 *     and returns an array of {@see ManifestValidationError} objects.
 *
 * Unknown top-level fields are accepted at construction time but
 * surfaced as `unknown_field` errors from {@see self::validate()}.
 *
 * See `docs/plugins/manifest.md` for the human-readable spec.
 *
 * @package Phlex\Plugins
 * @since 0.10.0
 */
final class Manifest
{
    /**
     * Top-level keys recognised by the schema. Anything else is reported
     * as `unknown_field` by {@see self::validate()}.
     *
     * @var list<string>
     */
    private const KNOWN_TOP_LEVEL_KEYS = [
        'name',
        'version',
        'phlex_min_server_version',
        'type',
        'entry',
        'events',
        'settings',
        'signature',
    ];

    /**
     * Absolute filesystem path to the JSON Schema shipped with the
     * project. Resolved once at construction-site usage rather than
     * baked at class-load time so the project root can move under tests.
     */
    private const SCHEMA_RELATIVE_PATH = '/docs/plugins/manifest.schema.json';

    /**
     * @param string $name Plugin identifier, kebab-case, prefixed `phlex-plugin-`.
     * @param string $version Plugin semver.
     * @param string $phlexMinServerVersion Minimum Phlex server semver.
     * @param string $type Raw type string. Resolve via {@see self::manifestType()}.
     * @param string $entry Fully-qualified entry-class name.
     * @param list<string> $events Manifest event aliases.
     * @param array<string, array{type: string, required?: bool, secret?: bool, default?: mixed}> $settings
     *     Settings schema keyed by setting name.
     * @param string|null $signature `sha256:<hex>` signature or null when unsigned.
     * @param array<string, mixed> $rawData Original decoded array, retained for {@see self::validate()}.
     * @param list<string> $unknownFields Top-level keys not in {@see self::KNOWN_TOP_LEVEL_KEYS}.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $phlexMinServerVersion,
        public readonly string $type,
        public readonly string $entry,
        public readonly array $events,
        public readonly array $settings,
        public readonly ?string $signature,
        private readonly array $rawData,
        private readonly array $unknownFields,
    ) {
    }

    /**
     * Parse a JSON-encoded manifest. Throws when the payload cannot
     * become a {@see Manifest} at all.
     *
     * @throws InvalidManifestException When the JSON is malformed or the decoded root is not an object.
     */
    public static function fromJson(string $json): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidManifestException(
                'Manifest is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidManifestException(
                'Manifest root must be a JSON object, got ' . gettype($decoded) . '.',
            );
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }

    /**
     * Build a {@see Manifest} from an already-decoded array. Performs
     * the minimum coercion needed to populate the readonly properties;
     * full schema validation is opt-in via {@see self::validate()}.
     *
     * @param array<string, mixed> $data Decoded manifest payload.
     */
    public static function fromArray(array $data): self
    {
        $rawType = is_string($data['type'] ?? null) ? (string) $data['type'] : '';

        $name = is_string($data['name'] ?? null) ? (string) $data['name'] : '';
        $version = is_string($data['version'] ?? null) ? (string) $data['version'] : '';
        $minVersion = is_string($data['phlex_min_server_version'] ?? null)
            ? (string) $data['phlex_min_server_version']
            : '';
        $entry = is_string($data['entry'] ?? null) ? (string) $data['entry'] : '';

        $events = [];
        if (isset($data['events']) && is_array($data['events'])) {
            foreach ($data['events'] as $event) {
                if (is_string($event)) {
                    $events[] = $event;
                }
            }
        }

        $settings = [];
        if (isset($data['settings']) && is_array($data['settings'])) {
            foreach ($data['settings'] as $key => $value) {
                if (is_string($key) && is_array($value)) {
                    /** @var array{type: string, required?: bool, secret?: bool, default?: mixed} $value */
                    $settings[$key] = $value;
                }
            }
        }

        $signature = null;
        if (array_key_exists('signature', $data) && is_string($data['signature'])) {
            $signature = $data['signature'];
        }

        $unknownFields = [];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KNOWN_TOP_LEVEL_KEYS, true)) {
                $unknownFields[] = (string) $key;
            }
        }

        return new self(
            name: $name,
            version: $version,
            phlexMinServerVersion: $minVersion,
            type: $rawType,
            entry: $entry,
            events: $events,
            settings: $settings,
            signature: $signature,
            rawData: $data,
            unknownFields: $unknownFields,
        );
    }

    /**
     * Resolve the typed {@see ManifestType} enum for this manifest, or
     * null when the raw `type` string is not one of the known cases.
     * Callers that need a guaranteed-valid type should run
     * {@see self::validate()} first.
     */
    public function manifestType(): ?ManifestType
    {
        if ($this->type === '') {
            return null;
        }

        return ManifestType::tryFrom($this->type);
    }

    /**
     * Serialise the manifest back to its original decoded shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->rawData;
    }

    /**
     * Validate the manifest against the bundled JSON Schema and a small
     * set of soft rules (unknown top-level fields). Returns an empty
     * array when the manifest is valid.
     *
     * @return list<ManifestValidationError>
     */
    public function validate(): array
    {
        $errors = [];

        $schema = self::loadSchema();

        // The validator wants the *decoded* payload as a stdClass-like
        // tree, not an associative array, so round-trip the raw data.
        /** @var mixed $payload */
        $payload = json_decode((string) json_encode($this->rawData), false);

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

        foreach ($this->unknownFields as $unknown) {
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
     * @throws \RuntimeException When the schema file is missing or unreadable.
     */
    private static function loadSchema(): mixed
    {
        $schemaPath = self::resolveSchemaPath();
        $schemaSource = @file_get_contents($schemaPath);
        if ($schemaSource === false) {
            throw new \RuntimeException(
                sprintf('Manifest schema not found at %s — Phlex install is broken.', $schemaPath),
            );
        }

        return json_decode($schemaSource, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return string Absolute path to the bundled schema file.
     */
    private static function resolveSchemaPath(): string
    {
        // src/Plugins/Manifest.php -> project root is two dirs up.
        $root = dirname(__DIR__, 2);

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
