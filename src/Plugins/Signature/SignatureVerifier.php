<?php

declare(strict_types=1);

namespace Phlix\Plugins\Signature;

use Phlix\Plugins\Manifest;

/**
 * Verifies the optional `signature` field of a {@see Manifest} against
 * a trusted-key allowlist AND against the on-disk content of the
 * staged plugin.
 *
 * **Scope for A.4 (per `PHLIX_EXPANSION_PLAN.md` §10 risk #4):**
 * the verifier is built but the trusted-key allowlist defaults to
 * empty. Plugins without a signature are accepted with a warning (the
 * loader logs on the `plugins` channel). Plugins WITH a signature
 * must:
 *
 *  1. Pass a content-integrity check — the digest in `signature` is
 *     compared byte-for-byte against `hash_file('sha256', plugin.json)`.
 *     A mismatch ALWAYS returns `invalid`, regardless of allowlist
 *     contents, because it indicates the manifest on disk was tampered
 *     with after signing.
 *  2. If the operator configured a non-empty allowlist, the signature
 *     must also be on it; otherwise the verifier returns `invalid`.
 *  3. If the allowlist is empty and `requireSignature` is false, a
 *     content-matching signature is treated as `valid` (optimistic
 *     accept).
 *  4. If `requireSignature` is true with an empty allowlist, every
 *     signature is rejected — the operator has explicitly opted into
 *     trusted-only installs.
 *
 * Signatures are `sha256:<64-hex>` strings — the JSON Schema enforces
 * the format. The "content hash" we compare against is computed over
 * the bytes of `plugin.json` (the manifest the user actually signed).
 *
 * @package Phlix\Plugins\Signature
 * @since 0.10.0
 */
class SignatureVerifier
{
    public const RESULT_VALID = 'valid';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_UNSIGNED = 'unsigned';

    /**
     * Prefix expected on every signature value, e.g. `sha256:abc...`.
     */
    public const SIGNATURE_PREFIX = 'sha256:';

    /**
     * @param list<string> $trustedSignatures Allowlist of trusted `sha256:<hex>` digests.
     * @param bool $requireSignature When true, unsigned plugins return RESULT_INVALID
     *                               and empty-allowlist + signed always returns INVALID.
     */
    public function __construct(
        private readonly array $trustedSignatures = [],
        private readonly bool $requireSignature = false,
    ) {
    }

    /**
     * Inspect the manifest's signature field and return one of:
     *
     *  - {@see self::RESULT_VALID}    — signature is well-formed, matches
     *    the sha256 of the on-disk `plugin.json`, AND (when the allowlist
     *    is non-empty) appears on it.
     *  - {@see self::RESULT_INVALID}  — manifest is signed but the digest
     *    does not match disk content, or the signature is not on the
     *    allowlist (when one is configured), or signing is required and
     *    the plugin is unsigned, or signing is required with an empty
     *    allowlist.
     *  - {@see self::RESULT_UNSIGNED} — manifest has no signature and the
     *    operator has not enabled `PHLIX_PLUGINS_REQUIRE_SIGNATURE`.
     *
     * @param Manifest $manifest  Parsed manifest.
     * @param string   $directory Absolute path to the staged plugin
     *                            (used to read `plugin.json` for the
     *                            content-hash comparison).
     *
     * @return string One of the RESULT_* constants.
     *
     * @since 0.10.0
     */
    public function verify(Manifest $manifest, string $directory): string
    {
        $signature = $manifest->signature;

        if ($signature === null || $signature === '') {
            return $this->requireSignature ? self::RESULT_INVALID : self::RESULT_UNSIGNED;
        }

        // Step 1: content-integrity check against the on-disk manifest.
        // A tampered plugin.json must always fail, regardless of
        // allowlist content; this is the protection the field is
        // supposed to give.
        $manifestPath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($manifestPath)) {
            // Without a manifest on disk we cannot prove integrity;
            // treat as invalid even if the allowlist would otherwise
            // accept the digest.
            return self::RESULT_INVALID;
        }

        $expectedHex = self::stripPrefix($signature);
        if ($expectedHex === null) {
            return self::RESULT_INVALID;
        }
        $actualHex = hash_file('sha256', $manifestPath);
        if (!is_string($actualHex) || !hash_equals($expectedHex, $actualHex)) {
            return self::RESULT_INVALID;
        }

        // Step 2: allowlist enforcement.
        if ($this->trustedSignatures === []) {
            // Empty allowlist + signature present + signature required => reject.
            // Empty allowlist + signature not required => optimistic accept once
            // content has matched.
            return $this->requireSignature ? self::RESULT_INVALID : self::RESULT_VALID;
        }

        return in_array($signature, $this->trustedSignatures, true)
            ? self::RESULT_VALID
            : self::RESULT_INVALID;
    }

    /**
     * Pull the hex digest off a `sha256:<hex>` signature string.
     * Returns null if the value is malformed.
     */
    private static function stripPrefix(string $signature): ?string
    {
        if (!str_starts_with($signature, self::SIGNATURE_PREFIX)) {
            return null;
        }
        $hex = substr($signature, strlen(self::SIGNATURE_PREFIX));
        if ($hex === '' || !preg_match('/^[0-9a-f]+$/i', $hex)) {
            return null;
        }
        return strtolower($hex);
    }
}
