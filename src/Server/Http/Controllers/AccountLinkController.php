<?php

/**
 * Phlix media server component: Controller.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Ldap\LdapProvider;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Shared\Auth\AuthResult;

/**
 * S45 account-linking endpoints (the authenticated half).
 *
 * Lets a signed-in local account (1) list the external identities already linked
 * to it and (2) link an LDAP identity by proving control of it via a real bind.
 * The OIDC link uses a browser redirect flow and lives on
 * {@see \Phlix\Plugins\Oidc\Controller\OidcCallbackController} (authorizeLink +
 * the callback link branch); this controller handles the read + LDAP paths.
 *
 * ## Security model (the linchpin)
 *
 * A link MUST prove the logged-in user actually controls the external identity —
 * a client-claimed `external_id` is NEVER trusted. For LDAP that proof is a
 * successful {@see LdapProvider::authenticate()} bind; the linked identity is the
 * provider-returned `ldap.<dn>`, not anything from the request body.
 *
 * ## Storage + conflicts
 *
 * Linking = INSERT a `user_identities` row (migration 092) for the current
 * `user_id`; it never mutates `users`. Guards:
 *  - already linked to the SAME user → idempotent success (200);
 *  - linked to ANOTHER user → 409 (the DB UNIQUE index is the race backstop — a
 *    duplicate-key throw on create() is re-classified by re-reading and mapped
 *    to 409/idempotent success ONLY when the identity genuinely exists; a
 *    non-duplicate create() failure is re-thrown and surfaces as a 5xx, never a
 *    mislabeled 409).
 *
 * ## S47 additions
 *
 * This controller now also serves the UNLINK endpoint
 * ({@see self::unlink()}, DELETE /auth/identities/{id}) that pairs with S45's
 * link. Repointing the login READ path onto `user_identities` lives in
 * {@see \Phlix\Auth\UserRepository::findOrCreateByExternalId()} (S47 too).
 *
 * @package Phlix\Server\Http\Controllers
 * @since 0.100.0
 */
final class AccountLinkController
{
    /** Provider family for LDAP links. */
    private const string LDAP_PROVIDER = 'ldap';

    /** Default single-instance sentinel (S47 multi-instance uses non-empty). */
    private const string DEFAULT_INSTANCE = '';

    /**
     * S47: the registry INSTANCE KEY for the built-in LDAP provider. The bundled
     * LDAP link flow uses the family's DEFAULT instance, whose registry key is the
     * family name verbatim ({@see AuthProviderRegistry::instanceKey()} with the ''
     * sentinel), so this resolves to `'ldap'` — behaviour-identical to the raw
     * family string it replaces. Centralising the key here matches the instance-key
     * pattern S47 applied to OIDC ({@see \Phlix\Plugins\Oidc\Controller\OidcCallbackController::oidcInstanceKey()})
     * instead of hard-coding the literal family string at the registry lookups.
     */
    private static function ldapInstanceKey(): string
    {
        return AuthProviderRegistry::instanceKey(self::LDAP_PROVIDER, self::DEFAULT_INSTANCE);
    }

    /**
     * Request-path self-heal for the per-worker provider registry (S44 Finding 3).
     * Optional so direct-construction / unit tests keep working; the DI factory
     * binds it explicitly.
     */
    private ?AuthProviderBootstrapper $bootstrapper;

    /**
     * S47 unlink safety guard: used to determine whether the caller still has a
     * LOCAL password sign-in method before removing an external identity, so we
     * never leave an account with NO way to log in. Optional so existing
     * direct-construction / unit call sites keep working; the DI factory binds it
     * explicitly. When null the guard fails SAFE (refuses to remove the last
     * identity) rather than risk a lock-out.
     */
    private ?UserRepository $userRepository;

    public function __construct(
        private readonly UserIdentityRepository $identities,
        private readonly AuthProviderRegistry $registry,
        ?AuthProviderBootstrapper $bootstrapper = null,
        ?UserRepository $userRepository = null,
    ) {
        $this->bootstrapper = $bootstrapper;
        $this->userRepository = $userRepository;
    }

    /**
     * GET /auth/identities (AUTHENTICATED).
     *
     * List the external identities linked to the current user. Shapes each row
     * for the client and DELIBERATELY omits `provider_data` (it may hold
     * metadata that should not leak to the browser).
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function listIdentities(Request $request, array $params): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }

        $identities = [];
        foreach ($this->identities->findByUserId($userId) as $row) {
            $identities[] = [
                'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
                'provider' => is_string($row['provider'] ?? null) ? $row['provider'] : '',
                'provider_instance' => is_string($row['provider_instance'] ?? null)
                    ? $row['provider_instance']
                    : '',
                'external_id' => is_string($row['external_id'] ?? null) ? $row['external_id'] : '',
                'linked_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : null,
            ];
        }

        return (new Response())->json(['identities' => $identities]);
    }

    /**
     * DELETE /auth/identities/{id} (AUTHENTICATED — S47).
     *
     * Unlinks (removes) one external identity from the current user's account.
     * Pairs with S45's link + the {@see self::listIdentities()} listing (whose
     * `id` field is the `{id}` path parameter here).
     *
     * ## Security — own-identity-only (the linchpin)
     *
     * A caller may only unlink an identity they actually OWN. Ownership is proven
     * by resolving `{id}` within the CURRENT user's own identity list
     * ({@see UserIdentityRepository::findByUserId()} keyed on the trusted session
     * `user_id`) — an id that is not in that list (another user's identity, or a
     * non-existent one) yields an indistinguishable 404, never a cross-account
     * delete. The actual DELETE is then additionally scoped by `user_id`
     * ({@see UserIdentityRepository::delete()}), so the removal can never touch
     * another account even under a race.
     *
     * ## Safety — never remove the LAST sign-in method
     *
     * Removing an identity that would leave the account with NO password AND no
     * other external identity would lock the user out. Such a request is refused
     * (409). "Has password" comes from `users.password_hash`
     * ({@see UserRepository::hasLocalPassword()}, nullable since migration 091);
     * "other identities" is the count of the user's remaining identity rows.
     *
     * The local password and every OTHER identity are left untouched.
     *
     * @param Request $request
     * @param array<string, string> $params Route params; `id` = identity row UUID.
     * @return Response
     */
    public function unlink(Request $request, array $params): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }

        $identityId = $params['id'] ?? '';
        if ($identityId === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_identity_id',
                'message' => 'An identity id is required',
            ]);
        }

        // Resolve the target WITHIN the caller's own identities — this is the
        // own-identity-only scope: an id owned by someone else (or absent) is
        // simply not in this list and yields a 404, never a cross-account delete.
        $owned = $this->identities->findByUserId($userId);
        $target = null;
        foreach ($owned as $row) {
            if (($row['id'] ?? null) === $identityId) {
                $target = $row;
                break;
            }
        }

        if ($target === null) {
            return (new Response())->status(404)->json([
                'error' => 'identity_not_found',
                'message' => 'No such linked identity',
            ]);
        }

        // Last-sign-in-method guard. If removing this identity would leave the
        // account with NO local password AND no other external identity, refuse —
        // otherwise the user could lock themselves out.
        $otherIdentityCount = count($owned) - 1;
        $hasPassword = $this->userRepository !== null
            ? $this->userRepository->hasLocalPassword($userId)
            : false; // fail SAFE when the repository is unavailable
        if (!$hasPassword && $otherIdentityCount <= 0) {
            return (new Response())->status(409)->json([
                'error' => 'last_sign_in_method',
                'message' => 'Cannot remove your only sign-in method',
            ]);
        }

        // Delete by the target's own row id. Ownership is ALREADY established: the
        // target was resolved from within the caller's OWN identity list
        // (findByUserId keyed on the trusted session user_id) above, so its `id`
        // is a row this user owns — a raw request id is NEVER deleted without that
        // resolution. The row id is the PRIMARY KEY, so this removes exactly this
        // one identity and no other identity/password is affected.
        $targetId = is_string($target['id'] ?? null) ? $target['id'] : '';
        $this->identities->deleteById($targetId);

        return (new Response())->status(200)->json([
            'success' => true,
            'message' => 'Identity unlinked',
        ]);
    }

    /**
     * POST /auth/identities/link/ldap (AUTHENTICATED).
     *
     * Body: `{username, password}`. On a successful LDAP bind, links the
     * provider-verified `ldap.<dn>` identity to the current user. A failed bind
     * links NOTHING and returns a single generic 401 (no user-enumeration
     * oracle, mirroring the LDAP login path).
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function linkLdap(Request $request, array $params): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->unauthorized();
        }

        $body = $request->body;
        $username = is_string($body['username'] ?? null) ? $body['username'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if ($username === '' || $password === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_credentials',
                'message' => 'username and password are required',
            ]);
        }

        // S44 Finding 3 — reconcile THIS worker's registry with the persisted
        // `auth.ldap.enabled` flag before attempting the bind. Settings-only, no
        // network I/O.
        $this->bootstrapper?->ensureProviderRegistered(AuthProviderBootstrapper::LDAP);

        if (!$this->registry->hasProvider(self::ldapInstanceKey())) {
            return $this->providerUnavailable();
        }
        $provider = $this->registry->getProvider(self::ldapInstanceKey());
        if (!$provider instanceof LdapProvider) {
            return $this->providerUnavailable();
        }

        // The bounded (5s) LDAP bind — the proof of control. ext-ldap is not
        // Swoole-hookable so this stays synchronous by design (see S44).
        $result = $provider->authenticate([
            'username' => $username,
            'password' => $password,
        ]);

        if ($result->isFailure()) {
            $error = is_string($result->error) ? $result->error : '';
            // A genuine config/connection failure is a 5xx, not a bad password.
            if (str_starts_with($error, 'ldap_error:')) {
                LoggerFactory::get(LogChannels::AUTH)->error('LDAP link provider error', [
                    'error' => $error,
                ]);
                return $this->providerUnavailable();
            }
            // invalid_credentials / user_not_found / missing_* all collapse to a
            // single generic 401 so we never confirm whether an LDAP user exists.
            return (new Response())->status(401)->json([
                'error' => 'invalid_credentials',
                'message' => 'Invalid credentials',
            ]);
        }

        // The identity to link is the provider-verified `ldap.<dn>`, NEVER a
        // client-supplied value.
        $externalId = is_string($result->externalId) ? $result->externalId : '';
        if ($externalId === '') {
            LoggerFactory::get(LogChannels::AUTH)->error('LDAP bind succeeded without an external id');
            return $this->providerUnavailable();
        }

        return $this->linkVerifiedIdentity($userId, self::LDAP_PROVIDER, $externalId, $result);
    }

    /**
     * Persist a provider-verified identity as a link on the current user, with
     * the already-linked / conflict guards. JSON responses (this is an API POST).
     *
     * @param string $userId Trusted current-user id (from the validated session).
     * @param string $provider Provider family (e.g. "ldap").
     * @param string $externalId Provider-verified external identity — never client input.
     * @param AuthResult $result The verified auth result (for non-secret metadata).
     * @return Response
     */
    private function linkVerifiedIdentity(
        string $userId,
        string $provider,
        string $externalId,
        AuthResult $result,
    ): Response {
        $existing = $this->identities->findByProviderExternalId(
            $provider,
            self::DEFAULT_INSTANCE,
            $externalId,
        );
        if ($existing !== null) {
            $ownerId = is_string($existing['user_id'] ?? null) ? $existing['user_id'] : '';
            if ($ownerId === $userId) {
                return $this->linkSuccess($provider, false);
            }
            return $this->identityConflict();
        }

        try {
            $this->identities->create(
                $userId,
                $provider,
                self::DEFAULT_INSTANCE,
                $externalId,
                $this->buildProviderData($result),
            );
        } catch (\Throwable $e) {
            // create() failed — CLASSIFY the failure by re-reading. The row's
            // actual presence is the authoritative signal (more reliable than
            // parsing driver error codes, which the MySQL wrapper flattens to
            // SQLSTATE 23000 across dup-key/FK/NOT-NULL alike):
            //  - re-read non-null ⇒ a genuine duplicate raced the DB UNIQUE index
            //    (migration 092): same user ⇒ idempotent success, else 409;
            //  - re-read null ⇒ the INSERT did NOT land for a NON-duplicate reason
            //    (transient DB fault, FK/constraint, connection drop) — a real
            //    server error that must NOT be mislabeled as a 409 conflict.
            $raced = $this->identities->findByProviderExternalId(
                $provider,
                self::DEFAULT_INSTANCE,
                $externalId,
            );
            if ($raced === null) {
                // Not a conflict. Re-throw so the central error mapper surfaces a
                // 5xx (and log at error, not warning) rather than masking it.
                LoggerFactory::get(LogChannels::AUTH)->error('identity link create failed', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
            if (($raced['user_id'] ?? null) === $userId) {
                return $this->linkSuccess($provider, false);
            }
            LoggerFactory::get(LogChannels::AUTH)->warning('identity link conflict', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            return $this->identityConflict();
        }

        return $this->linkSuccess($provider, true);
    }

    /**
     * Non-secret provider metadata for a linked identity row: the user's own
     * email + display name (for the settings UI). No tokens/secrets — and the
     * listing never returns provider_data anyway.
     *
     * @param AuthResult $result The verified auth result.
     * @return array<string, string>|null
     */
    private function buildProviderData(AuthResult $result): ?array
    {
        $data = [];
        $email = $result->getEmail();
        if (is_string($email) && $email !== '') {
            $data['email'] = $email;
        }
        $name = $result->getDisplayName();
        if (is_string($name) && $name !== '') {
            $data['name'] = $name;
        }

        return $data === [] ? null : $data;
    }

    /**
     * Extract the trusted current-user id, or null when the request is not
     * authenticated. {@see \Phlix\Server\Http\Middleware\AuthMiddleware} gates the
     * route, but this defends the controller when called directly (tests) or if a
     * route is mis-wired.
     */
    private function currentUserId(Request $request): ?string
    {
        $userId = $request->userId;

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    private function linkSuccess(string $provider, bool $created): Response
    {
        return (new Response())->status(200)->json([
            'success' => true,
            'provider' => $provider,
            'created' => $created,
            'message' => $created ? 'Identity linked' : 'Identity already linked to this account',
        ]);
    }

    private function identityConflict(): Response
    {
        return (new Response())->status(409)->json([
            'error' => 'identity_already_linked',
            'message' => 'This external identity is already linked to another account',
        ]);
    }

    private function unauthorized(): Response
    {
        return (new Response())->status(401)->json([
            'error' => 'Unauthorized',
            'code' => 'auth.required',
        ]);
    }

    private function providerUnavailable(): Response
    {
        return (new Response())->status(503)->json([
            'error' => 'provider_unavailable',
            'code' => 'provider_unavailable',
            'message' => 'LDAP provider is not available',
        ]);
    }
}
