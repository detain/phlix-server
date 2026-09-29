<?php

/**
 * Phlix media server component: WebAuthn.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth\WebAuthn;

use CBOR\Decoder;
use CBOR\StringStream;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Key\Ec2Key;
use Cose\Key\OkpKey;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;
use Phlix\Shared\Auth\AuthResult;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * WebAuthn registration/assertion manager.
 *
 * M-2 (security audit 2026-09-29): this class used to be a verifier-shaped
 * object that verified NOTHING. The assertion "signature check" was a comment
 * ("In a production implementation, you would verify the signature"), so any
 * client that could reach the endpoint with a stolen `credential_id` walked
 * away with a full JWT pair — possession of the secret was never demonstrated.
 * Challenges lived in a per-worker array (broken under the 14-worker pool:
 * options issued on worker 3 were invisible to the verify request on worker
 * 11) and leaked forever when a ceremony was abandoned.
 *
 * What changed, and the invariants the rest of this class exists to hold:
 *
 *  1. ASSERTIONS ARE CRYPTOGRAPHED. The signature over
 *     `authenticatorData || SHA-256(clientDataJSON)` is verified against the
 *     COSE public key captured at registration (ES256 and EdDSA). A failed or
 *     unsupported verification REJECTS the ceremony; nothing about the
 *     presented identity is trusted otherwise.
 *  2. CHALLENGES ARE SERVER STATE. {@see WebAuthnChallengeStore} (DB-backed,
 *     one-shot consume under a row lock, 5-minute TTL) replaced the in-worker
 *     maps. The browser echoes the exact base64url challenge string it was
 *     handed; the clientDataJSON `challenge` must equal it byte-for-byte.
 *  3. ORIGIN AND rpId ARE ENFORCED. clientDataJSON `origin` must be in the
 *     configured allow-list (WebAuthnSettings::effectiveOrigins()) and
 *     authenticatorData's rpIdHash must be SHA-256 of the configured rpId —
 *     a credential minted for evil.example can never authenticate here.
 *  4. USER VERIFICATION IS REQUIRED. The UV flag (plus UP) must be set on
 *     both ceremonies; options ask authenticators for `required` so real
 *     devices do not skip it.
 *  5. THE COUNTER IS MONOTONIC. A replay/stale-counter presentation is
 *     rejected (with the standard exception for non-counting authenticators,
 *     stored counter 0).
 *  6. THE CREDENTIAL'S OWNER IS THE USER. finishAuthentication trusts the
 *     STORED credential row's `user_id`, never anything the client presents;
 *     the claimed username must resolve to that same id.
 *
 * Attestation statements (packed/none/etc.) are PARSED for the credential
 * material but their trust anchors are not validated — attestation answers
 * "what model of authenticator is this", not "does this client hold the
 * secret"; the latter (point 1) is what gated the minted JWT all along.
 */
class WebAuthnManager
{
    // --- COSE algorithm/key-type ids (RFC 9052/9053 registry) ---
    private const COSE_KTY_OKP = 1;
    private const COSE_KTY_EC2 = 2;
    private const COSE_ALG_ES256 = -7;
    private const COSE_ALG_EDDSA = -8;
    private const COSE_EC2_CRV_P256 = 1;
    private const COSE_OKP_CRV_ED25519_WIRE = 3;

    // --- AuthenticatorData flag bits (WebAuthn spec §6.1) ---
    private const FLAG_USER_PRESENT = 0x01;
    private const FLAG_USER_VERIFIED = 0x04;
    private const FLAG_ATTESTED_CRED_DATA = 0x40;

    /** Fixed AuthenticatorData prologue: rpIdHash(32) || flags(1) || signCount(4). */
    private const AUTH_DATA_MIN_LENGTH = 37;
    /** AttestedCredentialData inside the AT payload: aaguid(16) || credIdLen(2). */
    private const ATTESTED_DATA_HEADER_LENGTH = 18;

    private UserRepository $userRepo;
    private ?StructuredLogger $logger;
    private WebAuthnSettings $settings;
    private WebAuthnCredentialRepository $credentialRepo;
    private WebAuthnChallengeStore $challengeStore;

    public function __construct(
        UserRepository $userRepo,
        Connection $db,
        WebAuthnCredentialRepository $credentialRepo,
        WebAuthnSettings $settings,
        ?StructuredLogger $logger = null,
        ?WebAuthnChallengeStore $challengeStore = null
    ) {
        $this->userRepo = $userRepo;
        $this->credentialRepo = $credentialRepo;
        $this->settings = $settings;
        $this->logger = $logger;
        // The DB connection stopped being decorative here (M-2): the shared
        // challenge store rides it. Explicit injection is for tests that want
        // a fake; production gets the real store built off the same $db the
        // container autowires.
        $this->challengeStore = $challengeStore ?? new WebAuthnChallengeStore($db);
    }

    /**
     * Build the `navigator.credentials.create()` options for a user.
     *
     * @return array<string, mixed> JSON-safe PublicKeyCredentialCreationOptions.
     */
    public function startRegistration(string $userId, string $username): array
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $challenge = $this->generateChallenge();
        $this->challengeStore->issue($challenge, WebAuthnChallengeStore::SCOPE_REGISTER, $userId);

        $rpId = $this->settings->rpId;

        // The descriptor/parameter VOs the old code returned serialised their
        // raw BYTES ids, so json_encode(JSON_THROW_ON_ERROR) 500'd the options
        // endpoints — WebAuthn JSON uses base64url strings for every byte
        // field, emitted as plain arrays here.
        $publicKeyCredentialParams = [
            ['type' => 'public-key', 'alg' => self::COSE_ALG_ES256],
            ['type' => 'public-key', 'alg' => self::COSE_ALG_EDDSA],
        ];

        $excludeCredentials = [];
        $existingCredentials = $this->credentialRepo->findByUserId($userId);
        foreach ($existingCredentials as $cred) {
            $excludeCredentials[] = [
                'type' => 'public-key',
                'id' => self::base64UrlEncode($cred->credentialId),
            ];
        }

        $authenticatorSelection = [
            'authenticatorAttachment' => null,
            // 'required'/'required' — spec enum strings (the old `true` bool
            // was not a valid value), and UV-required here pairs with the UV
            // flag this manager now REQUIRES during verification.
            'residentKey' => 'required',
            'userVerification' => 'required',
        ];

        $attestation = $this->settings->attestationRequired ? 'direct' : 'none';

        $timeout = 60000;

        $this->log('debug', 'Started WebAuthn registration', [
            'user_id' => $userId,
            'username' => $username,
            'rp_id' => $rpId,
        ]);

        return [
            'challenge' => $challenge,
            'rp' => [
                'id' => $rpId,
                'name' => $this->settings->rpName,
            ],
            'user' => [
                'id' => $userId,
                'name' => $username,
                'displayName' => $username,
            ],
            'pubKeyCredParams' => $publicKeyCredentialParams,
            'timeout' => $timeout,
            'excludeCredentials' => $excludeCredentials,
            'authenticatorSelection' => $authenticatorSelection,
            'attestation' => $attestation,
        ];
    }

    /**
     * Verify a registration response and persist its credential.
     *
     * @param array<array-key, mixed> $credential Decoded JSON body from the browser
     *                                            (`attestationObject`, `clientDataJSON`).
     *
     * @return string The new credential id in the standard base64 form the
     *                credentials-list API already uses (clients round-trip it
     *                back to deleteCredential, which accepts both alphabets).
     */
    public function finishRegistration(
        string $userId,
        string $username,
        array $credential,
        string $expectedChallenge
    ): string {
        $attestationObject = $credential['attestationObject'] ?? null;
        $clientDataJSON = $credential['clientDataJSON'] ?? null;

        if (!is_string($attestationObject) || $attestationObject === '') {
            throw new \InvalidArgumentException('Missing attestation data');
        }
        if (!is_string($clientDataJSON) || $clientDataJSON === '') {
            throw new \InvalidArgumentException('Missing attestation data');
        }

        // One-shot challenge consumption FIRST: an unknown/replayed/expired
        // challenge — or one issued to a different principal — never reaches
        // parsing at all (no oracle, no double-registration).
        if (!$this->challengeStore->consume($expectedChallenge, WebAuthnChallengeStore::SCOPE_REGISTER, $userId)) {
            throw new \InvalidArgumentException('Invalid or expired registration challenge');
        }

        $clientDataRaw = self::base64UrlDecode($clientDataJSON, 'Invalid client data encoding');
        $clientData = json_decode($clientDataRaw, true);
        if (!is_array($clientData)) {
            throw new \InvalidArgumentException('Invalid client data JSON');
        }

        $this->assertClientDataMatches($clientData, $expectedChallenge, 'webauthn.create');

        $attestationRaw = self::base64UrlDecode($attestationObject, 'Invalid attestation data encoding');
        $attestationMap = $this->decodeCborMap($attestationRaw, 'Invalid attestation object');

        $authDataRaw = $attestationMap['authData'] ?? null;
        if (!is_string($authDataRaw) || $authDataRaw === '') {
            throw new \InvalidArgumentException('Attestation object missing authenticator data');
        }

        $parsed = $this->parseAuthenticatorData($authDataRaw, expectAttestedCredentialData: true);
        $credentialId = (string) $parsed['credentialId'];
        $publicKeyCose = (string) $parsed['publicKey'];

        // Fail loud at the door: a key we could never verify an assertion
        // with must not be stored as if it could.
        $this->assertSupportedCoseKey($publicKeyCose);

        $id = $this->generateUuid();

        $webauthnCredential = new WebAuthnCredential(
            credentialId: $credentialId,
            userId: $userId,
            publicKey: $publicKeyCose,
            counter: (string) $parsed['counter'],
            type: 'public-key',
            deviceType: null,
            aaguid: $parsed['aaguid'],
            registeredAt: time()
        );

        $this->credentialRepo->save($webauthnCredential, $id);

        $this->log('info', 'WebAuthn credential registered', [
            'user_id' => $userId,
            'credential_id' => base64_encode($credentialId),
            'fmt' => is_string($attestationMap['fmt'] ?? null) ? $attestationMap['fmt'] : '?',
        ]);

        return base64_encode($credentialId);
    }

    /**
     * Build the `navigator.credentials.get()` options for a username.
     *
     * @return array<string, mixed> JSON-safe PublicKeyCredentialRequestOptions.
     */
    public function startAuthentication(string $username): array
    {
        $user = $this->userRepo->findByUsername($username);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $userId = $user['id'] ?? null;
        if (!is_string($userId) || $userId === '') {
            throw new \InvalidArgumentException('User not found');
        }

        $challenge = $this->generateChallenge();
        $this->challengeStore->issue($challenge, WebAuthnChallengeStore::SCOPE_AUTHENTICATE, $username);

        $credentials = $this->credentialRepo->findByUserId($userId);
        $allowCredentials = [];

        foreach ($credentials as $cred) {
            $allowCredentials[] = [
                'type' => 'public-key',
                'id' => self::base64UrlEncode($cred->credentialId),
            ];
        }

        if (empty($allowCredentials)) {
            throw new \InvalidArgumentException('No credentials registered for user');
        }

        $timeout = 60000;
        $rpId = $this->settings->rpId;

        $this->log('debug', 'Started WebAuthn authentication', [
            'username' => $username,
            'rp_id' => $rpId,
            'credential_count' => count($allowCredentials),
        ]);

        return [
            'challenge' => $challenge,
            'rpId' => $rpId,
            'allowCredentials' => $allowCredentials,
            'timeout' => $timeout,
            'userVerification' => 'required',
        ];
    }

    /**
     * Verify an assertion response: signature, challenge, origin, rpId, flags,
     * counter, and owner binding — then and only then return the AuthResult
     * the controller mints a token pair from.
     *
     * @param array<array-key, mixed> $credential Decoded JSON body from the browser
     *                                            (`id`, `clientDataJSON`, `authenticatorData`, `signature`).
     */
    public function finishAuthentication(
        string $username,
        array $credential,
        string $expectedChallenge
    ): AuthResult {
        // One-shot, principal-bound challenge consumption before any parsing.
        if (!$this->challengeStore->consume(
            $expectedChallenge,
            WebAuthnChallengeStore::SCOPE_AUTHENTICATE,
            $username
        )) {
            throw new \InvalidArgumentException('Invalid or expired authentication challenge');
        }

        $credentialId = $credential['id'] ?? null;
        $clientDataJSON = $credential['clientDataJSON'] ?? null;
        $authenticatorData = $credential['authenticatorData'] ?? null;
        $signature = $credential['signature'] ?? null;

        if (
            !is_string($credentialId) || $credentialId === ''
            || !is_string($clientDataJSON) || $clientDataJSON === ''
            || !is_string($authenticatorData) || $authenticatorData === ''
            || !is_string($signature) || $signature === ''
        ) {
            throw new \InvalidArgumentException('Missing credential data');
        }

        $decodedCredentialId = self::base64UrlDecode($credentialId, 'Invalid credential ID encoding');
        $clientDataRaw = self::base64UrlDecode($clientDataJSON, 'Invalid client data encoding');
        $authDataRaw = self::base64UrlDecode($authenticatorData, 'Invalid authenticator data encoding');
        $signatureRaw = self::base64UrlDecode($signature, 'Invalid signature encoding');

        $clientData = json_decode($clientDataRaw, true);
        if (!is_array($clientData)) {
            throw new \InvalidArgumentException('Invalid client data JSON');
        }

        $this->assertClientDataMatches($clientData, $expectedChallenge, 'webauthn.get');

        $storedCredential = $this->credentialRepo->findByCredentialId($decodedCredentialId);
        if (!$storedCredential) {
            throw new \InvalidArgumentException('Credential not found');
        }

        $parsed = $this->parseAuthenticatorData($authDataRaw, expectAttestedCredentialData: false);
        $newCounter = (int) $parsed['counter'];

        // THE check that was a comment (M-2): ECDSA/EdDSA signature over
        // authenticatorData || SHA-256(clientDataJSONRaw) against the stored
        // COSE key. Everything above only proves the client knows the
        // username; this proves the authenticator holding the private key
        // signed THIS challenge.
        $this->verifyAssertionSignature(
            $storedCredential->publicKey,
            $authDataRaw . hash('sha256', $clientDataRaw, true),
            $signatureRaw
        );

        // Counter monotonicity (spec §7.2 step 21, advisory-reduced to hard):
        // non-counting authenticators legitimately keep 0==0; once a device
        // counts, any non-increase means the counter went backwards — cloned
        // authenticator or replay.
        $storedCounter = (int) $storedCredential->counter;
        if ($storedCounter > 0 && $newCounter <= $storedCounter) {
            $this->log('warning', 'WebAuthn counter rollback detected', [
                'credential_id' => $credentialId,
                'stored' => $storedCounter,
                'presented' => $newCounter,
            ]);
            throw new \InvalidArgumentException('Potential replay attack detected');
        }

        // Owner binding: the credential row decides WHO authenticates. The
        // claimed username must resolve to the same account — otherwise a
        // holder of B's credential could name A during the ceremony.
        $claimedUserId = $this->userRepo->findByUsername($username)['id'] ?? null;
        if (!is_string($claimedUserId) || $claimedUserId !== $storedCredential->userId) {
            throw new \InvalidArgumentException('Credential does not belong to this user');
        }

        $this->credentialRepo->updateCounter($decodedCredentialId, $newCounter);

        $this->log('info', 'WebAuthn authentication successful', [
            'username' => $username,
            'user_id' => $storedCredential->userId,
        ]);

        return new AuthResult(
            success: true,
            userId: $storedCredential->userId,
            externalId: 'webauthn:' . $credentialId,
            error: null,
            attributes: [
                'username' => $username,
            ]
        );
    }

    /**
     * @return array<WebAuthnCredential>
     */
    public function listCredentials(string $userId): array
    {
        return $this->credentialRepo->findByUserId($userId);
    }

    public function deleteCredential(string $userId, string $credentialId): bool
    {
        try {
            $decoded = self::base64UrlDecode($credentialId, 'Invalid credential ID encoding');
        } catch (\InvalidArgumentException) {
            // Honest bool contract (the endpoint maps false → 404): garbage in
            // the path segment means "no such credential", not a server fault.
            return false;
        }

        $result = $this->credentialRepo->delete($decoded, $userId);

        if ($result) {
            $this->log('info', 'WebAuthn credential deleted', [
                'user_id' => $userId,
                'credential_id' => $credentialId,
            ]);
        }

        return $result;
    }

    /**
     * A fresh challenge: 32 CSPRNG bytes, handed out (and echoed back) in the
     * base64url form WebAuthn JSON uses — raw bytes in a json_encode'd options
     * payload is exactly what 500'd the endpoints before (M-2).
     */
    private function generateChallenge(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    private function generateUuid(): string
    {
        return Uuid::v4();
    }

    /**
     * The checks every ceremony shares: the browser-bound type, the exact
     * challenge echo, and the origin allow-list.
     *
     * @param array<array-key, mixed> $clientData Decoded CollectedClientData.
     */
    private function assertClientDataMatches(array $clientData, string $expectedChallenge, string $expectedType): void
    {
        $clientChallenge = $clientData['challenge'] ?? null;
        if (!is_string($clientChallenge) || !hash_equals($expectedChallenge, $clientChallenge)) {
            throw new \InvalidArgumentException('Challenge mismatch');
        }

        $clientType = $clientData['type'] ?? null;
        if (!is_string($clientType) || $clientType !== $expectedType) {
            throw new \InvalidArgumentException('Invalid ceremony type');
        }

        // Origin binding: a phlix session token minted from a credential
        // observed on evil.example is as good as a phishing kit with hardware.
        $origin = $clientData['origin'] ?? null;
        if (!is_string($origin) || !in_array($origin, $this->settings->effectiveOrigins(), true)) {
            throw new \InvalidArgumentException('Origin not allowed');
        }
    }

    /**
     * Parse + enforce the AuthenticatorData envelope.
     *
     * Byte layout (spec §6.1): rpIdHash(32) || flags(1) || signCount(u32 BE)
     * || [attestedCredentialData when AT is set] || extensions.
     *
     * @return array{flags: int, counter: int, credentialId: string|null, publicKey: string|null, aaguid: string|null}
     */
    private function parseAuthenticatorData(string $raw, bool $expectAttestedCredentialData): array
    {
        if (strlen($raw) < self::AUTH_DATA_MIN_LENGTH) {
            throw new \InvalidArgumentException('Authenticator data too short');
        }

        $rpIdHash = substr($raw, 0, 32);
        $expectedRpIdHash = hash('sha256', $this->settings->rpId, true);
        if (!hash_equals($expectedRpIdHash, $rpIdHash)) {
            throw new \InvalidArgumentException('rpId hash mismatch');
        }

        $flags = ord($raw[32]);
        if (($flags & self::FLAG_USER_PRESENT) === 0) {
            throw new \InvalidArgumentException('User presence flag not set');
        }
        if (($flags & self::FLAG_USER_VERIFIED) === 0) {
            throw new \InvalidArgumentException('User verification flag not set');
        }

        $counterUnpacked = unpack('N', substr($raw, 33, 4));
        if (!is_array($counterUnpacked) || !is_int($counterUnpacked[1])) {
            throw new \InvalidArgumentException('Malformed signature counter');
        }
        $counter = $counterUnpacked[1];

        $hasAt = ($flags & self::FLAG_ATTESTED_CRED_DATA) !== 0;
        if ($hasAt !== $expectAttestedCredentialData) {
            throw new \InvalidArgumentException(
                $expectAttestedCredentialData
                    ? 'Attested credential data missing'
                    : 'Unexpected attested credential data in assertion'
            );
        }

        if (!$hasAt) {
            return [
                'flags' => $flags,
                'counter' => $counter,
                'credentialId' => null,
                'publicKey' => null,
                'aaguid' => null,
            ];
        }

        if (strlen($raw) < self::AUTH_DATA_MIN_LENGTH + self::ATTESTED_DATA_HEADER_LENGTH) {
            throw new \InvalidArgumentException('Truncated attested credential data');
        }

        $offset = self::AUTH_DATA_MIN_LENGTH;
        $aaguid = substr($raw, $offset, 16);
        $offset += 16;
        $credLenUnpacked = unpack('n', substr($raw, $offset, 2));
        if (!is_array($credLenUnpacked) || !is_int($credLenUnpacked[1])) {
            throw new \InvalidArgumentException('Malformed credential ID length');
        }
        $credentialIdLength = $credLenUnpacked[1];
        $offset += 2;

        if ($credentialIdLength === 0 || strlen($raw) < $offset + $credentialIdLength) {
            throw new \InvalidArgumentException('Truncated credential ID');
        }
        $credentialId = substr($raw, $offset, $credentialIdLength);
        $offset += $credentialIdLength;

        // The CBOR-encoded COSE key is self-delimiting; measure it with a
        // minimal item-length walk (the vendored StringStream exposes no
        // position API, so "how many bytes did decode() eat" is unanswerable
        // without our own scan) and keep the raw slice for verbatim storage.
        $keyLength = self::cborItemLength($raw, $offset);
        if ($keyLength === 0 || strlen($raw) < $offset + $keyLength) {
            throw new \InvalidArgumentException('Malformed COSE public key');
        }
        $publicKey = substr($raw, $offset, $keyLength);

        return [
            'flags' => $flags,
            'counter' => $counter,
            'credentialId' => $credentialId,
            'publicKey' => $publicKey,
            'aaguid' => $aaguid,
        ];
    }

    /**
     * Decode a CBOR map and fail loud (spec violation / hostile bytes) on
     * anything that is not a map.
     *
     * @return array<array-key, mixed>
     */
    private function decodeCborMap(string $bytes, string $errorMessage): array
    {
        try {
            $decoded = Decoder::create()->decode(new StringStream($bytes));
        } catch (Throwable $e) {
            throw new \InvalidArgumentException($errorMessage, 0, $e);
        }

        // normalize() lives on the Normalizable trait the concrete objects
        // compose — not on the CBORObject interface the decoder returns.
        if (!method_exists($decoded, 'normalize')) {
            throw new \InvalidArgumentException($errorMessage);
        }
        $normalized = $decoded->normalize();
        if (!is_array($normalized)) {
            throw new \InvalidArgumentException($errorMessage);
        }

        return $normalized;
    }

    /**
     * Reject, at registration time, any stored key shape the verifier could
     * not check later. ES256 (EC2/P-256) and EdDSA (OKP/Ed25519) — the two
     * algorithms startRegistration offers.
     */
    private function assertSupportedCoseKey(string $coseKeyRaw): void
    {
        $key = $this->decodeCborMap($coseKeyRaw, 'Unsupported credential public key');

        $kty = self::coseInt($key, 1);
        $alg = self::coseInt($key, 3);

        if ($kty === self::COSE_KTY_EC2 && $alg === self::COSE_ALG_ES256) {
            $required = [self::coseInt($key, -1), self::coseValue($key, -2), self::coseValue($key, -3)];
            if ($required[0] === self::COSE_EC2_CRV_P256 && is_string($required[1]) && is_string($required[2])) {
                return;
            }
            throw new \InvalidArgumentException('Malformed ES256 credential public key');
        }

        if ($kty === self::COSE_KTY_OKP && $alg === self::COSE_ALG_EDDSA) {
            $crv = self::coseInt($key, -1);
            $x = self::coseValue($key, -2);
            if (
                ($crv === self::COSE_OKP_CRV_ED25519_WIRE || $crv === OkpKey::CURVE_ED25519)
                && is_string($x)
            ) {
                return;
            }
            throw new \InvalidArgumentException('Malformed EdDSA credential public key');
        }

        throw new \InvalidArgumentException('Unsupported credential public key');
    }

    /**
     * Verify the assertion signature against the stored COSE key (M-2 core).
     *
     * @throws \InvalidArgumentException on ANY failure mode: undecodable key,
     *                                   unsupported algorithm, malformed or
     *                                   invalid signature.
     */
    private function verifyAssertionSignature(string $publicKeyCose, string $signedData, string $signatureRaw): void
    {
        $key = $this->decodeCborMap($publicKeyCose, 'Stored credential public key unreadable');

        // Normalize stringifies int members — branch on the int space (coseInt),
        // never === against the raw value (coseValue would hand back '-8').
        $kty = self::coseInt($key, 1);
        $alg = self::coseInt($key, 3);

        $verified = false;

        if ($kty === self::COSE_KTY_EC2 && $alg === self::COSE_ALG_ES256) {
            $x = self::coseValue($key, -2);
            $y = self::coseValue($key, -3);
            if (is_string($x) && is_string($y)) {
                try {
                    // Wire labels ARE the Cose\Key labels for EC2/P-256.
                    $verified = ES256::create()->verify(
                        $signedData,
                        Ec2Key::create([
                            1 => self::COSE_KTY_EC2,
                            3 => self::COSE_ALG_ES256,
                            -1 => self::COSE_EC2_CRV_P256,
                            -2 => $x,
                            -3 => $y,
                        ]),
                        $signatureRaw
                    );
                } catch (Throwable) {
                    $verified = false;
                }
            }
        } elseif ($kty === self::COSE_KTY_OKP && $alg === self::COSE_ALG_EDDSA) {
            $crv = self::coseInt($key, -1);
            $x = self::coseValue($key, -2);
            if (
                ($crv === self::COSE_OKP_CRV_ED25519_WIRE || $crv === OkpKey::CURVE_ED25519)
                && is_string($x)
                && strlen($x) === 32
                // Ed25519 signatures are EXACTLY 64 bytes; sodium would throw a
                // ValueError (not return false) on any other length, so reject
                // short/long blobs here as a plain failed verification.
                && strlen($signatureRaw) === 64
            ) {
                // DO NOT route through Cose's Ed256::verify: it PRE-HASHES
                // (`hash('sha256', $data)`) before sodium — a HashEdDSA shape.
                // WebAuthn specifies pure EdDSA over the raw
                // authData ‖ SHA-256(clientDataJSON), so the spec-correct check
                // is the detached verify on the raw signed bytes (ES256 needs
                // no such bypass — openssl_verify digests internally).
                $verified = sodium_crypto_sign_verify_detached($signatureRaw, $signedData, $x);
            }
        }

        if (!$verified) {
            $this->log('warning', 'WebAuthn assertion signature verification failed', [
                'kty' => is_int($kty) ? $kty : '?',
                'alg' => is_int($alg) ? $alg : '?',
            ]);
            throw new \InvalidArgumentException('Invalid assertion signature');
        }
    }

    /**
     * Value lookup in a normalized COSE map — integer labels may surface as
     * int or (defensively) string keys; byte-string values arrive as plain
     * strings.
     *
     * @param array<array-key, mixed> $map
     */
    private static function coseValue(array $map, int $label): int|string|null
    {
        $value = $map[$label] ?? $map[(string) $label] ?? null;
        if ($value === null || is_int($value) || is_string($value)) {
            return $value;
        }
        return null;
    }

    /**
     * Integer-valued COSE entry (kty / alg / crv).
     *
     * cbor-php's normalize() stringifies integer members (bignum safety), so
     * a COSE algorithm label arrives as the DECIMAL STRING '-7' — compare on
     * the int space, and only accept genuinely numeric payloads: binary
     * coordinate strings must never leak through here.
     *
     * @param array<array-key, mixed> $map
     */
    private static function coseInt(array $map, int $label): ?int
    {
        $value = self::coseValue($map, $label);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Byte length of the CBOR item starting at $offset — a minimal, bounds-
     * checked scanner over the majors we can actually meet in COSE maps and
     * WebAuthn byte strings (uint/nint, byte/text strings, arrays, maps,
     * floats/simple). Indefinite-length items are REJECTED: DER-style
     * encodings have no place in an attestation object and accepting them
     * would mean a streaming parser this hot path does not need.
     */
    private static function cborItemLength(string $bytes, int $offset, int $depth = 0): int
    {
        if ($depth > 8) {
            throw new \InvalidArgumentException('CBOR nesting too deep');
        }
        if ($offset >= strlen($bytes)) {
            throw new \InvalidArgumentException('CBOR item truncated');
        }

        $initial = ord($bytes[$offset]);
        $major = $initial >> 5;
        $additional = $initial & 31;

        // Header size = 1 + the raw additional-info bytes.
        $header = match (true) {
            $additional < 24 => 1,
            $additional === 24 => 2,
            $additional === 25 => 3,
            $additional === 26 => 5,
            $additional === 27 => 9,
            default => throw new \InvalidArgumentException('Unsupported CBOR header'),
        };
        if (strlen($bytes) < $offset + $header) {
            throw new \InvalidArgumentException('CBOR header truncated');
        }

        // Scalars occupy only their header (value inline).
        if ($major === 0 || $major === 1 || $major === 7) {
            return $header;
        }

        $argument = self::cborHeaderArgument($bytes, $offset, $additional);

        if ($major === 2 || $major === 3) {
            return $header + $argument;
        }

        // Containers: walk $argument member items (arrays: values; maps:
        // key+value pairs).
        $position = $offset + $header;
        $members = $major === 4 ? $argument : $argument * 2;
        for ($i = 0; $i < $members; $i++) {
            $position += self::cborItemLength($bytes, $position, $depth + 1);
        }

        return $position - $offset;
    }

    /**
     * The unsigned integer encoded in a CBOR header's additional info.
     */
    private static function cborHeaderArgument(string $bytes, int $offset, int $additional): int
    {
        if ($additional < 24) {
            return $additional;
        }

        $argument = match ($additional) {
            24 => ord($bytes[$offset + 1]),
            25 => self::unpackUnsigned('n', substr($bytes, $offset + 1, 2)),
            26 => self::unpackUnsigned('N', substr($bytes, $offset + 1, 4)),
            27 => (static function (string $eight): int {
                $high = self::unpackUnsigned('N', substr($eight, 0, 4));
                $low = self::unpackUnsigned('N', substr($eight, 4, 4));
                if ($high !== 0) {
                    throw new \InvalidArgumentException('CBOR length out of range');
                }
                return $low;
            })(substr($bytes, $offset + 1, 8)),
            default => throw new \InvalidArgumentException('Unsupported CBOR header'),
        };

        return (int) $argument;
    }

    /**
     * Fail-loud unpack: `unpack()` returns `array|false` in the type system;
     * a false/missing slot here means the byte slice was malformed, which the
     * CBOR scanners treat as a hard parse error rather than a silent zero.
     */
    private static function unpackUnsigned(string $format, string $chunk): int
    {
        $unpacked = unpack($format, $chunk);
        if (!is_array($unpacked) || !isset($unpacked[1]) || !is_int($unpacked[1])) {
            throw new \InvalidArgumentException('Malformed CBOR integer encoding');
        }

        return $unpacked[1];
    }

    /**
     * base64url (RFC 4648 §5, unpadded) — the WebAuthn JSON byte encoding.
     */
    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Strict base64url decode: wrong alphabet or padding garbage throws the
     * caller's ceremony-level error rather than smuggling in a mangled key.
     */
    private static function base64UrlDecode(string $encoded, string $errorMessage): string
    {
        $normalized = strtr($encoded, '-_', '+/');
        $padded = str_pad($normalized, (int) (ceil(strlen($normalized) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);
        if ($decoded === false || $decoded === '') {
            throw new \InvalidArgumentException($errorMessage);
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->$level($message, $context);
        }
    }
}
