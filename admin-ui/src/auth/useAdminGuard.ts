/**
 * Admin-guard hook.
 *
 * The server already gates `/admin/*` via `AdminMiddleware` (redirecting
 * non-admins to `/login` before the shell is even served), so this hook is
 * the CLIENT-side complement: it confirms the stored session still
 * resolves to an admin user by calling `GET /api/v1/auth/me`, and drives
 * the SPA's own redirect when the session is missing/expired/non-admin.
 *
 * States:
 *  - `loading`     — the `auth/me` probe is in flight.
 *  - `authorized`  — a logged-in admin; `user` is populated.
 *  - `unauthorized`— no token, expired token, or a non-admin user. The
 *                    hook redirects to `/login` (via the injected
 *                    `redirect` fn, defaulting to a real navigation).
 *
 * Redirect is injectable so it is unit-testable without a real `window`.
 */
import { useEffect, useState } from 'react';
import { ApiError, normalizeBool, type ApiClient, type AuthUser } from '../api/client';

export type GuardStatus = 'loading' | 'authorized' | 'unauthorized';

export interface AdminGuardResult {
  status: GuardStatus;
  user: AuthUser | null;
}

export type RedirectFn = (to: string) => void;

const defaultRedirect: RedirectFn = (to) => {
  if (typeof window !== 'undefined') {
    window.location.href = to;
  }
};

/**
 * Resolve whether the current session is an authorised admin. Returns the
 * guard status and (when authorised) the user. Performs the redirect side
 * effect itself for the unauthorized case.
 */
export function useAdminGuard(
  client: ApiClient,
  redirect: RedirectFn = defaultRedirect,
): AdminGuardResult {
  const [result, setResult] = useState<AdminGuardResult>({
    status: 'loading',
    user: null,
  });

  useEffect(() => {
    let cancelled = false;

    // No stored token at all → straight to login, skip the network call.
    if (!client.isLoggedIn()) {
      setResult({ status: 'unauthorized', user: null });
      redirect('/login');
      return;
    }

    client
      .getCurrentUser()
      .then((user) => {
        if (cancelled) {
          return;
        }
        if (isAdminUser(user)) {
          setResult({ status: 'authorized', user });
        } else {
          // Authenticated but not an admin → bounce to login.
          setResult({ status: 'unauthorized', user: null });
          redirect('/login');
        }
      })
      .catch((err: unknown) => {
        if (cancelled) {
          return;
        }
        // 401/403 (or any failure) → treat as unauthorized + redirect.
        void err;
        setResult({ status: 'unauthorized', user: null });
        redirect('/login');
      });

    return () => {
      cancelled = true;
    };
  }, [client, redirect]);

  return result;
}

/**
 * An "admin user" is any authenticated user the server returns from
 * `auth/me` that is flagged `is_admin`. The server-side AdminMiddleware is
 * the authoritative gate; this is a defensive client-side check so the SPA
 * never renders admin chrome for a non-admin token that slipped through.
 */
export function isAdminUser(user: AuthUser | null | undefined): boolean {
  return user !== null && user !== undefined && normalizeBool(user.is_admin);
}

/** Re-export so callers can narrow on the API error status if needed. */
export { ApiError };
