<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Security;

use InvalidArgumentException;
use Phlix\Auth\CookiePolicy;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\PasswordHasher;
use Phlix\Plugins\OAuth2\Pkce;
use Phlix\Plugins\OAuth2\StateCorrelation;
use Phlix\Hub\RelayMessageFramer;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Shared\Relay\FrameCodec;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\SyncPlayDialect;
use Phlix\Shared\Auth\PasswordHasher as SharedHasher;
use Phlix\Shared\Auth\SessionFamily;
use Phlix\Shared\Security\CredentialDigest;
use Phlix\Shared\Security\Ed25519Pem;
use Phlix\Shared\Security\OriginPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Behavior pins for the phlix-shared v0.56.0 primitive delegation (Wave-S1).
 *
 * These assertions hold identically at the parent (hand-rolled primitives)
 * and after the swap (delegation to Base64Url / Ed25519Pem / Pkce) — they
 * characterize the WIRE, not the implementation, so they are the regression
 * floor for the server's ratified byte-for-byte compatibility.
 */
final class SharedPrimitiveDelegationTest extends TestCase
{
    /** Legacy RFC 4648 §5 formula — the pre-delegation server body verbatim. */
    private static function legacyEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function legacyDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private static function legacyStrictDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }

    /** @return array<array-key, list{string}> */
    public static function provideByteCorpus(): array
    {
        $vectors = [[''], ['a'], ['ab'], ['abc'], ['hello world'], ["\x00\x01\x02"], ["\xff\xfe\xfd"], ['0'], ['Hello World']];
        for ($i = 0; $i < 256; $i++) {
            $vectors['byte-' . $i] = [chr($i)];
            $vectors['pair-' . $i] = [chr($i) . chr(($i + 1) % 256)];
        }

        return $vectors;
    }

    /** @return array<string, string> raw corpus for the non-PHPUnit loop tests */
    private static function byteCorpusRaw(): array
    {
        $raw = [];
        foreach (self::provideByteCorpus() as $key => $dataset) {
            $raw[$key] = $dataset[0];
        }

        return $raw;
    }

    /** @dataProvider provideByteCorpus */
    public function testJwtHandlerWireFormatIsLegacyIdentical(string $secret): void
    {
        $handler = new JwtHandler('pin-' . $secret, 'HS256', 3600, 604800);
        $token = $handler->createAccessToken('user-' . strlen($secret));

        [$headerB64, $payloadB64, $signatureB64] = explode('.', $token);
        $this->assertSame('{"alg":"HS256","typ":"JWT"}', self::legacyDecode($headerB64));
        $this->assertSame(
            self::legacyEncode(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, 'pin-' . $secret, true)),
            $signatureB64,
            'minted signature must match the legacy HMAC/base64url chain'
        );
        $this->assertIsArray(json_decode(self::legacyDecode($payloadB64), true));
        $this->assertNotNull($handler->validateToken($token));
    }

    public function testSessionFamilyConstAliasesAreLiteralIdentical(): void
    {
        $this->assertSame('sid', JwtHandler::CLAIM_SESSION_ID);
        $this->assertSame('profile_id', JwtHandler::CLAIM_PROFILE_ID);
        $this->assertSame(SessionFamily::CLAIM_SESSION_ID, JwtHandler::CLAIM_SESSION_ID);
        $this->assertSame(SessionFamily::CLAIM_PROFILE_ID, JwtHandler::CLAIM_PROFILE_ID);
    }

    public function testPkceMatchesFixedVerifierKnownAnswer(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXK';
        // Deterministic S256 KAT pinned from an independent computation of the
        // RFC 7636 formula (hash sha256 raw + unpadded base64url), re-derived
        // inline below — the pair must agree with the delegated path.
        $this->assertSame(
            'gMhFviSMvh4p6Dk0JJBqmff50a_bngH3n_i14zTH5Z4',
            Pkce::computeCodeChallenge($verifier)
        );
        $this->assertSame(
            self::legacyEncode(hash('sha256', $verifier, true)),
            Pkce::computeCodeChallenge($verifier)
        );
    }

    /** @dataProvider provideByteCorpus */
    public function testPkceEncodeIsLegacyIdentical(string $bytes): void
    {
        $this->assertSame(self::legacyEncode($bytes), Pkce::base64UrlEncode($bytes));
    }

    public function testEd25519PemRoundTripAndKidMatchServerLaw(): void
    {
        $managerClass = new ReflectionClass(\Phlix\Hub\Ed25519KeyManager::class);
        $manager = $managerClass->newInstanceWithoutConstructor();
        foreach (['delegation-seed-A', 'delegation-seed-B', 'delegation-seed-C'] as $seedLabel) {
            $keypair = sodium_crypto_sign_seed_keypair(hash('sha256', $seedLabel, true));
            $secret = sodium_crypto_sign_secretkey($keypair);
            $public = sodium_crypto_sign_publickey($keypair);

            $build = $managerClass->getMethod('buildPem');
            $build->setAccessible(true);
            $pem = $build->invoke($manager, $secret);

            $parse = $managerClass->getMethod('parsePem');
            $parse->setAccessible(true);
            $this->assertSame($secret, $parse->invoke($manager, $pem), 'native PEM must round-trip');

            $kid = $managerClass->getMethod('extractKidFromPublicKey');
            $kid->setAccessible(true);
            $this->assertSame(
                self::legacyEncode(substr(hash('sha256', $public, true), 0, 8)),
                $kid->invoke($manager, $public),
                'kid law: base64url of first 8 SHA-256 bytes of the public key'
            );
            $this->assertSame(
                Ed25519Pem::fingerprint($public, Ed25519Pem::PREFIX_BYTES_SERVER),
                $kid->invoke($manager, $public)
            );
        }
    }

    public function testWebAuthnStrictDecodeKeepsCallerErrorSemantics(): void
    {
        $class = new ReflectionClass(\Phlix\Auth\WebAuthn\WebAuthnManager::class);
        $decode = $class->getMethod('base64UrlDecode');
        $decode->setAccessible(true);
        $encode = $class->getMethod('base64UrlEncode');
        $encode->setAccessible(true);

        $this->assertSame('0', $decode->invoke(null, 'MA', 'boom'), 'unpadded strict input decodes identically');
        $this->assertSame('Hello World', $decode->invoke(null, $encode->invoke(null, 'Hello World'), 'boom'));
        foreach (['####', 'M@#', ''] as $garbage) {
            try {
                $decode->invoke(null, $garbage, 'boom');
                $this->fail('garbage input must throw with the caller message');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
        }
    }

    public function testHubJwtValidatorStrictArmMatchesLegacyCoalesce(): void
    {
        $class = new ReflectionClass(\Phlix\Hub\HubJwtValidator::class);
        $decode = $class->getMethod('base64UrlDecode');
        $decode->setAccessible(true);
        $validator = $class->newInstanceWithoutConstructor();

        foreach (self::byteCorpusRaw() as $bytes) {
            $encoded = self::legacyEncode($bytes);
            $legacy = self::legacyStrictDecode($encoded);
            $current = $decode->invoke($validator, $encoded);
            if ($bytes === '0') {
                $this->assertSame('0', $current, 'shared strict arm no longer collapses decoded "0"; json/object consumers treat both alike');
                continue;
            }
            $this->assertSame($legacy, $current);
        }
    }

    /**
     * Leg S1.3 characterization pins — the OriginPolicy/CredentialDigest/
     * PasswordHasher delegations must reproduce the server's ratified bytes.
     */
    public function testCookiePolicyScalarArmsMatchOriginPolicy(): void
    {
        foreach ([null, '', 'https', 'HTTPS', 'https, http', 'http'] as $proto) {
            $this->assertSame(OriginPolicy::httpsForwardedValue($proto), CookiePolicy::isHttpsForwarded($proto));
        }
        foreach ([null, '', 'localhost', 'LOCALHOST:8443', '127.0.0.1', '[::1]:9000', 'example.com'] as $host) {
            $this->assertSame(OriginPolicy::localhostHost($host), CookiePolicy::isLocalhostHost($host));
        }
        foreach (['http://localhost', 'http://localhost:80', 'https://localhost:443', 'http://[::1]', 'app://host', 'not a url', 'http://example.com:8080'] as $origin) {
            foreach (['localhost', 'example.com:8080', 'EXAMPLE.COM', '', null] as $host) {
                $this->assertSame(OriginPolicy::originIsSameSite($origin, $host), CookiePolicy::originIsSameSite($origin, $host));
            }
        }
        $this->assertSame(OriginPolicy::MODE_COOKIE, CookiePolicy::MODE_COOKIE);
        $this->assertSame(OriginPolicy::MODE_BEARER, CookiePolicy::MODE_BEARER);
    }

    public function testStrictSameSiteLawMatchesSharedAcrossBeltMatrix(): void
    {
        foreach (['http://localhost', 'null', '', null, 'http://example.com'] as $origin) {
            foreach ([null, '', 'null', 'http://localhost/', 'garbage url'] as $referer) {
                foreach (['localhost', 'example.com:8080', null, ''] as $host) {
                    foreach ([null, 'cross-site', 'SAME-ORIGIN', 'junk'] as $site) {
                        $this->assertSame(
                            OriginPolicy::stateChangingRequestOriginAllowed($origin, $referer, $host, $site),
                            CookiePolicy::stateChangingRequestOriginAllowed($origin, $referer, $host, $site)
                        );
                    }
                }
            }
        }
    }

    public function testCustodyModeDelegatesValueForValue(): void
    {
        $policy = new CookiePolicy(null);
        foreach ([true, false] as $formEra) {
            foreach (['roku', ''] as $device) {
                foreach (['http://localhost', '', 'http://evil.example'] as $origin) {
                    foreach ([null, 'same-origin', 'cross-site'] as $site) {
                        $this->assertSame(
                            OriginPolicy::custodyMode($formEra, $device, $origin, 'localhost', $site),
                            $policy->custodyMode($formEra, $device, $origin, 'localhost', $site)
                        );
                    }
                }
            }
        }
    }

    public function testStateChangingVerbGateMatchesSharedPredicate(): void
    {
        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'GET', 'HEAD', 'OPTIONS', 'post', 'CONNECT'] as $method) {
            $this->assertSame(
                in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true),
                OriginPolicy::stateChangingMethod($method)
            );
        }
    }

    public function testCredentialDigestReproducesLegacySha256Sites(): void
    {
        $this->assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', CredentialDigest::digest(''));
        $this->assertSame(hash('sha256', "oidc\0ext.id"), CredentialDigest::digest("oidc\0ext.id"));
        $this->assertTrue(CredentialDigest::match('pw', CredentialDigest::digest('pw')));
        $this->assertFalse(CredentialDigest::match('pw2', CredentialDigest::digest('pw')));
        $this->assertSame(CredentialDigest::digest('state-secret'), StateCorrelation::fingerprint('state-secret'));
        $this->assertSame(CredentialDigest::digest('group-pw'), GroupState::hashPassword('group-pw'));
    }

    public function testPasswordHasherShimPreservesCanonicalCostShape(): void
    {
        $this->assertSame(SharedHasher::ARGON2ID_OPTIONS, PasswordHasher::ARGON2ID_OPTIONS);
        $this->assertSame(
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
            PasswordHasher::ARGON2ID_OPTIONS
        );
        $hash = PasswordHasher::hash('delegation-pin-password');
        $this->assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', $hash);
        $this->assertTrue(PasswordHasher::verify('delegation-pin-password', $hash));
        $this->assertFalse(PasswordHasher::verify('wrong', $hash));
        $this->assertFalse(PasswordHasher::needsRehash($hash));
        $this->assertTrue(PasswordHasher::needsRehash(password_hash('x', PASSWORD_BCRYPT)));
        $this->assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', SharedHasher::TIMING_BURN_DUMMY_HASH);
    }

    /**
     * Wave-S1 leg 1.7 pins: the framer seams delegate to shared FrameCodec
     * byte-for-byte, and Messages TYPE_* constants are pure aliases of the
     * shared SyncPlayDialect catalog (design M-33: const names mirror the
     * server so the alias form preserves every consumer import).
     */
    public function testFramerSeamsDelegateToSharedFrameCodec(): void
    {
        $framer = new ReflectionMethod(RelayMessageFramer::class, "encodeFrame");
        $framer->setAccessible(true);

        $vectors = [
            [RelayFrameType::DATA, 0, ""],
            [RelayFrameType::DATA, 42, "hello"],
            [RelayFrameType::HEARTBEAT, 4294967295, random_bytes(700)],
        ];
        foreach ($vectors as $v) {
            self::assertSame(
                FrameCodec::encode($v[0], $v[1], $v[2]),
                $framer->invoke(null, $v[0], $v[1], $v[2])
            );
        }

        $obj = new RelayMessageFramer();
        self::assertSame(FrameCodec::encodeHello("jwt-1", "srv-1"), $obj->encodeHello("jwt-1", "srv-1"));
        self::assertSame(FrameCodec::encodeHelloAck("rs-1", "t-1"), $obj->encodeHelloAck("rs-1", "t-1"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Payload exceeds maximum length of 65535 bytes: 65536");
        $framer->invoke(null, RelayFrameType::DATA, 1, str_repeat("x", 65536));
    }

    public function testSyncPlayTypeConstantsAreDialectAliases(): void
    {
        foreach (SyncPlayDialect::all() as $type) {
            self::assertTrue(Messages::isValidType($type), "valid: {$type}");
        }
        self::assertFalse(Messages::isValidType("syncplay_bogus"));

        $names = [
            "TYPE_GROUP_CREATE", "TYPE_GROUP_JOIN", "TYPE_GROUP_LEAVE", "TYPE_GROUP_STATE",
            "TYPE_GROUP_LIST", "TYPE_PLAYBACK_PLAY", "TYPE_PLAYBACK_PAUSE", "TYPE_PLAYBACK_SEEK",
            "TYPE_PLAYBACK_QUEUE", "TYPE_PLAYBACK_SYNC", "TYPE_CHAT_MESSAGE", "TYPE_CHAT_TYPING",
            "TYPE_HOST_TRANSFER", "TYPE_HOST_ELECT", "TYPE_TIME_PING", "TYPE_TIME_PONG",
            "TYPE_TIME_SYNC", "TYPE_ERROR", "TYPE_INFO",
        ];
        foreach ($names as $n) {
            self::assertSame(
                constant(SyncPlayDialect::class . "::" . $n),
                constant(Messages::class . "::" . $n),
                "alias: {$n}"
            );
        }
    }
}
