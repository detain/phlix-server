<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Signature;

use Phlex\Plugins\Manifest;
use Phlex\Plugins\Signature\SignatureVerifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Plugins\Signature\SignatureVerifier
 */
final class SignatureVerifierTest extends TestCase
{
    private const SIGNED = 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function test_unsigned_manifest_returns_unsigned_when_not_required(): void
    {
        $verifier = new SignatureVerifier(trustedSignatures: [], requireSignature: false);
        $result = $verifier->verify($this->buildManifest(signature: null), '/tmp');

        $this->assertSame(SignatureVerifier::RESULT_UNSIGNED, $result);
    }

    public function test_unsigned_manifest_returns_invalid_when_required(): void
    {
        $verifier = new SignatureVerifier(trustedSignatures: [], requireSignature: true);
        $result = $verifier->verify($this->buildManifest(signature: null), '/tmp');

        $this->assertSame(SignatureVerifier::RESULT_INVALID, $result);
    }

    public function test_signed_manifest_is_valid_when_signature_matches_allowlist(): void
    {
        $verifier = new SignatureVerifier(trustedSignatures: [self::SIGNED]);
        $result = $verifier->verify($this->buildManifest(signature: self::SIGNED), '/tmp');

        $this->assertSame(SignatureVerifier::RESULT_VALID, $result);
    }

    public function test_signed_manifest_is_invalid_when_signature_not_in_allowlist(): void
    {
        $verifier = new SignatureVerifier(
            trustedSignatures: ['sha256:' . str_repeat('a', 64)],
        );
        $result = $verifier->verify($this->buildManifest(signature: self::SIGNED), '/tmp');

        $this->assertSame(SignatureVerifier::RESULT_INVALID, $result);
    }

    public function test_empty_allowlist_with_signed_manifest_returns_valid_when_not_required(): void
    {
        // Empty allowlist + signature present + not required => optimistic accept.
        $verifier = new SignatureVerifier(trustedSignatures: [], requireSignature: false);
        $result = $verifier->verify($this->buildManifest(signature: self::SIGNED), '/tmp');

        $this->assertSame(SignatureVerifier::RESULT_VALID, $result);
    }

    public function test_empty_allowlist_with_signed_manifest_returns_invalid_when_required(): void
    {
        $verifier = new SignatureVerifier(trustedSignatures: [], requireSignature: true);
        $result = $verifier->verify($this->buildManifest(signature: self::SIGNED), '/tmp');

        $this->assertSame(SignatureVerifier::RESULT_INVALID, $result);
    }

    private function buildManifest(?string $signature): Manifest
    {
        $payload = [
            'name' => 'phlex-plugin-sigtest',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlex\\Tests\\Sig\\Plugin',
        ];
        if ($signature !== null) {
            $payload['signature'] = $signature;
        }
        return Manifest::fromArray($payload);
    }
}
