<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

/**
 * `$_SESSION`-backed implementation of {@see LastfmOAuthStateStore}.
 *
 * Stores the per-request CSRF `state` alongside the initiating user UUID
 * and honors the one-shot contract: {@see consume()} unsets the entry
 * before returning it so a captured state value cannot be replayed.
 *
 * Mirrors {@see \Phlix\Plugins\Scrobbler\Trakt\SessionTraktOAuthStateStore}.
 *
 * @since 0.32.0
 */
final class SessionLastfmOAuthStateStore implements LastfmOAuthStateStore
{
    private const STATE_KEY = 'lastfm_oauth_state';
    private const USER_KEY = 'lastfm_oauth_user_id';

    public function put(string $state, string $userId): void
    {
        $_SESSION[self::STATE_KEY] = $state;
        $_SESSION[self::USER_KEY] = $userId;
    }

    public function consume(string $state): ?string
    {
        $saved = is_string($_SESSION[self::STATE_KEY] ?? null) ? $_SESSION[self::STATE_KEY] : '';
        $userId = is_string($_SESSION[self::USER_KEY] ?? null) ? $_SESSION[self::USER_KEY] : '';

        // One-shot: regardless of outcome we wipe the saved values so a
        // replay attempt cannot reuse them.
        unset($_SESSION[self::STATE_KEY], $_SESSION[self::USER_KEY]);

        if ($saved === '' || $userId === '') {
            return null;
        }
        if (!hash_equals($saved, $state)) {
            return null;
        }

        return $userId;
    }
}
