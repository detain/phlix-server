/**
 * App — the admin SPA shell.
 *
 * Responsibilities:
 *  - Run the client-side {@link useAdminGuard} (complements the server's
 *    AdminMiddleware): while loading, show a spinner; if unauthorized the
 *    guard has already redirected to `/login`, so we render nothing.
 *  - When authorized, render the layout: {@link AdminNav} sidebar + the
 *    routed page content, wrapped in a {@link ToastProvider}.
 *  - Routing uses a `/admin` basename because the bundle is mounted at
 *    `/admin/*` by the server, while its assets live under
 *    `/assets/admin/` (the Vite `base`).
 */
import { Route, Routes } from 'react-router-dom';
import { useAdminGuard, type RedirectFn } from './auth/useAdminGuard';
import { AdminNav } from './nav/AdminNav';
import { DashboardPage } from './pages/DashboardPage';
import { LibrariesPage } from './pages/LibrariesPage';
import { SettingsPage } from './pages/SettingsPage';
import { UsersPage } from './pages/UsersPage';
import { WebhooksPage } from './pages/WebhooksPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { ToastProvider } from './components/Toast';
import type { ApiClient } from './api/client';

export interface AppProps {
  client: ApiClient;
  /** Injectable redirect for the guard (tests pass a spy). */
  redirect?: RedirectFn;
}

export function App({ client, redirect }: AppProps): JSX.Element | null {
  const { status, user } = useAdminGuard(client, redirect);

  if (status === 'loading') {
    return (
      <div className="admin-loading" role="status" aria-live="polite">
        Loading…
      </div>
    );
  }

  if (status === 'unauthorized') {
    // The guard has already triggered the redirect to /login; render
    // nothing to avoid flashing admin chrome.
    return null;
  }

  return (
    <ToastProvider>
      <div className="admin-shell">
        <AdminNav client={client} user={user} />
        <main className="admin-shell__main">
          <Routes>
            <Route path="/" element={<DashboardPage user={user} />} />
            <Route path="/libraries" element={<LibrariesPage client={client} />} />
            <Route path="/settings" element={<SettingsPage client={client} />} />
            <Route path="/users" element={<UsersPage client={client} />} />
            <Route path="/webhooks" element={<WebhooksPage client={client} />} />
            <Route path="*" element={<NotFoundPage />} />
          </Routes>
        </main>
      </div>
    </ToastProvider>
  );
}
