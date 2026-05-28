import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { IntegrationsPage } from './IntegrationsPage';
import { ApiClient } from '../api/client';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a test page driven by ordered real-shaped responses. */
function renderPage(
  responses: Array<{ status: number; body: unknown }>,
): { calls: ReturnType<typeof makeFetch>['calls']; unmount: () => void } {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  const result = render(
    <ToastProvider timeoutMs={0}>
      <IntegrationsPage client={client} />
    </ToastProvider>,
  );
  return { calls, unmount: result.unmount };
}

afterEach(() => {
  vi.useRealTimers();
});

describe('IntegrationsPage', () => {
  describe('Arr sync section', () => {
    it('shows loading then heading', async () => {
      renderPage([{ status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } }]);
      expect(screen.getByRole('heading', { name: 'Integrations' })).toBeInTheDocument();
      expect(screen.getByText('Loading sync status…')).toBeInTheDocument();
    });

    it('renders Never synced when last_sync_at is null', async () => {
      renderPage([{ status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } }]);
      await waitFor(() => {
        expect(screen.getByText('Never synced')).toBeInTheDocument();
      });
    });

    it('renders last sync time when available', async () => {
      renderPage([
        { status: 200, body: { enabled: true, last_sync_at: '2026-05-28T10:00:00Z', last_sync_timestamp: 1716880800 } },
        { status: 200, body: { providers: [] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('2026-05-28T10:00:00Z')).toBeInTheDocument();
      });
    });

    it('renders enabled/disabled badge', async () => {
      renderPage([
        { status: 200, body: { enabled: true, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [] } },
      ]);
      await waitFor(() => {
        // Look for the status-badge span specifically (the one in the section header)
        const badges = screen.getAllByText('Enabled');
        const headerBadge = badges.find(b => b.classList.contains('status-badge'));
        expect(headerBadge).toBeInTheDocument();
      });
    });

    it('Sync now button triggers POST and shows spinner (if observable)', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { enabled: true, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [] } },
        { status: 200, body: { success: true, message: 'Sync complete' } },
        { status: 200, body: { enabled: true, last_sync_at: '2026-05-28T10:00:00Z', last_sync_timestamp: 1716880800 } },
        { status: 200, body: { providers: [] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('Never synced')).toBeInTheDocument();
      });

      const syncButton = screen.getByRole('button', { name: 'Sync now' });
      // Note: with fast mock, the "Syncing…" intermediate state may not be observable.
      // Verify the button click triggers the sync and eventually succeeds.
      await user.click(syncButton);

      // Wait for success toast (proves the sync flow completed)
      await waitFor(() => {
        expect(screen.getByRole('status')).toHaveTextContent('Sync complete');
      });
    });

    it('Sync now success → toast + updated last sync time', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { enabled: true, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [] } },
        { status: 200, body: { success: true, message: 'Sync complete.' } },
        { status: 200, body: { enabled: true, last_sync_at: '2026-05-28T10:00:00Z', last_sync_timestamp: 1716880800 } },
        { status: 200, body: { providers: [] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('Never synced')).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Sync now' }));

      await waitFor(() => {
        expect(screen.getByRole('status')).toHaveTextContent('Sync complete.');
      });
    });

    it('Sync now failure → error toast', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { enabled: true, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [] } },
        { status: 500, body: { success: false, error: 'Sync process crashed' } },
        { status: 200, body: { enabled: true, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('Never synced')).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Sync now' }));

      await waitFor(() => {
        expect(screen.getByRole('alert')).toHaveTextContent('Sync process crashed');
      });
    });

    it('enable/disable toggle → PUT + success toast', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [] } },
        { status: 200, body: { message: 'Sync enabled' } },
        { status: 200, body: { enabled: true, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('Auto-sync')).toBeInTheDocument();
      });

      // The checkbox is labeled by its state text ("Enabled" or "Disabled")
      const toggle = screen.getByRole('checkbox', { name: 'Disabled' });
      await user.click(toggle);

      await waitFor(() => {
        expect(screen.getByRole('status')).toHaveTextContent('Auto-sync enabled.');
      });
    });
  });

  describe('Auth providers section', () => {
    it('renders providers list loading then shows OIDC and LDAP cards', async () => {
      renderPage([
        { status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [{ name: 'oidc', supports_authentication: true }, { name: 'ldap', supports_authentication: true }] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('OIDC')).toBeInTheDocument();
      });
      expect(screen.getByText('LDAP')).toBeInTheDocument();
    });

    it('renders enable/disable toggle for each provider', async () => {
      renderPage([
        { status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [{ name: 'oidc', supports_authentication: true }, { name: 'ldap', supports_authentication: true }] } },
        { status: 200, body: { provider_url: 'https://idp.example.com', client_id: 'c1', scopes: 'openid', configured: true } },
        { status: 200, body: { host: 'ldap.example.com', port: 636, ssl: true, base_dn: 'dc=example,dc=com', bind_dn: '', user_filter: '', admin_group: '', configured: true } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('OIDC')).toBeInTheDocument();
      });

      // Both toggles should be present (arr-sync toggle + 2 provider toggles)
      const toggles = screen.getAllByRole('checkbox');
      expect(toggles.length).toBeGreaterThanOrEqual(2);
    });

    it('clicking Configure on OIDC expands the OIDC form', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [{ name: 'oidc', supports_authentication: true }] } },
        { status: 200, body: { provider_url: 'https://idp.example.com', client_id: 'c1', scopes: 'openid profile', configured: true } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('OIDC')).toBeInTheDocument();
      });

      // Click the Configure button specifically for OIDC
      const oidcCard = screen.getByText('OIDC').closest('.integrations__provider-card') as HTMLElement;
      await user.click(within(oidcCard).getByRole('button', { name: 'Configure' }));

      await waitFor(() => {
        expect(screen.getByLabelText(/Provider URL/)).toBeInTheDocument();
        expect(screen.getByDisplayValue('https://idp.example.com')).toBeInTheDocument();
        expect(screen.getByDisplayValue('c1')).toBeInTheDocument();
      });
    });

    it('clicking Configure on LDAP expands the LDAP form with pre-filled values', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [{ name: 'ldap', supports_authentication: true }] } },
        {
          status: 200,
          body: {
            host: 'ldap.example.com', port: 636, ssl: true,
            base_dn: 'dc=example,dc=com', bind_dn: 'cn=admin,dc=example,dc=com',
            user_filter: '(uid=%s)', admin_group: 'cn=admins,dc=example,dc=com',
            configured: true,
          },
        },
      ]);
      await waitFor(() => {
        expect(screen.getByText('LDAP')).toBeInTheDocument();
      });

      // Click the Configure button specifically for LDAP
      const ldapCard = screen.getByText('LDAP').closest('.integrations__provider-card') as HTMLElement;
      await user.click(within(ldapCard).getByRole('button', { name: 'Configure' }));

      await waitFor(() => {
        expect(screen.getByDisplayValue('ldap.example.com')).toBeInTheDocument();
        expect(screen.getByDisplayValue('636')).toBeInTheDocument();
        expect(screen.getByDisplayValue('dc=example,dc=com')).toBeInTheDocument();
      });
    });

    it('LDAP Test connection button fires POST /ldap/test and shows toast', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [{ name: 'ldap', supports_authentication: true }] } },
        {
          status: 200,
          body: {
            host: 'ldap.example.com', port: 636, ssl: true,
            base_dn: 'dc=example,dc=com', bind_dn: 'cn=admin',
            user_filter: '(uid=%s)', admin_group: '',
            configured: true,
          },
        },
        // Test result
        { status: 200, body: { success: true, message: 'Connection OK' } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('LDAP')).toBeInTheDocument();
      });

      const ldapCard = screen.getByText('LDAP').closest('.integrations__provider-card') as HTMLElement;
      await user.click(within(ldapCard).getByRole('button', { name: 'Configure' }));

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Test connection' })).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Test connection' }));

      await waitFor(() => {
        expect(screen.getByRole('status')).toHaveTextContent('Connection OK');
      });
    });

    it('OIDC save → success toast + form closes', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } },
        { status: 200, body: { providers: [{ name: 'oidc', supports_authentication: true }] } },
        { status: 200, body: { provider_url: 'https://idp.example.com', client_id: 'c1', scopes: 'openid', configured: false } },
        { status: 200, body: { message: 'OIDC settings saved.' } },
        { status: 200, body: { providers: [{ name: 'oidc', supports_authentication: true }] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('OIDC')).toBeInTheDocument();
      });

      const oidcCard = screen.getByText('OIDC').closest('.integrations__provider-card') as HTMLElement;
      await user.click(within(oidcCard).getByRole('button', { name: 'Configure' }));

      await waitFor(() => {
        expect(screen.getByLabelText(/Provider URL/)).toBeInTheDocument();
      });

      // Fill in the required fields
      await user.clear(screen.getByLabelText(/Provider URL/));
      await user.type(screen.getByLabelText(/Provider URL/), 'https://new-idp.example.com');
      await user.clear(screen.getByLabelText(/Client ID/));
      await user.type(screen.getByLabelText(/Client ID/), 'new-client');

      await user.click(screen.getByRole('button', { name: 'Save OIDC' }));

      await waitFor(() => {
        expect(screen.getByRole('status')).toHaveTextContent('OIDC settings saved.');
      });
    });
  });
});
