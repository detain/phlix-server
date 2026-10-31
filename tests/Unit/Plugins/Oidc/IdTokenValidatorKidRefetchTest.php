<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Oidc\DiscoveryDocument;
use Phlix\Plugins\Oidc\IdTokenValidator;
use Phlix\Plugins\Oidc\OidcHttpClient;
use Phlix\Plugins\Oidc\OidcValidationException;
use Phlix\Plugins\Util\RecursiveDelete;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Response as WorkermanResponse;

/**
 * minors M-SERVER-39 (W-min108) — kid-aware JWKS refetch.
 *
 * The static JWKS cache served the same key set for up to 24 h no matter what
 * a token asked for, so an IdP key rotation stranded logins for a full day.
 * The cure: a token naming a kid the loaded set lacks triggers ONE forced
 * refetch, throttled to at most one network attempt per window; failures
 * degrade to the historical generic copy — no new wire states.
 *
 * All HTTP goes through a createMock(OidcHttpClient::class) double whose
 * callback counts jwks-endpoint hits — the observable is network behaviour,
 * never internals.
 */
final class IdTokenValidatorKidRefetchTest extends TestCase
{
    private string $cacheDir = '';

    /** @var array<string,int> */
    private array $calls = ['discovery' => 0, 'jwks' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        IdTokenValidator::clearJwksCache();
        DiscoveryDocument::clearMemoryCache();
        $this->calls = ['discovery' => 0, 'jwks' => 0];
        $this->cacheDir = sys_get_temp_dir() . '/phlix_s39_' . uniqid('', true) . '/cache';
        mkdir($this->cacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RecursiveDelete::remove(dirname($this->cacheDir));
    }

    private function assertJwksCalls(int $expected): void
    {
        self::assertSame($expected, $this->calls['jwks']);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $header */
    private function compactJws(array $header, array $payload): string
    {
        return $this->b64((string) json_encode($header))
            . '.' . $this->b64((string) json_encode($payload))
            . '.' . $this->b64('not-a-real-signature');
    }

    /** @return array<string,string> kid => jwk-array */
    private function rsaPublicJwk(string $kid): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => $this->b64($details['rsa']['n']),
            'e' => $this->b64($details['rsa']['e']),
        ];
    }

    private function countingClient(string $jwksJson): OidcHttpClient
    {
        $providerUrl = 'https://rotation.test';
        $discoveryJson = (string) json_encode([
            'issuer' => $providerUrl,
            'authorization_endpoint' => $providerUrl . '/authorize',
            'token_endpoint' => $providerUrl . '/token',
            'jwks_uri' => $providerUrl . '/jwks',
        ]);

        $client = $this->createMock(OidcHttpClient::class);
        $client->method('get')->willReturnCallback(
            function (string $url) use ($jwksJson, $discoveryJson): ResponseInterface {
                if (str_contains($url, 'openid-configuration')) {
                    $this->calls['discovery']++;

                    return new WorkermanResponse(200, [], $discoveryJson);
                }
                $this->calls['jwks']++;

                return new WorkermanResponse(200, [], $jwksJson);
            }
        );

        return $client;
    }

    public function testNonForcedFetchStillServesTheFreshCache(): void
    {
        // Control (green before and after the cure): the 24h serve law for
        // ordinary bootstrap fetches is NOT weakened.
        $client = $this->countingClient((string) json_encode(['keys' => []]));
        $discovery = new DiscoveryDocument('https://rotation.test', $this->cacheDir, $client);

        IdTokenValidator::fetchJwks($discovery, $client);
        IdTokenValidator::fetchJwks($discovery, $client);

        self::assertSame(1, $this->calls['jwks']);
    }

    public function testForcedFetchBypassesTheFreshCacheAndThenThrottlesItself(): void
    {
        $client = $this->countingClient((string) json_encode(['keys' => []]));
        $discovery = new DiscoveryDocument('https://rotation.test', $this->cacheDir, $client);

        IdTokenValidator::fetchJwks($discovery, $client);
        self::assertSame(1, $this->calls['jwks']);

        // The rotation signal: a forced fetch must hit the network despite the
        // entry being hours-young...
        IdTokenValidator::fetchJwks($discovery, $client, true);
        $this->assertJwksCalls(2);

        // ...and the second forced fetch inside the throttle window must NOT
        // (a flood of unknown-kid tokens cannot become an amplification lever).
        // Asserted through the helper so the pin is the OBSERVED post-call
        // count, not a value phpstan already narrowed at the line above.
        IdTokenValidator::fetchJwks($discovery, $client, true);
        $this->assertJwksCalls(2);
    }

    public function testKidMissRefetchesOnceAdoptsTheRotatedSetAndKeepsTheHistoricalCopy(): void
    {
        $rotated = $this->rsaPublicJwk('r-2');
        $client = $this->countingClient((string) json_encode(['keys' => [$rotated]]));
        $discovery = new DiscoveryDocument('https://rotation.test', $this->cacheDir, $client);

        // One-key starting set: an EMPTY set makes the vendor verifier throw
        // before reaching the historical false-copy, so the parent behavior
        // under test is the real one (pre-cure: no refetch, generic failure).
        $starting = \Jose\Component\Core\JWKSet::createFromKeyData(
            ['keys' => [$this->rsaPublicJwk('r-1')]],
        );
        $validator = new IdTokenValidator($discovery, $starting, $client);

        $token = $this->compactJws(
            ['alg' => 'RS256', 'kid' => 'r-2', 'typ' => 'JWT'],
            ['iss' => 'https://rotation.test', 'aud' => 'client', 'exp' => time() + 3600],
        );

        $message = '';
        try {
            $validator->validate($token, 'client');
        } catch (OidcValidationException $e) {
            $message = $e->getMessage();
        }

        // The wire copy is the historical generic one — a garbage signature
        // must never verify, rotated keys included (no trust shortcut).
        self::assertSame('Token signature verification failed', $message);
        // ...but the unknown kid cost exactly ONE refetch...
        self::assertSame(1, $this->calls['jwks']);

        // ...and the set was ADOPTED: a second ceremony with the same kid
        // finds it present, so no further network attempt happens (this pin
        // proves the adoption branch, not the throttle). Copy stays the
        // historical one — a garbage signature never verifies, rotated keys
        // included.
        $second = '';
        try {
            $validator->validate($token, 'client');
        } catch (OidcValidationException $e) {
            $second = $e->getMessage();
        }
        self::assertSame($message, $second);
        self::assertSame(1, $this->calls['jwks']);
    }

    public function testTokenWithoutKidNeverTriggersARefetch(): void
    {
        // Control for the trigger law: no kid in the protected header means
        // nothing to look up — zero jwks network attempts from validate().
        $client = $this->countingClient((string) json_encode(['keys' => []]));
        $discovery = new DiscoveryDocument('https://rotation.test', $this->cacheDir, $client);

        $starting = \Jose\Component\Core\JWKSet::createFromKeyData(
            ['keys' => [$this->rsaPublicJwk('r-1')]],
        );
        $validator = new IdTokenValidator($discovery, $starting, $client);

        $token = $this->compactJws(
            ['alg' => 'RS256', 'typ' => 'JWT'],
            ['iss' => 'https://rotation.test', 'aud' => 'client', 'exp' => time() + 3600],
        );

        try {
            $validator->validate($token, 'client');
        } catch (OidcValidationException $e) {
            self::assertSame('Token signature verification failed', $e->getMessage());
        }

        self::assertSame(0, $this->calls['jwks']);
    }
}
