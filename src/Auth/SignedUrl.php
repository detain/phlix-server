<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

/**
 * Mints and verifies short-lived, HMAC-signed URLs for the byte-serving /
 * streaming endpoints that a `<video>`, `<img>`, `<audio>`, e-reader or native
 * media player requests WITHOUT being able to attach an `Authorization: Bearer`
 * header.
 *
 * Those endpoints (`/media/{id}/stream`, `/hls/**`, `/dash/**`, book
 * read/cover/download, audiobook read/stream, photo thumbnail/full) cannot sit
 * behind the JSON {@see \Phlix\Server\Http\Middleware\AuthMiddleware} because a
 * media element / e-reader supplies no header. They were therefore reachable by
 * anyone who knew the (UUID) id. A signed URL closes that gap: the now-gated
 * JSON detail endpoints — which DO require a signed-in user — mint a URL carrying
 * `?exp=<unix-seconds>&sig=<base64url-hmac>`; {@see \Phlix\Server\Http\Middleware\SignedUrlMiddleware}
 * (and the inline guard in {@see \Phlix\Server\Workerman\HttpHandler::serveMediaStream()})
 * recompute the HMAC and reject anything missing, tampered, or expired.
 *
 * The signature covers a *canonical resource* (see {@see self::canonicalResource()})
 * plus the expiry, never the runtime query params (image `w`/`h`/`fit`, audiobook
 * `chapter`/`offset`) so a client may vary those freely without re-signing. For
 * HLS/DASH the resource is the per-job directory prefix (`/hls/{job}`), so ONE
 * token authorises the master playlist and every sub-playlist/segment under it.
 *
 * The signing key is read from the environment (so it survives restarts and is
 * shared across both HTTP entry points without DI wiring, mirroring how
 * {@see \Phlix\Common\Container\Providers\AuthServicesProvider} reads
 * `JWT_SECRET`). When `PHLIX_SIGNED_URL_SECRET` is unset it is derived from
 * `JWT_SECRET` via a domain-separated HMAC so a leaked stream token can never be
 * replayed as — or brute-forced against — a real JWT, and vice-versa.
 *
 * @package Phlix\Auth
 * @since 0.44.0
 */
final class SignedUrl
{
    /** Default token lifetime in seconds (6 hours) when none is configured. */
    public const DEFAULT_TTL = 21600;

    /**
     * Version/domain tag baked into every HMAC message. Bump to invalidate all
     * outstanding tokens (e.g. if the scheme ever changes) without rotating the
     * secret.
     */
    private const VERSION = 'phlix-signed-url-v1';

    /** Process-wide instance built from the environment by {@see self::fromEnv()}. */
    private static ?self $shared = null;

    /**
     * @param string $secret     HMAC signing key (>= 1 byte; callers supply a
     *                           high-entropy value via {@see self::fromEnv()}).
     * @param int    $defaultTtl Token lifetime in seconds used by {@see self::mint()}
     *                           when no per-call TTL is given.
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $defaultTtl = self::DEFAULT_TTL,
    ) {
    }

    /**
     * Returns the memoised, environment-configured signer.
     *
     * Reads `PHLIX_SIGNED_URL_SECRET` (preferred) else derives a key from
     * `JWT_SECRET` (domain-separated), and `PHLIX_SIGNED_URL_TTL` for the
     * lifetime. Both HTTP entry points and every minting controller share this
     * single instance so signatures verify regardless of which worker/process
     * minted them.
     */
    public static function fromEnv(): self
    {
        if (self::$shared instanceof self) {
            return self::$shared;
        }

        $secret = (string) (getenv('PHLIX_SIGNED_URL_SECRET') ?: '');
        if ($secret === '') {
            $jwtSecret = (string) (getenv('JWT_SECRET')
                ?: \Phlix\Common\Container\Providers\AuthServicesProvider::DEFAULT_JWT_SECRET);
            // Domain-separate from the JWT key: signed-URL tokens and JWTs must
            // never be interchangeable even when only JWT_SECRET is configured.
            $secret = hash_hmac('sha256', self::VERSION, $jwtSecret);
        }

        $ttlEnv = getenv('PHLIX_SIGNED_URL_TTL');
        $ttl = is_string($ttlEnv) && ctype_digit($ttlEnv) && (int) $ttlEnv > 0
            ? (int) $ttlEnv
            : self::DEFAULT_TTL;

        return self::$shared = new self($secret, $ttl);
    }

    /**
     * Drops the memoised {@see self::fromEnv()} instance. Test-only — lets a
     * test set env vars and observe a freshly-derived key.
     */
    public static function resetSharedForTesting(): void
    {
        self::$shared = null;
    }

    /** The configured default token lifetime in seconds. */
    public function defaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Normalises a request path to the resource the signature is bound to.
     *
     * HLS/DASH playback fans a single playable URL out into many sibling
     * requests (master playlist → variant playlists → segments), so the token
     * is scoped to the per-job directory: every path under `/hls/{job}` or
     * `/dash/{job}` collapses to that prefix and verifies against one signature.
     * The DVR timeshift buffer (SV-3.1 f) is the same shape — one `buffer.m3u8`
     * playlist plus `seg_NNNNN.ts` segments under `/livetv/timeshift/{session}`,
     * so it collapses to that per-session prefix too, letting one signed playlist
     * URL authorise every segment request beneath it. (The single-file DVR
     * recording stream `/livetv/recording/{id}/stream` has no sub-segments and
     * stays bound to its exact path.) Every other endpoint is bound to its exact
     * path.
     *
     * @param string $path Request path (no query string).
     */
    public function canonicalResource(string $path): string
    {
        // Bind to the path only — never the runtime query params (image w/h/fit,
        // audiobook chapter/offset, and the exp/sig pair itself). The verifier
        // sees Request::$path (already query-less), so stripping here keeps both
        // sides computing the HMAC over the same string.
        $path = explode('?', $path, 2)[0];

        if (preg_match('#^(/(?:hls|dash)/[^/]+|/livetv/timeshift/[^/]+)(?:/.*)?$#', $path, $m) === 1) {
            return $m[1];
        }

        return $path;
    }

    /**
     * Computes the base64url HMAC signature for a path at a given expiry.
     *
     * Deterministic (no clock read) so it is trivially testable and so the
     * verifier can recompute it exactly.
     *
     * @param string $path Request path (no query string).
     * @param int    $exp  Absolute expiry as a Unix timestamp (seconds).
     */
    public function signature(string $path, int $exp): string
    {
        $message = self::VERSION . "\n" . $this->canonicalResource($path) . "\n" . $exp;
        $raw = hash_hmac('sha256', $message, $this->secret, true);

        return self::base64UrlEncode($raw);
    }

    /**
     * Appends a fresh `exp`/`sig` token to a path, returning a signed URL.
     *
     * Preserves any existing query string (e.g. a photo thumbnail's `w`/`h`),
     * appending with `&` when one is present and `?` otherwise.
     *
     * @param string   $path Path to sign, optionally already carrying a query string.
     * @param int|null $ttl  Lifetime in seconds; defaults to the configured TTL.
     * @param int|null $now  Override "now" (Unix seconds) — test seam; defaults to time().
     *
     * @return string The path with `exp`/`sig` query parameters appended.
     */
    public function mint(string $path, ?int $ttl = null, ?int $now = null): string
    {
        $now ??= time();
        $ttl = ($ttl !== null && $ttl > 0) ? $ttl : $this->defaultTtl;
        $exp = $now + $ttl;

        // Preserve any existing query string in the returned URL while signing
        // only the path (the signature must match the query-less Request::$path
        // the verifier recomputes against).
        [$pathOnly, $existingQuery] = array_pad(explode('?', $path, 2), 2, '');
        $sig = $this->signature($pathOnly, $exp);
        $query = ($existingQuery !== '' ? $existingQuery . '&' : '') . 'exp=' . $exp . '&sig=' . $sig;

        return $pathOnly . '?' . $query;
    }

    /**
     * Verifies a request's `exp`/`sig` against the (canonicalised) path.
     *
     * Returns true only when the signature is well-formed, matches the recomputed
     * HMAC (constant-time {@see hash_equals()}), and has not expired. A null/blank
     * or non-numeric component fails closed.
     *
     * @param string      $path Request path (no query string).
     * @param string|null $exp  The `exp` query parameter (Unix seconds, as a string).
     * @param string|null $sig  The `sig` query parameter (base64url HMAC).
     * @param int|null    $now  Override "now" (Unix seconds) — test seam; defaults to time().
     */
    public function verify(string $path, ?string $exp, ?string $sig, ?int $now = null): bool
    {
        if ($sig === null || $sig === '' || $exp === null || !ctype_digit($exp)) {
            return false;
        }

        $now ??= time();
        $expInt = (int) $exp;
        if ($expInt < $now) {
            return false;
        }

        return hash_equals($this->signature($path, $expInt), $sig);
    }

    /**
     * URL-safe, unpadded base64 (RFC 4648 §5) — keeps the signature clean inside
     * a query string without percent-encoding.
     */
    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
