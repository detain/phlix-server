<?php

declare(strict_types=1);

namespace Phlix\Auth\WebAuthn;

use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Shared\Auth\AuthResult;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialUserEntity;
use Workerman\MySQL\Connection;

class WebAuthnManager
{
    private UserRepository $userRepo;
    private ?StructuredLogger $logger;
    private WebAuthnSettings $settings;
    private WebAuthnCredentialRepository $credentialRepo;

    /** @var array<string, string> challenge => userId mapping for registration */
    private array $registrationChallenges = [];

    /** @var array<string, string> challenge => username mapping for authentication */
    private array $authenticationChallenges = [];

    /**
     * @param Connection $db Currently unused — retained in the signature for
     *                       forward compatibility with direct DB-bound flows
     *                       (challenge persistence, audit tables). Persistence
     *                       today goes through {@see $credentialRepo}.
     */
    public function __construct(
        UserRepository $userRepo,
        Connection $db,
        WebAuthnCredentialRepository $credentialRepo,
        WebAuthnSettings $settings,
        ?StructuredLogger $logger = null
    ) {
        unset($db); // intentionally unused; see param docblock
        $this->userRepo = $userRepo;
        $this->credentialRepo = $credentialRepo;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * @return array{
     *     challenge: string,
     *     rp: array{id: string, name: string},
     *     user: array{id: string, name: string, displayName: string},
     *     pubKeyCredParams: list<PublicKeyCredentialParameters>,
     *     timeout: int,
     *     excludeCredentials: list<PublicKeyCredentialDescriptor>,
     *     authenticatorSelection: array{authenticatorAttachment: string|null, residentKey: bool, userVerification: string},
     *     attestation: string
     * }
     */
    public function startRegistration(string $userId, string $username): array
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $challenge = $this->generateChallenge();
        $this->registrationChallenges[$challenge] = $userId;

        $userEntity = new PublicKeyCredentialUserEntity(
            $username,
            $userId,
            $username
        );

        $rpId = $this->settings->rpId;

        $publicKeyCredentialParams = [
            new PublicKeyCredentialParameters('public-key', 1),
            new PublicKeyCredentialParameters('public-key', 7),
        ];

        $excludeCredentials = [];
        $existingCredentials = $this->credentialRepo->findByUserId($userId);
        foreach ($existingCredentials as $cred) {
            $excludeCredentials[] = new PublicKeyCredentialDescriptor(
                'public-key',
                $cred->credentialId,
                ['cross-platform', 'platform']
            );
        }

        $authenticatorSelection = [
            'authenticatorAttachment' => null,
            'residentKey' => true,
            'userVerification' => 'preferred',
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
     * @param array<array-key, mixed> $credential Decoded JSON body from the browser
     *                                            (`attestationObject`, `clientDataJSON`, etc.)
     */
    public function finishRegistration(
        string $userId,
        string $username,
        array $credential,
        string $expectedChallenge
    ): string {
        if (!isset($this->registrationChallenges[$expectedChallenge])) {
            throw new \InvalidArgumentException('Invalid or expired registration challenge');
        }

        if ($this->registrationChallenges[$expectedChallenge] !== $userId) {
            throw new \InvalidArgumentException('Challenge does not match user');
        }

        unset($this->registrationChallenges[$expectedChallenge]);

        $attestationObject = $credential['attestationObject'] ?? null;
        $clientDataJSON = $credential['clientDataJSON'] ?? null;

        if (!is_string($attestationObject) || $attestationObject === '') {
            throw new \InvalidArgumentException('Missing attestation data');
        }
        if (!is_string($clientDataJSON) || $clientDataJSON === '') {
            throw new \InvalidArgumentException('Missing attestation data');
        }

        $clientData = json_decode($clientDataJSON, true);
        if (!is_array($clientData)) {
            throw new \InvalidArgumentException('Invalid client data JSON');
        }

        $clientChallenge = $clientData['challenge'] ?? '';
        if (!is_string($clientChallenge) || $clientChallenge !== base64_encode($expectedChallenge)) {
            throw new \InvalidArgumentException('Challenge mismatch');
        }

        $clientType = $clientData['type'] ?? '';
        if (!is_string($clientType) || $clientType !== 'webauthn.create') {
            throw new \InvalidArgumentException('Invalid ceremony type');
        }

        $parsedAttestation = $this->parseAttestationObject($attestationObject);
        $credentialId = $parsedAttestation['credentialId'];
        $publicKeyCose = $parsedAttestation['publicKey'];
        $counter = $parsedAttestation['counter'];
        $attestedCredentialData = $parsedAttestation['attestedCredentialData'];
        $deviceType = null;
        $aaguid = null;

        if (is_array($attestedCredentialData)) {
            $deviceType = $attestedCredentialData['deviceType'] ?? null;
            $aaguid = $attestedCredentialData['aaguid'];
        }

        $type = 'public-key';

        $id = $this->generateUuid();

        $webauthnCredential = new WebAuthnCredential(
            credentialId: $credentialId,
            userId: $userId,
            publicKey: $publicKeyCose,
            counter: (string) $counter,
            type: $type,
            deviceType: $deviceType,
            aaguid: $aaguid,
            registeredAt: time()
        );

        $this->credentialRepo->save($webauthnCredential, $id);

        $this->log('info', 'WebAuthn credential registered', [
            'user_id' => $userId,
            'credential_id' => base64_encode($credentialId),
        ]);

        return base64_encode($credentialId);
    }

    /**
     * @return array{
     *     challenge: string,
     *     rpId: string,
     *     allowCredentials: list<PublicKeyCredentialDescriptor>,
     *     timeout: int,
     *     userVerification: string
     * }
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
        $this->authenticationChallenges[$challenge] = $username;

        $credentials = $this->credentialRepo->findByUserId($userId);
        $allowCredentials = [];

        foreach ($credentials as $cred) {
            $allowCredentials[] = new PublicKeyCredentialDescriptor(
                'public-key',
                $cred->credentialId,
                ['cross-platform', 'platform']
            );
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
            'userVerification' => 'preferred',
        ];
    }

    /**
     * @param array<array-key, mixed> $credential Decoded JSON body from the browser
     *                                            (`id`, `clientDataJSON`, `authenticatorData`, `signature`)
     */
    public function finishAuthentication(
        string $username,
        array $credential,
        string $expectedChallenge
    ): AuthResult {
        if (!isset($this->authenticationChallenges[$expectedChallenge])) {
            throw new \InvalidArgumentException('Invalid or expired authentication challenge');
        }

        if ($this->authenticationChallenges[$expectedChallenge] !== $username) {
            throw new \InvalidArgumentException('Challenge does not match username');
        }

        unset($this->authenticationChallenges[$expectedChallenge]);

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

        $decodedCredentialId = base64_decode($credentialId, true);
        if ($decodedCredentialId === false) {
            throw new \InvalidArgumentException('Invalid credential ID encoding');
        }

        $clientData = json_decode($clientDataJSON, true);
        if (!is_array($clientData)) {
            throw new \InvalidArgumentException('Invalid client data JSON');
        }

        $clientChallenge = $clientData['challenge'] ?? '';
        if (!is_string($clientChallenge) || $clientChallenge !== base64_encode($expectedChallenge)) {
            throw new \InvalidArgumentException('Challenge mismatch');
        }

        $clientType = $clientData['type'] ?? '';
        if (!is_string($clientType) || $clientType !== 'webauthn.get') {
            throw new \InvalidArgumentException('Invalid ceremony type');
        }

        $storedCredential = $this->credentialRepo->findByCredentialId($decodedCredentialId);
        if (!$storedCredential) {
            throw new \InvalidArgumentException('Credential not found');
        }

        $storedCounter = (int) $storedCredential->counter;
        $newCounter = $this->parseAuthenticatorData($authenticatorData);

        if ($newCounter <= $storedCounter && $storedCounter > 0) {
            throw new \InvalidArgumentException('Potential replay attack detected');
        }

        $authenticatorDataBytes = base64_decode($authenticatorData, true);
        if ($authenticatorDataBytes === false || $authenticatorDataBytes === '') {
            throw new \InvalidArgumentException('Invalid authenticator data');
        }

        $this->credentialRepo->updateCounter($decodedCredentialId, $newCounter);

        $user = $this->userRepo->findByUsername($username);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $userId = $user['id'] ?? null;
        if (!is_string($userId) || $userId === '') {
            throw new \InvalidArgumentException('User not found');
        }

        $this->log('info', 'WebAuthn authentication successful', [
            'username' => $username,
            'user_id' => $userId,
        ]);

        return new AuthResult(
            success: true,
            userId: $userId,
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
        $decoded = base64_decode($credentialId, true);
        if ($decoded === false) {
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

    private function generateChallenge(): string
    {
        return random_bytes(32);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * @return array{
     *     credentialId: string,
     *     publicKey: string,
     *     counter: int,
     *     attestedCredentialData: array{aaguid: string|null, credentialIdLength: int, deviceType?: string|null}|null,
     *     aaguid: string|null
     * }
     */
    private function parseAttestationObject(string $attestationObject): array
    {
        $decoded = base64_decode($attestationObject, true);
        if ($decoded === false) {
            $decoded = $attestationObject;
        }

        $attestedCredentialData = null;
        $aaguid = null;
        $credentialIdLength = 0;

        if (strlen($decoded) < 37) {
            throw new \InvalidArgumentException('Attestation object too short');
        }

        $authData = substr($decoded, 37);

        if (strlen($authData) >= 16) {
            $aaguid = substr($authData, 0, 16);
        }

        if (strlen($authData) >= 19) {
            $unpacked = unpack('n', substr($authData, 16, 2));
            if (is_array($unpacked) && isset($unpacked[1]) && is_int($unpacked[1])) {
                $credentialIdLength = $unpacked[1];
            }
        }

        if (strlen($authData) >= 19 + $credentialIdLength) {
            $attestedCredentialData = [
                'aaguid' => $aaguid,
                'credentialIdLength' => $credentialIdLength,
            ];
        }

        $publicKeyStart = 37 + 16 + 2 + $credentialIdLength;

        if (strlen($decoded) > $publicKeyStart) {
            $publicKeyCoseBytes = substr($decoded, $publicKeyStart);
        } else {
            $publicKeyCoseBytes = '';
        }

        $counterStart = 37 + 16 + 2 + $credentialIdLength + strlen($publicKeyCoseBytes);
        $counter = 0;
        if (strlen($decoded) >= $counterStart + 4) {
            $counterBytes = substr($decoded, $counterStart, 4);
            $unpackedCounter = unpack('N', $counterBytes);
            if (is_array($unpackedCounter) && isset($unpackedCounter[1]) && is_int($unpackedCounter[1])) {
                $counter = $unpackedCounter[1];
            }
        }

        $credentialId = '';
        if ($credentialIdLength > 0 && strlen($authData) >= 19 + $credentialIdLength) {
            $credentialId = substr($authData, 19, $credentialIdLength);
        }

        return [
            'credentialId' => $credentialId,
            'publicKey' => $publicKeyCoseBytes,
            'counter' => $counter,
            'attestedCredentialData' => $attestedCredentialData,
            'aaguid' => $aaguid,
        ];
    }

    private function parseAuthenticatorData(string $authenticatorData): int
    {
        $decoded = base64_decode($authenticatorData, true);
        if ($decoded === false) {
            $decoded = $authenticatorData;
        }

        if (strlen($decoded) < 37) {
            return 0;
        }

        $counterStart = 37;
        if (strlen($decoded) >= $counterStart + 4) {
            $counterBytes = substr($decoded, $counterStart, 4);
            $unpacked = unpack('N', $counterBytes);
            if (is_array($unpacked) && isset($unpacked[1]) && is_int($unpacked[1])) {
                return $unpacked[1];
            }
        }

        return 0;
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
