<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

/**
 * Server-side store for the Last.fm OAuth `state` (CSRF) value bound to
 * the user UUID that initiated the "Connect Last.fm" flow.
 *
 * Unlike the Trakt flow (which stores a PKCE `code_verifier`), the Last.fm
 * web-auth handshake has no verifier; the value we must protect is the
 * identity of the user the resulting session key gets bound to. Binding
 * `state => userId` lets the callback BOTH validate the CSRF state AND
 * recover which user initiated the flow, so a forged callback cannot link
 * an attacker's Last.fm account to a victim's Phlix account.
 *
 * The store is one-shot per `state`: after {@see consume()} the entry MUST
 * be deleted so a captured state value cannot be replayed.
 *
 * Implementations bind entries to whatever notion of "session" the host
 * already has. The default {@see SessionLastfmOAuthStateStore} uses
 * `$_SESSION`, mirroring {@see \Phlix\Plugins\Scrobbler\Trakt\SessionTraktOAuthStateStore}.
 *
 * @since 0.32.0
 */
interface LastfmOAuthStateStore
{
    /**
     * Persist a `(state, userId)` pair for later one-shot lookup.
     */
    public function put(string $state, string $userId): void;

    /**
     * Look up the userId matching the supplied state and atomically remove
     * the entry. Returns null if no matching state exists, which MUST be
     * treated as a CSRF failure by callers.
     */
    public function consume(string $state): ?string;
}
