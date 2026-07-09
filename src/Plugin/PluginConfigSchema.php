<?php

/**
 * Phlix media server component: Plugin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Plugin;

use InvalidArgumentException;

/**
 * Validates a configuration array against a defined schema.
 *
 * Schema format:
 * ```
 * [
 *     'key' => [
 *         'type'     => 'string|int|bool|array',
 *         'required' => bool,
 *         'default'  => mixed,
 *     ],
 *     ...
 * ]
 * ```
 *
 * ## Early Exit
 * - Returns immediately with validated config when no schema keys exist
 * - Skips validation entirely when schema is empty
 *
 * ## Parse Don't Validate
 * - Values are cast to their declared types during validation
 * - Unknown keys in config are ignored (not rejected) to allow forward compatibility
 *
 * ## Fail Fast
 * - Throws InvalidArgumentException for missing required keys
 * - Throws InvalidArgumentException for values that cannot be cast to the declared type
 *
 * @package Phlix\Plugin
 * @since 0.15.0
 */
final class PluginConfigSchema
{
    /**
     * Supported type identifiers.
     *
     * @var array<string, true>
     */
    private const SUPPORTED_TYPES = [
        'string' => true,
        'int'    => true,
        'bool'   => true,
        'array'  => true,
    ];

    /**
     * Validates the given configuration against the schema.
     *
     * @param array<string, array{
     *     type?: string,
     *     required?: bool,
     *     default?: mixed
     * }> $schema  Schema definition keyed by setting name.
     * @param array<string, mixed> $config Configuration to validate.
     *
     * @return array<string, mixed> Validated and type-cast configuration.
     *
     * @throws InvalidArgumentException When a required key is missing or
     *         a value cannot be cast to the declared type.
     *
     * @since 0.15.0
     */
    public function validateConfig(array $schema, array $config): array
    {
        if ($schema === []) {
            return $config;
        }

        $validated = [];

        foreach ($schema as $key => $definition) {
            $type = $definition['type'] ?? 'string';
            $required = $definition['required'] ?? false;
            $default = $definition['default'] ?? null;

            $this->assertValidType($key, $type);

            if (!array_key_exists($key, $config)) {
                if ($required) {
                    throw new InvalidArgumentException(sprintf(
                        'Missing required config key "%s"',
                        $key
                    ));
                }
                $validated[$key] = $default;
                continue;
            }

            $value = $config[$key];
            $validated[$key] = $this->castValue($key, $value, $type);
        }

        return $validated;
    }

    /**
     * Assert that the given type identifier is supported.
     *
     * @param string $key  Config key name (for error messages).
     * @param string $type Type identifier from the schema.
     *
     * @throws InvalidArgumentException When the type is not supported.
     *
     * @since 0.15.0
     */
    private function assertValidType(string $key, string $type): void
    {
        if (isset(self::SUPPORTED_TYPES[$type])) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Invalid type "%s" for config key "%s". Supported types: %s',
            $type,
            $key,
            implode(', ', array_keys(self::SUPPORTED_TYPES))
        ));
    }

    /**
     * Cast a value to the declared type.
     *
     * @param string $key   Config key name (for error messages).
     * @param mixed  $value Raw value from configuration.
     * @param string $type  Target type identifier.
     *
     * @return mixed The cast value.
     *
     * @throws InvalidArgumentException When the value cannot be cast.
     *
     * @since 0.15.0
     */
    private function castValue(string $key, mixed $value, string $type): mixed
    {
        return match ($type) {
            'string' => $this->castToString($key, $value),
            'int'    => $this->castToInt($key, $value),
            'bool'   => $this->castToBool($key, $value),
            'array'  => $this->castToArray($key, $value),
            default  => throw new InvalidArgumentException(sprintf(
                'Unsupported type "%s" for config key "%s"',
                $type,
                $key
            )),
        };
    }

    /**
     * Cast a value to string.
     *
     * @param string $key   Config key name.
     * @param mixed  $value  Value to cast.
     *
     * @return string
     *
     * @throws InvalidArgumentException When cast is not possible.
     */
    private function castToString(string $key, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        throw new InvalidArgumentException(sprintf(
            'Config key "%s" expected string, got %s',
            $key,
            gettype($value)
        ));
    }

    /**
     * Cast a value to int.
     *
     * @param string $key   Config key name.
     * @param mixed  $value  Value to cast.
     *
     * @return int
     *
     * @throws InvalidArgumentException When cast is not possible.
     */
    private function castToInt(string $key, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        throw new InvalidArgumentException(sprintf(
            'Config key "%s" expected int, got %s',
            $key,
            gettype($value)
        ));
    }

    /**
     * Cast a value to bool.
     *
     * @param string $key   Config key name.
     * @param mixed  $value  Value to cast.
     *
     * @return bool
     *
     * @throws InvalidArgumentException When cast is not possible.
     */
    private function castToBool(string $key, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }
        throw new InvalidArgumentException(sprintf(
            'Config key "%s" expected bool, got %s',
            $key,
            gettype($value)
        ));
    }

    /**
     * Cast a value to array.
     *
     * @param string $key   Config key name.
     * @param mixed  $value  Value to cast.
     *
     * @return array<mixed>
     *
     * @throws InvalidArgumentException When cast is not possible.
     */
    private function castToArray(string $key, mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        throw new InvalidArgumentException(sprintf(
            'Config key "%s" expected array, got %s',
            $key,
            gettype($value)
        ));
    }
}
