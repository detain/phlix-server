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
    /**
     * Sample bytes used as the on-disk plugin.json for content-hash
     * checks. The signature value below is `hash('sha256', self::MANIFEST_BYTES)`
     * with the `sha256:` prefix applied.
     */
    private const MANIFEST_BYTES = '{"name":"phlex-plugin-sigtest","version":"1.0.0"}';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlex_sigtest_' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tmpDir)) {
            @unlink($this->tmpDir . '/plugin.json');
            @rmdir($this->tmpDir);
        }
    }

    public function test_unsigned_manifest_returns_unsigned_when_not_required(): void
    {
        $this->writeManifestBytes();
        $verifier = new SignatureVerifier(trustedSignatures: [], requireSignature: false);
        $result = $verifier->verify($this->buildManifest(signature: null), $this->tmpDir);

        $this->assertSame(SignatureVerifier::RESULT_UNSIGNED, $result);
    }

    public function test_unsigned_manifest_returns_invalid_when_required(): void
    {
        $this->writeManifestBytes();
        $verifier = new SignatureVerifier(trustedSignatures: [], requireSignature: true);
        $result = $verifier->verify($this->buildManifest(signature: null), $this->tmpDir);

        $this->assertSame(SignatureVerifier::RESULT_INVALID, $result);
    }

    public function test_signed_manifest_is_valid_when_signature_matches_allowlist_and_content(): void
    {
        $this->writeManifestBytes();
        $signature = $this->expectedSignature();

        $verifier = new SignatureVerifier(trustedSignatures: [$signature]);
        $result = $verifier->verify($this->buildManifest(signature: $signature), $this->tmpDir);

        $this->assertSame(SignatureVerifier::RESULT_VALID, $result);
    }

    public function test_signed_manifest_is_invalid_when_signature_not_in_allowlist(): void
    {
        $this->writeManifestBytes();
        $contentMatching = $this->expectedSignature();
        $otherTrusted = 'sha256:' . str_repeat('a', 64);

        $verifier = new SignatureVerifier(trustedSignatures: [$otherTrusted]);
        $result = $verifier->verify(
            $this->buildManifest(signature: $contentMatching),
            $this->tmpDir,
        );

        $this->assertSame(SignatureVerifier::RESULT_INVALID, $result);
    }

    public function test_empty_allowlist_with_signed_manifest_returns_valid_when_not_required(): void
    {
        $this->writeManifestBytes();
        $verifier = new SignatureVerifier(trustedSignatures: [], requireSignature: false);
        $result = $verifier->verify(
            $this->buildManifest(signature: $this->expectedSignature()),
            $this->tmpDir,
        );

        $this->assertSame(SignatureVerifier::RESULT_VALID, $result);
    }

    public function test_empty_allowlist_with_signed_manifest_returns_invalid_when_required(): void
    {
        $this->writeManifestBytes();
        $verifier = new SignatureVerifier(trustedSignatures: [], requireSignature: true);
        $result = $verifier->verify(
            $this->buildManifest(signature: $this->expectedSignature()),
            $this->tmpDir,
        );

        $this->assertSame(SignatureVerifier::RESULT_INVALID, $result);
    }

    public function test_tampered_manifest_disk_content_returns_invalid_even_with_allowlist_match(): void
    {
        // Disk bytes differ from what the signature describes: the
        // verifier must reject the install even though the signature
        // matches the trusted allowlist.
        $declaredSignature = $this->expectedSignature();
        file_put_contents(
            $this->tmpDir . '/plugin.json',
            '{"name":"phlex-plugin-sigtest","version":"9.9.9-tampered"}',
        );

        $verifier = new SignatureVerifier(trustedSignatures: [$declaredSignature]);
        $result = $verifier->verify(
            $this->buildManifest(signature: $declaredSignature),
            $this->tmpDir,
        );

        $this->assertSame(SignatureVerifier::RESULT_INVALID, $result);
    }

    public function test_missing_manifest_file_returns_invalid_for_signed_plugin(): void
    {
        // No plugin.json on disk -> cannot prove integrity -> invalid.
        $verifier = new SignatureVerifier(trustedSignatures: [$this->expectedSignature()]);
        $result = $verifier->verify(
            $this->buildManifest(signature: $this->expectedSignature()),
            $this->tmpDir, // contains nothing
        );

        $this->assertSame(SignatureVerifier::RESULT_INVALID, $result);
    }

    public function test_malformed_signature_prefix_returns_invalid(): void
    {
        $this->writeManifestBytes();
        $verifier = new SignatureVerifier(trustedSignatures: []);
        $result = $verifier->verify(
            $this->buildManifest(signature: 'md5:0123456789abcdef0123456789abcdef'),
            $this->tmpDir,
        );

        $this->assertSame(SignatureVerifier::RESULT_INVALID, $result);
    }

    private function writeManifestBytes(): void
    {
        file_put_contents($this->tmpDir . '/plugin.json', self::MANIFEST_BYTES);
    }

    private function expectedSignature(): string
    {
        return SignatureVerifier::SIGNATURE_PREFIX . hash('sha256', self::MANIFEST_BYTES);
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
