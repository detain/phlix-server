/**
 * Admin SPA bootstrap.
 *
 * Mounts {@link App} into `#root` under a `BrowserRouter` whose basename is
 * `/admin` — the path the server mounts this bundle at (its static assets
 * live under `/assets/admin/`, the Vite `base`). Uses the shared
 * {@link api} client singleton.
 *
 * This file is the entry point only (no branching logic), so it is
 * excluded from coverage in `vite.config.ts`.
 */
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { App } from './App';
import { api } from './api/client';
import './styles.css';

const rootEl = document.getElementById('root');
if (rootEl) {
  createRoot(rootEl).render(
    <StrictMode>
      <BrowserRouter basename="/admin">
        <App client={api} />
      </BrowserRouter>
    </StrictMode>,
  );
}
