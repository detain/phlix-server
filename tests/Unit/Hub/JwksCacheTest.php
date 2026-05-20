<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\JwksCache;

class JwksCacheTest extends TestCase
{
    public function testGetReturnsCachedJwk(): void
    {
        $cache = new JwksCache(900);
        $jwk = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'key-1', 'x' => 'dGhpcyBpcyBhIHRlc3QgcHVibGljIGtleQ=='];

        $cache->set('key-1', $jwk);
        $result = $cache->get('key-1');

        $this->assertEquals($jwk, $result);
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $cache = new JwksCache(900);

        $result = $cache->get('nonexistent');

        $this->assertNull($result);
    }

    public function testGetReturnsNullOnExpiredEntry(): void
    {
        $cache = new JwksCache(0);
        $jwk = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'key-1', 'x' => 'dGhpcyBpcyBhIHRlc3QgcHVibGljIGtleQ=='];

        $cache->set('key-1', $jwk);

        $result = $cache->get('key-1');

        $this->assertNull($result);
    }

    public function testInvalidateClearsCache(): void
    {
        $cache = new JwksCache(900);
        $jwk = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'key-1', 'x' => 'dGhpcyBpcyBhIHRlc3QgcHVibGljIGtleQ=='];

        $cache->set('key-1', $jwk);
        $cache->invalidate();

        $this->assertNull($cache->get('key-1'));
    }

    public function testGetAllReturnsOnlyNonExpiredEntries(): void
    {
        $cache = new JwksCache(0);
        $jwk1 = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'key-1', 'x' => 'dGhpcyBpcyBhIHRlc3QgcHVibGljIGtleQ=='];
        $jwk2 = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'key-2', 'x' => 'YW5vdGhlciB0ZXN0IHB1YmxpYyBrZXk='];

        $cache->set('key-1', $jwk1);
        $cache->set('key-2', $jwk2);

        $result = $cache->getAll();

        $this->assertEmpty($result);
    }

    public function testGetAllReturnsCachedEntries(): void
    {
        $cache = new JwksCache(900);
        $jwk1 = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'key-1', 'x' => 'dGhpcyBpcyBhIHRlc3QgcHVibGljIGtleQ=='];
        $jwk2 = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'key-2', 'x' => 'YW5vdGhlciB0ZXN0IHB1YmxpYyBrZXk='];

        $cache->set('key-1', $jwk1);
        $cache->set('key-2', $jwk2);

        $result = $cache->getAll();

        $this->assertCount(2, $result);
        $this->assertEquals($jwk1, $result['key-1']);
        $this->assertEquals($jwk2, $result['key-2']);
    }

    public function testSetOverwritesExistingEntry(): void
    {
        $cache = new JwksCache(900);
        $jwk1 = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'key-1', 'x' => 'dGhpcyBpcyBhIHRlc3QgcHVibGljIGtleQ=='];
        $jwk2 = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'key-1', 'x' => 'bmV3IHZhbHVlIGZvciB0aGUgc2FtZSBrZXk='];

        $cache->set('key-1', $jwk1);
        $cache->set('key-1', $jwk2);

        $result = $cache->get('key-1');
        $this->assertEquals($jwk2, $result);
    }
}
