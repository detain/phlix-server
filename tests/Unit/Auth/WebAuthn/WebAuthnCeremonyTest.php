<?php

/**
 * Phlix media server test: WebAuthn ceremony round-trips (M-2, security audit
 * 2026-09-29).
 *
 * WHY THIS FILE EXISTS: the old suite asserted that an options array had a
 * 'challenge' key — it never once proved a ceremony COMPLETES, which is how a
 * verifier with no signature check shipped green. These tests drive the real
 * manager end to end against an in-memory fake DB (challenge store SQL
 * included): software-generated Ed25519 and ES256 keys sign genuine
 * authenticatorData||SHA-256(clientDataJSON) assertions, and every rejected
 * path that matters — forged signature, wrong origin, replayed challenge,
 * counter rollback, missing UV, foreign rpId, misplaced AT, wrong ceremony
 * type, unsupported key, owner mismatch — must fail loudly.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\WebAuthn;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnChallengeStore;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Auth\WebAuthn\WebAuthnSettings;
use Phlix\Shared\Auth\AuthResult;
use Workerman\MySQL\Connection;

final class WebAuthnCeremonyTest extends TestCase
{
    private const ALICE_ID = '550e8400-e29b-41d4-a716-446655440001';
    private const BOB_ID = '550e8400-e29b-41d4-a716-446655440002';
    private const RP_ID = 'localhost';
    private const ORIGIN = 'https://localhost';

    /** @var array<string, array<string, mixed>> fake `users` rows by id */
    private array $users = [];

    /** @var array<string, array<string, mixed>> fake `webauthn_credentials` rows by raw credential id */
    private array $credentials = [];

    /** @var array<string, array{data: string, expires: int}> fake `oauth_state_store` rows by state_value */
    private array $stateRows = [];

    /** @var Connection&MockObject */
    private Connection $db;

    private WebAuthnManager $manager;

    protected function setUp(): void
    {
        $this->users = [
            self::ALICE_ID => [
                'id' => self::ALICE_ID,
                'username' => 'alice',
                'email' => 'alice@example.com',
                'status' => 'active',
            ],
            self::BOB_ID => [
                'id' => self::BOB_ID,
                'username' => 'bob',
                'email' => 'bob@example.com',
                'status' => 'active',
            ],
        ];
        $this->credentials = [];
        $this->stateRows = [];

        $this->db = $this->createMock(Connection::class);
        $this->db->method('query')->willReturnCallback(
            /** @param array<int, mixed> $params */
            function (string $sql, array $params = []) {
                return $this->fakeQuery($sql, $params);
            }
        );

        $settings = new WebAuthnSettings(
            rpId: self::RP_ID,
            rpName: 'Test RP',
            rpOrigin: self::ORIGIN,
            attestationRequired: false
        );

        $this->manager = new WebAuthnManager(
            new UserRepository($this->db),
            $this->db,
            new WebAuthnCredentialRepository($this->db),
            $settings,
            null,
            new WebAuthnChallengeStore($this->db)
        );
    }

    // ------------------------------------------------------------------
    // Happy paths
    // ------------------------------------------------------------------

    public function test_ed25519_full_ceremony_authenticates_the_credential_owner(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);

        $this->registerCredential($credentialId, $coseKey, 0);

        $authResult = $this->authenticate($credentialId, $secret, 5);

        $this->assertInstanceOf(AuthResult::class, $authResult);
        $this->assertTrue($authResult->success);
        $this->assertSame(self::ALICE_ID, $authResult->userId);
        $this->assertStringStartsWith('webauthn:', $authResult->externalId);
        // Counter advanced on the stored row.
        $this->assertSame(5, (int) $this->credentials[self::rawIdKey($credentialId)]['counter']);
    }

    public function test_es256_full_ceremony_authenticates_the_credential_owner(): void
    {
        [$privateKey, $coseKey] = self::es256Material();
        $credentialId = random_bytes(20);

        $this->registerCredential($credentialId, $coseKey, 0);

        $clientData = self::clientData('webauthn.get', $this->startAuthChallenge());
        $authData = self::assertionAuthData(7);
        $der = '';
        openssl_sign(
            $authData . hash('sha256', $clientData, true),
            $der,
            $privateKey,
            OPENSSL_ALGO_SHA256
        );

        $result = $this->manager->finishAuthentication(
            'alice',
            [
                'id' => self::b64url($credentialId),
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url(self::derToRawEcSignature($der)),
            ],
            self::$lastChallenge
        );

        $this->assertTrue($result->success);
        $this->assertSame(self::ALICE_ID, $result->userId);
    }

    public function test_options_payloads_are_json_encodable(): void
    {
        // The 500 this pins away: raw bytes / VO objects with byte-string ids
        // reached json_encode(JSON_THROW_ON_ERROR) before.
        $registration = $this->manager->startRegistration(self::ALICE_ID, 'alice');
        $this->assertIsString(json_encode($registration, JSON_THROW_ON_ERROR));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $registration['challenge']);

        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, self::ed25519Material()[1], 0);

        $authentication = $this->manager->startAuthentication('alice');
        $this->assertIsString(json_encode($authentication, JSON_THROW_ON_ERROR));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $authentication['challenge']);
        $this->assertSame('required', $authentication['userVerification']);
    }

    // ------------------------------------------------------------------
    // Negative paths — each is a way the pre-fix code minted tokens it
    // must never mint.
    // ------------------------------------------------------------------

    public function test_forged_signature_is_rejected(): void
    {
        [, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 0);

        $clientData = self::clientData('webauthn.get', $this->startAuthChallenge());
        $authData = self::assertionAuthData(5);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid assertion signature');

        $this->manager->finishAuthentication(
            'alice',
            [
                'id' => self::b64url($credentialId),
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url(random_bytes(64)),
            ],
            self::$lastChallenge
        );
    }

    public function test_origin_mismatch_is_rejected(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 0);

        $challenge = $this->startAuthChallenge();
        // Signed honestly — but by a page on evil.example.
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://evil.example',
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
        $authData = self::assertionAuthData(5);
        $signature = sodium_crypto_sign_detached(
            $authData . hash('sha256', $clientData, true),
            $secret
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Origin not allowed');

        $this->manager->finishAuthentication(
            'alice',
            [
                'id' => self::b64url($credentialId),
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url($signature),
            ],
            $challenge
        );
    }

    public function test_replayed_challenge_is_rejected(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 0);

        $challenge = $this->startAuthChallenge();
        $clientData = self::clientData('webauthn.get', $challenge);
        $authData = self::assertionAuthData(5);
        $signature = sodium_crypto_sign_detached(
            $authData . hash('sha256', $clientData, true),
            $secret
        );
        $payload = [
            'id' => self::b64url($credentialId),
            'clientDataJSON' => self::b64url($clientData),
            'authenticatorData' => self::b64url($authData),
            'signature' => self::b64url($signature),
        ];

        $this->manager->finishAuthentication('alice', $payload, $challenge);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired authentication challenge');
        $this->manager->finishAuthentication('alice', $payload, $challenge);
    }

    public function test_unknown_challenge_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired registration challenge');

        $this->manager->finishRegistration(
            self::ALICE_ID,
            'alice',
            ['attestationObject' => 'AAAA', 'clientDataJSON' => 'AAAA'],
            'never-issued-challenge'
        );
    }

    public function test_counter_rollback_is_rejected_and_increase_accepted(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 10);

        // stored 10 > 0: presenting 9 (or 10) is a rollback → reject…
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Potential replay attack detected');
        $this->attemptAuthentication($credentialId, $secret, 9);
    }

    public function test_counter_equal_to_stored_is_rejected(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 10);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Potential replay attack detected');
        $this->attemptAuthentication($credentialId, $secret, 10);
    }

    public function test_counter_increase_is_accepted(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 10);

        $result = $this->attemptAuthentication($credentialId, $secret, 11);
        $this->assertTrue($result->success);
    }

    public function test_missing_user_verification_flag_is_rejected(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 0);

        $challenge = $this->startAuthChallenge();
        $clientData = self::clientData('webauthn.get', $challenge);
        // UP set, UV clear.
        $authData = self::authData(0x01, 5);
        $signature = sodium_crypto_sign_detached(
            $authData . hash('sha256', $clientData, true),
            $secret
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User verification flag not set');

        $this->manager->finishAuthentication(
            'alice',
            [
                'id' => self::b64url($credentialId),
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url($signature),
            ],
            $challenge
        );
    }

    public function test_foreign_rpid_hash_is_rejected(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 0);

        $challenge = $this->startAuthChallenge();
        $clientData = self::clientData('webauthn.get', $challenge);
        $authData = hash('sha256', 'evil.example', true) . chr(0x05) . pack('N', 5);
        $signature = sodium_crypto_sign_detached(
            $authData . hash('sha256', $clientData, true),
            $secret
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rpId hash mismatch');

        $this->manager->finishAuthentication(
            'alice',
            [
                'id' => self::b64url($credentialId),
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url($signature),
            ],
            $challenge
        );
    }

    public function test_attested_credential_data_in_assertion_is_rejected(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 0);

        $challenge = $this->startAuthChallenge();
        $clientData = self::clientData('webauthn.get', $challenge);
        // AT bit set on what must be a bare assertion.
        $authData = self::authData(0x45, 5) . random_bytes(40);
        $signature = sodium_crypto_sign_detached(
            $authData . hash('sha256', $clientData, true),
            $secret
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected attested credential data');

        $this->manager->finishAuthentication(
            'alice',
            [
                'id' => self::b64url($credentialId),
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url($signature),
            ],
            $challenge
        );
    }

    public function test_wrong_ceremony_type_in_registration_is_rejected(): void
    {
        [$secret, $coseKey] = self::ed25519Material();

        $options = $this->manager->startRegistration(self::ALICE_ID, 'alice');
        $challenge = (string) $options['challenge'];
        $credentialId = random_bytes(16);

        // Assertion-type clientData replayed into the CREATE ceremony.
        $clientData = self::clientData('webauthn.get', $challenge);
        $attestationObject = self::attestationObject(
            self::registrationAuthData($credentialId, $coseKey, 0),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ceremony type');

        $this->manager->finishRegistration(
            self::ALICE_ID,
            'alice',
            [
                'attestationObject' => self::b64url($attestationObject),
                'clientDataJSON' => self::b64url($clientData),
            ],
            $challenge
        );
        unset($secret);
    }

    public function test_unsupported_cose_key_is_rejected_at_registration(): void
    {
        // RSA (kty 3 / alg -257): we offer only ES256 + EdDSA, and a key the
        // verifier could not check must never reach storage.
        $coseKey = hex2bin('a2010303190101');

        $options = $this->manager->startRegistration(self::ALICE_ID, 'alice');
        $challenge = (string) $options['challenge'];
        $clientData = self::clientData('webauthn.create', $challenge);
        $attestationObject = self::attestationObject(
            self::registrationAuthData(random_bytes(16), $coseKey, 0),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported credential public key');

        $this->manager->finishRegistration(
            self::ALICE_ID,
            'alice',
            [
                'attestationObject' => self::b64url($attestationObject),
                'clientDataJSON' => self::b64url($clientData),
            ],
            $challenge
        );
    }

    public function test_credential_presented_under_the_wrong_username_is_rejected(): void
    {
        [$secret, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);
        $this->registerCredential($credentialId, $coseKey, 0);
        // Bob needs a registered credential of his own so startAuthentication
        // ('bob') succeeds — his is the decoy, ALICE's key material signs.
        $this->seedCredential('bob-decoy-raw', self::BOB_ID, $coseKey, 0);

        $challenge = $this->startAuthChallenge('bob');
        $clientData = self::clientData('webauthn.get', $challenge);
        $authData = self::assertionAuthData(5);
        $signature = sodium_crypto_sign_detached(
            $authData . hash('sha256', $clientData, true),
            $secret
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credential does not belong to this user');

        $this->manager->finishAuthentication(
            'bob',
            [
                'id' => self::b64url($credentialId),
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url($signature),
            ],
            $challenge
        );
    }

    public function test_registration_consumes_its_challenge_once(): void
    {
        [, $coseKey] = self::ed25519Material();
        $credentialId = random_bytes(16);

        $challenge = $this->registerCredential($credentialId, $coseKey, 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired registration challenge');

        // Same ceremony's challenge, presented a second time: the row is gone.
        $clientData = self::clientData('webauthn.create', $challenge);
        $attestationObject = self::attestationObject(
            self::registrationAuthData($credentialId, $coseKey, 0),
        );
        $this->manager->finishRegistration(
            self::ALICE_ID,
            'alice',
            [
                'attestationObject' => self::b64url($attestationObject),
                'clientDataJSON' => self::b64url($clientData),
            ],
            $challenge
        );
    }

    // ------------------------------------------------------------------
    // Ceremony drivers
    // ------------------------------------------------------------------

    /** Most recently issued challenge (fake store is a map; this tracks the last start*). */
    private static string $lastChallenge = '';

    private function startAuthChallenge(string $username = 'alice'): string
    {
        $options = $this->manager->startAuthentication($username);
        self::$lastChallenge = (string) $options['challenge'];
        return self::$lastChallenge;
    }

    /**
     * Run the full registration ceremony through the manager (real options,
     * real finish) so the stored row carries genuine COSE material.
     *
     * @return string The consumed challenge (for replay tests).
     */
    private function registerCredential(string $credentialId, string $coseKey, int $counter): string
    {
        $options = $this->manager->startRegistration(self::ALICE_ID, 'alice');
        $challenge = (string) $options['challenge'];
        self::$lastChallenge = $challenge;

        $clientData = self::clientData('webauthn.create', $challenge);
        $attestationObject = self::attestationObject(
            self::registrationAuthData($credentialId, $coseKey, $counter),
        );

        $returned = $this->manager->finishRegistration(
            self::ALICE_ID,
            'alice',
            [
                'attestationObject' => self::b64url($attestationObject),
                'clientDataJSON' => self::b64url($clientData),
            ],
            $challenge
        );

        $this->assertSame(base64_encode($credentialId), $returned);
        return $challenge;
    }

    private function authenticate(string $credentialId, string $secret, int $counter): AuthResult
    {
        $challenge = $this->startAuthChallenge();
        return $this->attemptAuthentication($credentialId, $secret, $counter, $challenge);
    }

    private function attemptAuthentication(
        string $credentialId,
        string $secret,
        int $counter,
        ?string $challenge = null
    ): AuthResult {
        if ($challenge === null) {
            $challenge = $this->startAuthChallenge();
        }
        $clientData = self::clientData('webauthn.get', $challenge);
        $authData = self::assertionAuthData($counter);
        $signature = sodium_crypto_sign_detached(
            $authData . hash('sha256', $clientData, true),
            $secret
        );

        return $this->manager->finishAuthentication(
            'alice',
            [
                'id' => self::b64url($credentialId),
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url($signature),
            ],
            $challenge
        );
    }

    /** Direct seed that bypasses the manager (fake rows the repo can hydrate). */
    private function seedCredential(string $credentialId, string $userId, string $coseKey, int $counter): void
    {
        $this->credentials[$credentialId] = [
            'id' => 'row-' . substr(md5($credentialId), 0, 8),
            'user_id' => $userId,
            'credential_id' => $credentialId,
            'public_key' => $coseKey,
            'counter' => (string) $counter,
            'type' => 'public-key',
            'device_type' => null,
            'aaguid' => str_repeat("\0", 16),
            'registered_at' => time(),
        ];
    }

    // ------------------------------------------------------------------
    // Key material (software authenticators)
    // ------------------------------------------------------------------

    /** @return array{0: string, 1: string} [Ed25519 secret key (64B), COSE key bytes] */
    private static function ed25519Material(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        // COSE label ints are NEGATIVE: -1 encodes 0x20, -2 0x21, -3 0x22.
        $cose = hex2bin('a40101' . '0327' . '2003' . '215820') . $public;
        return [substr($pair, 0, 64), $cose];
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} [private key, COSE key bytes] */
    private static function es256Material(): array
    {
        $private = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        $x = str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        // a5: FIVE entries (kty, alg, crv, x, y) — a4 here silently truncates
        // the map and drops y (the decoder stops at the declared size).
        $cose = hex2bin('a50102' . '0326' . '2001' . '215820') . $x . hex2bin('225820') . $y;
        return [$private, $cose];
    }

    /**
     * ECDSA DER signature → fixed-width raw r||s (64B) — WebAuthn's wire form
     * and what Cose ES256::verify consumes.
     */
    private static function derToRawEcSignature(string $der): string
    {
        // 30 <len> 02 <rlen> <r> 02 <slen> <s>
        $offset = 0;
        if (ord($der[$offset]) !== 0x30) {
            self::fail('not a DER sequence');
        }
        $offset += 2; // SEQ + its (short-form) length
        $readInt = static function (string $der, int &$offset): string {
            if (ord($der[$offset]) !== 0x02) {
                self::fail('not a DER integer');
            }
            $len = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $len);
            $offset += 2 + $len;
            return ltrim($value, "\x00") === '' ? "\x00" : ltrim($value, "\x00");
        };
        $r = $readInt($der, $offset);
        $s = $readInt($der, $offset);

        return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // Wire-format builders (WebAuthn spec §6.1 / §6.5 layout constants)
    // ------------------------------------------------------------------

    private static function clientData(string $type, string $challenge): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => self::ORIGIN,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
    }

    private static function registrationAuthData(string $credentialId, string $coseKey, int $counter): string
    {
        return hash('sha256', self::RP_ID, true)
            . chr(0x45) // UP | UV | AT
            . pack('N', $counter)
            . str_repeat("\0", 16) // aaguid (none attestation)
            . pack('n', strlen($credentialId))
            . $credentialId
            . $coseKey;
    }

    private static function assertionAuthData(int $counter): string
    {
        return self::authData(0x05, $counter); // UP | UV, no AT
    }

    private static function authData(int $flags, int $counter): string
    {
        return hash('sha256', self::RP_ID, true) . chr($flags) . pack('N', $counter);
    }

    /** Packed `none`-attestation object around an authenticatorData blob. */
    private static function attestationObject(string $authData): string
    {
        return hex2bin('a3') // map(3)
            . self::cborText('fmt') . self::cborText('none')
            . self::cborText('attStmt') . hex2bin('a0')
            . self::cborText('authData') . self::cborBytes($authData);
    }

    private static function cborText(string $value): string
    {
        return self::cborHead(3, strlen($value)) . $value;
    }

    private static function cborBytes(string $value): string
    {
        return self::cborHead(2, strlen($value)) . $value;
    }

    private static function cborHead(int $major, int $argument): string
    {
        $high = $major << 5;
        if ($argument < 24) {
            return chr($high | $argument);
        }
        if ($argument < 256) {
            return chr($high | 24) . chr($argument);
        }
        return chr($high | 25) . pack('n', $argument);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function rawIdKey(string $credentialId): string
    {
        return $credentialId;
    }

    // ------------------------------------------------------------------
    // Fake DB
    // ------------------------------------------------------------------

    /** @param array<int, mixed> $params */
    private function fakeQuery(string $sql, array $params): mixed
    {
        // --- oauth_state_store (WebAuthnChallengeStore) ---
        if (str_contains($sql, 'INSERT INTO oauth_state_store')) {
            $this->stateRows[(string) $params[2]] = [
                'data' => (string) $params[3],
                'expires' => time() + 300,
            ];
            return '0'; // insert return shape: lastInsertId string
        }
        if (str_contains($sql, 'DELETE FROM oauth_state_store') && str_contains($sql, 'expires_at <= NOW()')) {
            $before = count($this->stateRows);
            foreach ($this->stateRows as $key => $row) {
                if ($row['expires'] <= time()) {
                    unset($this->stateRows[$key]);
                }
            }
            return $before - count($this->stateRows);
        }
        if (str_contains($sql, 'SELECT data FROM oauth_state_store')) {
            $key = (string) $params[1];
            $row = $this->stateRows[$key] ?? null;
            if ($row === null || $row['expires'] <= time()) {
                return [];
            }
            return [['data' => $row['data']]];
        }
        if (str_contains($sql, 'DELETE FROM oauth_state_store')) {
            $key = (string) $params[1];
            if (!isset($this->stateRows[$key])) {
                return 0;
            }
            unset($this->stateRows[$key]);
            return 1;
        }

        // --- users ---
        if (str_contains($sql, 'SELECT * FROM users WHERE id = ?')) {
            $row = $this->users[(string) $params[0]] ?? null;
            return $row === null ? [] : [$row];
        }
        if (str_contains($sql, 'SELECT * FROM users WHERE username = ?')) {
            foreach ($this->users as $row) {
                if ($row['username'] === $params[0]) {
                    return [$row];
                }
            }
            return [];
        }

        // --- webauthn_credentials ---
        if (str_contains($sql, 'INSERT INTO webauthn_credentials')) {
            $this->credentials[(string) $params[2]] = [
                'id' => (string) $params[0],
                'user_id' => (string) $params[1],
                'credential_id' => (string) $params[2],
                'public_key' => (string) $params[3],
                'counter' => (string) $params[4],
                'type' => (string) $params[5],
                'device_type' => $params[6],
                'aaguid' => $params[7],
                'registered_at' => (int) $params[8],
            ];
            return '0';
        }
        if (str_contains($sql, 'SELECT * FROM webauthn_credentials WHERE credential_id = ?')) {
            $row = $this->credentials[(string) $params[0]] ?? null;
            return $row === null ? [] : [$row];
        }
        if (str_contains($sql, 'SELECT * FROM webauthn_credentials WHERE user_id = ?')) {
            return array_values(array_filter(
                $this->credentials,
                static fn (array $row): bool => $row['user_id'] === $params[0]
            ));
        }
        if (str_contains($sql, 'UPDATE webauthn_credentials SET counter')) {
            $key = (string) $params[1];
            if (isset($this->credentials[$key])) {
                $this->credentials[$key]['counter'] = (string) $params[0];
                return 1;
            }
            return 0;
        }
        if (str_contains($sql, 'DELETE FROM webauthn_credentials')) {
            $key = (string) $params[0];
            if (
                isset($this->credentials[$key])
                && ($this->credentials[$key]['user_id'] ?? null) === ($params[1] ?? null)
            ) {
                unset($this->credentials[$key]);
                return 1;
            }
            return 0;
        }

        self::fail('unexpected SQL in fake DB: ' . $sql);
    }
}
