<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * `$_SESSION`-backed implementation of {@see TraktOAuthStateStore}.
 *
 * Retains the historic per-session storage behaviour of the Trakt
 * OAuth flow while honoring the new one-shot contract: {@see consume()}
 * unsets the entry before returning it so a captured state value
 * cannot be replayed.
 *
 * @since 0.16.0
 */
final class SessionTraktOAuthStateStore implements TraktOAuthStateStore
{
    private const STATE_KEY = 'trakt_oauth_state';
    private const VERIFIER_KEY = 'trakt_oauth_code_verifier';

    public function put(string $state, string $codeVerifier): void
    {
        $_SESSION[self::STATE_KEY] = $state;
        $_SESSION[self::VERIFIER_KEY] = $codeVerifier;
    }

    public function consume(string $state): ?string
    {
        $saved = is_string($_SESSION[self::STATE_KEY] ?? null) ? $_SESSION[self::STATE_KEY] : '';
        $verifier = is_string($_SESSION[self::VERIFIER_KEY] ?? null) ? $_SESSION[self::VERIFIER_KEY] : '';

        // One-shot: regardless of outcome we wipe the saved values so a
        // replay attempt cannot reuse them.
        unset($_SESSION[self::STATE_KEY], $_SESSION[self::VERIFIER_KEY]);

        if ($saved === '' || $verifier === '') {
            return null;
        }
        if (!hash_equals($saved, $state)) {
            return null;
        }

        return $verifier;
    }
}
