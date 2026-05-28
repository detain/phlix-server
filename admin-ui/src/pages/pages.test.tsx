import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { DashboardPage } from './DashboardPage';
import { NotFoundPage } from './NotFoundPage';
import { NAV_ITEMS } from '../nav/navItems';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';
import { ApiClient } from '../api/client';

function renderDashboard(user?: { id: string; name: string } | null) {
  const { fetch } = makeFetch([
    { status: 200, body: { success: true, data: [] } },           // now-playing
    { status: 200, body: { success: true, data: [] } },           // storage
    { status: 200, body: { success: true, data: [] } },           // activity
    { status: 200, body: { success: true, data: [] } },           // top-users
    { status: 200, body: { success: true, data: [] } },           // top-media
  ]);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return render(
    <ToastProvider timeoutMs={0}>
      <DashboardPage client={client} user={user ?? null} />
    </ToastProvider>,
  );
}

describe('DashboardPage', () => {
  it('renders the Dashboard heading', async () => {
    renderDashboard();
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    });
  });

  it('renders all 5 section headings', async () => {
    renderDashboard();
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Now Playing' })).toBeInTheDocument();
    });
    expect(screen.getByRole('heading', { name: 'Top Users (30d)' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Top Media (30d)' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Storage' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Recent Activity' })).toBeInTheDocument();
  });

  it('renders empty state for all sections when API returns empty arrays', async () => {
    renderDashboard();
    await waitFor(() => {
      expect(screen.getByText('No active sessions')).toBeInTheDocument();
    });
    expect(screen.getByText('No user data yet')).toBeInTheDocument();
    expect(screen.getByText('No media data yet')).toBeInTheDocument();
    expect(screen.getByText('No storage data')).toBeInTheDocument();
    expect(screen.getByText('No recent activity')).toBeInTheDocument();
  });

  it('renders date-range filter buttons', async () => {
    renderDashboard();
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    });
    expect(screen.getByRole('button', { name: '7d' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: '30d' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: '90d' })).toBeInTheDocument();
  });
});

describe('NotFoundPage', () => {
  it('renders a heading and a link back to the dashboard', () => {
    render(
      <MemoryRouter>
        <NotFoundPage />
      </MemoryRouter>,
    );
    expect(screen.getByRole('heading', { name: /page not found/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /back to the dashboard/i })).toHaveAttribute('href', '/');
  });
});

describe('NAV_ITEMS', () => {
  it('includes the Dashboard, Libraries, Users, Settings, Webhooks, Integrations, Services and Backup routes', () => {
    expect(NAV_ITEMS).toEqual([
      { path: '/', label: 'Dashboard' },
      { path: '/libraries', label: 'Libraries' },
      { path: '/users', label: 'Users' },
      { path: '/settings', label: 'Settings' },
      { path: '/webhooks', label: 'Webhooks' },
      { path: '/integrations', label: 'Integrations' },
      { path: '/services', label: 'Services' },
      { path: '/backup', label: 'Backup' },
    ]);
  });
});
