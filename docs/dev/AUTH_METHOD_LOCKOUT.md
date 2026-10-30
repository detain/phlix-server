# Auth-method lock-out guard — 422 semantics (`auth.<method>.enabled` writes)

**Status: SHIPPED (F7, hardened by B2).** Two admin surfaces write the five sign-in
toggles; both enforce the same guard *before* persisting and answer `422` with a
machine-readable `reason` when the proposed state would lock the install out. This page
is the error-meaning reference for integrators and operators; the operator narrative
lives in `README.md` → [Auth-method lock-out
guards](../README.md#auth-method-lock-out-guards).

## The governed keys

Five `server_settings` rows (`value_type = 'bool'`, values stored as `'1'`/`'0'`):

| Method | Key | Absent-row semantics |
|---|---|---|
| `password` | `auth.password.enabled` | answers the `config/auth.php` default (ships `true`) |
| `webauthn` | `auth.webauthn.enabled` | answers the `config/auth.php` default (ships `true`) |
| `oidc` | `auth.oidc.enabled` | **absent = OFF** (bootstrapper semantics) |
| `ldap` | `auth.ldap.enabled` | **absent = OFF** (bootstrapper semantics) |
| `github` | `auth.github.enabled` | **absent = OFF** (bootstrapper semantics) |

Canonical method/key order is `AuthMethodPolicy::METHODS`
(`password, webauthn, oidc, ldap, github`).

## The two rules and their reason tokens

The SSOT for both is `AuthMethodPolicy::assertSafeTransition()`, which throws
`AuthMethodLockoutException` carrying one of exactly two reason constants
(`AuthMethodLockoutException::REASON_*`) — a total mapping, never prose-parsed:

| Rule | Prevents | `reason` token | Human message (`message` / per-key `errors` value) |
|---|---|---|---|
| **R1** | a proposed state switching ALL five methods off | `all_methods_disabled` | `At least one sign-in method must remain enabled` |
| **R2 / R2-bis** | an ACTIVE administrator (`is_admin = 1 AND status = 'active'`) losing their last usable factor — a password while `password` stays on, a registered passkey while `webauthn` stays on, or a stored identity for an external provider that stays on AND is configured | `admin_lockout` | `Disabling these auth methods would leave <N> active administrator[s] with no usable sign-in factor` (spelled singular for one, plural for more) |

Both arms are produced by `AuthMethodLockoutException::publicMessage()`. The
`auth.method_lockout:`-prefixed variant of these sentences is the LOG line
(`getMessage()`); its machine-token prefix and the `(s)` count squeeze never
ride the wire — discriminate with the `reason` token, not the prose
(minors M-SERVER-05, W-min100).

What to do on each refusal: see the README table ("Auth-method lock-out guards") — in
short, R1 means "keep one door open"; R2 means "give that admin a factor before you
close this one" (the count in the message is how many admins are affected; the user ids
stay server-side in the audit path, they are never on the wire).

## The two HTTP surfaces and their envelopes

Both surfaces answer the **byte-identical** 422 envelope (`AdminSettingsController::updateAuthGuarded()`,
`AuthProviderController::disableProvider()`):

```json
{
  "success": false,
  "error":   "Validation failed",
  "errors":  { "auth.<method>.enabled": "<human message>" },
  "reason":  "all_methods_disabled" | "admin_lockout",
  "message": "<human message>"
}
```

- `PUT /api/v1/admin/settings` — only when the write-set touches at least one of the
  five keys; the `errors` map carries one entry per auth method the write-set touched.
  Non-auth keys in the same PUT ride the same transaction: a refusal rolls back the
  **entire batch**, zero persistence anywhere.
- `POST /api/v1/admin/auth-providers/{name}/disable` (`oidc` / `ldap` / `github`) — the
  `errors` map carries the single `auth.<name>.enabled` flag key
  (`AuthProviderBootstrapper::flagKey()`). `enable` needs no guard (enabling can never
  lock anyone out — monotone-safe, deliberately zero-transaction).

Integrators: branch on `reason`; render `errors` under the offending toggle's form row.
The per-key `message` and top-level `message` are operator prose, not contract.

Fail-closed siblings (not lock-out semantics — infrastructure posture): if the guard
cannot run at all, both surfaces answer `500` and persist nothing
(`AuthMethodGuardUnwiredException` / `AuthMethodGuardCheckFailedException` →
`{"success":false,"error":"Auth-method lock-out guard unavailable"|"Auth-method policy
check failed","message":...}`). A broken guard never waves a write through.

## Why a refusal is race-free (the B2 serialization law)

Both writers run the same protocol from `AuthMethodPolicy::guardAndPersist()` /
`guardAndPersistThrough()`: `BEGIN` → `SELECT ... FOR UPDATE` over the **identical
five-key set in the identical canonical order** → guard re-read against the LOCKED
latest-committed view → persist → `COMMIT`; any rejection is `ROLLBACK` + the envelope
above. Universal key-set overlap means admin ∥ provider writers fully serialize — the
all-off state is unreachable through either API regardless of interleaving. Writes that
bypass the APIs entirely (raw SQL, restores) sit outside every lock; the read-path
fallback below is their backstop.

## The read-path fallback (forced states only)

`AuthMethodPolicy::FALLBACK_EVENT = 'auth.methods_all_disabled_fallback'`: if the store
is ever hand-forced into five-off, sign-in falls back to password-only so the install
stays reachable. The event is logged loudly once per worker life, and the fallback is
NEVER persisted — the emergency stays visible to the operator instead of silently
rewriting their data.

## Accepted boundaries (owner-reviewed at F7 rework)

- The quintet is a **sign-in-factor** switch, not an account-creation switch: `password`
  off + `auth.signup_mode` open still mints and admits new accounts. Close signup to
  stop that; the toggles cannot substitute.
- With `webauthn` off, the four **ceremonies** (login/register `start`/`finish`) are
  refused, but `GET`/`DELETE` of your own registered passkeys stays available — the
  documented escape hatch for cleaning up credentials of a disabled factor.
- A provider disable where the provider was never configured is a different, older
  answer: `409 not_configured` (nothing to do with the lock-out guard).

## Recovery when nobody can sign in

Only a bypass write (SQL/restore) can reach that state — see the README recovery
section for the exact re-open leg:
`UPDATE server_settings SET setting_value = '1' WHERE setting_key = 'auth.password.enabled';`. Toggling back takes effect on the workers' next
decision (live reads — no restart needed).
