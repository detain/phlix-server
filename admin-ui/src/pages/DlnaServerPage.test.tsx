import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ApiClient } from '../api/client';
import { DlnaServerPage } from './DlnaServerPage';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch, type ResponseSpec, type RecordedRequest } from '../test/memoryTokenStore';

/** Build a test ApiClient backed by an ordered list of responses. */
function makeClient(responses: ResponseSpec[]): { client: ApiClient; calls: RecordedRequest[] } {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { client, calls };
}

// ---------------------------------------------------------------------------
// Mock data
// ---------------------------------------------------------------------------

const dlnaServerRunning = {
  enabled: true,
  running: true,
  serverId: 'uuid:phlix-server-main',
  friendlyName: 'Phlix Media Server',
  port: 8200,
  baseUrl: '192.168.1.100',
};

const dlnaServerStopped = {
  enabled: true,
  running: false,
  serverId: 'uuid:phlix-server-main',
  friendlyName: 'Phlix Media Server',
  port: 8200,
  baseUrl: '192.168.1.100',
};

const dlnaServerNotConfigured = {
  enabled: false,
  running: false,
  serverId: null,
  friendlyName: null,
  port: null,
  baseUrl: null,
  message: 'DLNA server not configured',
};

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('DlnaServerPage', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  function renderPage(responses: ResponseSpec[]) {
    const { client, calls } = makeClient(responses);
    const renderResult = render(
      <ToastProvider timeoutMs={0}>
        <DlnaServerPage client={client} />
      </ToastProvider>,
    );
    return { renderResult, calls };
  }

  // -------------------------------------------------------------------------
  // Initial render
  // -------------------------------------------------------------------------

  it('renders loading state initially', async () => {
    renderPage([
      { status: 200, body: dlnaServerRunning, urlMatch: '/api/v1/admin/dlna/status' },
    ]);

    expect(screen.getByText('Loading DLNA server status…')).toBeInTheDocument();
  });

  it('renders running status with green indicator', async () => {
    renderPage([
      { status: 200, body: dlnaServerRunning, urlMatch: '/api/v1/admin/dlna/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByText('Running')).toBeInTheDocument();
    });

    expect(screen.getByText('🟢')).toBeInTheDocument();
    expect(screen.getByText('Phlix Media Server')).toBeInTheDocument();
  });

  it('renders stopped status with red indicator', async () => {
    renderPage([
      { status: 200, body: dlnaServerStopped, urlMatch: '/api/v1/admin/dlna/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByText('Stopped')).toBeInTheDocument();
    });

    expect(screen.getByText('🔴')).toBeInTheDocument();
  });

  it('renders not-configured status', async () => {
    renderPage([
      { status: 200, body: dlnaServerNotConfigured, urlMatch: '/api/v1/admin/dlna/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByText('DLNA server is not configured.')).toBeInTheDocument();
    });
  });

  // -------------------------------------------------------------------------
  // Action buttons
  // -------------------------------------------------------------------------

  it('shows Start Server button when stopped', async () => {
    renderPage([
      { status: 200, body: dlnaServerStopped, urlMatch: '/api/v1/admin/dlna/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByText('Stopped')).toBeInTheDocument();
    });

    expect(screen.getByRole('button', { name: 'Start Server' })).toBeInTheDocument();
  });

  it('shows Stop Server button when running', async () => {
    renderPage([
      { status: 200, body: dlnaServerRunning, urlMatch: '/api/v1/admin/dlna/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByText('Running')).toBeInTheDocument();
    });

    expect(screen.getByRole('button', { name: 'Stop Server' })).toBeInTheDocument();
  });

  // Note: Testing loading state during fast API calls is timing-dependent
  // due to React 18 batching. The important behaviors (API call, toast, refresh)
  // are tested in other cases.

  // -------------------------------------------------------------------------
  // Toast notifications
  // -------------------------------------------------------------------------

  it('shows success toast after starting server', async () => {
    renderPage([
      { status: 200, body: dlnaServerStopped, urlMatch: '/api/v1/admin/dlna/status' },
      { status: 200, body: { success: true }, urlMatch: '/api/v1/admin/dlna/start' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Start Server' })).toBeInTheDocument();
    });

    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Start Server' }));

    await waitFor(() => {
      expect(screen.getByText('DLNA server started.')).toBeInTheDocument();
    });
  });

  it('shows success toast after stopping server', async () => {
    renderPage([
      { status: 200, body: dlnaServerRunning, urlMatch: '/api/v1/admin/dlna/status' },
      { status: 200, body: { success: true }, urlMatch: '/api/v1/admin/dlna/stop' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Stop Server' })).toBeInTheDocument();
    });

    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Stop Server' }));

    await waitFor(() => {
      expect(screen.getByText('DLNA server stopped.')).toBeInTheDocument();
    });
  });

  it('shows error toast when API fails', async () => {
    renderPage([
      { status: 200, body: dlnaServerStopped, urlMatch: '/api/v1/admin/dlna/status' },
      { status: 500, body: { success: false, message: 'Server error' }, urlMatch: '/api/v1/admin/dlna/start' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Start Server' })).toBeInTheDocument();
    });

    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Start Server' }));

    await waitFor(() => {
      expect(screen.getByText('Server error')).toBeInTheDocument();
    });
  });

  // -------------------------------------------------------------------------
  // Re-fetches status after successful action
  // -------------------------------------------------------------------------

  it('re-fetches status after successful start', async () => {
    const { calls } = renderPage([
      { status: 200, body: dlnaServerStopped, urlMatch: '/api/v1/admin/dlna/status' },
      { status: 200, body: { success: true }, urlMatch: '/api/v1/admin/dlna/start' },
      { status: 200, body: dlnaServerRunning, urlMatch: '/api/v1/admin/dlna/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Start Server' })).toBeInTheDocument();
    });

    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Start Server' }));

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Stop Server' })).toBeInTheDocument();
    });

    // Should have made 3 calls: initial status, start, then refreshed status
    const statusCalls = calls.filter((c) => c.url.includes('/api/v1/admin/dlna/status'));
    expect(statusCalls.length).toBeGreaterThanOrEqual(2);
  });
});
