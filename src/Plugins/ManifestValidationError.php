<?php

declare(strict_types=1);

namespace Phlex\Plugins;

/**
 * Immutable DTO describing a single validation problem found while
 * checking a parsed {@see Manifest} against the JSON Schema at
 * `docs/plugins/manifest.schema.json` (or against any soft rule, such
 * as "unknown field", that the schema cannot express).
 *
 * `field` uses JSON Pointer-style dotted paths (e.g., `settings.api_key.type`).
 * `code` is a short machine identifier (`required`, `pattern`, `enum`,
 * `type`, `unknown_field`, …). `message` is the human-readable
 * description suitable for surfacing in admin UI tooltips.
 *
 * @package Phlex\Plugins
 * @since 0.10.0
 */
final class ManifestValidationError
{
    /**
     * @param string $field   Dotted path to the offending field, or '' for root.
     * @param string $code    Stable short identifier (`required`, `enum`, `pattern`, `type`, `unknown_field`, ...).
     * @param string $message Human-readable description.
     */
    public function __construct(
        public readonly string $field,
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
