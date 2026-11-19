<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub;

use InvalidArgumentException;
use Phlix\Shared\Security\Base64Url;
use Phlix\Shared\Security\Ed25519Pem;
use RuntimeException;

/**
 * Manages Ed25519 keypairs for server-to-hub authentication.
 *
 * Generates, stores, loads, and rotates Ed25519 keypairs using the
 * libsodium-compatible sodium_crypto_sign_* API. Keys are stored in
 * PEM format at the path supplied to the constructor.
 *
 * Key ID format: the deterministic fingerprint base64url(SHA-256(raw public
 * key)[0:8]) — NOT a wall-clock timestamp. Because the private key is
 * persisted and reloaded, the kid is STABLE across restarts and changes only
 * when the key itself rotates. An earlier version fell back to
 * `gmdate`-formatted second-resolution stamps whenever the kid was resolved
 * before the key file existed, so the same key could be served under two
 * different ids; consumers that registered the pairing-time kid (the hub peer registry, the
 * `/.well-known/jwks.json` surface) then saw re-labelling churn — the exact
 * failure mode the sibling phlix-hub key manager documented and fixed
 * (spurious ENROLLMENT_TOKEN_EXPIRED on every restart). Porting the hub law:
 * kid resolution materializes the keypair on demand ({@see getKid()}), so a
 * time-derived id can never be minted.
 *
 * @package Phlix\Hub
 * @since 0.11.0
 */
final class Ed25519KeyManager
{
    /** @var string Path to the PEM-encoded private key file */
    private string $keyPath;

    /** @var string|null kid resolved once from the key's public half (deterministic fingerprint) */
    private ?string $kid = null;

    /**
     * Resolve where the hub identity keypair lives (SH-019).
     *
     * Precedence, first match wins:
     *   1. An explicit configured path (config/hub.php 'key_path', which
     *      itself defaults from env PHLIX_HUB_KEY_PATH) — operator truth.
     *   2. Env PHLIX_HUB_KEY_PATH — for the manual-fallback constructors
     *      that never load config/hub.php.
     *   3. LEGACY COMPAT PROBE: config/hub-server-key.pem when it ALREADY
     *      EXISTS on disk. Installs paired before this cure carry their key
     *      (and therefore their hub enrollment identity) there; silently
     *      moving them to a fresh key would de-anchor every live pairing, so
     *      an existing legacy file keeps winning until it is absent.
     *   4. Default: var/hub-server-key.pem.
     *
     * The 4th arm is the actual cure: the git-tracked config/ directory was
     * the DEFAULT BIRTHPLACE for a private key. No PEM is committed (the
     * tree is clean of them and `.gitignore` now carries a blanket `*.pem`
     * backstop), but a default that writes secret material into a VCS
     * working directory invites the operator's own `git add -A` to capture
     * it. var/ is the stateful home — already git-ignored per subsystem,
     * already on the systemd ReadWritePaths list (scripts/install.sh step
     * 4d), and created 0700 by {@see self::generateAndStoreKeyPair()} if
     * absent.
     *
     * @param string|null $configuredPath A configured 'key_path' value or null.
     * @param string|null $configDir      Directory holding config/hub.php;
     *                                    defaults to this checkout's config/.
     *                                    (Relative values resolve against the
     *                                    CWD exactly as the pre-cure fallback
     *                                    constructors did — behavior kept.)
     *
     * @return non-empty-string Usable key path (existence of the PARENT is
     *                          guaranteed by the generator, not this resolver).
     */
    public static function resolveKeyPath(?string $configuredPath = null, ?string $configDir = null): string
    {
        if ($configuredPath !== null && $configuredPath !== '') {
            return $configuredPath;
        }

        $fromEnv = getenv('PHLIX_HUB_KEY_PATH');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $dir = $configDir !== null && $configDir !== ''
            ? rtrim($configDir, '/')
            : dirname(__DIR__, 2) . '/config';

        $legacy = $dir . '/hub-server-key.pem';
        if (is_file($legacy)) {
            return $legacy;
        }

        return dirname($dir) . '/var/hub-server-key.pem';
    }

    /**
     * Creates a new Ed25519KeyManager.
     *
     * @param string $keyPath Absolute path where the PEM-encoded Ed25519
     *                       private key is stored (or will be written).
     *                       New callers should pass
     *                       {@see self::resolveKeyPath()} (SH-019).
     */
    public function __construct(string $keyPath)
    {
        $this->keyPath = $keyPath;
    }

    /**
     * Returns the current keypair, creating one if the key file does
     * not yet exist.
     *
     * @return KeyPair The current 32-byte secret / 32-byte public keypair.
     *
     * @throws RuntimeException If the key file exists but cannot be read.
     */
    public function getOrCreateKeyPair(): KeyPair
    {
        if (file_exists($this->keyPath)) {
            return $this->loadKeyPair();
        }

        return $this->generateAndStoreKeyPair();
    }

    /**
     * Generates a fresh keypair, persists it to disk, and returns it.
     *
     * @return KeyPair The newly generated keypair.
     */
    public function rotate(): KeyPair
    {
        return $this->generateAndStoreKeyPair();
    }

    /**
     * Returns the current key ID (kid) for the JWK.
     *
     * The kid is the deterministic fingerprint of the key's public half
     * ({@see extractKidFromPublicKey()}), resolved once and memoized. When the
     * key file does not exist yet, resolving the kid materializes the keypair
     * (via {@see getOrCreateKeyPair()}) so there is exactly one kid law on
     * every path — a wall-clock placeholder is never minted, which keeps the
     * id stable across restarts and identical to what pairing and the JWKS
     * surface publish.
     *
     * @return string The key ID (base64url of the first 8 bytes of the
     *                SHA-256 digest of the raw public key).
     */
    public function getKid(): string
    {
        return $this->kid ??= $this->extractKidFromPublicKey($this->getOrCreateKeyPair()->publicKey);
    }

    /**
     * Returns the current private key as raw 32-byte secret.
     *
     * @return string The 32-byte Ed25519 secret key.
     */
    public function getCurrentPrivateKey(): string
    {
        return $this->getOrCreateKeyPair()->secretKey;
    }

    /**
     * Returns the public key as a JWK map for inclusion in JWKS.
     *
     * @param string|null $kid Optional kid override. When null the
     *                         current key's kid is used.
     *
     * @return array{kty: string, crv: string, x: string, kid: string, use: string, alg: string}
     */
    public function getPublicKeyJwk(?string $kid = null): array
    {
        $keyPair = $this->getOrCreateKeyPair();
        $actualKid = $kid ?? $this->getKid();

        return [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => $this->base64UrlEncode($keyPair->publicKey),
            'kid' => $actualKid,
            'use' => 'sig',
            'alg' => 'EdDSA',
        ];
    }

    /**
     * Generates a new Ed25519 keypair and stores it in PEM format.
     *
     * @return KeyPair The newly generated keypair.
     *
     * @throws RuntimeException If the key file cannot be written.
     */
    private function generateAndStoreKeyPair(): KeyPair
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = substr($keypair, 0, 64);
        $publicKey = substr($keypair, 64);

        $keyPair = new KeyPair($secretKey, $publicKey);

        $this->kid = $this->extractKidFromPublicKey($publicKey);

        $pem = $this->buildPem($secretKey);
        $dir = dirname($this->keyPath);
        if (!is_dir($dir)) {
            // 0700: the signing-key directory must never be world-readable
            // (umask only removes bits, so 0700 stays private on any host).
            @mkdir($dir, 0700, true);
        }

        $this->writePrivateKeyAtomically($pem, $dir);

        return $keyPair;
    }

    /**
     * Writes the PEM through a 0600-from-birth temp file, then renames it
     * into place.
     *
     * `tempnam()` creates the file with mode 0600 regardless of the process
     * umask (PHP has no O_CREAT-mode stream context for regular files), so the
     * secret is world-readable at no point between creation and rename —
     * closing the write-then-chmod TOCTOU window a plain `file_put_contents()`
     * + `chmod()` leaves open.
     *
     * @param string $pem PEM-encoded private key material.
     * @param string $dir Directory of the final key path (temp must live on
     *                    the same filesystem for rename() to be atomic).
     *
     * @throws RuntimeException If the temp file cannot be created, written, or moved.
     */
    private function writePrivateKeyAtomically(string $pem, string $dir): void
    {
        $tmp = @tempnam($dir, 'ed25519-');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create temp Ed25519 key file in: ' . $dir);
        }

        if (@file_put_contents($tmp, $pem) === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write Ed25519 private key: ' . $this->keyPath);
        }

        // Belt-and-suspenders: tempnam is already 0600; chmod keeps the
        // guarantee explicit if that ever changes.
        @chmod($tmp, 0600);

        if (!@rename($tmp, $this->keyPath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to move Ed25519 private key into place: ' . $this->keyPath);
        }
    }

    /**
     * Loads an existing keypair from the PEM file.
     *
     * @return KeyPair The loaded keypair.
     *
     * @throws RuntimeException If the PEM file is malformed or unreadable.
     */
    private function loadKeyPair(): KeyPair
    {
        $content = @file_get_contents($this->keyPath);
        if ($content === false) {
            throw new RuntimeException('Cannot read Ed25519 private key: ' . $this->keyPath);
        }

        $secretKey = $this->parsePem($content);
        if ($secretKey === null || strlen($secretKey) !== 64) {
            throw new InvalidArgumentException('Invalid Ed25519 PEM file: ' . $this->keyPath);
        }

        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        return new KeyPair($secretKey, $publicKey);
    }

    /**
     * Builds the native PEM string from the raw libsodium secret key.
     *
     * Emits the application's own `-----BEGIN ED25519 PRIVATE KEY-----` label
     * wrapping the base64url of the raw libsodium secret key. The writer is
     * intentionally unchanged (SV-4.16): newly generated keys keep this native
     * format, while {@see parsePem()} now additionally tolerates reading a
     * standard PKCS#8 Ed25519 key so externally generated keys also load.
     *
     * @param string $secretKey 64-byte libsodium secret key (seed ‖ public key).
     *
     * @return string PEM-encoded private key with line breaks.
     */
    private function buildPem(string $secretKey): string
    {
        return Ed25519Pem::buildPem($secretKey);
    }

    /**
     * Parses a PEM string and extracts the raw 64-byte Ed25519 secret key.
     *
     * Two on-disk PEM formats are accepted so the reader tolerates both keys
     * this application generates itself and keys produced by standard tooling
     * (SV-4.16):
     *
     *  1. Native format (written by {@see buildPem()}):
     *     `-----BEGIN ED25519 PRIVATE KEY-----` wrapping the base64url of the
     *     raw 64-byte libsodium secret key (32-byte seed ‖ 32-byte public key).
     *  2. Standard PKCS#8 (RFC 8410), e.g. produced by
     *     `openssl genpkey -algorithm Ed25519`:
     *     `-----BEGIN PRIVATE KEY-----` wrapping the DER-encoded 32-byte seed.
     *     The seed is expanded back to the 64-byte libsodium secret key so the
     *     rest of the pipeline is identical regardless of source format.
     *
     * Only the native format is written back; see {@see buildPem()}.
     *
     * @param string $pem The PEM content.
     *
     * @return string|null The raw 64-byte Ed25519 secret key, or null on parse failure.
     */
    private function parsePem(string $pem): ?string
    {
        // v0.56.0 adoption: the two-format ladder (native label first, then
        // RFC 8410 PKCS#8 with sodium seed expansion) moved verbatim into
        // shared Ed25519Pem::parsePem(); the shared parser returns the
        // secret/public pair — this manager only ever consumed the secret,
        // so the public arm is dropped here. Byte-for-byte equivalence over
        // native/PKCS#8/garbage corpora proven before the swap.
        $parsed = Ed25519Pem::parsePem($pem);

        return $parsed['secret'] ?? null;
    }

    /**
     * Extracts the key ID from a public key using its SHA-256 digest.
     *
     * This is THE kid law (SRV-2.1, ported from the hub key manager): the kid
     * is the base64url-encoded first 8 bytes of the SHA-256 digest of the
     * public key. Deterministic — the same key always yields the same id, so
     * nothing a consumer (hub peer registry, JWKS fetcher) stored under a kid
     * survives re-labelling. The former timestamp-based fallback minter was
     * deleted: see the class header for the churn it caused.
     *
     * @param string $publicKey 32-byte Ed25519 public key.
     *
     * @return string The key ID string.
     */
    private function extractKidFromPublicKey(string $publicKey): string
    {
        return Ed25519Pem::fingerprint($publicKey, Ed25519Pem::PREFIX_BYTES_SERVER);
    }

    /**
     * Encodes binary data as base64url (no padding).
     *
     * @param string $data Raw binary data.
     *
     * @return string Base64url-encoded string.
     */
    private function base64UrlEncode(string $data): string
    {
        return Base64Url::encode($data);
    }
}
