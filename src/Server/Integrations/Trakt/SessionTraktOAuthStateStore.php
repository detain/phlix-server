<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Integrations\Trakt;

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
    private const USER_ID_KEY = 'trakt_oauth_user_id';

    public function put(string $state, string $codeVerifier, ?string $userId = null): void
    {
        $_SESSION[self::STATE_KEY] = $state;
        $_SESSION[self::VERIFIER_KEY] = $codeVerifier;
        // M-4: parse at the boundary — an empty identity is NO identity, never
        // a hash_equals-able '' that a forged empty userId could match.
        $_SESSION[self::USER_ID_KEY] = ($userId === '') ? null : $userId;
    }

    public function consume(string $state): ?string
    {
        $entry = $this->consumeWithIdentity($state);

        return $entry === null ? null : $entry['code_verifier'];
    }

    public function consumeWithIdentity(string $state): ?array
    {
        $saved = is_string($_SESSION[self::STATE_KEY] ?? null) ? $_SESSION[self::STATE_KEY] : '';
        $verifier = is_string($_SESSION[self::VERIFIER_KEY] ?? null) ? $_SESSION[self::VERIFIER_KEY] : '';
        $boundUserId = is_string($_SESSION[self::USER_ID_KEY] ?? null) ? $_SESSION[self::USER_ID_KEY] : null;

        // One-shot: regardless of outcome we wipe the saved values so a
        // replay attempt cannot reuse them.
        unset($_SESSION[self::STATE_KEY], $_SESSION[self::VERIFIER_KEY], $_SESSION[self::USER_ID_KEY]);

        if ($saved === '' || $verifier === '') {
            return null;
        }
        if (!hash_equals($saved, $state)) {
            return null;
        }

        return ['code_verifier' => $verifier, 'user_id' => $boundUserId];
    }
}
