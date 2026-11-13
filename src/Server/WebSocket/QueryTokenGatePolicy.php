<?php

/**
 * Phlix media server component: Server - WebSocket.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\WebSocket;

use Phlix\Admin\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * SSOT resolver for the legacy `?token=` WS query-carrier gate (CP-007 /
 * CP-024 PHASE B + the 2026-11-10 off-kit owner ruling wiring
 * `auth.ws_query_token`).
 *
 * ## What it answers
 *
 * Exactly one question — "may a QUERY-carrier handshake complete right now?"
 * — and nothing else consults the precedence: {@see
 * SyncPlayAuthMiddleware::queryTokenCarrierAllowed()} delegates here per
 * decision, and it is the sole consumer. Bearer and admitted-cookie carriers
 * are resolved by {@see SyncPlayAuthMiddleware::resolveHandshakeToken()}
 * alone and NEVER pass through this class (CP-006 precedence law,
 * COOKIE-DESIGN.md §2.3 — UNMOVED). The shape mirrors the estate's own
 * staged env-then-stored idiom shipped for the cookie carrier
 * ({@see \Phlix\Auth\CookiePolicy::insecureOverride()} — "the same staged
 * env-first shape as `auth.cookie.insecure`", WEBSOCKET_AUTH_CARRIERS.md).
 *
 * ## Precedence (fixed, three tiers, one default)
 *
 *  1. **EXPLICIT ENV** — `PHLIX_WS_QUERY_TOKEN` (config
 *     `websocket.query_token`) when set to a non-empty value. The operator
 *     override wins in BOTH directions: `deny` outranks an admin-row allow,
 *     `allow` outranks an admin-row deny (the straggler-host escape hatch).
 *     The ENV-tier vocabulary is UNMOVED from PHASE A and lives in
 *     {@see SyncPlayAuthMiddleware::parseQueryTokenPolicy()}: among SET
 *     values only an exact `deny` denies, a misspelled value still fails
 *     open to `allow` (opt-in-tightening law — a typo in a production env
 *     file must never silently lock legacy clients out). Env is
 *     restart-class: read once at boot and handed in.
 *  2. **ADMIN DB ROW** — the shared-schema key `auth.ws_query_token` (the
 *     `allow`/`deny` string enum, phlix-shared schemas/server-settings.schema.json).
 *     LIVE per decision — an admin flip lands on the very next handshake,
 *     no restart, no worker recycle. Read exactly like {@see
 *     \Phlix\Auth\AuthManager::resolveSignupMode()} and {@see
 *     \Phlix\Auth\CookiePolicy}: one fresh `getEffective()` per call, store
 *     failures degrade to the shipped posture, no cross-decision cache (the
 *     de573d6e rework law: the container memoizes THIS object's owner for
 *     the worker's lifetime, so any memo here would be a process cache in
 *     a per-decision costume).
 *  3. **PHASE B DEFAULT — DENY** (CP-024): row absent, row unusable, store
 *     unreadable, or no store wired all refuse the query carrier. `deny` is
 *     the ONLY shipped default, held here alone — the CP-024-era drift smell
 *     of three sites each hardcoding their own `deny` fallback
 *     (config/server.php, the WebSocketServer constructor, the middleware
 *     constructor parameter) is closed by this class: every former site now
 *     defers here.
 *
 * ## Fail-closed deviation (disclosed in the 11-10 register)
 *
 * {@see \Phlix\Auth\AuthMethodPolicy} throws on an unreadable store because
 * a login request can render that failure as a 500. This gate lives on
 * `onWebSocketConnect`, where a throw would trigger Workerman's
 * `Worker::stopAll()` and kill `:8097` outright (the shipped law at
 * {@see WebSocketServer::onWebSocketConnect()} rate-limit block: the hook
 * "must NOT throw"). So an unreadable row, or a row holding any value
 * outside the enum, normalizes to DENY with a once-per-instance
 * (bounded-alarm) warning — the safe direction at a security gate whose
 * shipped default is already deny: a broken row can only restore the
 * shipped posture, never widen it. The warning NEVER logs the stored
 * bytes (gettype only) — the estate's redacted-log law has no exceptions
 * on this rail, and config data is not owed a log line the way credentials
 * are denied one.
 */
final class QueryTokenGatePolicy
{
    /**
     * Shared-schema stored form of the retirement gate (admin control,
     * phlix-shared server-settings schema; string enum `allow`/`deny`).
     */
    public const ADMIN_SETTING_KEY = 'auth.ws_query_token';

    /** Bounded-alarm latches (once per instance = once per worker life). */
    private bool $unusableRowLogged = false;

    private bool $unreadableRowLogged = false;

    /**
     * @param string|null            $envPolicy Raw `PHLIX_WS_QUERY_TOKEN` value
     *                                          as the config surface passes it
     *                                          (nullableText semantics: unset
     *                                          and empty are BOTH "operator
     *                                          silent" = null), or null from a
     *                                          boot that never read env.
     * @param SettingsRepository|null $settings Admin-row store; null (tests,
     *                                          standalone harness boots)
     *                                          makes tier 2 unavailable —
     *                                          the PHASE B default answers,
     *                                          exactly like the pre-wiring
     *                                          shipped posture.
     * @param LoggerInterface|null    $logger   `websocket` channel — the same
     *                                          handle the middleware logs
     *                                          through, so gate warnings ride
     *                                          the established capture idiom.
     */
    public function __construct(
        private readonly ?string $envPolicy,
        private readonly ?SettingsRepository $settings = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The effective gate answer for THIS decision — fresh every call.
     *
     * @return bool True when a legacy `?token=` query-carrier handshake may
     *               complete; false refuses it pre-101.
     */
    public function allowsQueryCarrier(): bool
    {
        // Tier 1: the operator spoke through env (or the direct config
        // surface) — their word wins, parsed by the UNMOVED PHASE A
        // vocabulary. Empty string counts as silence, matching EnvConfig's
        // unset-or-empty "not configured" law.
        if ($this->envPolicy !== null && $this->envPolicy !== '') {
            return SyncPlayAuthMiddleware::parseQueryTokenPolicy($this->envPolicy);
        }

        // Tier 2: ask the admin row — live, once per decision.
        if ($this->settings === null) {
            return false;
        }

        try {
            $stored = $this->settings->getEffective(self::ADMIN_SETTING_KEY);
        } catch (\Throwable $error) {
            $this->logUnreadable($error);

            return false;
        }

        if ($stored === SyncPlayAuthMiddleware::QUERY_TOKEN_ALLOW) {
            return true;
        }

        if ($stored === SyncPlayAuthMiddleware::QUERY_TOKEN_DENY || $stored === null) {
            // Exact stored deny, or no row anywhere (phlix-shared v0.55+
            // declares 'deny' as the schema default, matching the CP-024
            // shipped default; only absence answers tier 3, and tier 3 is deny).
            return false;
        }

        $this->logUnusableRow($stored);

        return false;
    }

    /**
     * Bounded alarm: the store could not be consulted. Fail-closed to deny —
     * see the class docblock for why a throw is the wrong direction here.
     */
    private function logUnreadable(\Throwable $error): void
    {
        if ($this->unreadableRowLogged || $this->logger === null) {
            return;
        }
        $this->unreadableRowLogged = true;

        $this->logger->warning(
            'WebSocket query-token gate: settings store unreadable; the legacy ?token= carrier stays refused (deny)',
            ['error' => $error->getMessage()],
        );
    }

    /**
     * Bounded alarm: the row holds a value outside the enum (or a non-string
     * at all — raw-SQL/restored writes bypass the API's schema validation).
     * Normalizes to deny so a broken admin row can only restore the shipped
     * posture. NEVER logs the stored bytes — the value is operator-entered
     * data of arbitrary shape and size; the type name is the whole
     * disclosure.
     */
    private function logUnusableRow(mixed $stored): void
    {
        if ($this->unusableRowLogged || $this->logger === null) {
            return;
        }
        $this->unusableRowLogged = true;

        $this->logger->warning(
            'WebSocket query-token gate: admin setting ' . self::ADMIN_SETTING_KEY
                . ' holds an unusable value; normalizing to deny (expected the string "allow" or "deny")',
            ['value_type' => gettype($stored)],
        );
    }
}
