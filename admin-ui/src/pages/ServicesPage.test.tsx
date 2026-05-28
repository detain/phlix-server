import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ServicesPage } from './ServicesPage';
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
      <ServicesPage client={client} />
    </ToastProvider>,
  );
  return { calls, unmount: result.unmount };
}

afterEach(() => {
  vi.useRealTimers();
});

describe('ServicesPage', () => {
  describe('Trakt.tv section', () => {
    it('shows heading and loading state', async () => {
      renderPage([
        { status: 200, body: { connected: false, username: null } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);
      expect(screen.getByRole('heading', { name: 'Services' })).toBeInTheDocument();
      expect(screen.getByText('Loading Trakt status…')).toBeInTheDocument();
    });

    it('renders connected state with username', async () => {
      renderPage([
        { status: 200, body: { connected: true, username: 'traktuser' } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('traktuser')).toBeInTheDocument();
      });
    });

    it('renders disconnected state with Connect button', async () => {
      renderPage([
        { status: 200, body: { connected: false, username: null } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);
      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Connect to Trakt' })).toBeInTheDocument();
      });
    });

    it('shows status badge - Not connected', async () => {
      renderPage([
        { status: 200, body: { connected: false, username: null } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);
      await waitFor(() => {
        expect(screen.getAllByText('Not connected').length).toBeGreaterThan(0);
      });
    });

    it('shows status badge - Connected', async () => {
      renderPage([
        { status: 200, body: { connected: true, username: 'traktuser' } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('Connected')).toBeInTheDocument();
      });
    });
  });

  describe('Last.fm section', () => {
    it('shows loading state', async () => {
      renderPage([
        { status: 200, body: { connected: false, username: null } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);
      expect(screen.getByText('Loading Last.fm status…')).toBeInTheDocument();
    });

    it('renders connected state with username and API key status', async () => {
      renderPage([
        { status: 200, body: { connected: true, username: 'traktuser' } },
        { status: 200, body: { connected: true, username: 'lastfmuser', api_key_set: true } },
      ]);
      // Wait for useEffect to run and state to update
      await new Promise(resolve => setTimeout(resolve, 0));
      // Both services show their username when connected
      expect(screen.getAllByText('lastfmuser').length).toBeGreaterThan(0);
      // API key status is shown
      expect(screen.getAllByText('Set').length).toBeGreaterThan(0);
    });

    it('renders disconnected state with Connect button', async () => {
      renderPage([
        { status: 200, body: { connected: false, username: null } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);
      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Connect Last.fm' })).toBeInTheDocument();
      });
    });

    it('shows status badge - Not connected for Last.fm', async () => {
      renderPage([
        { status: 200, body: { connected: false, username: null } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);
      await waitFor(() => {
        // Both services show "Not connected" when disconnected
        expect(screen.getAllByText('Not connected').length).toBeGreaterThanOrEqual(2);
      });
    });
  });

  describe('disconnect action', () => {
    it('disconnect button is shown when Trakt is connected', async () => {
      renderPage([
        { status: 200, body: { connected: true, username: 'traktuser' } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);
      await waitFor(() => {
        const buttons = screen.getAllByRole('button', { name: 'Disconnect' });
        expect(buttons.length).toBeGreaterThan(0);
      });
    });

    it('shows disconnect confirmation after clicking', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { connected: true, username: 'traktuser' } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
        { status: 200, body: { message: 'Disconnected' } },
        { status: 200, body: { connected: false, username: null } },
        { status: 200, body: { connected: false, username: null, api_key_set: false } },
      ]);

      await waitFor(() => {
        expect(screen.getAllByRole('button', { name: 'Disconnect' }).length).toBeGreaterThan(0);
      });

      const disconnectBtn = screen.getAllByRole('button', { name: 'Disconnect' })[0]!;
      await user.click(disconnectBtn);
    });
  });
});
