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
    private string $privateKey;
    private string $publicKey;
    private string $kid;

    protected function setUp(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $this->privateKey = substr($keyPair, 0, 64);
        $this->publicKey = substr($keyPair, 64);
        $this->kid = 'test-key-id-123';
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

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

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signedMessage = $headerEncoded . '.' . $payloadEncoded;

        $signature = sodium_crypto_sign_detached($signedMessage, $this->privateKey);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return $signedMessage . '.' . $signatureEncoded;
    }

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

    private function createValidator(HttpClientInterface $httpClient, string $serverId = 'test-server'): HubJwtValidator
    {
        $factory = $this->createMock(HttpClientFactoryInterface::class);
        $factory->method('create')->willReturn($httpClient);

        return new HubJwtValidator(
            'https://hub.example.com/.well-known/jwks.json',
            $factory,
            new NullLogger(),
            $serverId,
            new JwksCache(900),
            900,
        );
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

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signedMessage = $headerEncoded . '.' . $payloadEncoded;

        $signature = sodium_crypto_sign_detached($signedMessage, $privateKey2);
        $signatureEncoded = $this->base64UrlEncode($signature);
        $jwt = $signedMessage . '.' . $signatureEncoded;

        $claims = $validator->validate($jwt);

        $this->assertNull($claims);
    }

    public function testUnknownKidFetchesJwksAndRetries(): void
    {
        $callCount = 0;
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturnCallback(function ($path) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return new HttpResponse(200, [], ['keys' => []]);
            }
            return new HttpResponse(200, [], ['keys' => [[
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'kid' => $this->kid,
                'x' => $this->base64UrlEncode($this->publicKey),
            ]]]);
        });

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

        $this->assertInstanceOf(HubUserClaims::class, $claims);
        $this->assertEquals(2, $callCount);
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

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
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
