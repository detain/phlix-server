import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { DashboardPage } from './DashboardPage';
import { NotFoundPage } from './NotFoundPage';
import { NAV_ITEMS } from '../nav/navItems';

describe('DashboardPage', () => {
  it('greets the named user', () => {
    render(<DashboardPage user={{ id: '1', name: 'Ada' }} />);
    expect(screen.getByTestId('dashboard-greeting')).toHaveTextContent('Ada');
  });

  it('falls back to "admin" when no user fields are present', () => {
    render(<DashboardPage user={null} />);
    expect(screen.getByTestId('dashboard-greeting')).toHaveTextContent('admin');
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
  it('includes the Dashboard, Libraries, Users, Settings, Webhooks and Integrations routes', () => {
    expect(NAV_ITEMS).toEqual([
      { path: '/', label: 'Dashboard' },
      { path: '/libraries', label: 'Libraries' },
      { path: '/users', label: 'Users' },
      { path: '/settings', label: 'Settings' },
      { path: '/webhooks', label: 'Webhooks' },
      { path: '/integrations', label: 'Integrations' },
    ]);
  });
});
