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
 * Outcome of a single {@see MetadataHttpClient} request.
 *
 * Carries the HTTP status and any provider-level status code alongside the
 * body, so a caller can tell a real empty result from an auth rejection.
 * Before this type existed the client read `$response->getStatusCode()` and
 * then threw it away, returning the *error body* of an HTTP 401 as though it
 * were an ordinary response — which is exactly how an invalid `tmdb.api_key`
 * came to be logged as a routine "search miss" for days.
 *
 * Instances are immutable; construct via {@see self::success()} or
 * {@see self::failure()}, or let {@see self::classify()} work out the kind.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Value object carrying a metadata request's status and outcome
 * @see MetadataFailureKind For the failure taxonomy
 */
final class MetadataHttpResult
{
    /**
     * Provider-level status codes that mean "your credentials are not valid".
     *
     * These are TMDB v3 `status_code` values: 3 and 14 (authentication failed),
     * 7 (invalid API key), 10 (suspended API key), 16 (device denied), and
     * 17 (session denied).
     *
     * @var list<int>
     */
    private const PROVIDER_AUTH_STATUS_CODES = [3, 7, 10, 14, 16, 17];

    /** TMDB `status_code` for "The resource you requested could not be found." */
    private const PROVIDER_NOT_FOUND_STATUS_CODE = 34;

    /**
     * @param MetadataFailureKind        $kind               Why the request failed (or {@see MetadataFailureKind::None}).
     * @param int|null                   $httpStatus         HTTP status, or null when no response arrived.
     * @param array<string, mixed>|null  $rawBody            Decoded body, kept even for
     *                                                       failures (diagnostics only).
     * @param int|null                   $providerStatusCode Provider-level status code (e.g. TMDB `status_code`).
     * @param string|null                $providerMessage    Provider-supplied error message, when present.
     */
    private function __construct(
        public readonly MetadataFailureKind $kind,
        public readonly ?int $httpStatus,
        private readonly ?array $rawBody,
        public readonly ?int $providerStatusCode,
        public readonly ?string $providerMessage,
    ) {
    }

    /**
     * Build a successful result.
     *
     * @param int                  $httpStatus HTTP status (2xx).
     * @param array<string, mixed> $body       Decoded JSON object body.
     *
     * @return self Successful result carrying $body.
     */
    public static function success(int $httpStatus, array $body): self
    {
        return new self(MetadataFailureKind::None, $httpStatus, $body, null, null);
    }

    /**
     * Build a failure result of an explicitly chosen kind.
     *
     * Used for cases the HTTP status cannot express — notably
     * {@see MetadataFailureKind::Transport} (no response at all) and the
     * post-retry {@see MetadataFailureKind::RateLimited} /
     * {@see MetadataFailureKind::ServerError} verdicts.
     *
     * @param MetadataFailureKind       $kind       Failure classification.
     * @param int|null                  $httpStatus HTTP status when one was received.
     * @param array<string, mixed>|null $body       Decoded body when one was parsed.
     *
     * @return self Failure result.
     */
    public static function failure(MetadataFailureKind $kind, ?int $httpStatus = null, ?array $body = null): self
    {
        return new self(
            $kind,
            $httpStatus,
            $body,
            self::extractProviderStatusCode($body),
            self::extractProviderMessage($body),
        );
    }

    /**
     * Classify a decoded response by HTTP status and body.
     *
     * The provider-level status code wins over the HTTP status where the two
     * disagree, because it is the more specific signal — TMDB, for instance,
     * has returned `status_code` 7 under more than one HTTP status over the
     * life of the v3 API.
     *
     * @param int                  $httpStatus HTTP status code.
     * @param array<string, mixed> $body       Decoded JSON object body.
     *
     * @return self Success when 2xx with no provider-level error, else a classified failure.
     */
    public static function classify(int $httpStatus, array $body): self
    {
        $providerCode = self::extractProviderStatusCode($body);

        if ($providerCode !== null) {
            if (in_array($providerCode, self::PROVIDER_AUTH_STATUS_CODES, true)) {
                return self::failure(MetadataFailureKind::Auth, $httpStatus, $body);
            }
            if ($providerCode === self::PROVIDER_NOT_FOUND_STATUS_CODE) {
                return self::failure(MetadataFailureKind::NotFound, $httpStatus, $body);
            }
        }

        if ($httpStatus === 401 || $httpStatus === 403) {
            return self::failure(MetadataFailureKind::Auth, $httpStatus, $body);
        }

        if ($httpStatus === 404) {
            return self::failure(MetadataFailureKind::NotFound, $httpStatus, $body);
        }

        if ($httpStatus === 429) {
            return self::failure(MetadataFailureKind::RateLimited, $httpStatus, $body);
        }

        if ($httpStatus >= 500) {
            return self::failure(MetadataFailureKind::ServerError, $httpStatus, $body);
        }

        if ($httpStatus >= 400) {
            return self::failure(MetadataFailureKind::ClientError, $httpStatus, $body);
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            // A 2xx carrying an explicit provider-level failure flag (TMDB sends
            // `success: false`) is still a failure, just an unclassified one.
            if (($body['success'] ?? null) === false) {
                return self::failure(MetadataFailureKind::ClientError, $httpStatus, $body);
            }
            return self::success($httpStatus, $body);
        }

        // 1xx/3xx reaching here means the request did not complete as expected.
        return self::failure(MetadataFailureKind::ClientError, $httpStatus, $body);
    }

    /**
     * Whether the request succeeded and {@see self::body()} is usable.
     *
     * @return bool True on a 2xx with no provider-level error.
     */
    public function isSuccess(): bool
    {
        return $this->kind === MetadataFailureKind::None;
    }

    /**
     * Whether this outcome is a fault rather than an ordinary result.
     *
     * Note this is false for {@see MetadataFailureKind::NotFound} — see
     * {@see MetadataFailureKind::isBenign()} for why.
     *
     * @return bool True when something actually went wrong.
     */
    public function isFailure(): bool
    {
        return !$this->kind->isBenign();
    }

    /**
     * Whether this outcome should count against the provider's health score.
     *
     * @return bool True when the provider genuinely failed to serve us.
     */
    public function countsAgainstHealth(): bool
    {
        return $this->kind->countsAgainstHealth();
    }

    /**
     * Build a result for a lookup that was never attempted.
     *
     * @param string|null $detail Optional detail, surfaced as the provider message.
     *
     * @return self Unsupported-lookup result.
     */
    public static function unsupported(?string $detail = null): self
    {
        return new self(MetadataFailureKind::Unsupported, null, null, null, $detail);
    }

    /**
     * Whether the provider rejected our credentials.
     *
     * @return bool True for HTTP 401/403 or a provider auth status code.
     */
    public function isAuthFailure(): bool
    {
        return $this->kind === MetadataFailureKind::Auth;
    }

    /**
     * The decoded body, but only when the request actually succeeded.
     *
     * Returns null for every failure kind so an error body can never be
     * mistaken for data — the precise bug this class was introduced to prevent.
     *
     * @return array<string, mixed>|null Decoded body on success, null otherwise.
     */
    public function body(): ?array
    {
        return $this->isSuccess() ? $this->rawBody : null;
    }

    /**
     * Structured log context describing this outcome.
     *
     * Always includes `outcome` so failures are greppable by kind, and omits
     * null fields so DEBUG lines for ordinary calls stay compact.
     *
     * @return array<string, mixed> Log context fragment.
     */
    public function logContext(): array
    {
        $context = ['outcome' => $this->kind->value];

        if ($this->httpStatus !== null) {
            $context['http_status'] = $this->httpStatus;
        }
        if ($this->providerStatusCode !== null) {
            $context['provider_status_code'] = $this->providerStatusCode;
        }
        if ($this->providerMessage !== null) {
            $context['provider_message'] = $this->providerMessage;
        }

        return $context;
    }

    /**
     * Pull a provider-level numeric status code out of an error body.
     *
     * Recognises TMDB's `status_code`. Returns null when absent or non-numeric.
     *
     * @param array<string, mixed>|null $body Decoded body.
     *
     * @return int|null Provider status code, or null when the body carries none.
     */
    private static function extractProviderStatusCode(?array $body): ?int
    {
        $code = $body['status_code'] ?? null;

        if (is_int($code)) {
            return $code;
        }

        // TMDB has been observed returning this as a numeric string.
        if (is_string($code) && $code !== '' && ctype_digit($code)) {
            return (int) $code;
        }

        return null;
    }

    /**
     * Pull a provider-supplied error message out of an error body.
     *
     * Recognises TMDB's `status_message`, TVDB v3's `Error`, and Fanart.tv's
     * `error message`. Truncated so a verbose upstream message cannot bloat a
     * log line.
     *
     * @param array<string, mixed>|null $body Decoded body.
     *
     * @return string|null Error message, or null when the body carries none.
     */
    private static function extractProviderMessage(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        foreach (['status_message', 'Error', 'error message', 'error'] as $key) {
            $message = $body[$key] ?? null;
            if (is_string($message) && $message !== '') {
                return mb_substr($message, 0, 200);
            }
        }

        return null;
    }
}
