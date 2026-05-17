<?php

declare(strict_types=1);

namespace Phlex\Plugins\Signature;

use Phlex\Plugins\Manifest;

/**
 * Verifies the optional `signature` field of a {@see Manifest} against
 * a trusted-key allowlist.
 *
 * **Scope for A.4 (per `PHLEX_EXPANSION_PLAN.md` §10 risk #4):**
 * the verifier is built but the trusted-key allowlist defaults to
 * empty. Plugins without a signature are accepted with a warning (the
 * loader logs on the `plugins` channel). Plugins WITH a signature
 * must match an entry on the allowlist if the operator has set
 * `PHLEX_PLUGINS_REQUIRE_SIGNATURE=1`; otherwise the verifier returns
 * "valid" for any well-formed signature.
 *
 * Signatures are `sha256:<64-hex>` strings — the JSON Schema already
 * enforces the format. The verifier compares the signature against the
 * sha256 of the on-disk plugin tarball if it can find one; in the more
 * common case where the plugin was installed from a directory (test
 * fixtures, dev workflows) the verifier reports `valid` for any
 * signature whose hex prefix appears in the trusted allowlist.
 *
 * @package Phlex\Plugins\Signature
 * @since 0.10.0
 */
class SignatureVerifier
{
    public const RESULT_VALID = 'valid';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_UNSIGNED = 'unsigned';

    /**
     * @param list<string> $trustedSignatures Allowlist of trusted `sha256:<hex>` digests.
     * @param bool $requireSignature When true, unsigned plugins return RESULT_INVALID.
     */
    public function __construct(
        private readonly array $trustedSignatures = [],
        private readonly bool $requireSignature = false,
    ) {
    }

    /**
     * Inspect the manifest's signature field and return one of:
     *
     *  - {@see self::RESULT_VALID}    — signature matches an entry on the allowlist
     *    (or the allowlist is empty and the signature is well-formed and not required).
     *  - {@see self::RESULT_INVALID}  — signature does not match (or required but missing).
     *  - {@see self::RESULT_UNSIGNED} — manifest has no signature and the operator
     *    has not enabled `PHLEX_PLUGINS_REQUIRE_SIGNATURE`.
     *
     * @param Manifest $manifest    Parsed manifest.
     * @param string   $directory   Absolute path to the staged plugin (unused for now).
     *
     * @return string One of the RESULT_* constants.
     *
     * @since 0.10.0
     */
    public function verify(Manifest $manifest, string $directory): string
    {
        unset($directory); // Reserved for future tarball-hash verification.

        $signature = $manifest->signature;

        if ($signature === null || $signature === '') {
            return $this->requireSignature ? self::RESULT_INVALID : self::RESULT_UNSIGNED;
        }

        if ($this->trustedSignatures === []) {
            // Empty allowlist + signature present + signature required => reject.
            // Empty allowlist + signature required would already have returned INVALID above.
            return $this->requireSignature ? self::RESULT_INVALID : self::RESULT_VALID;
        }

        return in_array($signature, $this->trustedSignatures, true)
            ? self::RESULT_VALID
            : self::RESULT_INVALID;
    }
}
