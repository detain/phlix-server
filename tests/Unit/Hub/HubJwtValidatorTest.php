<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\HubJwtValidator;
use Phlix\Hub\HubUserClaims;
use Phlix\Hub\HttpClientFactoryInterface;
use Phlix\Hub\HttpClientInterface;
use Phlix\Hub\HttpResponse;
use Phlix\Hub\JwksCache;
use Psr\Log\NullLogger;

class HubJwtValidatorTest extends TestCase
{
    /** @var non-empty-string */
    private string $privateKey;
    private string $publicKey;
    private string $kid;

    protected function setUp(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = substr($keyPair, 0, 64);
        if ($privateKey === '') {
            self::fail('Failed to derive Ed25519 private key');
        }
        $this->privateKey = $privateKey;
        $this->publicKey = substr($keyPair, 64);
        $this->kid = 'test-key-id-123';
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createJwt(array $payload, int $exp = null): string
    {
        $header = [
            'alg' => 'EdDSA',
            'typ' => 'JWT',
            'kid' => $this->kid,
        ];

        if ($exp !== null) {
            $payload['exp'] = $exp;
        }

        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signedMessage = $headerEncoded . '.' . $payloadEncoded;

        $signature = sodium_crypto_sign_detached($signedMessage, $this->privateKey);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return $signedMessage . '.' . $signatureEncoded;
    }

    /**
     * @param array<int, array<string, mixed>> $additionalKeys
     */
    private function createJwksResponse(array $additionalKeys = []): HttpResponse
    {
        $keys = [[
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'kid' => $this->kid,
            'x' => $this->base64UrlEncode($this->publicKey),
        ]];

        foreach ($additionalKeys as $key) {
            $keys[] = $key;
        }

        return new HttpResponse(200, [], ['keys' => $keys]);
    }

    private function createValidator(
        HttpClientInterface $httpClient,
        string $serverId = 'test-server',
        ?JwksCache $cache = null,
        int $fetchCooldown = 0,
    ): HubJwtValidator {
        $factory = $this->createMock(HttpClientFactoryInterface::class);
        $factory->method('create')->willReturn($httpClient);

        return new HubJwtValidator(
            'https://hub.example.com/.well-known/jwks.json',
            $factory,
            new NullLogger(),
            $serverId,
            $cache ?? new JwksCache(900),
            900,
            $fetchCooldown,
        );
    }

    /**
     * @param array{iss: string, aud: string, sub: string, hub_user_id: string, server_id: string} $baseClaims
     */
    private function validClaims(array $baseClaims): array
    {
        return $baseClaims + ['exp' => time() + 3600];
    }

    public function testValidJwtReturnsClaims(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn($this->createJwksResponse());

        $validator = $this->createValidator($httpClient);
        $jwt = $this->createJwt([
            'iss' => 'phlix-hub',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
        ], time() + 3600);

        $claims = $validator->validate($jwt);

        $this->assertInstanceOf(HubUserClaims::class, $claims);
        $this->assertEquals('hub-user-123', $claims->userId);
        $this->assertEquals('test-server', $claims->serverId);
        $this->assertEquals('phlix-hub', $claims->issuer);
    }

    public function testExpiredJwtReturnsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn($this->createJwksResponse());

        $validator = $this->createValidator($httpClient);
        $jwt = $this->createJwt([
            'iss' => 'phlix-hub',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
            'exp' => time() - 3600,
        ]);

        $claims = $validator->validate($jwt);

        $this->assertNull($claims);
    }

    public function testWrongIssuerReturnsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn($this->createJwksResponse());

        $validator = $this->createValidator($httpClient);
        $jwt = $this->createJwt([
            'iss' => 'wrong-issuer',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
            'exp' => time() + 3600,
        ]);

        $claims = $validator->validate($jwt);

        $this->assertNull($claims);
    }

    public function testWrongAudienceReturnsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn($this->createJwksResponse());

        $validator = $this->createValidator($httpClient);
        $jwt = $this->createJwt([
            'iss' => 'phlix-hub',
            'aud' => 'wrong-audience',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
            'exp' => time() + 3600,
        ]);

        $claims = $validator->validate($jwt);

        $this->assertNull($claims);
    }

    public function testWrongServerIdReturnsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn($this->createJwksResponse());

        $validator = $this->createValidator($httpClient, 'my-server');
        $jwt = $this->createJwt([
            'iss' => 'phlix-hub',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'different-server',
            'exp' => time() + 3600,
        ]);

        $claims = $validator->validate($jwt);

        $this->assertNull($claims);
    }

    public function testInvalidSignatureReturnsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn($this->createJwksResponse());

        $validator = $this->createValidator($httpClient);

        $keyPair2 = sodium_crypto_sign_keypair();
        $privateKey2 = substr($keyPair2, 0, 64);
        $publicKey2 = substr($keyPair2, 64);

        $header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $this->kid];
        $payload = [
            'iss' => 'phlix-hub',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
            'exp' => time() + 3600,
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signedMessage = $headerEncoded . '.' . $payloadEncoded;

        $signature = sodium_crypto_sign_detached($signedMessage, $privateKey2);
        $signatureEncoded = $this->base64UrlEncode($signature);
        $jwt = $signedMessage . '.' . $signatureEncoded;

        $claims = $validator->validate($jwt);

        $this->assertNull($claims);
    }

    public function testUnknownKidFetchesJwksOnceAndValidates(): void
    {
        $callCount = 0;
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturnCallback(function ($path) use (&$callCount) {
            $callCount++;
            return $this->createJwksResponse();
        });

        // Cooldown 0 = rate-limiting off (this test pins the fetch itself).
        $validator = $this->createValidator($httpClient, 'test-server', null, 0);
        $jwt = $this->createJwt($this->validClaims([
            'iss' => 'phlix-hub',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
        ]));

        $claims = $validator->validate($jwt);

        $this->assertInstanceOf(HubUserClaims::class, $claims);
        // M4: a single fetch returns the ENTIRE key set, so the old
        // invalidate-and-refetch second round-trip per request is gone.
        $this->assertEquals(1, $callCount);
    }

    public function testUnknownKidFloodIsRateLimitedByCooldown(): void
    {
        $callCount = 0;
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturnCallback(function ($path) use (&$callCount) {
            $callCount++;
            return new HttpResponse(200, [], ['keys' => []]);
        });

        $validator = $this->createValidator($httpClient, 'test-server', null, 3600);
        // kid is signed by our key but the hub's (empty) key set never holds
        // it: every request is an unknown-kid miss. Five forged-style requests
        // must trigger exactly ONE refetch, not two per request.
        $jwt = $this->createJwt($this->validClaims([
            'iss' => 'phlix-hub',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
        ]));

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($validator->validate($jwt));
        }

        $this->assertEquals(1, $callCount);
    }

    public function testCooldownServesStaleKeyForKnownKid(): void
    {
        $callCount = 0;
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturnCallback(function ($path) use (&$callCount) {
            $callCount++;
            return $this->createJwksResponse();
        });

        // TTL 0: every fetched entry is immediately stale, so each validate()
        // is an unknown-kid miss on the FRESH cache but has a stale fallback.
        $cache = new JwksCache(0);
        $validator = $this->createValidator($httpClient, 'test-server', $cache, 3600);
        $jwt = $this->createJwt($this->validClaims([
            'iss' => 'phlix-hub',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
        ]));

        $first = $validator->validate($jwt);
        $second = $validator->validate($jwt);

        // Rotation-already-happened case: stale key still validates…
        $this->assertInstanceOf(HubUserClaims::class, $first);
        $this->assertInstanceOf(HubUserClaims::class, $second);
        // …while the cooldown blocks the second request from refetching.
        $this->assertEquals(1, $callCount);
    }

    public function testJwksFetchFailureReturnsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn(new HttpResponse(500, [], []));

        $validator = $this->createValidator($httpClient);
        $jwt = $this->createJwt([
            'iss' => 'phlix-hub',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
            'exp' => time() + 3600,
        ]);

        $claims = $validator->validate($jwt);

        $this->assertNull($claims);
    }

    public function testMissingKidReturnsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn($this->createJwksResponse());

        $validator = $this->createValidator($httpClient);

        $header = ['alg' => 'EdDSA', 'typ' => 'JWT'];
        $payload = [
            'iss' => 'phlix-hub',
            'aud' => 'phlix-server',
            'sub' => 'hub-user-123',
            'hub_user_id' => 'hub-user-123',
            'server_id' => 'test-server',
            'exp' => time() + 3600,
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signedMessage = $headerEncoded . '.' . $payloadEncoded;
        $signature = sodium_crypto_sign_detached($signedMessage, $this->privateKey);
        $signatureEncoded = $this->base64UrlEncode($signature);
        $jwt = $signedMessage . '.' . $signatureEncoded;

        $claims = $validator->validate($jwt);

        $this->assertNull($claims);
    }

    public function testMalformedJwtReturnsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $validator = $this->createValidator($httpClient);

        $claims = $validator->validate('not.a.valid.jwt');
        $this->assertNull($claims);

        $claims = $validator->validate('only.two.parts');
        $this->assertNull($claims);

        $claims = $validator->validate('');
        $this->assertNull($claims);
    }

    public function testRefreshJwksInvalidatesAndRefetches(): void
    {
        $callCount = 0;
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturnCallback(function ($path) use (&$callCount) {
            $callCount++;
            return new HttpResponse(200, [], ['keys' => [[
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'kid' => $this->kid,
                'x' => $this->base64UrlEncode($this->publicKey),
            ]]]);
        });

        $validator = $this->createValidator($httpClient);
        $validator->refreshJwks();

        $this->assertEquals(1, $callCount);
    }
}
