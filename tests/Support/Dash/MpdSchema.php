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
 * ⚠ **And it must never touch the network.** MEASURED: `xlink.xsd` ships an
 * `xs:import` of `http://www.w3.org/2001/xml.xsd`, and libxml resolves that by
 * HTTP. A mutation run made enough validations for w3.org to answer **HTTP 429
 * Too Many Requests**, at which point `schemaValidate()` started reporting
 * *every* document — including known-good ones — as invalid. So `xml.xsd` is
 * vendored too, that one `schemaLocation` is repointed at it (the only edit
 * made to any of the three files), and {@see self::errors()} additionally
 * installs an external-entity loader that resolves ONLY the vendored files and
 * refuses everything else. A refusal surfaces as a validation error, never as a
 * pass.
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
        self::jailEntityLoader();

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
            libxml_set_external_entity_loader(null);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Restricts libxml's schema resolution to the three vendored files.
     *
     * Anything else — above all the `http://www.w3.org/2001/xml.xsd` that
     * `xlink.xsd` names — is refused by returning null, which libxml turns into
     * a "failed to load external entity" error and therefore into a FAILED
     * validation. There is no path by which an unresolvable dependency becomes a
     * pass, and no path by which a run depends on w3.org being reachable or
     * un-throttled.
     */
    private static function jailEntityLoader(): void
    {
        $dir = __DIR__;
        libxml_set_external_entity_loader(
            /**
             * @param array{directory?: string|null} $context
             */
            static function (?string $publicId, string $systemId, array $context) use ($dir): ?string {
                $local = $dir . '/' . basename(parse_url($systemId, PHP_URL_PATH) ?: $systemId);

                return is_file($local) ? $local : null;
            }
        );
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
