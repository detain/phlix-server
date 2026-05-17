<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Hub;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlex\Hub\Ed25519KeyManager;
use Phlex\Hub\KeyPair;
use RuntimeException;

class Ed25519KeyManagerTest extends TestCase
{
    private string $tmpDir;
    private string $keyPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phlex-hub-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->keyPath = $this->tmpDir . '/ed25519-test-key.pem';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
        $files = glob($this->tmpDir . '/*');
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
}
