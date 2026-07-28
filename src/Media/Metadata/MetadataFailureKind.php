<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

/**
 * Why a metadata provider request did not yield a usable body.
 *
 * Exists because {@see MetadataHttpClient::get()} historically returned a bare
 * `?array`, which collapsed four very different situations into one value:
 * a transport error, an auth rejection, a genuine "not found", and a real 2xx
 * response. Providers then logged all of them as a single DEBUG "search miss"
 * line, so an estate-wide TMDB outage (an invalid `tmdb.api_key`, HTTP 401 +
 * TMDB `status_code` 7) was indistinguishable in the logs from an obscure title
 * that genuinely is not in TMDB. That misreading silently invalidated an
 * episode match-rate baseline.
 *
 * The distinction that matters operationally: {@see self::NotFound} and a 2xx
 * with zero results are *normal* and belong at DEBUG; everything else means the
 * server is not talking to the provider and belongs at WARNING or ERROR.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Classification of metadata provider request failures
 * @see MetadataHttpResult For the value object carrying this classification
 */
enum MetadataFailureKind: string
{
    /** Request succeeded with a 2xx status and a decodable JSON object body. */
    case None = 'none';

    /** No HTTP response at all — DNS, TLS, connect, or read timeout. */
    case Transport = 'transport';

    /**
     * The provider rejected our credentials: HTTP 401/403, or a provider-level
     * auth status code (TMDB 7 "Invalid API key", 10 "suspended", 3/14 "auth
     * failed"). Always operator-actionable — the key is wrong, expired, or revoked.
     */
    case Auth = 'auth';

    /**
     * The requested resource does not exist: HTTP 404, or TMDB `status_code` 34.
     * This is a legitimate negative answer, not a fault.
     */
    case NotFound = 'not_found';

    /** HTTP 429 still returned after the retry/backoff budget was exhausted. */
    case RateLimited = 'rate_limited';

    /** HTTP 5xx still returned after the retry/backoff budget was exhausted. */
    case ServerError = 'server_error';

    /** Some other 4xx — a malformed query or an endpoint we are calling wrong. */
    case ClientError = 'client_error';

    /** A response arrived but the body was not a decodable JSON object. */
    case InvalidBody = 'invalid_body';

    /**
     * No request was attempted — the caller asked for something this provider
     * cannot express (e.g. an unknown external-ID type). A local defect, not an
     * upstream one.
     */
    case Unsupported = 'unsupported';

    /**
     * Whether this outcome is an ordinary, expected result rather than a fault.
     *
     * True for {@see self::None} and {@see self::NotFound} only. A 404 is the
     * provider correctly answering "no such record", which during a library
     * scan is the common case — treating it as a fault would flood the log and
     * make {@see ProviderHealthTracker} escalate on healthy providers.
     *
     * @return bool True when nothing is wrong.
     */
    public function isBenign(): bool
    {
        return $this === self::None || $this === self::NotFound;
    }

    /**
     * Whether this outcome should count against the provider's health score.
     *
     * Excludes the benign outcomes, and also {@see self::Unsupported} — that
     * one never reached the network, so counting it would let a local caller
     * bug masquerade as an upstream outage.
     *
     * @return bool True when the provider genuinely failed to serve us.
     */
    public function countsAgainstHealth(): bool
    {
        return !$this->isBenign() && $this !== self::Unsupported;
    }

    /**
     * PSR-3 level this failure should be logged at.
     *
     * Auth failures are ERROR because they are always a misconfiguration and
     * affect every subsequent call. Transport/rate-limit/server errors are
     * WARNING because they are usually transient and self-healing. Successes
     * and genuine "not found" answers stay at DEBUG so a full library scan does
     * not flood the log with one WARNING per unmatched file.
     *
     * @return string One of `error`, `warning`, or `debug`.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::Auth => 'error',
            self::None, self::NotFound => 'debug',
            default => 'warning',
        };
    }

    /**
     * Short human-readable reason, used as the log message suffix.
     *
     * @return string Human-readable description of the failure.
     */
    public function reason(): string
    {
        return match ($this) {
            self::None => 'ok',
            self::Transport => 'transport error (no response)',
            self::Auth => 'provider rejected the API key',
            self::NotFound => 'resource not found upstream',
            self::RateLimited => 'rate limited after retries',
            self::ServerError => 'provider server error after retries',
            self::ClientError => 'request rejected by provider',
            self::InvalidBody => 'unparseable response body',
            self::Unsupported => 'no request attempted (unsupported lookup)',
        };
    }
}
