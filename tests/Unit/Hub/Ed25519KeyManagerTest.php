<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Ed25519KeyManager;
use Phlix\Hub\KeyPair;
use RuntimeException;

class Ed25519KeyManagerTest extends TestCase
{
    private string $tmpDir;
    private string $keyPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->keyPath = $this->tmpDir . '/ed25519-test-key.pem';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function test_generates_keypair_when_not_exists(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $keyPair = $manager->getOrCreateKeyPair();

        $this->assertInstanceOf(KeyPair::class, $keyPair);
        $this->assertEquals(64, strlen($keyPair->secretKey));
        $this->assertEquals(32, strlen($keyPair->publicKey));
        $this->assertFileExists($this->keyPath);
    }

    public function test_loads_existing_keypair(): void
    {
        $manager1 = new Ed25519KeyManager($this->keyPath);
        $keyPair1 = $manager1->getOrCreateKeyPair();

        $manager2 = new Ed25519KeyManager($this->keyPath);
        $keyPair2 = $manager2->getOrCreateKeyPair();

        $this->assertEquals($keyPair1->secretKey, $keyPair2->secretKey);
        $this->assertEquals($keyPair1->publicKey, $keyPair2->publicKey);
    }

    public function test_rotate_keeps_old_key(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $original = $manager->getOrCreateKeyPair();

        $rotated = $manager->rotate();

        $this->assertNotEquals($original->secretKey, $rotated->secretKey);
        $this->assertNotEquals($original->publicKey, $rotated->publicKey);
        $this->assertEquals(64, strlen($rotated->secretKey));
        $this->assertEquals(32, strlen($rotated->publicKey));
    }

    public function test_getPublicKeyJwk_returns_valid_structure(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $manager->getOrCreateKeyPair();
        $jwk = $manager->getPublicKeyJwk();

        $this->assertEquals('OKP', $jwk['kty']);
        $this->assertEquals('Ed25519', $jwk['crv']);
        $this->assertArrayHasKey('x', $jwk);
        $this->assertArrayHasKey('kid', $jwk);
        $this->assertEquals('sig', $jwk['use']);
        $this->assertEquals('EdDSA', $jwk['alg']);
        $this->assertEquals(32, strlen(base64_decode(strtr($jwk['x'], '-_', '+/'))));
    }

    public function test_invalid_pem_throws(): void
    {
        file_put_contents($this->keyPath, "-----BEGIN ED25519 PRIVATE KEY-----\nINVALID\n-----END ED25519 PRIVATE KEY-----\n");

        $manager = new Ed25519KeyManager($this->keyPath);

        $this->expectException(InvalidArgumentException::class);
        $manager->getOrCreateKeyPair();
    }

    public function test_kid_is_deterministic(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $manager->getOrCreateKeyPair();
        $kid1 = $manager->getKid();
        $kid2 = $manager->getKid();

        $this->assertEquals($kid1, $kid2);
        $this->assertNotEmpty($kid1);
    }

    public function test_getCurrentPrivateKey_returns_64_bytes(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $keyPair = $manager->getOrCreateKeyPair();

        $privateKey = $manager->getCurrentPrivateKey();

        $this->assertEquals($keyPair->secretKey, $privateKey);
        $this->assertEquals(64, strlen($privateKey));
    }

    // ---------------------------------------------------------------------
    // SV-4.16 — PKCS#8 Ed25519 tolerance (reader accepts the standard
    // `-----BEGIN PRIVATE KEY-----` label) + rejection of non-Ed25519 /
    // malformed keys. Every key fixture is generated at runtime; no real
    // or production private-key material is committed.
    // ---------------------------------------------------------------------

    /**
     * Case 1 — a standard PKCS#8 v1 Ed25519 key (the shape produced by
     * `openssl genpkey -algorithm Ed25519`, 48-byte DER) loads and yields the
     * public key independently derived from the very same seed. Self-consistent:
     * the expected `x` is computed from the seed via sodium, not hardcoded.
     */
    public function test_loads_standard_pkcs8_v1_ed25519_key(): void
    {
        $seed = random_bytes(32);
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        $expectedX = self::base64UrlEncode($publicKey);

        file_put_contents($this->keyPath, self::pkcs8V1Pem($seed));

        $manager = new Ed25519KeyManager($this->keyPath);
        $keyPair = $manager->getOrCreateKeyPair();
        $jwk = $manager->getPublicKeyJwk();

        // The seed expands to the full 64-byte libsodium secret, and the parsed
        // public key equals the one derived from the same seed.
        $this->assertEquals(64, strlen($keyPair->secretKey));
        $this->assertSame($publicKey, $keyPair->publicKey);
        $this->assertSame($expectedX, $jwk['x']);

        // Mutation proof: the assertion actually bites — a public key that does
        // NOT come from this seed must never compare equal.
        $wrongX = self::base64UrlEncode(str_repeat("\x00", 32));
        $this->assertNotSame($wrongX, $jwk['x']);
    }

    /**
     * Case 2 — a PKCS#8 v2 `OneAsymmetricKey` (RFC 5958) that additionally
     * carries the `[1]` public-key attribute (~83-byte DER) parses to the same
     * public key. The seed precedes attributes per RFC 5958, so the first
     * `04 22 04 20` framing is the private key and the trailing public key is
     * ignored by the reader.
     */
    public function test_loads_pkcs8_v2_oneAsymmetricKey_ignoring_trailing_public_key(): void
    {
        $seed = random_bytes(32);
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        $expectedX = self::base64UrlEncode($publicKey);

        file_put_contents($this->keyPath, self::pkcs8V2Pem($seed, $publicKey));

        $manager = new Ed25519KeyManager($this->keyPath);
        $keyPair = $manager->getOrCreateKeyPair();
        $jwk = $manager->getPublicKeyJwk();

        $this->assertEquals(64, strlen($keyPair->secretKey));
        $this->assertSame($publicKey, $keyPair->publicKey);
        $this->assertSame($expectedX, $jwk['x']);
    }

    /**
     * Case 3 — backward-compat guard: a key generated by this application
     * (written by `buildPem()` in the native `-----BEGIN ED25519 PRIVATE KEY-----`
     * format) still round-trips through the refactored `parsePem()` dispatcher to
     * a valid 64-byte secret and the correct public JWK.
     */
    public function test_native_format_roundtrip_still_parses(): void
    {
        $writer = new Ed25519KeyManager($this->keyPath);
        $generated = $writer->getOrCreateKeyPair();
        $expectedX = self::base64UrlEncode($generated->publicKey);

        // The on-disk file must be the native label (writer is unchanged).
        $pem = file_get_contents($this->keyPath);
        $this->assertIsString($pem);
        $this->assertStringContainsString('-----BEGIN ED25519 PRIVATE KEY-----', $pem);

        $reader = new Ed25519KeyManager($this->keyPath);
        $reloaded = $reader->getOrCreateKeyPair();

        $this->assertEquals(64, strlen($reloaded->secretKey));
        $this->assertSame($generated->secretKey, $reloaded->secretKey);
        $this->assertSame($generated->publicKey, $reloaded->publicKey);
        $this->assertSame($expectedX, $reader->getPublicKeyJwk()['x']);
    }

    /**
     * Case 4 — an RSA-2048 PKCS#8 key wears the same `-----BEGIN PRIVATE KEY-----`
     * label but is rejected at the OID gate (no `06 03 2b 65 70`, no
     * `04 22 04 20` seed framing), so `loadKeyPair()` throws instead of
     * mis-reading arbitrary bytes as an Ed25519 seed.
     */
    public function test_rejects_rsa_pkcs8_key(): void
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($res === false) {
            self::fail('openssl_pkey_new failed to generate an RSA key fixture');
        }
        $rsaPem = '';
        if (!openssl_pkey_export($res, $rsaPem)) {
            self::fail('openssl_pkey_export failed for the RSA key fixture');
        }
        // Sanity: it is a PKCS#8 key (same label) yet contains no Ed25519 markers.
        $this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $rsaPem);

        file_put_contents($this->keyPath, $rsaPem);
        $manager = new Ed25519KeyManager($this->keyPath);

        $this->expectException(InvalidArgumentException::class);
        $manager->getOrCreateKeyPair();
    }

    /**
     * Cases 5 & 6 — malformed / truncated / garbage DER and a well-formed
     * PKCS#8 structure carrying a non-Ed25519 OID are all rejected (parse
     * returns null → `loadKeyPair()` throws). No fatal, no out-of-bounds read.
     *
     * @param string $pem A PKCS#8-labelled PEM that must not load.
     */
    #[DataProvider('provideRejectedPkcs8Pems')]
    public function test_rejects_malformed_or_wrong_oid_pkcs8(string $pem): void
    {
        file_put_contents($this->keyPath, $pem);
        $manager = new Ed25519KeyManager($this->keyPath);

        $this->expectException(InvalidArgumentException::class);
        $manager->getOrCreateKeyPair();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideRejectedPkcs8Pems(): array
    {
        // A well-formed PKCS#8 frame but with the X25519 OID (1.3.101.110 =>
        // 06 03 2b 65 6e) instead of Ed25519 (…70). The seed framing is present
        // but the OID gate rejects it.
        $wrongOid = self::wrapPkcs8Pem(
            self::derBytes('302e020100300506032b656e04220420') . str_repeat("\x01", 32)
        );

        // The Ed25519 OID is present but the inner `04 22 04 20` seed framing is
        // absent (>= 48 bytes so it clears the length floor) — the seed anchor
        // is not found, so extraction returns null.
        $oidNoSeedFraming = self::wrapPkcs8Pem(
            self::derBytes('06032b6570') . str_repeat("\x00", 45)
        );

        // The Ed25519 OID and the `04 22 04 20` framing are both present, but
        // fewer than 32 seed bytes follow, so the substring length check rejects.
        $truncatedSeed = self::wrapPkcs8Pem(
            str_repeat("\x00", 40) . self::derBytes('06032b6570')
            . self::derBytes('04220420') . str_repeat("\x11", 10)
        );

        return [
            'wrong OID (X25519, not Ed25519)' => [$wrongOid],
            'ed25519 OID but no 04 22 04 20 seed framing' => [$oidNoSeedFraming],
            'ed25519 OID + framing but truncated seed (< 32 bytes)' => [$truncatedSeed],
            // "QUJDRA==" decodes to "ABCD" (4 bytes) — far below the 48-byte floor.
            'truncated DER (< 48 bytes)' => [
                "-----BEGIN PRIVATE KEY-----\nQUJDRA==\n-----END PRIVATE KEY-----\n",
            ],
            'garbage non-base64 body' => [
                "-----BEGIN PRIVATE KEY-----\n!!!not base64!!!\n-----END PRIVATE KEY-----\n",
            ],
            'empty PKCS#8 body' => [
                "-----BEGIN PRIVATE KEY-----\n\n-----END PRIVATE KEY-----\n",
            ],
        ];
    }

    /**
     * Builds a standard PKCS#8 v1 Ed25519 PEM (48-byte DER) wrapping the given
     * 32-byte seed: SEQUENCE { INTEGER 0, AlgorithmIdentifier { OID 1.3.101.112 },
     * OCTET STRING { OCTET STRING seed } }.
     */
    private static function pkcs8V1Pem(string $seed): string
    {
        $der = self::derBytes('302e020100300506032b657004220420') . $seed;

        return self::wrapPkcs8Pem($der);
    }

    /**
     * Builds a PKCS#8 v2 `OneAsymmetricKey` PEM (RFC 5958) carrying the `[1]`
     * IMPLICIT public-key attribute after the private key, so the reader must
     * locate the seed from the leading `04 22 04 20` framing and ignore the
     * trailing public key.
     */
    private static function pkcs8V2Pem(string $seed, string $publicKey): string
    {
        // [1] IMPLICIT public-key attribute is a BIT STRING: a 00 "unused bits"
        // byte followed by the 32-byte public key.
        $publicKeyAttr = self::derBytes('8121') . "\x00" . $publicKey;
        $body = self::derBytes('020101')            // version v2 (1)
            . self::derBytes('300506032b6570')       // AlgorithmIdentifier: Ed25519 OID
            . self::derBytes('04220420') . $seed     // privateKey OCTET STRING framing + 32-byte seed
            . $publicKeyAttr;
        $der = "\x30" . chr(strlen($body)) . $body;

        return self::wrapPkcs8Pem($der);
    }

    /**
     * Wraps raw DER bytes in a standard (non-URL-safe) base64 PKCS#8 PEM.
     */
    private static function wrapPkcs8Pem(string $der): string
    {
        return "-----BEGIN PRIVATE KEY-----\n"
            . implode("\n", str_split(base64_encode($der), 64)) . "\n"
            . "-----END PRIVATE KEY-----\n";
    }

    /**
     * Decodes a fixed hex fixture into raw bytes, failing the test if the
     * literal is not valid hex (keeps the DER builders phpstan-clean).
     */
    private static function derBytes(string $hex): string
    {
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            self::fail('Invalid hex fixture: ' . $hex);
        }

        return $bytes;
    }

    /**
     * Encodes binary data as base64url (no padding), mirroring the JWK `x`
     * encoding used by the key manager.
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
