<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Server-side store for the Trakt OAuth `state` (CSRF) value and the
 * matching PKCE `code_verifier`.
 *
 * The store is one-shot per `state`: after {@see consume()} the entry
 * MUST be deleted so a malicious party cannot replay a captured
 * authorization code through a stolen state value.
 *
 * Implementations are expected to bind entries to whatever notion of
 * "user" the host already has (PHP session, JWT subject, DB row keyed
 * by user UUID, …). The default implementation in
 * {@see SessionTraktOAuthStateStore} uses `$_SESSION`, matching the
 * historic behaviour but lifted out into a swappable seam so the
 * controller becomes unit-testable and so production deployments that
 * run under Workerman (where `$_SESSION` is not request-scoped) can
 * provide a DB-backed implementation without touching controller logic.
 *
 * @since 0.16.0
 */
interface TraktOAuthStateStore
{
    /**
     * Persist a `(state, code_verifier)` pair for later one-shot lookup.
     */
    public function put(string $state, string $codeVerifier): void;

    /**
     * Look up the code_verifier matching the supplied state and atomically
     * remove the entry. Returns null if no matching state exists, which
     * MUST be treated as a CSRF failure by callers.
     */
    public function consume(string $state): ?string;
}
