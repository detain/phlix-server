/**
 * DashboardPage — the empty landing route for the admin SPA.
 *
 * Phase-0 scaffold only: it confirms the shell renders and that the typed
 * API client can perform an authenticated call. It shows the signed-in
 * user (already resolved by the admin guard) and a placeholder for the
 * richer Phase-1 dashboard. No feature widgets yet.
 */
import type { AuthUser } from '../api/client';

export interface DashboardPageProps {
  user: AuthUser | null;
}

export function DashboardPage({ user }: DashboardPageProps): JSX.Element {
  const who = user?.name ?? user?.username ?? user?.email ?? 'admin';
  return (
    <section className="page page--dashboard" aria-labelledby="dashboard-heading">
      <h1 id="dashboard-heading">Dashboard</h1>
      <p data-testid="dashboard-greeting">Signed in as {who}.</p>
      <p className="page__hint">
        Admin feature pages (libraries, users, settings) arrive in Phase&nbsp;1.
      </p>
    </section>
  );
}
