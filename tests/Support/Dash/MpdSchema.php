<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Dash;

use DOMDocument;
use RuntimeException;

/**
 * Validates an MPD against the real MPEG-DASH `DASH-MPD.xsd` (see README.md).
 *
 * ⚠ **The whole value of this class is that it can say NO.** `libxml` with
 * `libxml_use_internal_errors(true)` accumulates errors that nobody is obliged
 * to read, so a validator that ignores the error list — or that treats a schema
 * it failed to LOAD as a pass — reports every document as valid. Two rules
 * follow, and both are enforced below:
 *
 *  1. {@see self::errors()} returns `[]` **only** when `schemaValidate()`
 *     itself returned true. Every other path returns a non-empty list, and if
 *     libxml recorded nothing a synthetic entry is manufactured so the caller
 *     can never receive an empty "valid" answer by accident.
 *  2. A missing schema file THROWS. It does not skip and it does not pass.
 *
 * `Phlix\Tests\Unit\Media\Transcoding\TranscodeManagerVodMpdTest::test_the_schema_validator_rejects_a_malformed_manifest()`
 * is the positive control for rule 1.
 */
final class MpdSchema
{
    /** Absolute path to the vendored DASH MPD schema. */
    public static function path(): string
    {
        return __DIR__ . '/DASH-MPD.xsd';
    }

    /**
     * Schema-validation errors for an MPD document.
     *
     * @param string $xml The manifest source.
     *
     * @return list<string> Empty ONLY when the document validated; human-readable messages otherwise.
     *
     * @throws RuntimeException When the vendored schema is missing.
     */
    public static function errors(string $xml): array
    {
        $xsd = self::path();
        if (!is_file($xsd)) {
            throw new RuntimeException("The vendored DASH MPD schema is missing: {$xsd}");
        }

        if (trim($xml) === '') {
            // DOMDocument::loadXML() raises a ValueError on an empty source
            // rather than returning false, and a thrown error is NOT a verdict.
            return ['the manifest is empty'];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $doc = new DOMDocument();
            if ($doc->loadXML($xml) === false) {
                return self::drain('the manifest is not well-formed XML');
            }

            if ($doc->schemaValidate($xsd) === true) {
                // The one and only path that yields "valid".
                libxml_clear_errors();
                return [];
            }

            return self::drain('schemaValidate() returned false');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Collects libxml's accumulated errors, guaranteeing a non-empty result.
     *
     * @param string $fallback Message to use when libxml recorded nothing at all.
     *
     * @return non-empty-list<string>
     */
    private static function drain(string $fallback): array
    {
        $messages = [];
        foreach (libxml_get_errors() as $error) {
            $messages[] = trim($error->message) . ' (line ' . $error->line . ')';
        }

        return $messages === [] ? [$fallback . ' but libxml recorded no error'] : $messages;
    }
}
