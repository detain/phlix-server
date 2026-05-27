import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { App } from './App';
import { ApiClient } from './api/client';
import { MemoryTokenStore, makeFetch } from './test/memoryTokenStore';

function renderApp(
  responses: Array<{ status: number; body: unknown }>,
  tokenInit: { access?: string } = {},
  initialPath = '/',
): { redirect: ReturnType<typeof vi.fn> } {
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore(tokenInit),
    fetchImpl: makeFetch(responses).fetch,
  });
  const redirect = vi.fn();
  render(
    <MemoryRouter initialEntries={[initialPath]}>
      <App client={client} redirect={redirect} />
    </MemoryRouter>,
  );
  return { redirect };
}

describe('App shell', () => {
  it('shows a loading state before auth resolves', () => {
    renderApp([{ status: 200, body: { user: { id: 'u', is_admin: 1 } } }], { access: 't' });
    expect(screen.getByRole('status')).toHaveTextContent(/loading/i);
  });

  it('renders the nav + dashboard for an authenticated admin', async () => {
    renderApp([{ status: 200, body: { user: { id: 'u', username: 'root', is_admin: 1 } } }], { access: 't' });

    await waitFor(() =>
      expect(screen.getByRole('navigation', { name: /admin navigation/i })).toBeInTheDocument(),
    );
    // Nav brand + Dashboard link
    expect(screen.getByText('Phlix Admin')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Dashboard' })).toBeInTheDocument();
    // Dashboard page rendered with the resolved user
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    expect(screen.getByTestId('dashboard-greeting')).toHaveTextContent('root');
    // Nav shows the user + a logout control
    expect(screen.getByTestId('nav-user')).toHaveTextContent('root');
    expect(screen.getByRole('button', { name: /log out/i })).toBeInTheDocument();
  });

  it('renders nothing (no admin chrome) for an unauthorized session', async () => {
    const { redirect } = renderApp([{ status: 200, body: { user: { id: 'u', is_admin: 0 } } }], { access: 't' });

    await waitFor(() => expect(redirect).toHaveBeenCalledWith('/login'));
    expect(screen.queryByRole('navigation', { name: /admin navigation/i })).not.toBeInTheDocument();
    expect(screen.queryByText('Phlix Admin')).not.toBeInTheDocument();
  });

  it('renders the client-side 404 for an unknown admin route', async () => {
    renderApp([{ status: 200, body: { user: { id: 'u', is_admin: 1 } } }], { access: 't' }, '/nope');

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /page not found/i })).toBeInTheDocument(),
    );
  });
});
